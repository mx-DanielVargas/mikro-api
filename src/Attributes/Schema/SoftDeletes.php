<?php
// core/Attributes/Schema/SoftDeletes.php
namespace MikroApi\Attributes\Schema;

/**
 * Agrega la columna deleted_at (nullable timestamp) para soft deletes.
 *
 *   #[Table('users')]
 *   #[Timestamps]
 *   #[SoftDeletes]
 *   class CreateUsersTable extends Migration { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class SoftDeletes {}
