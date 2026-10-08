<?php

namespace MikroApi\Exception;

/**
 * Excepción base para errores HTTP. Cualquier excepción que la extienda
 * se convierte automáticamente en una respuesta JSON con su código de
 * estado, sin importar dónde se lance (controlador, servicio, guard,
 * interceptor, middleware).
 *
 * Uso:
 *   throw new NotFoundException('Usuario no encontrado');
 *   throw new HttpException('I am a teapot', 418);
 *   throw new BadRequestException('Datos inválidos', body: ['error' => '...', 'fields' => [...]]);
 *
 * Respuesta por defecto: {"error": "<mensaje>"} con el status indicado.
 */
class HttpException extends \RuntimeException
{
    /**
     * @param array|null            $body    Cuerpo JSON personalizado. null → ['error' => $message]
     * @param array<string, string> $headers Headers extra a incluir en la respuesta
     */
    public function __construct(
        string $message = '',
        private int $statusCode = 500,
        private ?array $body = null,
        private array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** Cuerpo JSON que se enviará como respuesta. */
    public function getBody(): array
    {
        return $this->body ?? ['error' => $this->getMessage()];
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
