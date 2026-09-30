# Roadmap de auditoría — MikroAPI

Este documento centraliza los hallazgos de la auditoría de mejoras y
optimización realizada sobre el framework, para llevar trazabilidad de
qué se ha corregido y qué queda pendiente.

Leyenda: ✅ Corregido · 🔄 En progreso · ⬜ Pendiente

**Estado global: 14/14 hallazgos corregidos.** Suite de tests: `vendor/bin/phpunit` → `OK (213 tests, 370 assertions)`.

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
- **Commit:** `c79f137`

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
- **Commit:** `a635981`

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
- **Commit:** `95634c2`

### AUD-004 — Rate limiting en memoria de proceso no persiste entre requests
- **Estado:** ✅ Corregido
- **Archivo:** `src/Middleware/RateLimitMiddleware.php`
- **Problema:** usaba un array estático por proceso; en PHP-FPM/Apache
  (sin proceso persistente) el contador no persistía de forma confiable
  entre requests, por lo que el rate limiting era efectivamente inoperante
  en despliegues típicos.
- **Fix:** se extrajo el almacenamiento a una interfaz `RateLimitStore`,
  con `InMemoryRateLimitStore` (comportamiento por defecto, mismo
  disclaimer) y `ApcuRateLimitStore` (opt-in, persistente entre requests
  vía APCu) como implementaciones intercambiables.
- **Commit:** `f83ba8e`

### AUD-005 — `/docs` y `/docs/json` se saltan todo el pipeline de middlewares
- **Estado:** ✅ Corregido
- **Archivo:** `src/App.php::run()`
- **Problema:** el chequeo de rutas de documentación ocurría antes de
  construir el pipeline de middlewares, por lo que CORS, rate limiting o
  cualquier guard/middleware de autenticación global no se aplicaban a la
  documentación.
- **Fix:** el chequeo de `swaggerUI->matches()` se movió dentro del
  closure `$core`, para que fluya por el mismo pipeline de middlewares
  que cualquier otra ruta.
- **Commit:** `b754a80`

## 🟡 Medio impacto (rendimiento / arquitectura)

### AUD-006 — Sin caché de rutas compiladas
- **Estado:** ✅ Corregido
- **Archivos:** `src/Router.php`, `src/App.php`
- **Fix:** nuevo `App::cacheRoutes(string $cacheFile)`. En cache-hit,
  `Router::loadFromCache()` carga las rutas serializadas y
  `registerController()` se vuelve un no-op (evita reflexión). En
  cache-miss, `Router::cacheTo()` serializa las rutas a un archivo PHP
  justo antes de procesar la primera request tras el bootstrap.
- **Commit:** `25f4ef2`

### AUD-007 — Motor de plantillas sin caché de compilación
- **Estado:** ✅ Corregido
- **Archivo:** `src/View/Engine.php`
- **Fix:** nuevo `Engine::setCachePath(?string $path)`. Cuando está
  habilitado, el PHP compilado de cada vista se cachea en disco
  (clave por hash del path) y se invalida automáticamente comparando el
  `mtime` del archivo fuente contra el del archivo cacheado.
- **Commit:** `e64eec9`

### AUD-008 — `BaseRepository::create()` hace un round-trip extra
- **Estado:** ✅ Corregido
- **Archivo:** `src/Repository/BaseRepository.php`
- **Fix:** nuevo parámetro opcional `create(array $data, bool $reload = true)`.
  El valor por defecto preserva el comportamiento histórico (SELECT tras
  el INSERT); `reload: false` omite ese round-trip para paths de alto
  volumen que no necesiten defaults/triggers de la BD.
- **Commit:** `fa20784`

### AUD-009 — `RelationLoader::makeRepo()` no usa el Container
- **Estado:** ✅ Corregido
- **Archivos:** `src/Container.php`, `src/Repository/BaseRepository.php`, `src/Repository/RelationLoader.php`
- **Fix:** `Container::autowire()` inyecta automáticamente el propio
  container a cualquier `BaseRepository` que resuelve (vía nuevo
  `BaseRepository::setContainer()`, que también auto-registra su
  `Database` en el container si no hay una ya vinculada).
  `RelationLoader` usa ese container (si está disponible) en `makeRepo()`
  para resolver repositorios relacionados con dependencias adicionales
  más allá de `Database`, con fallback al comportamiento anterior si no
  hay container.
- **Nota:** se identificó (no se corrigió, fuera de alcance) una
  limitación preexistente de `Container::autowire()`: no resuelve por sí
  solo un parámetro `?Database $db = null` porque `Database` tiene
  constructor privado y el autowire no falla hacia el valor por defecto
  cuando el tipo declarado es un objeto. Se documenta aquí para una
  futura iteración.
- **Commit:** `ec80030`

## 🟢 Bajo impacto (calidad de código / cosmético)

