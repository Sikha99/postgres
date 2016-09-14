<?php declare(strict_types = 1);

namespace Amp\Postgres\Test;

use Amp\{ Coroutine, Pause };
use Amp\Postgres\{ CommandResult, Connection, QueryError, Transaction, TransactionError, TupleResult };
use Interop\Async\Loop;

abstract class AbstractConnectionTest extends \PHPUnit_Framework_TestCase {
    /** @var \Amp\Postgres\Connection */
    protected $connection;

    /**
     * @return array Start test data for database.
     */
    public function getData() {
        return [
            ['amphp', 'org'],
            ['github', 'com'],
            ['google', 'com'],
            ['php', 'net'],
        ];
    }
    
    abstract public function createConnection(string $connectionString): Connection;

    abstract public function getConnectCallable(): callable;
    
    public function setUp() {
        $this->connection = $this->createConnection('host=localhost user=postgres');
    }
    
    public function testQueryWithTupleResult() {
        \Amp\execute(function () {
            /** @var \Amp\Postgres\TupleResult $result */
            $result = yield $this->connection->query("SELECT * FROM test");

            $this->assertInstanceOf(TupleResult::class, $result);

            $this->assertSame(2, $result->numFields());
            
            $data = $this->getData();

            for ($i = 0; yield $result->next(); ++$i) {
                $row = $result->getCurrent();
                $this->assertSame($data[$i][0], $row['domain']);
                $this->assertSame($data[$i][1], $row['tld']);
            }
        }, Loop::get());
    }

    public function testQueryWithCommandResult() {
        \Amp\execute(function () {
            /** @var \Amp\Postgres\CommandResult $result */
            $result = yield $this->connection->query("INSERT INTO test VALUES ('canon', 'jp')");

            $this->assertInstanceOf(CommandResult::class, $result);
            $this->assertSame(1, $result->affectedRows());
        }, Loop::get());
    }

    /**
     * @expectedException \Amp\Postgres\QueryError
     */
    public function testQueryWithEmptyQuery() {
        \Amp\execute(function () {
            /** @var \Amp\Postgres\CommandResult $result */
            $result = yield $this->connection->query('');
        }, Loop::get());
    }

    /**
     * @expectedException \Amp\Postgres\QueryError
     */
    public function testQueryWithSyntaxError() {
        \Amp\execute(function () {
            /** @var \Amp\Postgres\CommandResult $result */
            $result = yield $this->connection->query("SELECT & FROM test");
        }, Loop::get());
    }

    public function testPrepare() {
        \Amp\execute(function () {
            $query = "SELECT * FROM test WHERE domain=\$1";

            /** @var \Amp\Postgres\Statement $statement */
            $statement = yield $this->connection->prepare($query);

            $this->assertSame($query, $statement->getQuery());

            $data = $this->getData()[0];

            /** @var \Amp\Postgres\TupleResult $result */
            $result = yield $statement->execute($data[0]);

            $this->assertInstanceOf(TupleResult::class, $result);

            $this->assertSame(2, $result->numFields());
    
            while (yield $result->next()) {
                $row = $result->getCurrent();
                $this->assertSame($data[0], $row['domain']);
                $this->assertSame($data[1], $row['tld']);
            }
        }, Loop::get());
    }

    public function testExecute() {
        \Amp\execute(function () {
            $data = $this->getData()[0];

            /** @var \Amp\Postgres\TupleResult $result */
            $result = yield $this->connection->execute("SELECT * FROM test WHERE domain=\$1", $data[0]);

            $this->assertInstanceOf(TupleResult::class, $result);

            $this->assertSame(2, $result->numFields());

            while (yield $result->next()) {
                $row = $result->getCurrent();
                $this->assertSame($data[0], $row['domain']);
                $this->assertSame($data[1], $row['tld']);
            }
        }, Loop::get());
    }

    /**
     * @depends testQueryWithTupleResult
     */
    public function testSimultaneousQuery() {
        $callback = \Amp\coroutine(function ($value) {
            /** @var \Amp\Postgres\TupleResult $result */
            $result = yield $this->connection->query("SELECT {$value} as value");

            if ($value) {
                yield new Pause(100);
            }
    
            while (yield $result->next()) {
                $row = $result->getCurrent();
                $this->assertEquals($value, $row['value']);
            }
        });
        
        \Amp\execute(function () use ($callback) {
            yield \Amp\all([$callback(0), $callback(1)]);
        }, Loop::get());
    }

