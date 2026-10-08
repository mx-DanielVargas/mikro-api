<?php

namespace MikroApi\Exception;

class BadRequestException extends HttpException
{
    public function __construct(string $message = 'Bad Request', ?array $body = null, array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 400, $body, $headers, $previous);
    }
}
