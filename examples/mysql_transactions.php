<?php

require __DIR__ . '/../vendor/autoload.php';

use Cycle\Database\Config as Config;
use ReactphpX\CycleOrm\AsyncDatabaseManager;
use ReactphpX\CycleOrm\AsyncMysqlDriver;
use ReactphpX\CycleOrm\AsyncMySQLDriverConfig;
use ReactphpX\CycleOrm\AsyncTcpConnectionConfig;
use function React\Async\await;

$dbal = new AsyncDatabaseManager(new Config\DatabaseConfig([
    'default' => 'default',
    'databases' => [
        'default' => [
            'driver' => 'mysql',
            'prefix' => ''
        ],
    ],
    'connections' => [
        'mysql' => new AsyncMySQLDriverConfig(
            connection: new AsyncTcpConnectionConfig(
                database: getenv('DB_NAME') ?: 'test',
                host: getenv('DB_HOST') ?: '127.0.0.1',
                port: (int)(getenv('DB_PORT') ?: 3306),
                charset: getenv('DB_CHARSET') ?: 'utf8mb4',
                user: getenv('DB_USER') ?: 'root',
                password: getenv('DB_PASSWORD') ?: '123456'
            ),
            options: [
                'minConnections' => (int)(getenv('DB_POOL_MIN') ?: 1),
                'maxConnections' => (int)(getenv('DB_POOL_MAX') ?: 10),
                'waitQueue' => (int)(getenv('DB_POOL_QUEUE') ?: 100),
                'waitTimeout' => (int)(getenv('DB_POOL_TIMEOUT') ?: 0),
            ],
        ),
    ],
]));

$db = $dbal->database('default');

$db->execute('CREATE TABLE IF NOT EXISTS demo_tx (id INT AUTO_INCREMENT PRIMARY KEY, val INT)');

// Note: AsyncMysqlDriver recommends using transaction(callable)
/** @var AsyncMysqlDriver $driver */
$driver = $db->getDriver();

$result = $driver->transaction(function ($conn) {
    await($conn->query('INSERT INTO demo_tx (val) VALUES (?)', [100]));
    await($conn->query('INSERT INTO demo_tx (val) VALUES (?)', [200]));
    return 'ok';
});

echo "Transaction result: {$result}\n";

$stmt = $db->query('SELECT COUNT(*) AS c FROM demo_tx');
var_dump($stmt->fetchAll());


