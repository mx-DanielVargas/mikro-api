<?php

namespace MikroApi\Console;

/**
 * Salida del CLI. Colores ANSI solo si el stream es una terminal.
 */
final class Output
{
    /** @var resource */
    private $stream;
    private bool $colors;

    /** @param resource|null $stream */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? \STDOUT;
        $this->colors = \function_exists('stream_isatty') && @\stream_isatty($this->stream)
            && \getenv('NO_COLOR') === false;
    }

    public function line(string $text = ''): void
    {
        \fwrite($this->stream, $text . PHP_EOL);
    }

    public function success(string $text): void { $this->line($this->color('32', '✓ ') . $text); }
    public function skip(string $text): void    { $this->line($this->color('33', '- ') . $text); }
    public function error(string $text): void   { $this->line($this->color('31', '✗ ') . $text); }
    public function info(string $text): void    { $this->line($this->color('36', $text)); }
    public function title(string $text): void   { $this->line($this->color('1', $text)); }

    public function comment(string $text): string
    {
        return $this->color('2', $text);
    }

    public function highlight(string $text): string
    {
        return $this->color('32', $text);
    }

    /**
     * @param string[]   $headers
     * @param string[][] $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = \array_map('mb_strlen', $headers);
        foreach ($rows as $row) {
            foreach (\array_values($row) as $i => $cell) {
                $widths[$i] = \max($widths[$i] ?? 0, \mb_strlen((string) $cell));
            }
        }

        $format = function (array $cells) use ($widths): string {
            $out = [];
            foreach (\array_values($cells) as $i => $cell) {
                $out[] = $cell . \str_repeat(' ', $widths[$i] - \mb_strlen((string) $cell));
            }
            return \rtrim(\implode('  ', $out));
        };

        $this->line($this->color('1', $format($headers)));
        foreach ($rows as $row) {
            $this->line($format($row));
        }
    }

    private function color(string $code, string $text): string
    {
        return $this->colors ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
