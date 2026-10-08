<?php

namespace MikroApi\Console;

/**
 * Argumentos y opciones de la línea de comandos.
 *   mikro make:controller Product --module=Products --force
 *   → arguments: ['Product'], options: ['module' => 'Products', 'force' => true]
 */
final class Input
{
    /** @var string[] */
    public array $arguments = [];

    /** @var array<string, string|true> */
    public array $options = [];

    /** @param string[] $args sin el nombre del script ni el comando */
    public function __construct(array $args)
    {
        foreach ($args as $arg) {
            if (\preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $arg, $m)) {
                $this->options[\strtolower($m[1])] = isset($m[2]) ? $m[2] : true;
            } elseif (\preg_match('/^-([a-z])$/i', $arg, $m)) {
                $this->options[\strtolower($m[1])] = true;
            } else {
                $this->arguments[] = $arg;
            }
        }
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function flag(string ...$names): bool
    {
        foreach ($names as $name) {
            if (($this->options[$name] ?? false) === true) {
                return true;
            }
        }
        return false;
    }
}
