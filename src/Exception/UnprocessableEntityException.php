<?php

namespace MikroApi\Exception;

class UnprocessableEntityException extends HttpException
{
    public function __construct(string $message = 'Unprocessable Entity', ?array $body = null, array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 422, $body, $headers, $previous);
    }
}
