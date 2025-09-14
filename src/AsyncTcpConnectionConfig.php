<?php

/**
 * This file is part of Cycle Database package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ReactphpX\CycleOrm;

use Cycle\Database\Config\MySQL\ConnectionConfig;
use Cycle\Database\Config\ProvidesSourceString;

class AsyncTcpConnectionConfig extends ConnectionConfig implements ProvidesSourceString
{

    protected const DEFAULT_PDO_OPTIONS = [
        // \PDO::ATTR_CASE               => \PDO::CASE_NATURAL,
        // \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        // // TODO Should be moved into common driver settings.
        // \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES "UTF8"',
        // \PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];
    
    /**
     * @var positive-int
     */
    public int $port;

    /**
     * @param non-empty-string $host
     * @param numeric-string|positive-int $port
     * @param non-empty-string $database
     * @param non-empty-string|null $charset
     * @param non-empty-string|null $user
     * @param non-empty-string|null $password
     * @param array<int, non-empty-string|non-empty-string> $options
     */
    public function __construct(
        public string $database,
        public string $host = 'localhost',
        int|string $port = 3307,
        public ?string $charset = null,
        ?string $user = null,
        ?string $password = null,
        array $options = [],
    ) {
        $this->port = (int) $port;

        parent::__construct($user, $password, $options);
    }

    public function getSourceString(): string
    {
        return $this->database;
    }

    /**
     * Returns the MySQL-specific PDO DataSourceName with connection URI,
     * that looks like:
     * <code>
     *  mysql:host=localhost;port=3307;dbname=dbname
     * </code>
     *
     * {@inheritDoc}
     */
    public function getDsn(): string
    {
        $config = [
            'host' => $this->host,
            'port' => $this->port,
            'dbname' => $this->database,
            'charset' => $this->charset,
        ];

        return \sprintf('%s:%s', $this->getName(), $this->dsn($config));
    }
}
