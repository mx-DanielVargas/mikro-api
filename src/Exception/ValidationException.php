<?php

namespace MikroApi\Exception;

/**
 * 422 — falló la validación de un DTO (body, query o parámetros de ruta).
 * Respuesta: {"error": "Validation failed", "errors": {campo: [mensajes]}}
 */
class ValidationException extends UnprocessableEntityException
{
    /** @param array<string, string[]> $errors */
    public function __construct(private array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message, ['error' => $message, 'errors' => $errors]);
    }

    /** @return array<string, string[]> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
