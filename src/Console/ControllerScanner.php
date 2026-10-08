<?php

namespace MikroApi\Console;

use MikroApi\Attributes\Controller;

/**
 * Encuentra las clases con #[Controller] bajo un directorio (para
 * route:list y docs:export) sin arrancar la aplicación.
 */
final class ControllerScanner
{
    /** @return string[] FQCN de controladores, ordenados */
    public static function scan(string $dir): array
    {
        if (!\is_dir($dir)) {
            return [];
        }

        $controllers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') continue;

            $class = self::classFromFile($file->getPathname());
            if ($class === null) continue;

            if (!\class_exists($class)) {
                // No autocargable (PSR-4 distinto): cargar el archivo directamente
                require_once $file->getPathname();
                if (!\class_exists($class, false)) continue;
            }

            $ref = new \ReflectionClass($class);
            if (!$ref->isAbstract() && !empty($ref->getAttributes(Controller::class))) {
                $controllers[] = $class;
            }
        }

        \sort($controllers);
        return $controllers;
    }

    /** Namespace + nombre de la primera clase declarada en el archivo. */
    public static function classFromFile(string $path): ?string
    {
        $tokens    = \PhpToken::tokenize((string) \file_get_contents($path));
        $namespace = '';
        $count     = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_NAMESPACE)) {
                $namespace = '';
                for ($j = $i + 1; $j < $count && !$tokens[$j]->is([';', '{']); $j++) {
                    if ($tokens[$j]->is([T_STRING, T_NAME_QUALIFIED])) {
                        $namespace .= $tokens[$j]->text;
                    }
                }
            }

            if ($token->is(T_CLASS)) {
                // Ignorar Foo::class y clases anónimas (new class)
                $prev = $i - 1;
                while ($prev >= 0 && $tokens[$prev]->is(T_WHITESPACE)) $prev--;
                if ($prev >= 0 && $tokens[$prev]->is([T_DOUBLE_COLON, T_NEW])) continue;

                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]->is(T_STRING)) {
                        return \ltrim($namespace . '\\' . $tokens[$j]->text, '\\');
                    }
                    if (!$tokens[$j]->is(T_WHITESPACE)) break;
                }
            }
        }

        return null;
    }
}
