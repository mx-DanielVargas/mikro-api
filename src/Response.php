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
        $res->body   = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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

    /* ------------------------------------------------------------------ */
    /*  Fluent modifiers                                                    */
    /* ------------------------------------------------------------------ */

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;
        return $this;
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
