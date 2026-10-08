<?php

namespace MikroApi\Exception;

class InternalServerErrorException extends HttpException
{
    public function __construct(string $message = 'Internal Server Error', ?array $body = null, array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 500, $body, $headers, $previous);
    }
}
