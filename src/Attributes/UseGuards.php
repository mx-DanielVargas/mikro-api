<?php
// core/Attributes/UseGuards.php
namespace MikroApi\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class UseGuards
{
    /** @var string[] */
    public array $guards;

    public function __construct(string ...$guards)
    {
        $this->guards = $guards;
    }
}
