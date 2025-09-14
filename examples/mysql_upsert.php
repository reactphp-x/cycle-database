<?php

require __DIR__ . '/../vendor/autoload.php';

use Cycle\Database\Config as Config;
use ReactphpX\CycleDatabase\AsyncDatabaseManager;
use ReactphpX\CycleDatabase\AsyncMysqlDriver;
use ReactphpX\CycleDatabase\AsyncMySQLDriverConfig;
use ReactphpX\CycleDatabase\AsyncTcpConnectionConfig;

// DB config (same style as other examples)
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

// Prepare table
$db->execute('DROP TABLE IF EXISTS users_upsert');
$db->execute('CREATE TABLE users_upsert (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL UNIQUE,
    name VARCHAR(191) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

// Upsert single row
$db->upsert('users_upsert')
    ->columns('email', 'name')
    ->values('adam@email.com', 'Adam')
    ->run();

// Upsert multiple rows (second call updates existing row)
$db->upsert('users_upsert')
    ->columns('email', 'name')
    ->values(['email' => 'adam@email.com', 'name' => 'Adam Updated'])
    ->values(['email' => 'bill@email.com', 'name' => 'Bill'])
    ->run();

$db->upsert('users_upsert')
    ->columns('email', 'name')
    ->values([
        ['email' => 'adam@email.com', 'name' => 'Charlie2'],
        ['email' => 'dave@email.com', 'name' => 'Dave2'],
    ])
    ->run();

// Verify
$rows = $db->query('SELECT email, name FROM users_upsert ORDER BY email')->fetchAll();
var_dump($rows);

// Reference: Upsert semantics follow Cycle Database PR #231
// https://github.com/cycle/database/pull/231


