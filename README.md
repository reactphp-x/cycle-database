## reactphp-x/cycle-database

使用 ReactPHP 的 MySQL 连接池为 Cycle Database 提供异步驱动，在非阻塞环境下保持 Cycle 风格的同步 API（`$db->query()` / `$db->execute()` / 查询构建器等）。

基于组件：
- 驱动与管理：`ReactphpX\CycleDatabase\AsyncDatabaseManager`、`AsyncMysqlDriver`、`AsyncMySQLDriverConfig`、`AsyncTcpConnectionConfig`
- 查询/语句：`ReactphpX\CycleDatabase\AsyncDatabase`、`AsyncStatement`
- 依赖：`reactphp-x/mysql-pool`、`react/async`、`wpjscc/database`（Cycle Database 兼容实现）

### 特性
- **异步驱动**：内部使用连接池与 `React\Async\await`，对外暴露同步风格接口
- **兼容 Cycle Database API**：支持 `select/insert/update/delete/upsert` 构建器与 `DatabaseInterface`
- **事务（回调）**：通过驱动的 `transaction(callable)` 以回调方式执行
- **流式查询**：驱动层提供 `queryStream()`，适合大结果集
- **连接池**：可配置最小/最大连接数、等待队列与超时

> 仅支持 MySQL（`AsyncMysqlDriver`）。

---

### 安装

```bash
composer require reactphp-x/cycle-database
```

要求：PHP 8.1+（代码使用 `BackedEnum` 等特性），MySQL 5.7+/8.0+。

---

### 快速开始（Basic）

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Cycle\Database\Config as Config;
use ReactphpX\CycleDatabase\{AsyncDatabaseManager, AsyncMySQLDriverConfig, AsyncTcpConnectionConfig};

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

// 简单查询
$stmt = $db->query('SELECT 1 AS one');
var_dump($stmt->fetchAll());

// DDL / DML
$db->execute('CREATE TABLE IF NOT EXISTS demo_basic (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255))');
$affected = $db->execute('INSERT INTO demo_basic (title) VALUES (?)', ['hello']);
echo "Inserted rows: {$affected}\n";
echo 'Last ID: ' . $db->getDriver()->lastInsertID() . "\n";
```

更多可运行示例见 `examples/`：
- `examples/mysql_basic.php`
- `examples/mysql_queries.php`
- `examples/mysql_transactions.php`
- `examples/mysql_upsert.php`

---

### 运行示例

```bash
composer install

export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME=test
export DB_USER=root
export DB_PASSWORD=123456
export DB_CHARSET=utf8mb4

php examples/mysql_basic.php
php examples/mysql_queries.php
php examples/mysql_transactions.php
php examples/mysql_upsert.php
```

环境变量（可选，均有默认值）：

| 变量 | 默认值 | 说明 |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | MySQL 地址 |
| `DB_PORT` | `3306` | MySQL 端口 |
| `DB_NAME` | `test` | 数据库名 |
| `DB_USER` | `root` | 用户名 |
| `DB_PASSWORD` | `123456` | 密码 |
| `DB_CHARSET` | `utf8mb4` | 字符集 |
| `DB_POOL_MIN` | `1` | 连接池最小连接数 |
| `DB_POOL_MAX` | `10` | 连接池最大连接数 |
| `DB_POOL_QUEUE` | `100` | 等待队列容量 |
| `DB_POOL_TIMEOUT` | `0` | 获取连接超时（毫秒，0 表示不超时） |

---

### 使用方式

#### 同步风格查询

```php
// 原生 SQL 查询
$stmt = $db->query('SELECT * FROM users WHERE id > ?', [100]);
foreach ($stmt as $row) {
    echo json_encode($row) . "\n";
}

// 执行写操作
$affected = $db->execute('UPDATE users SET name=? WHERE id=?', ['new', 123]);

// 读取结果
$oneRow = $db->query('SELECT id, name FROM users LIMIT 1')->fetch();
$rows   = $db->query('SELECT id FROM users')->fetchAll();
$firstId = $db->query('SELECT id FROM users')->fetchColumn();
```

#### 查询构建器（兼容 Cycle Database）

```php
// SELECT
$rows = $db->select('*')
    ->from('users')
    ->where(['status' => 'active'])
    ->orderBy('id', 'DESC')
    ->limit(10)
    ->fetchAll();

