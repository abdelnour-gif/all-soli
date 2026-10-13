<?php
namespace QueuePro\Database;

defined('QP_APP') || exit;

/**
 * PDO database layer. SQLite by default; MySQL / MariaDB supported by config.
 *
 * Placeholders: %d (int), %f (float), %s (string), %i (identifier, inlined + quoted).
 * Values are always bound as real prepared-statement parameters (never interpolated).
 * Any failing statement throws DbException with a normalised error code.
 *
 * Concurrency model
 *  - SQLite: WAL mode (readers never block), write transactions start with
 *    BEGIN IMMEDIATE (one writer at a time → bookings are serialised, no races),
 *    busy_timeout makes concurrent writers wait instead of failing.
 *  - MySQL: InnoDB transactions + SELECT … FOR UPDATE row locks per provider.
 */
final class Connection
{
    public const ERR_DUPLICATE = 1062;
    public const ERR_DEADLOCK  = 1213;
    public const ERR_LOCK_WAIT = 1205;

    private \PDO $pdo;
    private string $driver;
    private int $txDepth = 0;

    public function __construct(array $cfg)
    {
        $this->driver = ($cfg['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
        $opts = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => true,
        ];
        try {
            if ($this->driver === 'sqlite') {
                $path = (string) $cfg['path'];
                $opts[\PDO::ATTR_TIMEOUT] = 15;
                $this->pdo = new \PDO('sqlite:' . $path, null, null, $opts);
                $this->pdo->exec('PRAGMA busy_timeout = 15000');
                $this->pdo->exec('PRAGMA foreign_keys = ON');
                // WAL lets visitors read while a booking is being written. Some network
                // filesystems refuse WAL; the database then keeps working in rollback mode.
                try {
                    $this->pdo->exec('PRAGMA journal_mode = WAL');
                    $this->pdo->exec('PRAGMA synchronous = NORMAL');
                } catch (\PDOException $e) {
                }
            } else {
                $dsn = !empty($cfg['socket'])
                    ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $cfg['socket'], $cfg['name'] ?? '')
                    : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'] ?? '127.0.0.1', (int) ($cfg['port'] ?? 3306), $cfg['name'] ?? '');
                $this->pdo = new \PDO($dsn, $cfg['user'] ?? '', $cfg['pass'] ?? '', $opts);
                $this->pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
            }
        } catch (\PDOException $e) {
            throw new DbException('Database connection failed: ' . $e->getMessage(), 0);
        }
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function table(string $name): string
    {
        return 'qp_' . $name;
    }

    // ------------------------------------------------------------------ dialect helpers

    /** Row lock clause (MySQL). SQLite already holds the write lock (BEGIN IMMEDIATE). */
    public function forUpdate(): string
    {
        return $this->isSqlite() ? '' : ' FOR UPDATE';
    }

    public function insertIgnore(): string
    {
        return $this->isSqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    }

    /**
     * "INSERT … ; on conflict update" tail.
     * $set uses {new:col} for the incoming value and plain column names for the stored one.
     */
    public function onConflict(array $keys, string $set): string
    {
        if ($this->isSqlite()) {
            $set = preg_replace('/\{new:(\w+)\}/', 'excluded.$1', $set);
            return ' ON CONFLICT(' . implode(',', $keys) . ') DO UPDATE SET ' . $set;
        }
        $set = preg_replace('/\{new:(\w+)\}/', 'VALUES($1)', $set);
        return ' ON DUPLICATE KEY UPDATE ' . $set;
    }

    /** SQL expression: whole seconds between two DATETIME expressions. */
    public function secondsBetween(string $from, string $to): string
    {
        return $this->isSqlite()
            ? "CAST(ROUND((julianday($to) - julianday($from)) * 86400) AS INTEGER)"
            : "TIMESTAMPDIFF(SECOND, $from, $to)";
    }

    // ------------------------------------------------------------------ queries

    /** @return array{0:string,1:array<int,array{0:mixed,1:int}>} */
    private function compile(string $sql, array $params): array
    {
        $binds = [];
        $i = 0;
        $out = preg_replace_callback('/%([dfsi%])/', function ($m) use (&$i, &$binds, $params) {
            if ($m[1] === '%') {
                return '%';
            }
            if (!array_key_exists($i, $params)) {
                throw new DbException('Missing SQL parameter #' . $i, 0);
            }
            $v = $params[$i++];
            switch ($m[1]) {
                case 'i':
                    $q = $this->isSqlite() ? '"' : '`';
                    return $q . str_replace([$q, '"', '`'], '', (string) $v) . $q;
                case 'd':
                    $binds[] = [(int) $v, \PDO::PARAM_INT];
                    return '?';
                case 'f':
                    $binds[] = [(string) (float) $v, \PDO::PARAM_STR];
                    return '?';
                default:
                    $binds[] = [$v === null ? null : (string) $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR];
                    return '?';
            }
        }, $sql);
        if ($i !== count($params)) {
            throw new DbException('Too many SQL parameters', 0);
        }
        return [$out, $binds];
    }

    private function run(string $sql, array $binds): \PDOStatement
    {
        try {
            $st = $this->pdo->prepare($sql);
            foreach ($binds as $n => [$v, $type]) {
                $st->bindValue($n + 1, $v, $type);
            }
            $st->execute();
            return $st;
        } catch (\PDOException $e) {
            throw $this->wrap($e);
        }
    }

    private function wrap(\PDOException $e): DbException
    {
        $info = $e->errorInfo ?? [];
        $code = (int) ($info[1] ?? 0);
        $msg = $e->getMessage();
        if ($this->isSqlite()) {
            if ($code === 19 && stripos($msg, 'UNIQUE constraint failed') !== false) {
                $code = self::ERR_DUPLICATE;
            } elseif ($code === 5 || $code === 6) { // SQLITE_BUSY / SQLITE_LOCKED
                $code = self::ERR_LOCK_WAIT;
            }
        }
        return new DbException($msg, $code);
    }

    public function query(string $sql, array $params = []): int
    {
        [$s, $b] = $this->compile($sql, $params);
        return $this->run($s, $b)->rowCount();
    }

    /** @return array<int, array<string, mixed>> */
    public function getResults(string $sql, array $params = []): array
    {
        [$s, $b] = $this->compile($sql, $params);
        return $this->run($s, $b)->fetchAll();
    }

    public function getRow(string $sql, array $params = []): ?array
    {
        [$s, $b] = $this->compile($sql, $params);
        $row = $this->run($s, $b)->fetch();
        return $row === false ? null : $row;
    }

    /** @return mixed|null */
    public function getVar(string $sql, array $params = [])
    {
        $row = $this->getRow($sql, $params);
        return $row ? reset($row) : null;
    }

    public function exec(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            throw $this->wrap($e);
        }
    }

