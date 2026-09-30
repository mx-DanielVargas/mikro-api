# Roadmap de auditoría — MikroAPI

Este documento centraliza los hallazgos de la auditoría de mejoras y
optimización realizada sobre el framework, para llevar trazabilidad de
qué se ha corregido y qué queda pendiente.

Leyenda: ✅ Corregido · 🔄 En progreso · ⬜ Pendiente

## 🔴 Crítico

### AUD-001 — Fuga de mensajes de error en producción (detección de APP_ENV rota)
- **Estado:** ✅ Corregido
- **Archivo:** `src/App.php`
- **Problema:** `App::run()` comprobaba `$_SERVER['APP_ENV']`, pero `ConfigService`
  solo escribe `APP_ENV` en `$_ENV` / `putenv()` al cargar `.env`, nunca en
  `$_SERVER`. Resultado: en producción configurada vía `.env`, el mensaje
  real de cualquier excepción (rutas, SQL, etc.) se filtraba en la respuesta.
- **Fix:** nuevo método `App::isProduction()` que revisa `$_ENV`, `$_SERVER`
  y `getenv()` en ese orden, cubriendo cualquier forma de definir `APP_ENV`.

### AUD-002 — Inyección SQL vía nombres de columna cuando `$fillable` está vacío
- **Estado:** ✅ Corregido
- **Archivo:** `src/Repository/BaseRepository.php`
- **Problema:** `filterColumns()` permitía pasar cualquier clave del array
  de datos (típicamente el body de la request) directamente como nombre de
  columna en el SQL de `INSERT`/`UPDATE` cuando `$fillable` no estaba
  definido, sin ninguna validación de identificador.
- **Fix:** se añadió `assertValidColumnName()` (mismo patrón que
  `QueryBuilder::assertIdentifier()`) y se aplica a todas las claves
  resultantes de `filterColumns()`, lanzando `InvalidArgumentException`
  ante cualquier nombre de columna que no sea un identificador SQL válido.

## 🟠 Alto impacto

### AUD-003 — Swagger se genera en cada request, no solo en `/docs`
- **Estado:** ✅ Corregido
- **Archivos:** `src/App.php`, `src/Swagger/SwaggerUI.php`
- **Problema:** `App::enableSwagger()` generaba el spec OpenAPI completo
  (reflexión sobre todos los controladores + DTOs) de forma eager en el
  bootstrap, sin importar la ruta solicitada.
- **Fix:** `SwaggerUI` ahora recibe una factory (`\Closure`) en lugar del
  spec ya construido, y solo la invoca (con memoización) dentro de
  `handle()`, cuando la ruta coincide con `/docs` o `/docs/json`.

### AUD-004 — Rate limiting en memoria de proceso no persiste entre requests
- **Estado:** ⬜ Pendiente
- **Archivo:** `src/Middleware/RateLimitMiddleware.php`
- **Problema:** usa un array estático por proceso; en PHP-FPM/Apache
  (sin proceso persistente) el contador no persiste de forma confiable
  entre requests, por lo que el rate limiting es efectivamente inoperante
  en despliegues típicos.
- **Sugerencia:** introducir una interfaz `RateLimitStore` intercambiable
  (APCu/Redis) y documentar la limitación explícitamente en el README.

### AUD-005 — `/docs` y `/docs/json` se saltan todo el pipeline de middlewares
- **Estado:** ⬜ Pendiente
- **Archivo:** `src/App.php::run()`
- **Problema:** el chequeo de rutas de documentación ocurre antes de
  construir el pipeline de middlewares, por lo que CORS, rate limiting o
  cualquier guard/middleware de autenticación global no se aplican a la
  documentación.
- **Sugerencia:** permitir proteger `/docs` con un guard/middleware
  opcional, o mover el chequeo dentro del pipeline como una ruta más.

## 🟡 Medio impacto (rendimiento / arquitectura)

### AUD-006 — Sin caché de rutas compiladas
- **Estado:** ⬜ Pendiente
- **Archivo:** `src/Router.php`
- **Sugerencia:** `App::cacheRoutes(string $path)` opcional que serialice
  las rutas a un archivo PHP y las cargue si existe y está actualizado.

### AUD-007 — Motor de plantillas sin caché de compilación
- **Estado:** ⬜ Pendiente
- **Archivo:** `src/View/Engine.php`
- **Sugerencia:** cachear el HTML/PHP compilado en disco, invalidando por
  `mtime` del archivo fuente.

### AUD-008 — `BaseRepository::create()` hace un round-trip extra
- **Estado:** ⬜ Pendiente
- **Archivo:** `src/Repository/BaseRepository.php`
- **Sugerencia:** ofrecer variante sin el `SELECT` posterior al `INSERT`
  para paths de alto volumen que no necesiten defaults/triggers de BD.

### AUD-009 — `RelationLoader::makeRepo()` no usa el Container
- **Estado:** ⬜ Pendiente
- **Archivo:** `src/Repository/RelationLoader.php`
- **Sugerencia:** resolver repositorios relacionados vía el `Container`
  cuando esté disponible, para soportar constructores con dependencias
  adicionales.

## 🟢 Bajo impacto (calidad de código / cosmético)

### AUD-010 — Falsos positivos de análisis estático en `View\Engine::evaluate()`
- **Estado:** ⬜ Pendiente
- **Archivo:** `src/View/Engine.php`
- Inicializar `$__layout`/`$__sections` antes del `eval()` para que el
  analizador estático no las marque como indefinidas.

### AUD-011 — Variable muerta en `SwaggerUI::serveJson()`
- **Estado:** ✅ Corregido (resuelto como parte de AUD-003)
- **Archivo:** `src/Swagger/SwaggerUI.php`
- `$json` se calculaba pero nunca se usaba; se eliminó al refactorizar
  `serveJson()` en el fix de AUD-003.

### AUD-012 — Parser de `.env` no soporta comentarios al final de línea
- **Estado:** ⬜ Pendiente
- **Archivo:** `src/Config/ConfigService.php`
- `KEY=value # comentario` incluye el comentario como parte del valor.

### AUD-013 — Docblocks incompletos
- **Estado:** ⬜ Pendiente
- **Archivos:** `App::enableSwagger`, `Response::render`,
  `Engine::render/evaluate/setFallbackPaths`, `DtoSchemaBuilder::inferTypeFromPhp`.

### AUD-014 — `tests/README.md` desactualizado
- **Estado:** ⬜ Pendiente
- Indica que `SwaggerGenerator`, `DtoSchemaBuilder`, `Response`,
  `MigrationRunner` (alter table) no tienen tests, pero ya existen
  `SwaggerGeneratorTest.php`, `DtoSchemaBuilderTest.php`, `ResponseTest.php`,
  `ResponseRenderTest.php`, `MigrationAlterTableTest.php`.

## Historial de cambios

| Hallazgo | Commit | Mensaje |
|---|---|---|
| AUD-001 | `c79f137` | fix: corregir detección de APP_ENV para evitar fuga de mensajes de error en producción (AUD-001) |
| AUD-002 | `a635981` | fix: sanitizar nombres de columna en BaseRepository para prevenir inyección SQL (AUD-002) |
| AUD-003 / AUD-011 | `95634c2` | perf: generar el spec de Swagger de forma diferida para evitar el costo de reflexión en cada request (AUD-003) |

## Validación

Tras los 3 fixes, la suite completa de tests sigue en verde:

```
$ vendor/bin/phpunit
OK (198 tests, 333 assertions)
```
