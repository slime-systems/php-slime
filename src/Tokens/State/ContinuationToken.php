<?php

declare(strict_types=1);

namespace SlimeSystems\Tokens\State;

use SlimeSystems\Tokens\Exceptions\InvalidTokenException;
use SlimeSystems\Tokens\Exceptions\TokenException;

class ContinuationToken
{
    protected OpaqueToken $opaqueToken;

    /**
     * @param OpaqueToken|callable|array|string $tokenOrSecret
     * @param string|null $defaultKeyId
     */
    public function __construct(
        OpaqueToken|callable|array|string $tokenOrSecret,
        ?string $defaultKeyId = null
    ) {
        if ($tokenOrSecret instanceof OpaqueToken) {
            $this->opaqueToken = $tokenOrSecret;
        } else {
            $this->opaqueToken = new OpaqueToken($tokenOrSecret, $defaultKeyId);
        }
    }

    /**
     * Issue an encrypted continuation/cursor token for streaming or pagination using PASETO v4.local.
     *
     * @param string $subject The operation or stream identifier (e.g. 'trading-document/stream')
     * @param array $data Cursor payload (e.g. ['from' => '60f1b2c3...', 'inclusive' => false, 'project_id' => 10])
     * @param string|null $keyId Optional key ID
     * @return string Encrypted PASETO token string
     * @throws TokenException
     */
    public function issue(string $subject, array $data, ?string $keyId = null): string
    {
        return $this->opaqueToken->encrypt([
            'sub' => $subject,
            'data' => $data,
        ], keyId: $keyId, implicitAssertion: $subject);
    }

    /**
     * Decrypt and verify a continuation token, asserting that the subject matches.
     *
     * @param string $token The encrypted token
     * @param string|null $expectedSubject The expected stream/operation subject
     * @return array The cursor data payload array
     * @throws TokenException
     */
    public function decode(string $token, ?string $expectedSubject = null): array
    {
        $payload = $this->opaqueToken->decrypt($token, implicitAssertion: $expectedSubject);

        $sub = $payload['sub'] ?? null;
        if ($expectedSubject !== null && $sub !== $expectedSubject) {
            throw new InvalidTokenException("Continuation token subject mismatch: expected [{$expectedSubject}] but got [{$sub}].");
        }

        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            throw new InvalidTokenException('Continuation token payload missing data array.');
        }

        return $data;
    }

    /**
     * Get the underlying OpaqueToken manager.
     */
    public function getOpaqueToken(): OpaqueToken
    {
        return $this->opaqueToken;
    }
}