    /** Insert a row; null values become SQL NULL. Returns the new id. */
    public function insert(string $table, array $data): int
    {
        $cols = [];
        $marks = [];
        $binds = [];
        foreach ($data as $col => $val) {
            $cols[] = $this->quoteIdent($col);
            $marks[] = '?';
            $binds[] = $this->bindFor($val);
        }
        $this->run('INSERT INTO ' . $this->quoteIdent($table) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $marks) . ')', $binds);
        return $this->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $sets = [];
        $conds = [];
        $binds = [];
        foreach ($data as $col => $val) {
            $sets[] = $this->quoteIdent($col) . ' = ?';
            $binds[] = $this->bindFor($val);
        }
        foreach ($where as $col => $val) {
            $conds[] = $this->quoteIdent($col) . ' = ?';
            $binds[] = $this->bindFor($val);
        }
        return $this->run('UPDATE ' . $this->quoteIdent($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $conds), $binds)->rowCount();
    }

    private function bindFor($val): array
    {
        if ($val === null) {
            return [null, \PDO::PARAM_NULL];
        }
        if (is_bool($val)) {
            return [$val ? 1 : 0, \PDO::PARAM_INT];
        }
        if (is_int($val)) {
            return [$val, \PDO::PARAM_INT];
        }
        return [(string) $val, \PDO::PARAM_STR];
    }

    private function quoteIdent(string $name): string
    {
        $q = $this->isSqlite() ? '"' : '`';
        return $q . str_replace(['"', '`'], '', $name) . $q;
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------ transactions

    /**
     * Run $fn inside a write transaction; retried on lock timeouts / deadlocks.
     * Nested calls join the outer transaction.
     */
    public function transaction(callable $fn, int $retries = 3)
    {
        if ($this->txDepth > 0) {
            return $fn();
        }
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $this->exec($this->isSqlite() ? 'BEGIN IMMEDIATE' : 'START TRANSACTION');
            } catch (DbException $e) {
                if (in_array($e->errno(), [self::ERR_LOCK_WAIT, self::ERR_DEADLOCK], true) && $attempt <= $retries) {
                    usleep(random_int(20000, 80000) * $attempt);
                    continue;
                }
                throw $e;
            }
            $this->txDepth = 1;
            try {
                $result = $fn();
                $this->exec('COMMIT');
                $this->txDepth = 0;
                return $result;
            } catch (\Throwable $e) {
                $this->txDepth = 0;
                try {
                    $this->exec('ROLLBACK');
                } catch (\Throwable $ignored) {
                }
                $retryable = $e instanceof DbException && in_array($e->errno(), [self::ERR_DEADLOCK, self::ERR_LOCK_WAIT], true);
                if ($retryable && $attempt <= $retries) {
                    usleep(random_int(20000, 80000) * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    public function inTransaction(): bool
    {
        return $this->txDepth > 0;
    }

    public function version(): string
    {
        return (string) $this->pdo->query($this->isSqlite() ? 'SELECT sqlite_version()' : 'SELECT VERSION()')->fetchColumn();
    }
}
