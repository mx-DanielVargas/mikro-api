<?php
// core/Database/Database.php
namespace MikroApi\Database;

/**
 * Wrapper de PDO. Singleton simple para compartir la conexión.
 * Soporta SQLite y MySQL/MariaDB.
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
 */
class Database
{
    private static ?self $instance = null;
    private \PDO $pdo;
    private string $driver;

    private function __construct(array $config)
    {
        $this->driver = $config['driver'] ?? 'sqlite';

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

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    public function execute(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /* ------------------------------------------------------------------ */

    private function buildDsn(array $config): string
    {
        return match ($config['driver'] ?? 'sqlite') {
            'sqlite' => $this->buildSqliteDsn($config),
            'mysql'  => $this->buildMysqlDsn($config),
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
}
