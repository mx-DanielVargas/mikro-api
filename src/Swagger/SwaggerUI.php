<?php

namespace MikroApi\Swagger;

use MikroApi\Response;

/**
 * Sirve la interfaz Swagger UI y el endpoint del spec JSON.
 * Usa Swagger UI desde CDN — no requiere instalación.
 */
class SwaggerUI
{
    public function __construct(
        private array  $spec,
        private string $uiPath,   // ej: /docs
        private string $jsonPath, // ej: /docs/json
    ) {}

    public function matches(string $path): bool
    {
        return $path === $this->uiPath || $path === $this->jsonPath;
    }

    public function handle(string $path): Response
    {
        if ($path === $this->jsonPath) {
            return $this->serveJson();
        }
        return $this->serveHtml();
    }

    private function serveJson(): Response
    {
        $json = \json_encode($this->spec, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        return Response::json($this->spec)
            ->withHeader('Access-Control-Allow-Origin', '*');
    }

    private function serveHtml(): Response
    {
        $jsonUrl = $this->jsonPath;
        $title   = \htmlspecialchars($this->spec['info']['title'] ?? 'API Docs');
        $version = \htmlspecialchars($this->spec['info']['version'] ?? '');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title} {$version} — Swagger UI</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui.min.css">
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: sans-serif; }
    .topbar { display: none; }
  </style>
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui-bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui-standalone-preset.min.js"></script>
  <script>
    SwaggerUIBundle({
      url:            '{$jsonUrl}',
      dom_id:         '#swagger-ui',
      presets:        [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
      layout:         'StandaloneLayout',
      deepLinking:    true,
      tryItOutEnabled: true,
      filter:         true,
      persistAuthorization: true,
    });
  </script>
</body>
</html>
HTML;

        return Response::html($html);
    }
}
