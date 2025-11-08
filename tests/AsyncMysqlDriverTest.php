<?php

declare(strict_types=1);

namespace Tests;

use Cycle\Database\Injection\Parameter;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use ReactphpX\CycleDatabase\AsyncMysqlDriver;
use ReactphpX\CycleDatabase\AsyncMySQLDriverConfig;
use ReactphpX\CycleDatabase\AsyncTcpConnectionConfig;
use Psr\Log\LoggerInterface;
use function React\Promise\resolve;
use ReactphpX\MySQL\Pool;
use Tests\Support\FakePool;

enum TestColor: string {
    case Red = 'red';
}

final class AsyncMysqlDriverTest extends TestCase
{
    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionClass($obj);
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($obj, $value);
    }

    public function testNormalizeParametersAndQuoteWithMicroseconds(): void
    {
        $driver = new AsyncMysqlDriver(
            uri: 'user:pass@127.0.0.1:3306/test?charset=utf8mb4',
            minConnections: 1,
            maxConnections: 1,
            waitQueue: 1,
            waitTimeout: 0,
        );

        $config = new AsyncMySQLDriverConfig(
            connection: new AsyncTcpConnectionConfig(database: 'test', host: '127.0.0.1', port: 3306, charset: 'utf8mb4', user: 'u', password: 'p'),
            options: [
                'withDatetimeMicroseconds' => true,
                'logInterpolatedQueries' => true,
                'logQueryParameters' => true,
            ],
        );
        $this->setPrivate($driver, 'config', $config);

        // Inject fake pool to capture params
        // Access the internal fake pool created by the driver
        $ref = new \ReflectionClass($driver);
        $poolProp = $ref->getProperty('pool');
        $poolProp->setAccessible(true);
        $fakePool = $poolProp->getValue($driver);

        // Use NullLogger to avoid signature mismatches
        $driver->setLogger(new \Psr\Log\NullLogger());

        $dt = new \DateTimeImmutable('2020-01-01 00:00:00.123456', new \DateTimeZone('Asia/Shanghai'));
        $affected = $driver->execute(
            'INSERT INTO t (a,b,c) VALUES (?,?,?)',
            [$dt, TestColor::Red, new Parameter(42)]
        );

        $this->assertSame(1, $affected);
        $this->assertTrue($driver->lastInsertID() > 0);

        // Parameters are normalized for execution (not quoted)
        $this->assertSame(['2020-01-01 00:00:00.123456', 'red', 42], $fakePool->calls[0][1]);

        // Quote should wrap and keep microseconds
        $this->assertSame("'2020-01-01 00:00:00.123456'", $driver->quote($dt));
    }
}


