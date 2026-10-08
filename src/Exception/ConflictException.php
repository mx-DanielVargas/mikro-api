<?php

namespace MikroApi\Exception;

class ConflictException extends HttpException
{
    public function __construct(string $message = 'Conflict', ?array $body = null, array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 409, $body, $headers, $previous);
    }
}
