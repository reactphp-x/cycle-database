<?php

declare(strict_types=1);

// Define a fake Pool class before vendor autoload so production class is not loaded.
namespace ReactphpX\MySQL {
    use React\Promise\PromiseInterface;
    use function React\Promise\resolve;

    if (!class_exists(Pool::class, false)) {
        class Pool
        {
            public array $calls = [];
            private array $state = [
                'lastInsertId' => 0,
                'rows' => [],
                'inTx' => false,
            ];

            public function __construct(...$args)
            {
                $this->state['rows']['demo_basic'] = [
                    ['id' => 1, 'title' => 'hello'],
                ];
                $this->state['rows']['demo_q'] = [
                    ['id' => 3, 'title' => 'title-2'],
                    ['id' => 2, 'title' => 'title-1'],
                    ['id' => 1, 'title' => 'title-0'],
                ];
                $this->state['rows']['users_upsert'] = [
                    ['email' => 'adam@email.com', 'name' => 'Charlie2', 'age' => 10],
                    ['email' => 'bill@email.com', 'name' => 'Bill', 'age' => 0],
                    ['email' => 'dave@email.com', 'name' => 'Dave10', 'age' => 40],
                ];
            }

            public function query(string $sql, array $params): PromiseInterface
            {
                $this->calls[] = [$sql, $params];
                $lower = \strtolower($sql);

                if (\str_starts_with($lower, 'select')) {
                    if (\str_contains($lower, 'count(')) {
                        return resolve((object)['resultRows' => [[1]]]);
                    }
                    if (\str_contains($lower, 'from demo_q')) {
                        return resolve((object)['resultRows' => $this->state['rows']['demo_q'], 'resultFields' => ['id','title']]);
                    }
                    if (\str_contains($lower, 'from users_upsert')) {
                        return resolve((object)['resultRows' => $this->state['rows']['users_upsert'], 'resultFields' => ['email','name','age']]);
                    }
                    return resolve((object)['resultRows' => [['one' => 1]], 'resultFields' => ['one']]);
                }

                if (\str_starts_with($lower, 'create table') || \str_starts_with($lower, 'drop table')) {
                    return resolve((object)['affectedRows' => 0]);
                }
                if (\str_starts_with($lower, 'insert')) {
                    $this->state['lastInsertId']++;
                    return resolve((object)['affectedRows' => 1, 'insertId' => $this->state['lastInsertId']]);
                }
                if (\str_starts_with($lower, 'update')) {
                    return resolve((object)['affectedRows' => 1]);
                }
                if (\str_starts_with($lower, 'delete')) {
                    return resolve((object)['affectedRows' => 1]);
                }
                if (\str_starts_with($lower, 'savepoint') || \str_starts_with($lower, 'release savepoint') || \str_starts_with($lower, 'rollback to savepoint')) {
                    return resolve((object)['affectedRows' => 0]);
                }
                if ($lower === 'begin' || \str_starts_with($lower, 'set transaction isolation level')) {
                    $this->state['inTx'] = true;
                    return resolve((object)['affectedRows' => 0]);
                }
                if ($lower === 'commit' || $lower === 'rollback') {
                    $this->state['inTx'] = false;
                    return resolve((object)['affectedRows' => 0]);
                }

                return resolve((object)['affectedRows' => 0]);
            }

            public function getConnection(): PromiseInterface
            {
                $pool = $this;
                $conn = new class($pool) {
                    private Pool $pool;
                    public function __construct(Pool $pool) { $this->pool = $pool; }
                    public function query(string $sql, array $params = []): PromiseInterface { return $this->pool->query($sql, $params); }
                };
                return resolve($conn);
            }

            public function releaseConnection($conn): void {}
            public function keepAlive(int $seconds = 30): void {}
            public function close(): void {}
            public function quit(): PromiseInterface { return resolve(true); }
            public function queryStream($sql, $params = []) { return null; }
        }
    }
}

namespace {
    require __DIR__ . '/../vendor/autoload.php';
}


