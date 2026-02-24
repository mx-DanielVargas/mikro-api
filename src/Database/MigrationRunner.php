<?php
// core/Database/MigrationRunner.php
namespace MikroApi\Database;

/**
 * Ejecuta y revierte migraciones, llevando registro en la tabla `migrations`.
 */
class MigrationRunner
{
    private Database $db;
    private string   $migrationsPath;
    private string   $migrationsNamespace;

    public function __construct(
        Database $db,
        string $migrationsPath      = __DIR__ . '/../../database/migrations',
        string $migrationsNamespace = 'Database\\Migrations',
    ) {
        $this->db                  = $db;
        $this->migrationsPath      = \realpath($migrationsPath) ?: $migrationsPath;
        $this->migrationsNamespace = $migrationsNamespace;
        $this->ensureMigrationsTable();
    }

    /* ------------------------------------------------------------------ */
    /*  Comandos principales                                                */
    /* ------------------------------------------------------------------ */

    /** Ejecuta todas las migraciones pendientes */
    public function migrate(): void
    {
        $pending = $this->getPending();

        if (empty($pending)) {
            $this->log('Nada que migrar. Todo está al día.');
            return;
        }

        foreach ($pending as $file => $class) {
            $this->log("Migrando: {$file}");
            $migration = new $class($this->db->getDriver(), $this->db->getPdo());
            $sql       = $migration->up();

            if ($sql && \trim($sql) !== '' && \trim($sql) !== '-- No hay cambios que aplicar') {
                $this->db->execute($sql);
            }
            $this->markAsMigrated($file);
            $this->log("  ✓ {$file}");
        }

        $this->log(\count($pending) . ' migración(es) ejecutada(s).');
    }

    /** Revierte la última migración ejecutada */
    public function rollback(): void
    {
        $last = $this->getLastMigrated();

        if (!$last) {
            $this->log('No hay migraciones para revertir.');
            return;
        }

        $class = $this->migrationsNamespace . '\\' . $this->fileToClass($last);

        if (!\class_exists($class)) {
            $this->requireFile($last);
        }

        $this->log("Revirtiendo: {$last}");
        $migration = new $class($this->db->getDriver(), $this->db->getPdo());
        $sql       = $migration->down();

        $this->db->execute($sql);
        $this->markAsRolledBack($last);
        $this->log("  ✓ {$last} revertida.");
    }

    /** Revierte todas las migraciones ejecutadas */
    public function reset(): void
    {
        $migrated = $this->getAllMigrated();

        if (empty($migrated)) {
            $this->log('No hay migraciones para revertir.');
            return;
        }

        foreach (\array_reverse($migrated) as $file) {
            $class = $this->migrationsNamespace . '\\' . $this->fileToClass($file);
            if (!\class_exists($class)) {
                $this->requireFile($file);
            }

            $this->log("Revirtiendo: {$file}");
            $migration = new $class($this->db->getDriver(), $this->db->getPdo());
            $this->db->execute($migration->down());
            $this->markAsRolledBack($file);
            $this->log("  ✓ {$file}");
        }

        $this->log(\count($migrated) . ' migración(es) revertida(s).');
    }

    /** Muestra el estado de todas las migraciones */
    public function status(): void
    {
        $migrated = $this->getAllMigrated();
        $all      = $this->getAllFiles();

        $this->log(\str_pad('Migración', 55) . 'Estado');
        $this->log(\str_repeat('-', 70));

        foreach ($all as $file) {
            $status = \in_array($file, $migrated) ? '✓ Ejecutada' : '○ Pendiente';
            $this->log(\str_pad($file, 55) . $status);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Tabla de control                                                    */
    /* ------------------------------------------------------------------ */

    private function ensureMigrationsTable(): void
    {
        $driver = $this->db->getDriver();

        if ($driver === 'sqlite') {
            $this->db->execute("
                CREATE TABLE IF NOT EXISTS `migrations` (
                    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
                    `migration`   TEXT NOT NULL,
                    `migrated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            $this->db->execute("
                CREATE TABLE IF NOT EXISTS `migrations` (
                    `id`          INT NOT NULL AUTO_INCREMENT,
                    `migration`   VARCHAR(255) NOT NULL,
                    `migrated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        }
    }

    private function markAsMigrated(string $file): void
    {
        $stmt = $this->db->getPdo()->prepare(
            "INSERT INTO `migrations` (`migration`) VALUES (?)"
        );
        $stmt->execute([$file]);
    }

    private function markAsRolledBack(string $file): void
    {
        $stmt = $this->db->getPdo()->prepare(
            "DELETE FROM `migrations` WHERE `migration` = ?"
        );
        $stmt->execute([$file]);
    }

    private function getLastMigrated(): ?string
    {
        $row = $this->db->queryOne(
            "SELECT `migration` FROM `migrations` ORDER BY `id` DESC LIMIT 1"
        );
        return $row['migration'] ?? null;
    }

    private function getAllMigrated(): array
    {
        $rows = $this->db->query("SELECT `migration` FROM `migrations` ORDER BY `id` ASC");
        return \array_column($rows, 'migration');
    }

    /* ------------------------------------------------------------------ */
    /*  Archivos de migración                                               */
    /* ------------------------------------------------------------------ */

    private function getAllFiles(): array
    {
        if (!\is_dir($this->migrationsPath)) return [];

        $files = \glob($this->migrationsPath . '/*.php') ?: [];
        $names = \array_map('basename', $files);
        \sort($names);
        return $names;
    }

    private function getPending(): array
    {
        $migrated = $this->getAllMigrated();
        $pending  = [];

        foreach ($this->getAllFiles() as $file) {
            if (\in_array($file, $migrated)) continue;

            $this->requireFile($file);
            $class = $this->migrationsNamespace . '\\' . $this->fileToClass($file);

            if (!\class_exists($class)) {
                throw new \RuntimeException("No se encontró la clase {$class} en {$file}");
            }

            $pending[$file] = $class;
        }

        return $pending;
    }

    private function requireFile(string $filename): void
    {
        $path = $this->migrationsPath . '/' . $filename;
        if (\file_exists($path)) {
            require_once $path;
        }
    }

    private function fileToClass(string $filename): string
    {
        // 2024_01_15_000001_create_users_table.php → CreateUsersTable
        $name = \preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', \basename($filename, '.php'));
        return \str_replace('_', '', \ucwords($name, '_'));
    }

    private function log(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
