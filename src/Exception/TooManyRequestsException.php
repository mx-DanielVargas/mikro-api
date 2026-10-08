<?php

namespace MikroApi\Exception;

class TooManyRequestsException extends HttpException
{
    public function __construct(string $message = 'Too Many Requests', ?array $body = null, array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 429, $body, $headers, $previous);
    }
}
