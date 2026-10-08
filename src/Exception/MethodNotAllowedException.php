<?php

namespace MikroApi\Exception;

/**
 * 405 — la ruta existe pero no para el método HTTP solicitado.
 * Incluye el header `Allow` con los métodos permitidos.
 */
class MethodNotAllowedException extends HttpException
{
    /** @param string[] $allowedMethods */
    public function __construct(
        private array $allowedMethods = [],
        string $message = 'Method Not Allowed',
        ?\Throwable $previous = null,
    ) {
        $headers = empty($allowedMethods) ? [] : ['Allow' => \implode(', ', $allowedMethods)];
        parent::__construct($message, 405, null, $headers, $previous);
    }

    /** @return string[] */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
