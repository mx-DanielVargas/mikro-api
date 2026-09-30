<?php

namespace MikroApi\View;

class Engine
{
    private string $viewsPath;
    private string $extension;

    public function __construct(string $viewsPath, string $extension = '.php')
    {
        $this->viewsPath = rtrim($viewsPath, '/');
        $this->extension = $extension;
    }

    public function setViewsPath(string $viewsPath): void
    {
        $this->viewsPath = rtrim($viewsPath, '/');
    }

    public function getViewsPath(): string
    {
        return $this->viewsPath;
    }

    private array $globals = [];

    public function addGlobal(string $key, mixed $value): void
    {
        $this->globals[$key] = $value;
    }

    private ?string $cachePath = null;

    /**
     * Habilita (o deshabilita con null) una caché en disco del PHP compilado
     * de cada vista, invalidada automáticamente comparando un hash del
     * contenido fuente (no por mtime, para evitar colisiones si el archivo
     * se edita y el caché se regenera dentro de la misma ventana de 1s).
     * Evita recompilar (parseo de @if/@foreach/etc.) en cada render cuando
     * la plantilla no ha cambiado.
     */
    public function setCachePath(?string $path): void
    {
        $this->cachePath = $path !== null ? rtrim($path, '/') : null;
    }

    public function render(string $view, array $data = []): string
    {
        $file = $this->resolve($view);
        $compiled = $this->getCompiled($file);

        return $this->evaluate($compiled, $data);
    }

    /**
     * Retorna el PHP compilado para $file, usando la caché en disco si está
     * habilitada y sigue vigente. La vigencia se determina comparando un hash
     * del contenido fuente actual contra el hash embebido en el archivo
     * cacheado (no por mtime, que tiene una ventana de colisión de 1 segundo
     * si el archivo se edita y el caché se regenera dentro del mismo segundo).
     */
    private function getCompiled(string $file): string
    {
        $source = file_get_contents($file);

        if ($this->cachePath === null) {
            return $this->compile($source);
        }

        $cacheFile   = $this->cachePath . '/' . md5($file) . '.php';
        $sourceHash  = md5($source);
        $marker      = "<?php /* src-hash:{$sourceHash} */ ?>\n";

        if (is_file($cacheFile)) {
            $cached = file_get_contents($cacheFile);
            if (str_starts_with($cached, $marker)) {
                return substr($cached, strlen($marker));
            }
        }

        $compiled = $this->compile($source);

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
        file_put_contents($cacheFile, $marker . $compiled);

        return $compiled;
    }

    private array $fallbackPaths = [];

    public function setFallbackPath(?string $path): void
    {
        $this->fallbackPaths = $path ? [rtrim($path, '/')] : [];
    }

    public function setFallbackPaths(array $paths): void
    {
        $this->fallbackPaths = array_map(fn($p) => rtrim($p, '/'), $paths);
    }

    private function resolve(string $view): string
    {
        $relative = str_replace('.', '/', $view) . $this->extension;
        $file = $this->viewsPath . '/' . $relative;
        if (is_file($file)) return $file;
        foreach ($this->fallbackPaths as $fb) {
            $candidate = $fb . '/' . $relative;
            if (is_file($candidate)) return $candidate;
        }
        throw new \RuntimeException("View not found: {$view}");
    }

    private function compile(string $template): string
    {
        // Order matters: extends/yield/section first, then control structures, then echo
        $compiled = $template;

        // @extends('layout')
        $compiled = preg_replace('/@extends\([\'"](.+?)[\'"]\)/', '<?php \$__layout = \'$1\'; ?>', $compiled);

        // @section('name') ... @endsection
        $compiled = preg_replace('/@section\([\'"](.+?)[\'"]\)/', '<?php \$__sections[\'$1\'] = \'\'; ob_start(); ?>', $compiled);
        $compiled = str_replace('@endsection', '<?php $__sections[array_key_last($__sections)] = ob_get_clean(); ?>', $compiled);

        // @yield('name')
        $compiled = preg_replace('/@yield\([\'"](.+?)[\'"]\)/', '<?php echo \$__sections[\'$1\'] ?? \'\'; ?>', $compiled);

        // @include('partial')
        $compiled = preg_replace_callback('/@include\([\'"](.+?)[\'"]\)/', function ($m) {
            return '<?php echo $__engine->render(\'' . $m[1] . '\', get_defined_vars()); ?>';
        }, $compiled);

        // @foreach ($items as $item) ... @endforeach
        $compiled = preg_replace('/@foreach\s*\((.+?)\)/', '<?php foreach ($1): ?>', $compiled);
        $compiled = str_replace('@endforeach', '<?php endforeach; ?>', $compiled);

        // @if / @elseif / @else / @endif
        $balanced = function (string $compiled, string $directive, string $php): string {
            while (preg_match('/@' . $directive . '\s*\(/', $compiled, $m, PREG_OFFSET_CAPTURE)) {
                $start = $m[0][1];
                $parenStart = $start + strlen($m[0][0]) - 1;
                $depth = 1;
                $i = $parenStart + 1;
                $len = strlen($compiled);
                while ($i < $len && $depth > 0) {
                    if ($compiled[$i] === '(') $depth++;
                    elseif ($compiled[$i] === ')') $depth--;
                    $i++;
                }
                $expr = substr($compiled, $parenStart + 1, $i - $parenStart - 2);
                $replacement = '<?php ' . $php . ' (' . $expr . '): ?>';
                $compiled = substr($compiled, 0, $start) . $replacement . substr($compiled, $i);
            }
            return $compiled;
        };
        $compiled = $balanced($compiled, 'if', 'if');
        $compiled = $balanced($compiled, 'elseif', 'elseif');
        $compiled = str_replace('@else', '<?php else: ?>', $compiled);
        $compiled = str_replace('@endif', '<?php endif; ?>', $compiled);

        // {{ $var }} — escaped output
        $compiled = preg_replace('/\{\{\s*(.+?)\s*\}\}/', '<?php echo htmlspecialchars((string)($1), ENT_QUOTES, \'UTF-8\'); ?>', $compiled);

        // {!! $var !!} — raw output
        $compiled = preg_replace('/\{!!\s*(.+?)\s*!!}/', '<?php echo $1; ?>', $compiled);

        return $compiled;
    }

    private function evaluate(string $compiled, array $data): string
    {
        $data['__engine'] = $this;
        $data['__sections'] = $data['__sections'] ?? [];
        $data = array_merge($this->globals, $data);

        extract($data, EXTR_SKIP);

        // Se inicializan explícitamente para que el analizador estático no las
        // marque como indefinidas; eval() puede sobreescribirlas si la plantilla
        // usa @extends()/@section() (AUD-010). isset($__layout) preserva su
        // semántica exacta ya que isset() sobre null retorna false.
        $__layout = null;
        $__sections = $__sections ?? [];

        ob_start();
        eval('?>' . $compiled);
        $content = ob_get_clean();

        // If view extends a layout, render the layout with sections
        if (isset($__layout)) {
            $__sections['content'] = $__sections['content'] ?? $content;
            return $this->render($__layout, array_merge($data, ['__sections' => $__sections]));
        }

        return $content;
    }
}
