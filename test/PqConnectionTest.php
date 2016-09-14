<?php declare(strict_types = 1);

namespace Amp\Postgres\Test;

use Amp\Postgres\{ Connection, PqConnection };

class PqConnectionTest extends AbstractConnectionTest {
    /** @var resource PostgreSQL connection resource. */
    protected $handle;

    public function createConnection(string $connectionString): Connection {
        $this->handle = new \pq\Connection($connectionString);
    
        $result = $this->handle->exec("CREATE TABLE test (domain VARCHAR(63), tld VARCHAR(63), PRIMARY KEY (domain, tld))");
    
        if (!$result) {
            $this->fail('Could not create test table.');
        }
    
        foreach ($this->getData() as $row) {
            $result = $this->handle->execParams("INSERT INTO test VALUES (\$1, \$2)", $row);
        
            if (!$result) {
                $this->fail('Could not insert test data.');
            }
        }
    
        return new PqConnection($this->handle);
    }
    
    public function getConnectCallable(): callable {
        return [PqConnection::class, 'connect'];
    }
    
    public function tearDown() {
        $this->handle->exec("ROLLBACK");
        $this->handle->exec("DROP TABLE test");
    }
}