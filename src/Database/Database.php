<?php
// core/Database/Database.php
namespace MikroApi\Database;

/**
 * Wrapper de PDO. Singleton simple para compartir la conexión.
 * Soporta SQLite, MySQL/MariaDB, PostgreSQL y Turso/libSQL.
 *
 * SQLite (config/database.php):
 *   return [
 *       'driver'   => 'sqlite',
 *       'database' => __DIR__ . '/../database/database.sqlite',
 *   ];
 *
 * MySQL (config/database.php):
 *   return [
 *       'driver'   => 'mysql',
 *       'host'     => 'localhost',
 *       'port'     => 3306,
 *       'database' => 'mi_db',
 *       'username' => 'root',
 *       'password' => 'secret',
 *       'charset'  => 'utf8mb4',
 *   ];
 *
 * PostgreSQL (config/database.php) — requiere la extensión pdo_pgsql.
 * El driver acepta 'pgsql', 'postgres' o 'postgresql':
 *   return [
 *       'driver'   => 'pgsql',
 *       'host'     => 'localhost',
 *       'port'     => 5432,
 *       'database' => 'mi_db',
 *       'username' => 'postgres',
 *       'password' => 'secret',
 *       'schema'   => 'public',   // opcional: search_path
 *       'sslmode'  => 'prefer',   // opcional: disable|allow|prefer|require|verify-ca|verify-full
 *   ];
 *
 * El framework escribe el SQL con identificadores entre backticks (`col`);
 * con PostgreSQL se traducen automáticamente a comillas dobles ("col").
 * Usa siempre los métodos de esta clase (query, queryOne, statement,
 * execute) en lugar de getPdo() para que la traducción se aplique.
 *
 * Turso/libSQL (config/database.php) — requiere `composer require turso/libsql`
 * (PHP >= 8.3 + extensión FFI con `ffi.enable=true`; ver MIGRATION_CLI.md):
 *   return [
 *       'driver'        => 'turso',
 *       'database'      => __DIR__ . '/../database/database.sqlite', // réplica local; null si es solo remoto
 *       'url'           => $_ENV['TURSO_DATABASE_URL'] ?? null,       // omitir para un archivo local puro
 *       'auth_token'    => $_ENV['TURSO_AUTH_TOKEN'] ?? null,         // omitir para un archivo local puro
 *       'sync_interval' => (int) ($_ENV['TURSO_SYNC_INTERVAL'] ?? 0), // segundos; 0 = sin sync periódico
 *   ];
 */
class Database
{
    private static ?self $instance = null;
    private \PDO $pdo;
    private string $driver;

    private function __construct(array $config)
    {
        $this->driver = self::normalizeDriver($config['driver'] ?? 'sqlite');
        $config['driver'] = $this->driver;

        if ($this->driver === 'turso') {
            if (!\class_exists(\Libsql\PDO::class)) {
                throw new \RuntimeException(
                    "El driver 'turso' requiere el paquete turso/libsql. Instálalo con: composer require turso/libsql"
                );
            }

            $path = $config['database'] ?? null;
            if ($path !== null && $path !== ':memory:') {
                $dir = \dirname($path);
                if (!\is_dir($dir)) {
                    \mkdir($dir, 0755, true);
                }
            }

            $this->pdo = new \Libsql\PDO(
                dsn: $path,
                username: null,
                password: $config['auth_token'] ?? null,
                options: [
                    'url'          => $config['url'] ?? null,
                    'syncInterval' => $config['sync_interval'] ?? 0,
                ],
            );

            return;
        }

        $dsn      = $this->buildDsn($config);
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $this->pdo = new \PDO($dsn, $username, $password, $options);

        // Pragmas recomendados para SQLite
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA journal_mode = WAL;');   // mejor concurrencia
            $this->pdo->exec('PRAGMA foreign_keys = ON;');    // activar FKs
            $this->pdo->exec('PRAGMA synchronous = NORMAL;'); // balance velocidad/seguridad
        }

