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
use Cycle\Database\Config\DriverConfig;
use ReactphpX\CycleOrm\AsyncMysqlDriver;


/**
 * @template-extends DriverConfig<ConnectionConfig>
 */
class AsyncMySQLDriverConfig extends DriverConfig
{
    public function __construct(
        ConnectionConfig $connection,
        string $driver = AsyncMySQLDriver::class,
        bool $reconnect = true,
        string $timezone = 'Asia/Shanghai',
        bool $queryCache = true,
        bool $readonlySchema = false,
        bool $readonly = false,
        array $options = [],
    ) {
        /** @psalm-suppress ArgumentTypeCoercion */
        parent::__construct(
            connection: $connection,
            driver: $driver,
            reconnect: $reconnect,
            timezone: $timezone,
            queryCache: $queryCache,
            readonlySchema: $readonlySchema,
            readonly: $readonly,
            options: $options,
        );
    }
}
