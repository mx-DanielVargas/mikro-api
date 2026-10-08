<?php

namespace MikroApi\Exception;

class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not Found', ?array $body = null, array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 404, $body, $headers, $previous);
    }
}
