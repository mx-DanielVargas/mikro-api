<?php

namespace MikroApi\Console;

/**
 * Agrega una clase a una lista (imports, controllers, providers, exports)
 * del atributo #[Module(...)] de un archivo, como hace `nest g` al
 * registrar lo generado en su módulo. Agrega también el `use` si hace falta.
 */
final class ModuleRegistrar
{
    public const ADDED   = 'added';
    public const EXISTS  = 'exists';
    public const FAILED  = 'failed';

    public static function register(string $file, string $key, string $fqcn): string
    {
        $source = @\file_get_contents($file);
        if ($source === false) {
            return self::FAILED;
        }

        $short = \substr($fqcn, \strrpos($fqcn, '\\') + 1);
        $entry = "{$short}::class";

        $attrPos = \strpos($source, '#[Module');
        if ($attrPos === false) {
            return self::FAILED;
        }

        $afterName = $attrPos + \strlen('#[Module');

        if (($source[$afterName] ?? '') !== '(') {
            // #[Module] sin argumentos
            if (($source[$afterName] ?? '') !== ']') {
                return self::FAILED;
            }
            $source = \substr_replace($source, "({$key}: [{$entry}])", $afterName, 0);
            return self::save($file, self::addUse($source, $fqcn));
        }

        $close = self::matching($source, $afterName, '(', ')');
        if ($close === null) {
            return self::FAILED;
        }
        $args = \substr($source, $afterName + 1, $close - $afterName - 1);

        if (\preg_match('/\b' . \preg_quote($key, '/') . '\s*:\s*\[/', $args, $m, PREG_OFFSET_CAPTURE)) {
            $open     = $afterName + 1 + $m[0][1] + \strlen($m[0][0]) - 1;
            $closeArr = self::matching($source, $open, '[', ']');
            if ($closeArr === null) {
                return self::FAILED;
            }

            $inner = \substr($source, $open + 1, $closeArr - $open - 1);
            if (\preg_match('/(?<![\w\\\\])' . \preg_quote($short, '/') . '::class/', $inner)) {
                return self::EXISTS;
            }

            if (\trim($inner) === '') {
                $replacement = $entry;
            } elseif (\str_contains($inner, "\n")) {
                // Lista multilínea: nueva línea con la indentación de los elementos
                \preg_match('/\n([ \t]*)\S/', $inner, $indent);
                $itemIndent = $indent[1] ?? '        ';
                $trimmed    = \rtrim($inner);
                $closingWs  = \substr($inner, \strlen($trimmed));
                $replacement = $trimmed . (\str_ends_with($trimmed, ',') ? '' : ',')
                    . "\n{$itemIndent}{$entry}," . $closingWs;
            } else {
                $trimmed     = \rtrim(\rtrim($inner), ',');
                $replacement = "{$trimmed}, {$entry}";
            }

            $source = \substr_replace($source, $replacement, $open + 1, $closeArr - $open - 1);
            return self::save($file, self::addUse($source, $fqcn));
        }

        // La clave no existe: agregarla al inicio de los argumentos
        if (\trim($args) === '') {
            $insert = "{$key}: [{$entry}]";
        } elseif (\str_starts_with(\ltrim($args, " \t"), "\n") || \str_starts_with($args, "\n")) {
            \preg_match('/\n([ \t]*)\S/', $args, $indent);
            $insert = "\n" . ($indent[1] ?? '    ') . "{$key}: [{$entry}],";
        } else {
            $insert = "{$key}: [{$entry}], ";
        }

        $source = \substr_replace($source, $insert, $afterName + 1, 0);
        return self::save($file, self::addUse($source, $fqcn));
    }

    /** Agrega `use $fqcn;` si la clase no está en el mismo namespace ni importada. */
    private static function addUse(string $source, string $fqcn): string
    {
        $namespace = \substr($fqcn, 0, (int) \strrpos($fqcn, '\\'));

        if (\preg_match('/^namespace\s+([^;]+);/m', $source, $ns) && \trim($ns[1]) === $namespace) {
            return $source;
        }
        if (\preg_match('/^use\s+' . \preg_quote($fqcn, '/') . '\s*;/m', $source)) {
            return $source;
        }

        $line = "use {$fqcn};\n";

        if (\preg_match_all('/^use\s+[^;]+;\n/m', $source, $uses, PREG_OFFSET_CAPTURE)) {
            $last = \end($uses[0]);
            return \substr_replace($source, $line, $last[1] + \strlen($last[0]), 0);
        }
        if (\preg_match('/^namespace\s+[^;]+;\n/m', $source, $ns, PREG_OFFSET_CAPTURE)) {
            return \substr_replace($source, "\n" . $line, $ns[0][1] + \strlen($ns[0][0]), 0);
        }
        return \preg_replace('/^<\?php\s*\n/', "<?php\n\n{$line}", $source, 1);
    }

    /** Posición del delimitador que cierra al abierto en $openPos, ignorando strings. */
    private static function matching(string $source, int $openPos, string $open, string $close): ?int
    {
        $depth = 0;
        $quote = null;
        $len   = \strlen($source);

        for ($i = $openPos; $i < $len; $i++) {
            $ch = $source[$i];
            if ($quote !== null) {
                if ($ch === '\\') { $i++; continue; }
                if ($ch === $quote) { $quote = null; }
                continue;
            }
            if ($ch === "'" || $ch === '"') { $quote = $ch; continue; }
            if ($ch === $open) { $depth++; }
            elseif ($ch === $close && --$depth === 0) { return $i; }
        }
        return null;
    }

    private static function save(string $file, string $source): string
    {
        return \file_put_contents($file, $source) === false ? self::FAILED : self::ADDED;
    }
}
