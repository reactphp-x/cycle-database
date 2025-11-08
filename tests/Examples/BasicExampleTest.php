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

final class BasicExampleTest extends TestCase
{
    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionClass($obj);
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($obj, $value);
    }

    public function testBasicFlowMirrorsExample(): void
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
            options: [
                'withDatetimeMicroseconds' => true,
            ],
        );
        $driver = AsyncMysqlDriver::create($driverConfig);

        $db = new AsyncDatabase('default', '', $driver, null);

        $stmt = $db->query('SELECT 1 AS one');
        $rows = $stmt->fetchAll();
        $this->assertNotEmpty($rows);

        $db->execute('CREATE TABLE IF NOT EXISTS demo_basic (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255))');
        $affected = $db->execute('INSERT INTO demo_basic (title) VALUES (?)', ['hello']);
        $this->assertSame(1, $affected);
        $this->assertSame(1, $db->getDriver()->lastInsertID());

        $count = $db->select()->from('demo_basic')->count();
        $this->assertIsInt($count);

        $list = $db->select()->from('demo_basic')->limit(1)->fetchAll();
        $this->assertIsArray($list);

        $byId = $db->select()->from('demo_basic')->where('id', 1)->fetchAll();
        $this->assertIsArray($byId);
    }
}


