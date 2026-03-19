<?php
// core/Response.php
namespace MikroApi;

class Response
{
    private int    $status  = 200;
    private array  $headers = ['Content-Type' => 'application/json'];
    private string $body    = '';

    private function __construct() {}

    /* ------------------------------------------------------------------ */
    /*  Factories                                                           */
    /* ------------------------------------------------------------------ */

    public static function json(mixed $data, int $status = 200): self
    {
        $res         = new self();
        $res->status = $status;
        $res->body   = json_encode($data, JSON_UNESCAPED_UNICODE);
        $res->headers['Content-Type'] = 'application/json; charset=utf-8';
        return $res;
    }

    public static function text(string $data, int $status = 200): self
    {
        $res         = new self();
        $res->status = $status;
        $res->body   = $data;
        $res->headers['Content-Type'] = 'text/plain; charset=utf-8';
        return $res;
    }

    public static function html(string $data, int $status = 200): self
    {
        $res         = new self();
        $res->status = $status;
        $res->body   = $data;
        $res->headers['Content-Type'] = 'text/html; charset=utf-8';
        return $res;
    }

    public static function error(string $message, int $status = 500): self
    {
        return self::json(['error' => $message], $status);
    }

    public static function empty(int $status = 204): self
    {
        $res         = new self();
        $res->status = $status;
        return $res;
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $res = new self();
        $res->status  = $status;
        $res->headers['Location'] = $url;
        return $res;
    }

    /* ------------------------------------------------------------------ */
    /*  Fluent modifiers                                                    */
    /* ------------------------------------------------------------------ */

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /* ------------------------------------------------------------------ */
    /*  Send                                                                */
    /* ------------------------------------------------------------------ */

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
