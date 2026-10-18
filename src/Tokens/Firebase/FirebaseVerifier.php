<?php

declare(strict_types = 1);

namespace SlimeSystems\Tokens\Firebase;

use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use InvalidArgumentException;
use SlimeSystems\Tokens\Exceptions\InvalidTokenException;
use SlimeSystems\Tokens\Exceptions\TokenException;
use SlimeSystems\Tokens\Exceptions\TokenExpiredException;
use SlimeSystems\Tokens\Exceptions\TokenSchemaViolationException;
use stdClass;
use UnexpectedValueException;

class FirebaseVerifier {
  protected KeyCache $keyCache;
  protected string $issuer;

  /**
   * @param string $projectId Firebase project ID (aud)
   * @param string|null $issuer Expected issuer (defaults to https://securetoken.google.com/{projectId})
   * @param KeyCache|null $keyCache Optional custom or pre-configured KeyCache
   * /** @var callable|null
   */
  protected $handleResult;

  /**
   * @param string $projectId Firebase project ID (aud)
   * @param string|null $issuer Expected issuer (defaults to https://securetoken.google.com/{projectId})
   * @param KeyCache|null $keyCache Optional custom or pre-configured KeyCache
   * @param int $leewaySeconds Permissible clock skew leeway (default: 30)
   * @param callable|null $handleResult Optional callback (array $claims, array $header): mixed
   */
  public function __construct(
    protected readonly string $projectId,
    ?string                   $issuer = null,
    ?KeyCache                 $keyCache = null,
    protected readonly int    $leewaySeconds = 30,
    ?callable                 $handleResult = null
  ) {
    $this->issuer = $issuer ?? "https://securetoken.google.com/{$this->projectId}";
    $this->keyCache = $keyCache ?? new KeyCache();
    $this->handleResult = $handleResult;
  }

  /**
   * Verify a Firebase ID Token.
   *
   * @param string $token
   * @param int|null $currentTime
   * @return mixed Decoded verified token claims or value returned by handleResult
   * @throws TokenException
   */
  public function verify(string $token, ?int $currentTime = null): mixed {
    $now = $currentTime ?? time();
    $header = $this->extractHeader($token);

    $alg = $header['alg'] ?? null;
    if ($alg !== 'RS256') {
      throw new InvalidTokenException("Invalid Firebase token algorithm [{$alg}]. Expected RS256.");
    }

    $kid = $header['kid'] ?? null;
    if (!is_string($kid) || $kid === '') {
      throw new TokenSchemaViolationException(['kid' => ['The kid header is required for Firebase tokens.']]);
    }

    $certPem = $this->keyCache->fetchKey($kid, $now);

    try {
      $headersObj = new stdClass();
      JWT::$leeway = $this->leewaySeconds;
      if ($currentTime !== null) {
        JWT::$timestamp = $currentTime;
      }
      $decoded = JWT::decode($token, new Key($certPem, 'RS256'), $headersObj);
      $claims = json_decode(json_encode($decoded), true);
    } catch (ExpiredException|BeforeValidException $e) {
      throw new TokenExpiredException($e->getMessage(), (int)$e->getCode(), $e);
    } catch (SignatureInvalidException|UnexpectedValueException|InvalidArgumentException|DomainException $e) {
      throw new InvalidTokenException("Firebase token verification failed: {$e->getMessage()}", (int)$e->getCode(), $e);
    } finally {
      if ($currentTime !== null) {
        JWT::$timestamp = null;
      }
    }

    $this->validateClaims($claims, $now);

    if ($this->handleResult !== null) {
      return ($this->handleResult)($claims, $header);
    }

    return $claims;
  }

  /**
   * Extract unverified header from token string.
   */
  protected function extractHeader(string $token): array {
    $parts = explode('.', trim($token));
    if (count($parts) !== 3) {
      throw new InvalidTokenException('Invalid JWT structure: token must have 3 segments.');
    }

    $headerJson = JWT::urlsafeB64Decode($parts[0]);
    $header = json_decode($headerJson, true);

    if (!is_array($header)) {
      throw new InvalidTokenException('Invalid JWT header format.');
    }

    return $header;
  }

  /**
   * Validate Firebase specific claims according to Google guidelines.
   */
  protected function validateClaims(array $claims, int $now): void {
    $aud = $claims['aud'] ?? null;
    if ($aud !== $this->projectId) {
      throw new TokenSchemaViolationException([
        'aud' => ["Audience mismatch: expected [{$this->projectId}] but got [{$aud}]."],
      ]);
    }

    $iss = $claims['iss'] ?? null;
    if ($iss !== $this->issuer) {
      throw new TokenSchemaViolationException([
        'iss' => ["Issuer mismatch: expected [{$this->issuer}] but got [{$iss}]."],
      ]);
    }

    $sub = $claims['sub'] ?? null;
    if (!is_string($sub) || $sub === '') {
      throw new TokenSchemaViolationException([
        'sub' => ['The sub claim must be a non-empty string representing the user UID.'],
      ]);
    }

    $authTime = $claims['auth_time'] ?? null;
    if (!is_numeric($authTime) || (int)$authTime > ($now + $this->leewaySeconds)) {
      throw new TokenExpiredException('auth_time must not be in the future beyond allowable leeway.');
    }

    $iat = $claims['iat'] ?? null;
    if (!is_numeric($iat) || (int)$iat > ($now + $this->leewaySeconds)) {
      throw new TokenExpiredException('iat must not be in the future beyond allowable leeway.');
    }

    $exp = $claims['exp'] ?? null;
    if (!is_numeric($exp) || (int)$exp < ($now - $this->leewaySeconds)) {
      throw new TokenExpiredException('Firebase token has expired.');
    }
  }

  public function getKeyCache(): KeyCache {
    return $this->keyCache;
  }
}
