<?php

namespace MikroApi\Config;

/**
 * Configuration service inspired by @nestjs/config.
 *
 * Loads environment variables from .env files and provides
 * typed access with dot-notation, defaults, and validation.
 *
 * Usage:
 *   $config = new ConfigService(__DIR__);
 *   $config->get('DB_HOST');                    // string|null
 *   $config->get('DB_PORT', 3306);              // with default
 *   $config->getOrThrow('DB_HOST');             // throws if missing
 *   $config->getInt('DB_PORT', 3306);           // typed accessors
 *   $config->getBool('DEBUG', false);
 *
 * Namespaced config (like @nestjs/config registerAs):
 *   $config->register('database', [
 *       'host'     => $config->get('DB_HOST', 'localhost'),
 *       'port'     => $config->getInt('DB_PORT', 3306),
 *       'database' => $config->getOrThrow('DB_NAME'),
 *   ]);
 *   $config->get('database.host');              // dot-notation access
 *
 * Validation:
 *   $config->validate(['DB_HOST', 'DB_NAME', 'JWT_SECRET']);
 */
class ConfigService
{
    /** @var array<string, mixed> Merged env values */
    private array $values = [];

    /** @var array<string, array<string, mixed>> Registered namespaces */
    private array $namespaces = [];

    /**
     * @param string|null $basePath  Directory containing .env file(s)
     * @param string      $envFile   Filename to load (default: .env)
     */
    public function __construct(?string $basePath = null, string $envFile = '.env')
    {
        if ($basePath !== null) {
            $this->loadEnvFile($basePath . '/' . $envFile);

            // Load environment-specific override: .env.local, .env.production, etc.
            $appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: null;
            if ($appEnv) {
                $this->loadEnvFile($basePath . '/' . $envFile . '.' . $appEnv);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Core accessors                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Get a config value. Supports dot-notation for registered namespaces.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Check registered namespaces first (dot-notation)
        if (str_contains($key, '.')) {
            [$ns, $rest] = explode('.', $key, 2);
            if (isset($this->namespaces[$ns])) {
                return $this->dotGet($this->namespaces[$ns], $rest) ?? $default;
            }
        }

        return $this->values[$key] ?? $default;
    }

    /**
     * Get a value or throw if not set.
     */
    public function getOrThrow(string $key): mixed
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new \RuntimeException("Missing required config: {$key}");
        }
        return $value;
    }

    public function getInt(string $key, ?int $default = null): ?int
    {
        $v = $this->get($key);
        return $v !== null ? (int) $v : $default;
    }

    public function getBool(string $key, ?bool $default = null): ?bool
    {
        $v = $this->get($key);
        if ($v === null) return $default;
        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function getFloat(string $key, ?float $default = null): ?float
    {
        $v = $this->get($key);
        return $v !== null ? (float) $v : $default;
    }

    /**
     * Set a value at runtime.
     */
    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    /* ------------------------------------------------------------------ */
    /*  Namespaces (registerAs)                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Register a namespaced config group, accessible via dot-notation.
     *
     * @param string               $namespace  Namespace key
     * @param array<string, mixed> $config     Key-value pairs
     */
    public function register(string $namespace, array $config): void
    {
        $this->namespaces[$namespace] = $config;
    }

    /* ------------------------------------------------------------------ */
    /*  Validation                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Validate that all required keys are present.
     *
     * @param string[] $keys Required environment variable names
     * @throws \RuntimeException If any key is missing
     */
    public function validate(array $keys): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if ($this->get($key) === null) {
                $missing[] = $key;
            }
        }
        if ($missing) {
            throw new \RuntimeException(
                'Missing required config keys: ' . implode(', ', $missing)
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  .env loader                                                         */
    /* ------------------------------------------------------------------ */

    private function loadEnvFile(string $path): void
    {
        if (!is_file($path)) return;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;

            $eqPos = strpos($line, '=');
            if ($eqPos === false) continue;

            $key   = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            // Strip surrounding quotes
            if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
                $value = substr($value, 1, -1);
            }

            // Variable interpolation: ${VAR} references
            $value = preg_replace_callback('/\$\{(\w+)\}/', function ($m) {
                return $this->values[$m[1]] ?? $_ENV[$m[1]] ?? getenv($m[1]) ?: '';
            }, $value);

            $this->values[$key] = $value;

            // Also set in environment so getenv() works globally
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                              */
    /* ------------------------------------------------------------------ */

    private function dotGet(array $data, string $key): mixed
    {
        $segments = explode('.', $key);
        foreach ($segments as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }
        return $data;
    }
}
