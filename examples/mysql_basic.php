<?php

require __DIR__ . '/../vendor/autoload.php';

use Cycle\Database\Config as Config;
use ReactphpX\CycleDatabase\AsyncDatabaseManager;
use ReactphpX\CycleDatabase\AsyncMysqlDriver;
use ReactphpX\CycleDatabase\AsyncMySQLDriverConfig;
use ReactphpX\CycleDatabase\AsyncTcpConnectionConfig;

// Configure connections per Cycle docs, but use AsyncDatabaseManager and AsyncMysqlDriver
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
                // Optional pool options
                'minConnections' => (int)(getenv('DB_POOL_MIN') ?: 1),
                'maxConnections' => (int)(getenv('DB_POOL_MAX') ?: 10),
                'waitQueue' => (int)(getenv('DB_POOL_QUEUE') ?: 100),
                'waitTimeout' => (int)(getenv('DB_POOL_TIMEOUT') ?: 0),
            ],
        ),
    ],
]));

$db = $dbal->database('default');

// Simple SELECT (await-style under the hood)
$stmt = $db->query('SELECT 1 AS one');
var_dump($stmt->fetchAll());

// INSERT and get lastInsertId
$db->execute('CREATE TABLE IF NOT EXISTS demo_basic (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255))');
$affected = $db->execute('INSERT INTO demo_basic (title) VALUES (?)', ['hello']);
echo "Inserted rows: {$affected}\n";
echo 'Last ID: ' . $db->getDriver()->lastInsertID() . "\n";

// Cleanup (optional)
// $db->execute('DROP TABLE demo_basic');

var_dump($db->select()->from('demo_basic')->count());


