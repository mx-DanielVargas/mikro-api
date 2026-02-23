<?php
// core/Request.php
namespace MikroApi;

class Request
{
    public string $method;
    public string $path;
    public array   $params  = [];   // route params  :id
    public array   $query   = [];   // ?foo=bar
    public array   $body    = [];   // JSON / form data
    public ?object $dto     = null; // DTO validado (si se usa #[Body])
    private array $headers = [];

    private function __construct() {}

    public static function capture(): self
    {
        $req = new self();

        $req->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Path sin query string
        $uri       = $_SERVER['REQUEST_URI'] ?? '/';
        $req->path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $req->path = '/' . trim($req->path, '/');
        if ($req->path === '//') $req->path = '/';

        // Query string
        $req->query = $_GET;

        // Body: JSON o form data
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $req->body = json_decode($raw, true) ?? [];
        } else {
            $req->body = $_POST;
        }

        // Headers
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name               = str_replace('_', '-', substr($key, 5));
                $req->headers[$name] = $value;
            }
        }
        // Content-Type y Authorization no siempre vienen con HTTP_
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $req->headers['CONTENT-TYPE'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $req->headers['AUTHORIZATION'] = $_SERVER['HTTP_AUTHORIZATION'];
        }

        return $req;
    }

    /** Obtiene un header (case-insensitive) */
    public function header(string $name): ?string
    {
        return $this->headers[strtoupper($name)] ?? null;
    }

    /** Obtiene un valor del body o un default */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }
}
