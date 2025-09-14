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
use Cycle\Database\StatementInterface;
use React\Async;
use ReactphpX\MySQL\Pool;

class AsyncMysqlDriver implements DriverInterface
{
    private Pool $pool;
    private $lastInsertId = null;

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
        $queryCompiler = $compiler instanceof CachingCompilerInterface ? new CompilerCache($compiler) : $compiler;
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
        // Pool is lazy - no explicit action needed
    }

    public function isConnected(): bool
    {
        // No direct knowledge; consider true when pool exists
        return $this->pool !== null;
    }

    public function disconnect(): void
    {
        $this->pool->close();
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
        try {
            $parameters = array_map(function ($parameter) {
                if ($parameter instanceof \Cycle\Database\Injection\Parameter) {
                    return $parameter->getValue();
                }
                return $parameter;
            }, $parameters);
            $result = Async\await($this->pool->query($statement, $parameters));
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
            var_dump($query, $parameters);
            $result = Async\await($this->pool->query($query, $parameters));
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

    public function beginTransaction(?string $isolationLevel = null): bool
    {
        throw new \BadMethodCallException('beginTransaction is not supported. Use transaction(callable).');
    }

    public function commitTransaction(): bool
    {
        throw new \BadMethodCallException('commitTransaction is not supported. Use transaction(callable).');
    }

    public function rollbackTransaction(): bool
    {
        throw new \BadMethodCallException('rollbackTransaction is not supported. Use transaction(callable).');
    }

    public function getTransactionLevel(): int
    {
        return 0;
    }

    public function transaction(callable $callable)
    {
        return Async\await($this->pool->transaction($callable));
    }

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
        $this->pool->close();
    }

    public function quit(): void
    {
        Async\await($this->pool->quit());
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

    private function formatDatetime(\DateTimeInterface $value): string
    {
        $datetime = $value instanceof \DateTimeImmutable
            ? $value->setTimezone($this->timezone)
            : (\DateTimeImmutable::createFromMutable($value))->setTimezone($this->timezone);
        return $datetime->format('Y-m-d H:i:s');
    }
}