// INSERT
$db->insert('users')->values(['email' => 'a@b.com', 'name' => 'Adam'])->run();

// UPDATE
$db->update('users', ['name' => 'Updated'], ['id' => 1])->run();

// DELETE
$db->delete('users', ['id' => 100])->run();

// UPSERT（见 examples/mysql_upsert.php）
$db->upsert('users')
    ->columns('email', 'name')
    ->values(['email' => 'a@b.com', 'name' => 'Adam'])
    ->run();
```

#### 事务（使用驱动回调）

> 注意：不支持 `begin/commit/rollback` 三个方法；请使用驱动的 `transaction(callable)`。

```php
use ReactphpX\CycleDatabase\AsyncMysqlDriver;
use function React\Async\await;

/** @var AsyncMysqlDriver $driver */
$driver = $db->getDriver();

$result = $driver->transaction(function ($conn) {
    await($conn->query('INSERT INTO logs (val) VALUES (?)', [100]));
    await($conn->query('INSERT INTO logs (val) VALUES (?)', [200]));
    return 'ok';
});
```

#### 流式查询（大结果集）

```php
/** @var AsyncMysqlDriver $driver */
$driver = $db->getDriver();
$stream = $driver->queryStream('SELECT * FROM big_table');
// 结合 reactphp-x/mysql-pool 的流式 API 消费数据
```

---

### UPSERT 用法（updates）

- **基本语义**：在 MySQL 上使用 `ON DUPLICATE KEY UPDATE`。当插入触发唯一键/主键冲突时转为更新。
- **默认更新列**：未调用 `updates()` 时，默认对本次插入的所有列执行 `col = VALUES(col)`。
- **指定更新列**：调用 `updates('colA', 'colB')` 仅更新给定列，其它插入列保持不变。
- **冲突列**：当前 MySQL 编译器无需 `conflicts()`，以表上定义的唯一键/主键为准。

示例（更多见 `examples/mysql_upsert.php`）：

```php
// 建表（email 唯一）
$db->execute('DROP TABLE IF EXISTS users_upsert');
$db->execute('CREATE TABLE users_upsert (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL UNIQUE,
    age INT NOT NULL DEFAULT 0,
    name VARCHAR(191) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

// 插入一行
$db->upsert('users_upsert')
    ->columns('email', 'name')
    ->values('adam@email.com', 'Adam')
    ->run();

// 多行，第二行会更新已存在的 email
$db->upsert('users_upsert')
    ->columns('email', 'name')
    ->values(['email' => 'adam@email.com', 'name' => 'Adam Updated'])
    ->values(['email' => 'bill@email.com', 'name' => 'Bill'])
    ->run();

// 仅更新指定列（age、name）。未在 updates() 中的列不被更新
$db->upsert('users_upsert')
    ->columns('email', 'name', 'age')
    ->updates('age', 'name')
    ->values([
        ['email' => 'adam@email.com', 'name' => 'Charlie2', 'age' => 10],
        ['email' => 'dave@email.com', 'name' => 'Dave10', 'age' => 40],
    ])
    ->run();
```

> 实现参考：`ReactphpX\CycleDatabase\MySQLCompiler::upsertQuery()` 会将未显式指定的更新列默认回落为 `columns()` 中的全部列。

---

### 连接池与可选项

在 `AsyncMySQLDriverConfig` 的 `options` 中传入：

- `minConnections`：最小连接数（默认 2）
- `maxConnections`：最大连接数（默认 10）
- `waitQueue`：等待队列容量（默认 100）
- `waitTimeout`：获取连接超时毫秒（默认 0，表示不超时）

驱动还提供：
- `keepAlive(int $seconds = 30)`：心跳保活
- `close()` / `quit()`：关闭或优雅退出

---

### 与 Cycle Database 的差异

- 使用异步驱动实现，同步外观；与官方 `PDO` 驱动不同
- 不支持 `begin/commit/rollback` 三个方法；请改用 `transaction(callable)`
- 仅 MySQL 实现（`AsyncMysqlDriver`）

API 命名与行为尽量保持与 Cycle Database 一致，迁移成本低。

---

### License

MIT


