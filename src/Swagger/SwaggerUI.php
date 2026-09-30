<?php

namespace MikroApi\Swagger;

use MikroApi\Response;

/**
 * Sirve la interfaz Swagger UI y el endpoint del spec JSON.
 * Usa Swagger UI desde CDN — no requiere instalación.
 *
 * El spec se genera de forma diferida (solo cuando se accede a /docs o
 * /docs/json) y se memoiza, para no penalizar el resto de requests con
 * el costo de reflexión de generar el spec completo (AUD-003).
 */
class SwaggerUI
{
    private ?array $spec = null;

    public function __construct(
        private \Closure $specFactory,
        private string   $uiPath,   // ej: /docs
        private string   $jsonPath, // ej: /docs/json
    ) {}

    public function matches(string $path): bool
    {
        return $path === $this->uiPath || $path === $this->jsonPath;
    }

    public function handle(string $path): Response
    {
        $spec = $this->getSpec();

        if ($path === $this->jsonPath) {
            return $this->serveJson($spec);
        }
        return $this->serveHtml($spec);
    }

    private function getSpec(): array
    {
        if ($this->spec === null) {
            $this->spec = ($this->specFactory)();
        }
        return $this->spec;
    }

    private function serveJson(array $spec): Response
    {
        return Response::json($spec)
            ->withHeader('Access-Control-Allow-Origin', '*');
    }

    private function serveHtml(array $spec): Response
    {
        $jsonUrl = $this->jsonPath;
        $title   = \htmlspecialchars($spec['info']['title'] ?? 'API Docs');
        $version = \htmlspecialchars($spec['info']['version'] ?? '');

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