### AUD-010 — Falsos positivos de análisis estático en `View\Engine::evaluate()`
- **Estado:** ✅ Corregido
- **Archivo:** `src/View/Engine.php`
- **Fix:** se inicializan `$__layout = null;` y `$__sections = $__sections ?? [];`
  antes del `eval()`, preservando la semántica exacta de `isset($__layout)`.
- **Commit:** `78bcb9d`

### AUD-011 — Variable muerta en `SwaggerUI::serveJson()`
- **Estado:** ✅ Corregido (resuelto como parte de AUD-003)
- **Archivo:** `src/Swagger/SwaggerUI.php`
- `$json` se calculaba pero nunca se usaba; se eliminó al refactorizar
  `serveJson()` en el fix de AUD-003.
- **Commit:** `95634c2`

### AUD-012 — Parser de `.env` no soporta comentarios al final de línea
- **Estado:** ✅ Corregido
- **Archivo:** `src/Config/ConfigService.php`
- **Fix:** valores sin comillas ahora descartan un comentario final
  precedido por espacio (`KEY=value # comentario` → `value`); valores
  entre comillas preservan cualquier `#` interno de forma literal; un
  `#` pegado sin espacio (ej. URLs con `#fragment`) no se trata como
  comentario.
- **Commit:** `d13ea2a`

### AUD-013 — Docblocks incompletos
- **Estado:** ✅ Corregido
- **Archivos:** `src/Response.php`, `src/Swagger/DtoSchemaBuilder.php`
  (App/Engine se completaron como parte de AUD-005/006/007/010, mismos commits).
- **Commit:** `89262e8`

### AUD-014 — `tests/README.md` desactualizado
- **Estado:** ✅ Corregido
- Se actualizaron las secciones de componentes testeados/no testeados y
  "próximos pasos" para reflejar la cobertura real (`SwaggerGeneratorTest`,
  `DtoSchemaBuilderTest`, `ResponseTest`, `ResponseRenderTest`,
  `ConfigServiceTest`, `MigrationAlterTableTest`, `BaseServiceTest`, etc.)
- **Commit:** `4857eab`

## Historial de cambios

| Hallazgo | Commit | Mensaje |
|---|---|---|
| AUD-001 | `c79f137` | fix: corregir detección de APP_ENV para evitar fuga de mensajes de error en producción (AUD-001) |
| AUD-002 | `a635981` | fix: sanitizar nombres de columna en BaseRepository para prevenir inyección SQL (AUD-002) |
| AUD-003 / AUD-011 | `95634c2` | perf: generar el spec de Swagger de forma diferida para evitar el costo de reflexión en cada request (AUD-003) |
| AUD-004 | `f83ba8e` | fix: hacer el almacenamiento de RateLimitMiddleware intercambiable y persistente (AUD-004) |
| AUD-012 | `d13ea2a` | fix: soportar comentarios al final de línea en el parser de .env (AUD-012) |
| AUD-013 (parcial) | `89262e8` | docs: completar docblocks faltantes en Response y DtoSchemaBuilder (AUD-013) |
| AUD-014 | `4857eab` | docs: actualizar tests/README.md para reflejar la cobertura de tests actual (AUD-014) |
| AUD-005 | `b754a80` | fix: aplicar el pipeline de middlewares también a las rutas de Swagger (AUD-005) |
| AUD-010 | `78bcb9d` | fix: inicializar variables de plantilla para evitar falsos positivos del analizador estático (AUD-010) |
| AUD-006 | `25f4ef2` | feat: agregar caché opcional de rutas compiladas vía App::cacheRoutes() (AUD-006) |
| AUD-007 | `e64eec9` | perf: agregar caché opcional de plantillas compiladas invalidada por mtime (AUD-007) |
| AUD-008 | `fa20784` | perf: permitir omitir el SELECT posterior al INSERT en BaseRepository::create() (AUD-008) |
| AUD-009 | `ec80030` | feat: permitir que RelationLoader resuelva repositorios relacionados vía Container (AUD-009) |

## Validación final

Toda la implementación (14/14 hallazgos) se validó con la suite completa:

```
$ vendor/bin/phpunit
OK (213 tests, 370 assertions)
```

Partiendo de 198 tests/333 assertions antes de esta ronda, se agregaron 15
tests nuevos (370-333=37 assertions nuevas) cubriendo cada fix aplicado.

## Metodología

Los hallazgos AUD-004 a AUD-014 se implementaron en paralelo mediante 5
sub-agentes trabajando sobre conjuntos de archivos disjuntos del mismo
árbol de trabajo, cada uno responsable de sus propios commits (`git add`
con archivos explícitos, sin `git add .`/`git commit -a`, para evitar
mezclar cambios de otros agentes en curso). Un agente adicional detectó y
corrigió un error propio (borrado accidental de un test preexistente en un
`edit_file` intermedio) antes de confirmar su commit — ver detalle en
AUD-009 arriba.
