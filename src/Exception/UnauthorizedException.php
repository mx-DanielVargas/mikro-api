<?php

namespace MikroApi\Exception;

class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Unauthorized', ?array $body = null, array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 401, $body, $headers, $previous);
    }
}
