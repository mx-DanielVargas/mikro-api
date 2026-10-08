<?php

namespace MikroApi\Console;

/**
 * Conversión de nombres para los generadores del CLI.
 * Pluralización/singularización en inglés, deliberadamente simple: los
 * archivos generados son editables.
 */
final class Naming
{
    /** snake_case / kebab-case / "texto libre" / camelCase → StudlyCase */
    public static function studly(string $name): string
    {
        $name = \preg_replace('/[^A-Za-z0-9]+/', ' ', $name);
        $name = \preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name);
        return \str_replace(' ', '', \ucwords(\strtolower(\trim($name))));
    }

    /** StudlyCase → snake_case */
    public static function snake(string $name): string
    {
        return \strtolower(\preg_replace('/(?<!^)[A-Z]/', '_$0', self::studly($name)));
    }

    /** StudlyCase → kebab-case */
    public static function kebab(string $name): string
    {
        return \str_replace('_', '-', self::snake($name));
    }

    public static function camel(string $name): string
    {
        return \lcfirst(self::studly($name));
    }

    public static function plural(string $word): string
    {
        if ($word === '' || self::isPlural($word)) {
            return $word;
        }
        if (\preg_match('/(s|x|z|ch|sh)$/i', $word)) {
            return $word . 'es';
        }
        if (\preg_match('/[^aeiou]y$/i', $word)) {
            return \substr($word, 0, -1) . 'ies';
        }
        return $word . 's';
    }

    public static function singular(string $word): string
    {
        if (\preg_match('/[^aeiou]ies$/i', $word)) {
            return \substr($word, 0, -3) . 'y';
        }
        if (\preg_match('/(us|ss|x|z|ch|sh)es$/i', $word)) {
            return \substr($word, 0, -2);
        }
        if (\preg_match('/(us|is|ss)$/i', $word)) {
            return $word; // status, analysis, address
        }
        if (\preg_match('/[^s]s$/i', $word)) {
            return \substr($word, 0, -1);
        }
        return $word;
    }

    private static function isPlural(string $word): bool
    {
        return \preg_match('/[^s]s$/i', $word) === 1 && self::singular($word) !== $word;
    }

    /** Quita un sufijo si existe (ProductController → Product) */
    public static function withoutSuffix(string $name, string $suffix): string
    {
        return \str_ends_with($name, $suffix) && $name !== $suffix
            ? \substr($name, 0, -\strlen($suffix))
            : $name;
    }

    /** Agrega un sufijo si falta (Product → ProductController) */
    public static function withSuffix(string $name, string $suffix): string
    {
        return \str_ends_with($name, $suffix) ? $name : $name . $suffix;
    }
}
