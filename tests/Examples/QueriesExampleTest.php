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

final class QueriesExampleTest extends TestCase
{
    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionClass($obj);
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($obj, $value);
    }

    public function testQueriesFlowMirrorsExample(): void
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

        $db->execute('CREATE TABLE IF NOT EXISTS demo_q (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255))');
        for ($i = 0; $i < 3; $i++) {
            $db->execute('INSERT INTO demo_q (title) VALUES (?)', ['title-' . $i]);
        }
        $stmt = $db->query('SELECT * FROM demo_q ORDER BY id DESC LIMIT 5');
        $rows = $stmt->fetchAll();
        $this->assertNotEmpty($rows);

        $updated = $db->execute('UPDATE demo_q SET title=? WHERE id=?', ['updated', 1]);
        $this->assertSame(1, $updated);

        $deleted = $db->execute('DELETE FROM demo_q WHERE id>?', [2]);
        $this->assertSame(1, $deleted);
    }
}