        if ($this->driver === 'pgsql') {
            $this->pdo->exec("SET client_encoding TO 'UTF8'");
            if (!empty($config['schema'])) {
                $this->pdo->exec('SET search_path TO ' . self::toDialect('`' . \str_replace('`', '', $config['schema']) . '`', 'pgsql'));
            }
        }
    }

    /** 'postgres' / 'postgresql' → 'pgsql' (nombre del driver PDO). */
    public static function normalizeDriver(string $driver): string
    {
        $driver = \strtolower($driver);
        return \in_array($driver, ['postgres', 'postgresql', 'pg'], true) ? 'pgsql' : $driver;
    }

    /**
     * Adapta SQL escrito con backticks al dialecto del driver. Para
     * PostgreSQL reemplaza `ident` por "ident", sin tocar el contenido de
     * literales entre comillas simples. Para el resto lo deja igual.
     */
    public static function toDialect(string $sql, string $driver): string
    {
        if ($driver !== 'pgsql' || !\str_contains($sql, '`')) {
            return $sql;
        }

        $out      = '';
        $inString = false;
        $len      = \strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === "'") {
                // '' dentro de un literal es una comilla escapada, no un cierre
                if ($inString && ($sql[$i + 1] ?? '') === "'") {
                    $out .= "''";
                    $i++;
                    continue;
                }
                $inString = !$inString;
            } elseif ($ch === '`' && !$inString) {
                $ch = '"';
            }
            $out .= $ch;
        }

        return $out;
    }

    public static function connect(array $config): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Base de datos no conectada. Llama a Database::connect() primero.');
        }
        return self::$instance;
    }

    /**
     * Resetea la instancia singleton. Útil para tests.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function getDriver(): string
    {
        // libSQL habla el dialecto SQL de SQLite; MigrationRunner y
        // SchemaBuilder deciden el dialecto comparando este string contra
        // 'sqlite'/'mysql' en ~20 sitios, así que se traduce una sola vez
        // aquí en vez de tocar cada call site. El campo interno $driver
        // conserva 'turso' (lo usa el constructor para elegir Libsql\PDO).
        return $this->driver === 'turso' ? 'sqlite' : $this->driver;
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    public function execute(string $sql): void
    {
        $this->pdo->exec(self::toDialect($sql, $this->driver));
    }

    /**
     * Prepara y ejecuta una sentencia con parámetros y retorna el statement
     * (para rowCount(), fetch(), etc.). Aplica la traducción de dialecto.
     */
    public function statement(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare(self::toDialect($sql, $this->driver));
        $stmt->execute($this->normalizeParams($params));
        return $stmt;
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->statement($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $row = $this->statement($sql, $params)->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function lastInsertId(?string $name = null): string
    {
        return $this->pdo->lastInsertId($name);
    }

    /**
     * PDO envía false como '' (inválido para BOOLEAN/INTEGER en PostgreSQL);
     * se normalizan los booleanos a 1/0, que PostgreSQL acepta en ambos tipos.
     */
    private function normalizeParams(array $params): array
    {
        if ($this->driver !== 'pgsql') {
            return $params;
        }
        return \array_map(fn($v) => \is_bool($v) ? (int) $v : $v, $params);
    }

    /**
     * Executes a callback inside a database transaction.
     * Rolls back on exception and re-throws.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ */

    private function buildDsn(array $config): string
    {
        return match ($config['driver'] ?? 'sqlite') {
            'sqlite' => $this->buildSqliteDsn($config),
            'mysql'  => $this->buildMysqlDsn($config),
            'pgsql'  => $this->buildPgsqlDsn($config),
            default  => throw new \RuntimeException("Driver '{$config['driver']}' no soportado."),
        };
    }

    private function buildSqliteDsn(array $config): string
    {
        $path = $config['database'] ?? ':memory:';

        // Crear el directorio si no existe
        if ($path !== ':memory:') {
            $dir = \dirname($path);
            if (!\is_dir($dir)) {
                \mkdir($dir, 0755, true);
            }
        }

        return "sqlite:{$path}";
    }

    private function buildMysqlDsn(array $config): string
    {
        $host    = $config['host']    ?? 'localhost';
        $port    = $config['port']    ?? 3306;
        $dbname  = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';
        return "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    }

    private function buildPgsqlDsn(array $config): string
    {
        $host   = $config['host']     ?? 'localhost';
        $port   = $config['port']     ?? 5432;
        $dbname = $config['database'] ?? '';
        $dsn    = "pgsql:host={$host};port={$port};dbname={$dbname}";
        if (!empty($config['sslmode'])) {
            $dsn .= ";sslmode={$config['sslmode']}";
        }
        return $dsn;
    }
}
