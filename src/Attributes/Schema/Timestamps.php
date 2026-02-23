<?php
// core/Attributes/Schema/Timestamps.php
namespace MikroApi\Attributes\Schema;

/**
 * Agrega automáticamente las columnas created_at y updated_at a la tabla.
 * Se pone a nivel de clase junto con #[Table].
 *
 *   #[Table('users')]
 *   #[Timestamps]
 *   class CreateUsersTable extends Migration { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Timestamps {}
