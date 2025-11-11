<?php

namespace ReactphpX\CycleDatabase;

use Cycle\Database\Config\DriverConfig;
use Cycle\Database\Config\MySQLDriverConfig;
use Cycle\Database\Config\PDOConnectionConfig;
use Cycle\Database\Driver\DriverInterface;
use Cycle\Database\Driver\HandlerInterface;
use Cycle\Database\Driver\CompilerInterface;
use Cycle\Database\Driver\CachingCompilerInterface;
use Cycle\Database\Driver\CompilerCache;
// use Cycle\Database\Driver\MySQL\MySQLCompiler;
use Cycle\Database\Driver\MySQL\MySQLHandler;
use Cycle\Database\Driver\MySQL\Query\MySQLDeleteQuery;
use Cycle\Database\Driver\MySQL\Query\MySQLSelectQuery;
use Cycle\Database\Driver\MySQL\Query\MySQLUpdateQuery;
use Cycle\Database\Query\UpsertQuery;
use Cycle\Database\Exception\ReadonlyConnectionException;
use Cycle\Database\Exception\StatementException;
use Cycle\Database\Query\BuilderInterface;
use Cycle\Database\Query\InsertQuery;
use Cycle\Database\Query\QueryBuilder;
use Cycle\Database\Query\Interpolator;
use Cycle\Database\StatementInterface;
use React\Async;
use ReactphpX\MySQL\Pool;
use Cycle\Database\Config\ProvidesSourceString;
use Cycle\Database\Injection\ParameterInterface as DbParameterInterface;
use Cycle\Database\Exception\DriverException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class AsyncMysqlDriver implements DriverInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * DateTime format to be used to perform automatic conversion of DateTime objects.
     */
    protected const DATETIME = 'Y-m-d H:i:s';
    protected const DATETIME_MICROSECONDS = 'Y-m-d H:i:s.u';

    private Pool $pool;
    private $lastInsertId = null;
    private DriverConfig $config;
    private bool $readonly = false;
    private \DateTimeZone $timezone;

    private HandlerInterface $schemaHandler;
    private CompilerInterface $queryCompiler;
    private BuilderInterface $queryBuilder;

    public static function create(DriverConfig $config): self
    {
        // Expect MySQLDriverConfig with PDOConnectionConfig inside
        $readonly = $config->readonly;
        $timezone = new \DateTimeZone($config->timezone);

        $conn = $config->connection;
        if (!$conn instanceof PDOConnectionConfig) {
            throw new \InvalidArgumentException('Unsupported connection config for AsyncMysqlDriver');
        }

        $uri = self::buildUriFromPdoConfig($conn);

        $options = $config->options ?? [];
        $minConnections = isset($options['minConnections']) ? (int)$options['minConnections'] : 2;
        $maxConnections = isset($options['maxConnections']) ? (int)$options['maxConnections'] : 10;
        $waitQueue = isset($options['waitQueue']) ? (int)$options['waitQueue'] : 100;
        $waitTimeout = isset($options['waitTimeout']) ? (int)$options['waitTimeout'] : 0;

        $driver = new self(
            uri: $uri,
            minConnections: $minConnections,
            maxConnections: $maxConnections,
            waitQueue: $waitQueue,
            waitTimeout: $waitTimeout,
        );
        $driver->readonly = $readonly;
        $driver->timezone = $timezone;

        // Initialize schema handler, compiler, and query builder similar to MySQLDriver
        $handler = (new MySQLHandler())->withDriver($driver);
        $compiler = new MySQLCompiler('``');
        if ($config->queryCache) {
            $queryCompiler = $compiler instanceof CachingCompilerInterface ? new CompilerCache($compiler) : $compiler;
        } else {
            $queryCompiler = $compiler;
        }
        $builder = (new QueryBuilder(
            new MySQLSelectQuery(),
            new InsertQuery(),
            new UpsertQuery(),
            new MySQLUpdateQuery(),
            new MySQLDeleteQuery(),
        ))->withDriver($driver);

        $driver->schemaHandler = $handler;
        $driver->queryCompiler = $queryCompiler;
        $driver->queryBuilder = $builder;
        $driver->config = $config;

        return $driver;
    }

    public function __construct(
        string $uri,
        int $minConnections = 2,
        int $maxConnections = 10,
        int $waitQueue = 100,
        int $waitTimeout = 0
    ) {
        $this->pool = new Pool(
            uri: $uri,
            minConnections: $minConnections,
            maxConnections: $maxConnections,
            waitQueue: $waitQueue,
            waitTimeout: $waitTimeout
        );
        $this->timezone = new \DateTimeZone('Asia/Shanghai');
    }

    public function isReadonly(): bool
    {
        return $this->readonly;
    }

    public function getType(): string
    {
        return 'MySQL';
    }

    /**
     * Get driver source database or file name.
     *
     * @psalm-return non-empty-string
     *
     * @throws DriverException
     */
    public function getSource(): string
    {
        $config = $this->config->connection;

        return $config instanceof ProvidesSourceString ? $config->getSourceString() : '*';
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

	/**
	 * Quote identifier using the current compiler rules.
	 */
	public function identifier(string $identifier): string
	{
		return $this->queryCompiler->quoteIdentifier($identifier);
	}

    public function connect(): void
    {
        // Pool is lazy - no explicit action needed
    }

    public function isConnected(): bool
    {
        // No direct knowledge; consider true when pool exists
        return $this->pool !== null;
    }

    public function disconnect(): void
    {
        try {
            $this->pool->close();
        } catch (\Throwable $e) {
            $this->logger?->error($e->getMessage());
        }
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
        // naive quoting
        $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$value);
        return "'{$escaped}'";
    }

    public function query(string $statement, array $parameters = []): StatementInterface
    {
        $queryStart = \microtime(true);
        try {
            $parameters = $this->normalizeParameters($parameters);
            $result = Async\await($this->pool->query($statement, $parameters));
        } catch (\Throwable $err) {
            $e = $this->mapException($err, Interpolator::interpolate($statement, $parameters));
            throw $e;
        } finally {
            if ($this->logger !== null) {
                $queryString = $this->config->options['logInterpolatedQueries']
                    ? Interpolator::interpolate($statement, $parameters, $this->config->options)
                    : $statement;

                $contextParameters = $this->config->options['logQueryParameters'] ?? false
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
            $result = Async\await($this->pool->query($query, $parameters));
        } catch (\Throwable $err) {
            $e = $this->mapException($err, Interpolator::interpolate($query, $parameters));
            throw $e;
        } finally {
            if ($this->logger !== null) {
                $queryString = $this->config->options['logInterpolatedQueries']
                    ? Interpolator::interpolate($query, $parameters, $this->config->options)
                    : $query;

                $contextParameters = $this->config->options['logQueryParameters'] ?? false
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

    public function beginTransaction(?string $isolationLevel = null): \Cycle\Database\Driver\DriverInterface
    {
        $connection = Async\await($this->pool->getConnection());
        try {
            $this->logger?->info('Begin transaction');
            Async\await($connection->query('BEGIN'));
            if ($isolationLevel !== null) {
                $this->logger?->info("Transaction isolation level '{$isolationLevel}'");
                Async\await($connection->query('SET TRANSACTION ISOLATION LEVEL ' . $isolationLevel));
            }
        } catch (\Throwable $e) {
            $this->pool->releaseConnection($connection);
            throw $this->mapException($e, 'BEGIN TRANSACTION');
        }
        $txDriver = new AsyncTransactionDriver(
            $this->pool,
            $connection,
            $this->getTimezone(),
            $this->getSchemaHandler(),
            $this->getQueryCompiler(),
            $this->getQueryBuilder(),
            $this->isReadonly(),
            $this->getSource(),
            $this->config->options ?? [],
        );
        if ($this->logger !== null) {
            $txDriver->setLogger($this->logger);
        }
        return $txDriver;
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
        return is_object($parameter) ? (string) $parameter : $parameter;
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
            $this->config->options['withDatetimeMicroseconds'] ? self::DATETIME_MICROSECONDS : self::DATETIME,
        );
    }

    public function commitTransaction(): bool
    {
        throw new \BadMethodCallException('Call commitTransaction() on the transaction driver returned by beginTransaction().');
    }

    public function rollbackTransaction(): bool
    {
        throw new \BadMethodCallException('Call rollbackTransaction() on the transaction driver returned by beginTransaction().');
    }

    public function getTransactionLevel(): int
    {
        return 0;
    }

    // transaction is handled at Database level for async drivers

    public function queryStream(string $sql, array $params = [])
    {
        return $this->pool->queryStream($sql, $params);
    }

    public function keepAlive(int $seconds = 30): void
    {
        $this->pool->keepAlive($seconds);
    }

    public function close(): void
    {
        try {
            $this->pool->close();
        } catch (\Throwable $e) {
            $this->logger?->error($e->getMessage());
        }
    }

    public function quit(): void
    {
        try {
            Async\await($this->pool->quit());
        } catch (\Throwable $e) {
            $this->logger?->error($e->getMessage());
        }
    }

	/**
	 * Backwards-compatibility helpers mirroring Cycle's Driver magic calls.
	 */
	public function __call(string $name, array $arguments): mixed
	{
		return match ($name) {
			'isProfiling' => true,
			'setProfiling' => null,
			'getSchema' => $this->getSchemaHandler()->getSchema(
				$arguments[0],
				$arguments[1] ?? null,
			),
			'tableNames' => $this->getSchemaHandler()->getTableNames(),
			'hasTable' => $this->getSchemaHandler()->hasTable($arguments[0]),
			'identifier' => $this->getQueryCompiler()->quoteIdentifier($arguments[0]),
			'eraseData' => $this->getSchemaHandler()->eraseTable(
				$this->getSchemaHandler()->getSchema($arguments[0]),
			),
			'insertQuery',
			'selectQuery',
			'updateQuery',
			'deleteQuery' => \call_user_func_array(
				[$this->queryBuilder, $name],
				$arguments,
			),
			default => throw new \Cycle\Database\Exception\DriverException("Undefined driver method `{$name}`"),
		};
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

        // Try to determine row count from result object shape
        if (\is_object($result)) {
            if (isset($result->affectedRows)) {
                $context['rowCount'] = (int)$result->affectedRows;
            } elseif (isset($result->resultRows) && \is_array($result->resultRows)) {
                $context['rowCount'] = \count($result->resultRows);
            }
        }

        foreach ($parameters as $parameter) {
            $context['parameters'][] = Interpolator::resolveValue($parameter, $this->config->options ?? []);
        }

        return $context;
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

    private static function buildUriFromPdoConfig(PDOConnectionConfig $config): string
    {
        $dsn = $config->getDsn();
        // mysql:host=127.0.0.1;dbname=test;port=3306
        $dsnParts = substr($dsn, 0, 6) === 'mysql:' ? substr($dsn, 6) : $dsn;
        $pairs = [];
        foreach (explode(';', $dsnParts) as $chunk) {
            if ($chunk === '') { continue; }
            [$k, $v] = array_pad(explode('=', $chunk, 2), 2, null);
            if ($k !== null && $v !== null) { $pairs[trim($k)] = trim($v); }
        }
        $host = $pairs['host'] ?? '127.0.0.1';
        $port = isset($pairs['port']) ? (':' . $pairs['port']) : '';
        $db = $pairs['dbname'] ?? '';
        $user = $config->getUsername() ?? '';
        $pass = $config->getPassword() ?? '';
        $auth = $user !== '' ? ($user . ':' . $pass . '@') : '';
        $query = [];
        if (isset($pairs['charset']) && $pairs['charset'] !== '') {
            $query['charset'] = $pairs['charset'];
        }
        $qs = $query ? ('?' . http_build_query($query)) : '';
        return $auth . $host . $port . '/' . $db . $qs;
    }

}