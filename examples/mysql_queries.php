<?php

require __DIR__ . '/../vendor/autoload.php';

use Cycle\Database\Config as Config;
use ReactphpX\CycleOrm\AsyncDatabaseManager;
use ReactphpX\CycleOrm\AsyncMysqlDriver;
use ReactphpX\CycleOrm\AsyncMySQLDriverConfig;
use ReactphpX\CycleOrm\AsyncTcpConnectionConfig;

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

$db->execute('CREATE TABLE IF NOT EXISTS demo_q (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255))');

// Insert many rows
for ($i = 0; $i < 3; $i++) {
    $db->execute('INSERT INTO demo_q (title) VALUES (?)', ['title-' . $i]);
}

// Select
$stmt = $db->query('SELECT * FROM demo_q ORDER BY id DESC LIMIT 5');
foreach ($stmt as $row) {
    echo json_encode($row) . "\n";
}

// Update
$affected = $db->execute('UPDATE demo_q SET title=? WHERE id=?', ['updated', 1]);
echo "Updated rows: {$affected}\n";

// Delete
$affected = $db->execute('DELETE FROM demo_q WHERE id>?', [2]);
echo "Deleted rows: {$affected}\n";


