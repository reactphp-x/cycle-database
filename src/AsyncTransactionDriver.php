<?php

namespace ReactphpX\CycleDatabase;

use Cycle\Database\Driver\DriverInterface;
use Cycle\Database\Driver\HandlerInterface;
use Cycle\Database\Driver\CompilerInterface;
use Cycle\Database\Config\DriverConfig;
use Cycle\Database\Query\BuilderInterface;
use Cycle\Database\Exception\ReadonlyConnectionException;
use Cycle\Database\Exception\StatementException;
use Cycle\Database\StatementInterface;
use ReactphpX\MySQL\Pool;
use React\Async;

final class AsyncTransactionDriver implements DriverInterface
{
    private Pool $pool;
    private \DateTimeZone $timezone;
    private HandlerInterface $schemaHandler;
    private CompilerInterface $queryCompiler;
    private BuilderInterface $queryBuilder;
    private $connection;
    private int $transactionLevel = 1;
    private $lastInsertId = null;
    private bool $readonly = false;
    private string $source;

    public function __construct(
        Pool $pool,
        $connection,
        \DateTimeZone $timezone,
        HandlerInterface $schemaHandler,
        CompilerInterface $queryCompiler,
        BuilderInterface $queryBuilder,
        bool $readonly = false,
        string $source = '*',
    ) {
        $this->pool = $pool;
        $this->connection = $connection;
        $this->timezone = $timezone;
        $this->schemaHandler = $schemaHandler->withDriver($this);
        $this->queryCompiler = $queryCompiler;
        $this->queryBuilder = $queryBuilder->withDriver($this);
        $this->readonly = $readonly;
        $this->source = $source;
    }

    public static function create(DriverConfig $config): self
    {
        throw new \BadMethodCallException('AsyncTransactionDriver cannot be created from config');
    }

    public function isReadonly(): bool
    {
        return $this->readonly;
    }

    public function getType(): string
    {
        return 'MySQL';
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getTimezone(): \DateTimeZone
    {
        return $this->timezone;
    }

    public function getSchemaHandler(): HandlerInterface
    {
        return $this->schemaHandler;
    }

    public function getQueryCompiler(): CompilerInterface
    {
        return $this->queryCompiler;
    }

    public function getQueryBuilder(): BuilderInterface
    {
        return $this->queryBuilder;
    }

    public function connect(): void
    {
        // already connected via provided connection
    }

    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    public function disconnect(): void
    {
        // keep connection until commit/rollback to ensure transactional integrity
    }

    public function quote(mixed $value, int $type = \PDO::PARAM_STR): string
    {
        if ($value instanceof \BackedEnum) {
            $value = (string)$value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            $value = $this->formatDatetime($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if ($value === null) {
            return 'NULL';
        }
        $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$value);
        return "'{$escaped}'";
    }

    public function query(string $statement, array $parameters = []): StatementInterface
    {
        try {
            $parameters = array_map(function ($parameter) {
                if ($parameter instanceof \Cycle\Database\Injection\Parameter) {
                    return $parameter->getValue();
                }
                return $parameter;
            }, $parameters);
            $result = Async\await($this->connection->query($statement, $parameters));
        } catch (\Throwable $e) {
            throw $this->mapException($e, $statement);
        }
        if (isset($result->insertId) && $result->insertId !== 0) {
            $this->lastInsertId = $result->insertId;
        }
        return new AsyncStatement($statement, $result);
    }

    public function execute(string $query, array $parameters = []): int
    {
        if ($this->isReadonly()) {
            throw ReadonlyConnectionException::onWriteStatementExecution();
        }
        try {
            $parameters = array_map(function ($parameter) {
                if ($parameter instanceof \Cycle\Database\Injection\Parameter) {
                    return $parameter->getValue();
                }
                return $parameter;
            }, $parameters);
            $result = Async\await($this->connection->query($query, $parameters));
        } catch (\Throwable $e) {
            throw $this->mapException($e, $query);
        }
        if (isset($result->insertId) && $result->insertId !== 0) {
            $this->lastInsertId = $result->insertId;
        }
        return isset($result->affectedRows) ? (int)$result->affectedRows : 0;
    }

    public function lastInsertID(?string $sequence = null)
    {
        return $this->lastInsertId;
    }

    public function beginTransaction(?string $isolationLevel = null): DriverInterface
    {
        ++$this->transactionLevel;
        if ($this->transactionLevel === 1) {
            // already in a transaction for this driver
            return $this;
        }
        $this->createSavepoint($this->transactionLevel);
        return $this;
    }

    public function commitTransaction(): bool
    {
        --$this->transactionLevel;
        if ($this->transactionLevel === 0) {
            try {
                Async\await($this->connection->query('COMMIT'));
            } finally {
                $this->pool->releaseConnection($this->connection);
                $this->connection = null;
            }
            return true;
        }
        $this->releaseSavepoint($this->transactionLevel + 1);
        return true;
    }

    public function rollbackTransaction(): bool
    {
        --$this->transactionLevel;
        if ($this->transactionLevel === 0) {
            try {
                Async\await($this->connection->query('ROLLBACK'));
            } finally {
                $this->pool->releaseConnection($this->connection);
                $this->connection = null;
            }
            return true;
        }
        $this->rollbackSavepoint($this->transactionLevel + 1);
        return true;
    }

    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return match ($name) {
            'identifier' => $this->getQueryCompiler()->quoteIdentifier($arguments[0]),
            default => throw new \Cycle\Database\Exception\DriverException("Undefined driver method `{$name}`"),
        };
    }

    private function createSavepoint(int $level): void
    {
        Async\await($this->connection->query('SAVEPOINT SVP' . $level));
    }

    private function releaseSavepoint(int $level): void
    {
        Async\await($this->connection->query('RELEASE SAVEPOINT SVP' . $level));
    }

    private function rollbackSavepoint(int $level): void
    {
        Async\await($this->connection->query('ROLLBACK TO SAVEPOINT SVP' . $level));
    }

    private function mapException(\Throwable $exception, string $query): StatementException
    {
        if ((int) $exception->getCode() === 23000) {
            return new StatementException\ConstrainException($exception, $query);
        }

        $message = \strtolower($exception->getMessage());

        if (
            \str_contains($message, 'server has gone away')
            || \str_contains($message, 'broken pipe')
            || \str_contains($message, 'connection')
            || \str_contains($message, 'packets out of order')
            || \str_contains($message, 'disconnected by the server because of inactivity')
            || ((int) $exception->getCode() > 2000 && (int) $exception->getCode() < 2100)
        ) {
            return new StatementException\ConnectionException($exception, $query);
        }

        return new StatementException($exception, $query);
    }

    private function formatDatetime(\DateTimeInterface $value): string
    {
        $datetime = $value instanceof \DateTimeImmutable
            ? $value->setTimezone($this->timezone)
            : (\DateTimeImmutable::createFromMutable($value))->setTimezone($this->timezone);
        return $datetime->format('Y-m-d H:i:s');
    }
}


