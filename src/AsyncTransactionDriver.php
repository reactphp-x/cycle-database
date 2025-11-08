<?php

namespace ReactphpX\CycleDatabase;

use Cycle\Database\Driver\DriverInterface;
use Cycle\Database\Driver\HandlerInterface;
use Cycle\Database\Driver\CompilerInterface;
use Cycle\Database\Config\DriverConfig;
use Cycle\Database\Query\BuilderInterface;
use Cycle\Database\Query\Interpolator;
use Cycle\Database\Exception\ReadonlyConnectionException;
use Cycle\Database\Exception\StatementException;
use Cycle\Database\StatementInterface;
use Cycle\Database\Injection\ParameterInterface as DbParameterInterface;
use Cycle\Database\Exception\DriverException;
use ReactphpX\MySQL\Pool;
use React\Async;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

final class AsyncTransactionDriver implements DriverInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * DateTime format to be used to perform automatic conversion of DateTime objects.
     */
    protected const DATETIME = 'Y-m-d H:i:s';
    protected const DATETIME_MICROSECONDS = 'Y-m-d H:i:s.u';

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
    private array $logOptions = [];

    public function __construct(
        Pool $pool,
        $connection,
        \DateTimeZone $timezone,
        HandlerInterface $schemaHandler,
        CompilerInterface $queryCompiler,
        BuilderInterface $queryBuilder,
        bool $readonly = false,
        string $source = '*',
        array $logOptions = [],
    ) {
        $this->pool = $pool;
        $this->connection = $connection;
        $this->timezone = $timezone;
        $this->schemaHandler = $schemaHandler->withDriver($this);
        $this->queryCompiler = $queryCompiler;
        $this->queryBuilder = $queryBuilder->withDriver($this);
        $this->readonly = $readonly;
        $this->source = $source;
        $this->logOptions = $logOptions;
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
        $queryStart = \microtime(true);
        try {
            $parameters = $this->normalizeParameters($parameters);
            $result = Async\await($this->connection->query($statement, $parameters));
        } catch (\Throwable $err) {
            $e = $this->mapException($err, Interpolator::interpolate($statement, $parameters));
            throw $e;
        } finally {
            if ($this->logger !== null) {
                $queryString = ($this->logOptions['logInterpolatedQueries'] ?? false)
                    ? Interpolator::interpolate($statement, $parameters, $this->logOptions)
                    : $statement;

                $contextParameters = ($this->logOptions['logQueryParameters'] ?? false)
                    ? $parameters
                    : [];

                $context = $this->defineLoggerContext($queryStart, $result ?? null, $contextParameters);

                if (isset($e)) {
                    $this->logger->error($queryString, $context);
                    $this->logger->alert($e->getMessage());
                } else {
                    $this->logger->info($queryString, $context);
                }
            }
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
        $queryStart = \microtime(true);
        try {
            $parameters = $this->normalizeParameters($parameters);
            $result = Async\await($this->connection->query($query, $parameters));
        } catch (\Throwable $err) {
            $e = $this->mapException($err, Interpolator::interpolate($query, $parameters));
            throw $e;
        } finally {
            if ($this->logger !== null) {
                $queryString = ($this->logOptions['logInterpolatedQueries'] ?? false)
                    ? Interpolator::interpolate($query, $parameters, $this->logOptions)
                    : $query;

                $contextParameters = ($this->logOptions['logQueryParameters'] ?? false)
                    ? $parameters
                    : [];

                $context = $this->defineLoggerContext($queryStart, $result ?? null, $contextParameters);

                if (isset($e)) {
                    $this->logger->error($queryString, $context);
                    $this->logger->alert($e->getMessage());
                } else {
                    $this->logger->info($queryString, $context);
                }
            }
        }
        if (isset($result->insertId) && $result->insertId !== 0) {
            $this->lastInsertId = $result->insertId;
        }
        return isset($result->affectedRows) ? (int)$result->affectedRows : 0;
    }

    public function lastInsertID(?string $sequence = null)
    {
        $result = $this->lastInsertId;
        $this->logger?->debug("Insert ID: {$result}");
        return $result;
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
                $this->logger?->info('Commit transaction');
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
                $this->logger?->info('Rollback transaction');
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
        $this->logger?->info("Transaction: new savepoint 'SVP{$level}'");
        Async\await($this->connection->query('SAVEPOINT SVP' . $level));
    }

    private function releaseSavepoint(int $level): void
    {
        $this->logger?->info("Transaction: release savepoint 'SVP{$level}'");
        Async\await($this->connection->query('RELEASE SAVEPOINT SVP' . $level));
    }

    private function rollbackSavepoint(int $level): void
    {
        $this->logger?->info("Transaction: rollback savepoint 'SVP{$level}'");
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
        try {
            $datetime = match (true) {
                $value instanceof \DateTimeImmutable => $value->setTimezone($this->getTimezone()),
                $value instanceof \DateTime => \DateTimeImmutable::createFromMutable($value)
                    ->setTimezone($this->getTimezone()),
                default => (new \DateTimeImmutable('now', $this->getTimezone()))->setTimestamp($value->getTimestamp()),
            };
        } catch (\Throwable $e) {
            throw new DriverException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return $datetime->format(
            $this->logOptions['withDatetimeMicroseconds'] ?? false ? self::DATETIME_MICROSECONDS : self::DATETIME,
        );
    }

    /**
     * Creating a context for logging
     *
     * @param float $queryStart Query start time
     * @param mixed $result Statement/Result object
     * @param iterable $parameters Query parameters
     */
    protected function defineLoggerContext(float $queryStart, mixed $result, iterable $parameters = []): array
    {
        $context = [
            'driver' => $this->getType(),
            'elapsed' => \microtime(true) - $queryStart,
        ];

        if (\is_object($result)) {
            if (isset($result->affectedRows)) {
                $context['rowCount'] = (int)$result->affectedRows;
            } elseif (isset($result->resultRows) && \is_array($result->resultRows)) {
                $context['rowCount'] = \count($result->resultRows);
            }
        }

        foreach ($parameters as $parameter) {
            $context['parameters'][] = Interpolator::resolveValue($parameter, $this->logOptions ?? []);
        }

        return $context;
    }

    /**
     * Normalize parameters for execution by unwrapping ParameterInterface, BackedEnum,
     * and formatting DateTimeInterface using connection timezone.
     */
    private function normalizeParameters(iterable $parameters): array
    {
        $normalized = [];
        foreach ($parameters as $name => $parameter) {
            $normalized[$name] = $this->normalizeParameterValue($parameter);
        }
        return $normalized;
    }

    private function normalizeParameterValue(mixed $parameter): mixed
    {
        if ($parameter instanceof DbParameterInterface) {
            $parameter = $parameter->getValue();
        }
        /** @since PHP 8.1 */
        if ($parameter instanceof \BackedEnum) {
            $parameter = $parameter->value;
        }
        if ($parameter instanceof \DateTimeInterface) {
            $parameter = $this->formatDatetime($parameter);
        }
        return $parameter;
    }
}