    /**
     * @depends testSimultaneousQuery
     */
    public function testSimultaneousQueryWithFirstFailing() {
        $callback = \Amp\coroutine(function ($query) {
            /** @var \Amp\Postgres\TupleResult $result */
            $result = yield $this->connection->query($query);

            $data = $this->getData();
    
            for ($i = 0; yield $result->next(); ++$i) {
                $row = $result->getCurrent();
                $this->assertSame($data[$i][0], $row['domain']);
                $this->assertSame($data[$i][1], $row['tld']);
            }
        });
    
        try {
            \Amp\execute(function () use ($callback) {
                $failing = $callback("SELECT & FROM test");
                $successful = $callback("SELECT * FROM test");
                
                yield $successful;
                yield $failing;
            }, Loop::get());
        } catch (QueryError $exception) {
            return;
        }
        
        $this->fail(\sprintf("Test did not throw an instance of %s", QueryError::class));
    }

    public function testSimultaneousQueryAndPrepare() {
        $awaitables = [];
        $awaitables[] = new Coroutine((function () {
            /** @var \Amp\Postgres\TupleResult $result */
            $result = yield $this->connection->query("SELECT * FROM test");

            $data = $this->getData();
    
            for ($i = 0; yield $result->next(); ++$i) {
                $row = $result->getCurrent();
                $this->assertSame($data[$i][0], $row['domain']);
                $this->assertSame($data[$i][1], $row['tld']);
            }
        })());

        $awaitables[] = new Coroutine((function () {
            /** @var \Amp\Postgres\Statement $statement */
            $statement = (yield $this->connection->prepare("SELECT * FROM test"));

            /** @var \Amp\Postgres\TupleResult $result */
            $result = yield $statement->execute();

            $data = $this->getData();
    
            for ($i = 0; yield $result->next(); ++$i) {
                $row = $result->getCurrent();
                $this->assertSame($data[$i][0], $row['domain']);
                $this->assertSame($data[$i][1], $row['tld']);
            }
        })());

        \Amp\execute(function () use ($awaitables) {
            yield \Amp\all($awaitables);
        }, Loop::get());
    }

    public function testSimultaneousPrepareAndExecute() {
        $awaitables[] = new Coroutine((function () {
            /** @var \Amp\Postgres\Statement $statement */
            $statement = yield $this->connection->prepare("SELECT * FROM test");

            /** @var \Amp\Postgres\TupleResult $result */
            $result = yield $statement->execute();

            $data = $this->getData();
    
            for ($i = 0; yield $result->next(); ++$i) {
                $row = $result->getCurrent();
                $this->assertSame($data[$i][0], $row['domain']);
                $this->assertSame($data[$i][1], $row['tld']);
            }
        })());

        $awaitables[] = new Coroutine((function () {
            /** @var \Amp\Postgres\TupleResult $result */
            $result = yield $this->connection->execute("SELECT * FROM test");

            $data = $this->getData();
    
            for ($i = 0; yield $result->next(); ++$i) {
                $row = $result->getCurrent();
                $this->assertSame($data[$i][0], $row['domain']);
                $this->assertSame($data[$i][1], $row['tld']);
            }
        })());

        \Amp\execute(function () use ($awaitables) {
            yield \Amp\all($awaitables);
        }, Loop::get());
    }

    public function testTransaction() {
        \Amp\execute(function () {
            $isolation = Transaction::COMMITTED;

            /** @var \Amp\Postgres\Transaction $transaction */
            $transaction = yield $this->connection->transaction($isolation);

            $this->assertInstanceOf(Transaction::class, $transaction);

            $data = $this->getData()[0];

            $this->assertTrue($transaction->isActive());
            $this->assertSame($isolation, $transaction->getIsolationLevel());

            yield $transaction->savepoint('test');

            $result = yield $transaction->execute("SELECT * FROM test WHERE domain=\$1 FOR UPDATE", $data[0]);

            yield $transaction->rollbackTo('test');

            yield $transaction->commit();

            $this->assertFalse($transaction->isActive());

            try {
                $result = yield $transaction->execute("SELECT * FROM test");
                $this->fail('Query should fail after transaction commit');
            } catch (TransactionError $exception) {
                // Exception expected.
            }
        }, Loop::get());
    }
    
    public function testConnect() {
        \Amp\execute(function () {
            $connect = $this->getConnectCallable();
            $connection = yield $connect('host=localhost user=postgres');
            $this->assertInstanceOf(Connection::class, $connection);
        });
    }
    
    /**
     * @expectedException \Amp\Postgres\FailureException
     */
    public function testConnectInvalidUser() {
        \Amp\execute(function () {
            $connect = $this->getConnectCallable();
            $connection = yield $connect('host=localhost user=invalid', 1);
        });
    }
    
    /**
     * @expectedException \Amp\Postgres\FailureException
     */
    public function testConnectInvalidConnectionString() {
        \Amp\execute(function () {
            $connect = $this->getConnectCallable();
            $connection = yield $connect('invalid connection string', 1);
        });
    }
    
    /**
     * @expectedException \Amp\Postgres\FailureException
     */
    public function testConnectInvalidHost() {
        \Amp\execute(function () {
            $connect = $this->getConnectCallable();
            $connection = yield $connect('hostaddr=invalid.host user=postgres', 1);
        });
    }
}