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

final class UpsertExampleTest extends TestCase
{
    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionClass($obj);
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($obj, $value);
    }

    public function testUpsertFlowMirrorsExample(): void
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

        $db->execute('DROP TABLE IF EXISTS users_upsert');
        $db->execute('CREATE TABLE users_upsert (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(191) NOT NULL UNIQUE, age INT NOT NULL DEFAULT 0, name VARCHAR(191) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $db->upsert('users_upsert')->columns('email', 'name')->values('adam@email.com', 'Adam')->run();
        $db->upsert('users_upsert')->columns('email', 'name')
            ->values(['email' => 'adam@email.com', 'name' => 'Adam Updated'])
            ->values(['email' => 'bill@email.com', 'name' => 'Bill'])
            ->run();
        $db->upsert('users_upsert')->columns('email', 'name', 'age')->updates('age', 'name')
            ->values([
                ['email' => 'adam@email.com', 'name' => 'Charlie2', 'age' => 10],
                ['email' => 'dave@email.com', 'name' => 'Dave10', 'age' => 40],
            ])->run();

        $rows = $db->query('SELECT email, name, age FROM users_upsert ORDER BY email')->fetchAll();
        $this->assertNotEmpty($rows);
    }
}


