<?php

declare(strict_types=1);

namespace SlimeSystems\Tokens\Exceptions;

use Illuminate\Support\MessageBag;

class TokenSchemaViolationException extends TokenException
{
    public readonly MessageBag $errors;

    public function __construct(MessageBag|array $errors, string $message = 'Token schema validation failed', int $code = 0, ?\Throwable $previous = null)
    {
        $this->errors = $errors instanceof MessageBag ? $errors : new MessageBag($errors);
        parent::__construct($message, $code, $previous);
    }

    public function getErrors(): MessageBag
    {
        return $this->errors;
    }
}
