<?php

declare(strict_types=1);

namespace Tests\Examples;

use Cycle\Database\Config as Config;
use PHPUnit\Framework\TestCase;
use ReactphpX\CycleDatabase\AsyncDatabase;
use ReactphpX\CycleDatabase\AsyncMysqlDriver;
use ReactphpX\CycleDatabase\AsyncMySQLDriverConfig;
use ReactphpX\CycleDatabase\AsyncTcpConnectionConfig;
use Tests\Support\FakePool;

final class TransactionsExampleTest extends TestCase
{
    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionClass($obj);
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($obj, $value);
    }

    public function testTransactionFlowMirrorsExample(): void
    {
        $driverConfig = new AsyncMySQLDriverConfig(
            connection: new AsyncTcpConnectionConfig(
                database: 'test',
                host: '127.0.0.1',
                port: 3306,
                charset: 'utf8mb4',
                user: 'root',
                password: '123456'
            ),
        );
        $driver = AsyncMysqlDriver::create($driverConfig);

        $db = new AsyncDatabase('default', '', $driver, null);

        $db->execute('CREATE TABLE IF NOT EXISTS demo_tx (id INT AUTO_INCREMENT PRIMARY KEY, val INT)');

        $result = $db->transaction(function ($txDb) {
            $txDb->execute('INSERT INTO demo_tx (val) VALUES (?)', [100]);
            $txDb->execute('INSERT INTO demo_tx (val) VALUES (?)', [200]);
            return 'ok';
        });

        $this->assertSame('ok', $result);

        $stmt = $db->query('SELECT COUNT(*) AS c FROM demo_tx');
        $rows = $stmt->fetchAll();
        $this->assertIsArray($rows);
    }
}


