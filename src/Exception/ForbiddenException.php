<?php

namespace MikroApi\Exception;

class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Forbidden', ?array $body = null, array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 403, $body, $headers, $previous);
    }
}
