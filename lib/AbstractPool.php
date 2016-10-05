<?php declare(strict_types = 1);

namespace Amp\Postgres;

use Amp\{ Coroutine, Deferred };
use Interop\Async\Awaitable;

abstract class AbstractPool implements Pool {
    /** @var \SplQueue */
    private $idle;

    /** @var \SplQueue */
    private $busy;

    /** @var \SplObjectStorage */
    private $connections;

    /** @var \Interop\Async\Awaitable|null */
    private $awaitable;
    
    /** @var \Amp\Deferred|null */
    private $deferred;
    
    /** @var \Amp\Postgres\Connection|\Interop\Async\Awaitable|null Connection used for notification listening. */
    private $listeningConnection;
    
    /** @var int Number of listeners on listening connection. */
    private $listenerCount = 0;

    /**
     * @return \Interop\Async\Awaitable<\Amp\Postgres\Connection>
     *
     * @throws \Amp\Postgres\FailureException
     */
    abstract protected function createConnection(): Awaitable;

    public function __construct() {
        $this->connections = new \SplObjectStorage();
        $this->idle = new \SplQueue();
        $this->busy = new \SplQueue();
    }

    /**
     * {@inheritdoc}
     */
    public function getConnectionCount(): int {
        return $this->connections->count();
    }

    /**
     * {@inheritdoc}
     */
    public function getIdleConnectionCount(): int {
        return $this->idle->count();
    }

    /**
     * @param \Amp\Postgres\Connection $connection
     */
    protected function addConnection(Connection $connection) {
        if (isset($this->connections[$connection])) {
            return;
        }

        $this->connections->attach($connection);
        $this->idle->push($connection);
    }

    /**
     * @coroutine
     *
     * @return \Generator
     *
     * @resolve \Amp\Postgres\Connection
     */
    private function pop(): \Generator {
        while ($this->awaitable !== null) {
            try {
                yield $this->awaitable; // Prevent simultaneous connection creation.
            } catch (\Throwable $exception) {
                // Ignore failure or cancellation of other operations.
            }
        }

        if ($this->idle->isEmpty()) {
            try {
                if ($this->connections->count() >= $this->getMaxConnections()) {
                    // All possible connections busy, so wait until one becomes available.
                    $this->deferred = new Deferred;
                    yield $this->awaitable = $this->deferred->getAwaitable();
                } else {
                    // Max connection count has not been reached, so open another connection.
                    $this->awaitable = $this->createConnection();
                    $this->addConnection(yield $this->awaitable);
                }
            } finally {
                $this->deferred = null;
                $this->awaitable = null;
            }
        }

        // Shift a connection off the idle queue.
        return $this->idle->shift();
    }

    /**
     * @param \Amp\Postgres\Connection $connection
     *
     * @throws \Error If the connection is not part of this pool.
     */
    private function push(Connection $connection) {
        if (!isset($this->connections[$connection])) {
            throw new \Error('Connection is not part of this pool');
        }

        $this->idle->push($connection);

        if ($this->deferred instanceof Deferred) {
            $this->deferred->resolve($connection);
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function query(string $sql): Awaitable {
        return new Coroutine($this->doQuery($sql));
    }
    
    private function doQuery(string $sql): \Generator {
        /** @var \Amp\Postgres\Connection $connection */
        $connection = yield from $this->pop();

        try {
            $result = yield $connection->query($sql);
        } catch (\Throwable $exception) {
            $this->push($connection);
            throw $exception;
        }
        
        if ($result instanceof Operation) {
            $result->onComplete(function () use ($connection) {
                $this->push($connection);
            });
        } else {
            $this->push($connection);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(string $sql, ...$params): Awaitable {
        return new Coroutine($this->doExecute($sql, $params));
    }
    
    private function doExecute(string $sql, array $params): \Generator {
        /** @var \Amp\Postgres\Connection $connection */
        $connection = yield from $this->pop();
    
        try {
            $result = yield $connection->execute($sql, ...$params);
        } catch (\Throwable $exception) {
            $this->push($connection);
            throw $exception;
        }
    
        if ($result instanceof Operation) {
            $result->onComplete(function () use ($connection) {
                $this->push($connection);
            });
        } else {
            $this->push($connection);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function prepare(string $sql): Awaitable {
        return new Coroutine($this->doPrepare($sql));
    }
    
    private function doPrepare(string $sql): \Generator {
        /** @var \Amp\Postgres\Connection $connection */
        $connection = yield from $this->pop();

        try {
            /** @var \Amp\Postgres\Statement $statement */
            $statement = yield $connection->prepare($sql);
        } finally {
            $this->push($connection);
        }
        
        return $statement;
    }
    
    /**
     * {@inheritdoc}
     */
    public function notify(string $channel, string $payload = ""): Awaitable {
        return new Coroutine($this->doNotify($channel, $payload));
    }
    
    private function doNotify(string $channel, string $payload): \Generator {
        /** @var \Amp\Postgres\Connection $connection */
        $connection = yield from $this->pop();
        
        try {
            $result = yield $connection->notify($channel, $payload);
        } finally {
            $this->push($connection);
        }
        
        return $result;
    }
    
    /**
     * {@inheritdoc}
     */
    public function listen(string $channel): Awaitable {
        return new Coroutine($this->doListen($channel));
    }
    
    public function doListen(string $channel): \Generator {
        ++$this->listenerCount;
        
        if ($this->listeningConnection === null) {
            $this->listeningConnection = new Coroutine($this->pop());
        }
        
        if ($this->listeningConnection instanceof Awaitable) {
            $this->listeningConnection = yield $this->listeningConnection;
        }
        
        try {
            /** @var \Amp\Postgres\Listener $listener */
            $listener = yield $this->listeningConnection->listen($channel);
        } catch (\Throwable $exception) {
            if (--$this->listenerCount === 0) {
                $connection = $this->listeningConnection;
                $this->listeningConnection = null;
                $this->push($connection);
            }
            throw $exception;
        }
        
        $listener->onComplete(function () {
            if (--$this->listenerCount === 0) {
                $connection = $this->listeningConnection;
                $this->listeningConnection = null;
                $this->push($connection);
            }
        });
    
        return $listener;
    }

    /**
     * {@inheritdoc}
     */
    public function transaction(int $isolation = Transaction::COMMITTED): Awaitable {
        return new Coroutine($this->doTransaction($isolation));
    }
    
    private function doTransaction(int $isolation = Transaction::COMMITTED): \Generator {
        /** @var \Amp\Postgres\Connection $connection */
        $connection = yield from $this->pop();

        try {
            /** @var \Amp\Postgres\Transaction $transaction */
            $transaction = yield $connection->transaction($isolation);
        } catch (\Throwable $exception) {
            $this->push($connection);
            throw $exception;
        }
        
        $transaction->onComplete(function () use ($connection) {
            $this->push($connection);
        });
        
        return $transaction;
    }
}
