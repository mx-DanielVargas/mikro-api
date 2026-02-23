# Tests de MikroAPI

Esta suite de tests cubre los componentes críticos del framework MikroAPI.

## 🎯 Estrategia de Testing

### Componentes Testeados (Alta Prioridad)

#### 1. **ValidatorTest** - Sistema de validación
- ✅ Validación de campos requeridos y opcionales
- ✅ Validación de tipos (string, int, float, bool, array)
- ✅ Validación de formato (email, URL, regex)
- ✅ Validación de longitud (min, max, length)
- ✅ Validación de rangos numéricos
- ✅ Validación de arrays (ArrayOf, ArrayUnique)
- ✅ Casteo automático de tipos
- ✅ Múltiples reglas por campo

#### 2. **RouterTest** - Sistema de enrutamiento
- ✅ Matching de rutas simples
- ✅ Extracción de parámetros de ruta (:id)
- ✅ Múltiples parámetros en una ruta
- ✅ Prefijos de controlador
- ✅ Ejecución de guards
- ✅ Validación de DTOs en el body
- ✅ Manejo de rutas no encontradas

#### 3. **QueryBuilderTest** - Constructor de queries SQL
- ✅ Condiciones WHERE (=, >, <, >=, <=, !=)
- ✅ Condiciones OR
- ✅ WHERE IN, BETWEEN, LIKE, NULL
- ✅ ORDER BY, LIMIT, OFFSET
- ✅ Paginación
- ✅ COUNT y EXISTS
- ✅ SELECT de columnas específicas
- ✅ Soft deletes
- ✅ Queries complejas combinadas

#### 4. **BaseRepositoryTest** - Repositorio base
- ✅ Operaciones CRUD (create, read, update, delete)
- ✅ Búsquedas (findById, findBy, findOneBy, findAll)
- ✅ Conteo y existencia
- ✅ Paginación
- ✅ Filtrado de columnas fillable
- ✅ QueryBuilder fluent
- ✅ Eager loading (with)
- ✅ Relaciones (HasMany, BelongsTo)
- ✅ Soft deletes y restore

#### 5. **RequestTest** - Parsing de requests HTTP
- ✅ Captura de método HTTP
- ✅ Normalización de paths
- ✅ Parsing de query strings
- ✅ Parsing de body (JSON y form data)
- ✅ Extracción de headers
- ✅ Headers case-insensitive
- ✅ Método input() con defaults

#### 6. **DatabaseTest** - Conexión a base de datos
- ✅ Conexión SQLite
- ✅ Patrón Singleton
- ✅ Ejecución de queries
- ✅ Prepared statements
- ✅ Transacciones (commit y rollback)
- ✅ lastInsertId

## 🚫 Componentes NO Testeados (Bajo Valor)

Los siguientes componentes no tienen tests porque:

- **Response**: Factories simples sin lógica compleja
- **App**: Orquestación que requiere mocks complejos del entorno HTTP
- **BaseService**: Solo helpers para lanzar excepciones
- **Attributes**: Clases de metadatos sin lógica ejecutable
- **MigrationRunner**: Integración con filesystem, mejor testear manualmente
- **SwaggerUI**: Renderizado HTML, difícil de testear unitariamente

## 📦 Instalación

```bash
composer install
```

## 🧪 Ejecutar Tests

```bash
# Instalar dependencias (si no lo has hecho)
composer install

# Todos los tests
composer test

# O directamente con PHPUnit
./vendor/bin/phpunit

# Con formato testdox (más legible)
./vendor/bin/phpunit --testdox

# Test específico
./vendor/bin/phpunit tests/ValidatorTest.php

# Con coverage (requiere Xdebug o PCOV)
./vendor/bin/phpunit --coverage-html coverage
```

## ✅ Estado Actual

**88 tests, 152 assertions - Todos pasando ✓**

## 📊 Coverage Esperado

Los tests cubren aproximadamente el 70-80% del código crítico:

- ✅ Validator: ~95%
- ✅ Router: ~85%
- ✅ QueryBuilder: ~90%
- ✅ BaseRepository: ~80%
- ✅ Request: ~90%
- ✅ Database: ~75%

## 🔧 Configuración

La configuración de PHPUnit está en `phpunit.xml`:

- Bootstrap: `vendor/autoload.php`
- Directorio de tests: `tests/`
- Coverage excluye: Attributes, interfaces, excepciones

## 📝 Convenciones

1. **Nombres de tests**: `testDescripcionDelComportamiento()`
2. **Estructura**: Arrange → Act → Assert
3. **setUp()**: Inicializar dependencias comunes
4. **Datos de prueba**: SQLite en memoria (`:memory:`)
5. **Assertions claras**: Usar el assertion más específico

## 🎓 Ejemplos de Uso

### Test de Validación

```php
public function testEmailValidation(): void
{
    $dto = new class {
        #[IsEmail]
        public string $email;
    };

    $result = $this->validator->validate($dto::class, ['email' => 'invalid']);
    
    $this->assertTrue($this->validator->hasErrors());
}
```

### Test de Repository

```php
public function testCreate(): void
{
    $user = $this->userRepo->create([
        'name' => 'John',
        'email' => 'john@example.com'
    ]);

    $this->assertArrayHasKey('id', $user);
    $this->assertEquals('John', $user['name']);
}
```

### Test de QueryBuilder

```php
public function testWhereCondition(): void
{
    $results = $this->qb
        ->where('active', 1)
        ->where('age', 18, '>=')
        ->get();

    $this->assertCount(2, $results);
}
```

## 🐛 Debugging

Si un test falla:

1. Ejecutar solo ese test: `./vendor/bin/phpunit --filter testNombre`
2. Agregar `var_dump()` o `print_r()` para inspeccionar valores
3. Usar `--debug` para ver más información: `./vendor/bin/phpunit --debug`

## 🚀 Próximos Pasos

Tests adicionales que podrían agregarse (prioridad media):

- [ ] SchemaBuilderTest - Generación de DDL
- [ ] RelationLoaderTest - Carga de relaciones N+1
- [ ] SwaggerGeneratorTest - Generación de OpenAPI spec
- [ ] Integration tests - Tests end-to-end

## 📚 Recursos

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Testing Best Practices](https://phpunit.de/manual/current/en/writing-tests-for-phpunit.html)
