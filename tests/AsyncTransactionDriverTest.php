<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use ReactphpX\CycleDatabase\AsyncTransactionDriver;
use Psr\Log\LoggerInterface;
use function React\Promise\resolve;

final class AsyncTransactionDriverTest extends TestCase
{
    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionClass($obj);
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($obj, $value);
    }

    public function testExecuteNormalizesParametersAndMicroseconds(): void
    {
        $captured = ['sql' => null, 'params' => null];

        $fakeConn = new class($captured) {
            public array $captured;
            public function __construct(array &$captured) { $this->captured = &$captured; }
            public function query(string $sql, array $params): PromiseInterface {
                $this->captured['sql'] = $sql;
                $this->captured['params'] = $params;
                $result = (object)['affectedRows' => 2];
                return resolve($result);
            }
        };
        $fakePool = new \ReactphpX\MySQL\Pool();

        $driver = new AsyncTransactionDriver(
            pool: $fakePool,
            connection: $fakeConn,
            timezone: new \DateTimeZone('Asia/Shanghai'),
            schemaHandler: (new \Cycle\Database\Driver\MySQL\MySQLHandler()),
            queryCompiler: new \ReactphpX\CycleDatabase\MySQLCompiler('``'),
            queryBuilder: (new \Cycle\Database\Query\QueryBuilder(
                new \Cycle\Database\Driver\MySQL\Query\MySQLSelectQuery(),
                new \Cycle\Database\Query\InsertQuery(),
                new \Cycle\Database\Query\UpsertQuery(),
                new \Cycle\Database\Driver\MySQL\Query\MySQLUpdateQuery(),
                new \Cycle\Database\Driver\MySQL\Query\MySQLDeleteQuery(),
            )),
            readonly: false,
            source: '*',
            logOptions: ['withDatetimeMicroseconds' => true],
        );

        // Ensure driver objects are wired to itself
        $this->setPrivate($driver, 'schemaHandler', $driver->getSchemaHandler()->withDriver($driver));
        $this->setPrivate($driver, 'queryBuilder', $driver->getQueryBuilder()->withDriver($driver));

        $driver->setLogger(new \Psr\Log\NullLogger());

        $dt = new \DateTimeImmutable('2020-01-01 00:00:00.000111', new \DateTimeZone('Asia/Shanghai'));
        $rows = $driver->execute('UPDATE t SET a=?', [$dt]);

        $this->assertSame(2, $rows);
        $this->assertSame(['2020-01-01 00:00:00.000111'], $captured['params']);
    }
}


