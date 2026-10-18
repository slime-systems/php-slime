<?php

declare(strict_types=1);

namespace SlimeSystems\Tokens\Exceptions;

class TokenExpiredException extends TokenException
{
    public function __construct(string $message = 'Token has expired', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
