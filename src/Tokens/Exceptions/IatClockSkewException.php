<?php

declare(strict_types=1);

namespace SlimeSystems\Tokens\Exceptions;

class IatClockSkewException extends TokenExpiredException
{
    public function __construct(string $message = 'Token issued-at timestamp is outside allowable clock skew window', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
