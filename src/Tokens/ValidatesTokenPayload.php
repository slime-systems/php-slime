<?php

declare(strict_types = 1);

namespace SlimeSystems\Tokens;

trait ValidatesTokenPayload {
  /**
   * Validate the issued-at (iat) timestamp for expiration and clock skew.
   *
   * @param int|float|null $iat
   * @param int $maxAgeSeconds Maximum age in seconds (default 300)
   * @param int $futureSkewSeconds Allowed future clock skew in seconds (default 30)
   * @param int|null $currentTime Fixed current timestamp for testing
   * @throws TokenException
   */
  protected function validateIat(
    int|float|null $iat,
    int            $maxAgeSeconds = 300,
    int            $futureSkewSeconds = 30,
    ?int           $currentTime = null
  ): void {
    if (!is_numeric($iat)) {
      throw new TokenException(
        TokenException::SCHEMA_VIOLATION,
        'The iat claim is required and must be an integer.',
        errors: ['iat' => ['The iat claim is required and must be an integer.']],
      );
    }

    $now = $currentTime ?? time();
    $iatInt = (int)$iat;

    if ($iatInt > ($now + $futureSkewSeconds)) {
      throw new TokenException(
        TokenException::CLOCK_SKEW,
        "iat [$iatInt] is in the future beyond allowable skew of {$futureSkewSeconds}s.",
        iat: $iatInt,
        maxSkew: $futureSkewSeconds,
      );
    }

    if (($now - $iatInt) > $maxAgeSeconds) {
      throw new TokenException(
        TokenException::EXPIRED,
        "iat [$iatInt] is older than maximum age of {$maxAgeSeconds}s.",
        iat: $iatInt,
        maxAge: $maxAgeSeconds,
      );
    }
  }

  /**
   * Validate the api:hash against the raw request body.
   *
   * @param string|null $apiHash
   * @param string $rawBody
   * @param string $algorithm
   * @throws TokenException
   */
  protected function validateApiHash(
    ?string $apiHash,
    string  $rawBody,
    string  $algorithm = 'sha256'
  ): void {
    if (!is_string($apiHash)) {
      throw new TokenException(
        TokenException::SCHEMA_VIOLATION,
        'The api:hash claim is required and must be a string.',
        errors: ['api:hash' => ['The api:hash claim is required and must be a string.']],
      );
    }

    // Validate hex format (16 to 32 bytes, corresponding to 32 to 64 hex characters)
    if (!preg_match('/^(?:[0-9a-f]{2}){16,32}$/i', $apiHash)) {
      throw new TokenException(
        TokenException::SCHEMA_VIOLATION,
        'The api:hash claim must be a valid hex digest of 16-32 bytes.',
        errors: ['api:hash' => ['The api:hash claim must be a valid hex digest of 16-32 bytes.']],
      );
    }

    $expectedHash = hash($algorithm, $rawBody);

    if (!hash_equals(strtolower($apiHash), strtolower($expectedHash))) {
      throw new TokenException(
        TokenException::MISMATCHED_HASH,
        "API hash mismatch: expected [$expectedHash] but received [$apiHash].",
        expectedHash: $expectedHash,
        actualHash: $apiHash,
      );
    }
  }
}
