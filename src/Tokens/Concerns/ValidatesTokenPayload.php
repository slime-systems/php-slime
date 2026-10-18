<?php

declare(strict_types = 1);

namespace SlimeSystems\Tokens\Concerns;

use SlimeSystems\Tokens\Exceptions\IatClockSkewException;
use SlimeSystems\Tokens\Exceptions\MismatchedApiHashException;
use SlimeSystems\Tokens\Exceptions\TokenExpiredException;
use SlimeSystems\Tokens\Exceptions\TokenSchemaViolationException;

trait ValidatesTokenPayload {
  /**
   * Validate the issued-at (iat) timestamp for expiration and clock skew.
   *
   * @param int|float|null $iat
   * @param int $maxAgeSeconds Maximum age in seconds (default 300)
   * @param int $futureSkewSeconds Allowed future clock skew in seconds (default 30)
   * @param int|null $currentTime Fixed current timestamp for testing
   * @throws IatClockSkewException|TokenExpiredException|TokenSchemaViolationException
   */
  protected function validateIat(
    int|float|null $iat,
    int            $maxAgeSeconds = 300,
    int            $futureSkewSeconds = 30,
    ?int           $currentTime = null
  ): void {
    if ($iat === null || !is_numeric($iat)) {
      throw new TokenSchemaViolationException(['iat' => ['The iat claim is required and must be an integer.']]);
    }

    $now = $currentTime ?? time();
    $iatInt = (int)$iat;

    if ($iatInt > ($now + $futureSkewSeconds)) {
      throw new IatClockSkewException("iat [{$iatInt}] is in the future beyond allowable skew of {$futureSkewSeconds}s.");
    }

    if (($now - $iatInt) > $maxAgeSeconds) {
      throw new TokenExpiredException("iat [{$iatInt}] is older than maximum age of {$maxAgeSeconds}s.");
    }
  }

  /**
   * Validate the api:hash against the raw request body.
   *
   * @param string|null $apiHash
   * @param string $rawBody
   * @param string $algorithm
   * @throws MismatchedApiHashException|TokenSchemaViolationException
   */
  protected function validateApiHash(
    ?string $apiHash,
    string  $rawBody,
    string  $algorithm = 'sha256'
  ): void {
    if ($apiHash === null || !is_string($apiHash)) {
      throw new TokenSchemaViolationException(['api:hash' => ['The api:hash claim is required and must be a string.']]);
    }

    // Validate hex format (16 to 32 bytes, corresponding to 32 to 64 hex characters)
    if (!preg_match('/^(?:[0-9a-f]{2}){16,32}$/i', $apiHash)) {
      throw new TokenSchemaViolationException(['api:hash' => ['The api:hash claim must be a valid hex digest of 16-32 bytes.']]);
    }

    $expectedHash = hash($algorithm, $rawBody);

    if (!hash_equals(strtolower($apiHash), strtolower($expectedHash))) {
      throw new MismatchedApiHashException(
        expectedHash: $expectedHash,
        actualHash: $apiHash,
        message: "API hash mismatch: expected [{$expectedHash}] but received [{$apiHash}]."
      );
    }
  }
}
