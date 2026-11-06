<?php

declare(strict_types = 1);

namespace SlimeSystems\Tokens\Api;

use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use SlimeSystems\Tokens\TokenException;
use SlimeSystems\Tokens\ValidatesTokenPayload;
use stdClass;
use UnexpectedValueException;

class ApiTokenVerifier {
  use ValidatesTokenPayload;

  /** @var callable */
  protected $fetchSecret;

  /** @var callable|null */
  protected $handleResult;

  /**
   * @param callable|array $fetchSecret Callable (string $kid, array $header): ?string, or array of ['kid' => 'secret']
   * @param int $maxAgeSeconds Maximum token age in seconds (default: 300)
   * @param int $futureSkewSeconds Permissible future clock skew in seconds (default: 30)
   * @param string $hashAlgorithm Hashing algorithm for api:hash (default: 'sha256')
   * @param bool $requireApiHash Whether the api:hash claim is required (default: true)
   * @param callable|null $handleResult Optional result handler callable (array $claims, array $header): mixed
   * @param array $allowedAlgorithms Allowed JWT algorithms (default: ['HS256'])
   */
  public function __construct(
    callable|array            $fetchSecret,
    protected readonly int    $maxAgeSeconds = 300,
    protected readonly int    $futureSkewSeconds = 30,
    protected readonly string $hashAlgorithm = 'sha256',
    protected readonly bool   $requireApiHash = true,
    ?callable                 $handleResult = null,
    protected readonly array  $allowedAlgorithms = ['HS256'],
  ) {
    if (is_array($fetchSecret)) {
      $this->fetchSecret = fn(string $kid) => $fetchSecret[$kid] ?? null;
    } else {
      $this->fetchSecret = $fetchSecret;
    }

    $this->handleResult = $handleResult;
  }

  /**
   * Verify a token against a request or explicit body string.
   *
   * @param string|Request $tokenOrRequest Token string or Laravel HTTP Request
   * @param string|Request|null $bodyOrRequest Raw body string or Laravel HTTP Request (if not extracted from first argument)
   * @param int|null $currentTime Optional fixed timestamp for testing
   * @return mixed Decoded claims or value returned by handleResult
   * @throws TokenException
   */
  public function verify(
    string|Request      $tokenOrRequest,
    string|Request|null $bodyOrRequest = null,
    ?int                $currentTime = null
  ): mixed {
    $token = $this->extractToken($tokenOrRequest);
    $rawBody = $this->extractRawBody($bodyOrRequest ?? $tokenOrRequest);

    return $this->verifyTokenAndBody($token, $rawBody, $currentTime);
  }

  /**
   * Extract token from string or Request.
   * @throws TokenException
   */
  public function extractToken(string|Request $tokenOrRequest): string {
    if (is_string($tokenOrRequest)) {
      return trim($tokenOrRequest);
    }

    $bearer = $tokenOrRequest->bearerToken();
    if ($bearer !== null && $bearer !== '') {
      return $bearer;
    }

    // Support fallback to input('request')
    $paramToken = $tokenOrRequest->input('request');
    if (is_string($paramToken) && $paramToken !== '') {
      return trim($paramToken);
    }

    throw new TokenException(TokenException::INVALID, 'Bearer token was not provided in request.');
  }

  /**
   * Extract raw body from string or Request.
   */
  public function extractRawBody(string|Request|null $bodyOrRequest): string {
    if ($bodyOrRequest === null) {
      return '';
    }

    if (is_string($bodyOrRequest)) {
      return $bodyOrRequest;
    }

    return $bodyOrRequest->getContent();
  }

  /**
   * Decode and verify a raw token string with an explicit raw body.
   *
   * @param string $token
   * @param string $rawBody
   * @param int|null $currentTime
   * @return mixed
   * @throws TokenException
   */
  public function verifyTokenAndBody(string $token, string $rawBody = '', ?int $currentTime = null): mixed {
    $header = $this->extractHeader($token);

    $alg = $header['alg'] ?? null;
    if (!in_array($alg, $this->allowedAlgorithms, true)) {
      throw new TokenException(TokenException::INVALID, "Algorithm [$alg] is not supported or allowed.");
    }

    $kid = $header['kid'] ?? null;
    if (!is_string($kid) || $kid === '') {
      throw new TokenException(
        TokenException::SCHEMA_VIOLATION,
        'The kid header is required and must be a non-empty string.',
        errors: ['kid' => ['The kid header is required and must be a non-empty string.']]
      );
    }

    $secret = ($this->fetchSecret)($kid, $header);
    if ($secret === null || $secret === '') {
      throw new TokenException(TokenException::KEY_NOT_FOUND, "API Key with kid [$kid] was not found.");
    }

    try {
      $headersObj = new stdClass();
      $decoded = JWT::decode($token, new Key($secret, $alg), $headersObj);
      $claims = json_decode(json_encode($decoded), true);
    } catch (ExpiredException|BeforeValidException $e) {
      throw new TokenException(TokenException::EXPIRED, $e->getMessage(), (int)$e->getCode(), $e);
    } catch (SignatureInvalidException|UnexpectedValueException|InvalidArgumentException|DomainException $e) {
      throw new TokenException(TokenException::INVALID, $e->getMessage(), (int)$e->getCode(), $e);
    }

    // Validate iat
    $this->validateIat(
      iat: $claims['iat'] ?? null,
      maxAgeSeconds: $this->maxAgeSeconds,
      futureSkewSeconds: $this->futureSkewSeconds,
      currentTime: $currentTime
    );

    // Validate api:hash
    if ($this->requireApiHash) {
      $this->validateApiHash(
        apiHash: $claims['api:hash'] ?? null,
        rawBody: $rawBody,
        algorithm: $this->hashAlgorithm
      );
    }

    // Run custom result handler if configured
    if ($this->handleResult !== null) {
      return ($this->handleResult)($claims, $header);
    }

    return $claims;
  }

  /**
   * Inspect and parse the unverified JWT header.
   * @throws TokenException
   */
  protected function extractHeader(string $token): array {
    $segments = explode('.', $token);
    if (count($segments) !== 3) {
      throw new TokenException(TokenException::INVALID, 'Invalid JWT structure: token must contain 3 segments.');
    }

    $headerJson = JWT::urlsafeB64Decode($segments[0]);
    $header = json_decode($headerJson, true);

    if (!is_array($header)) {
      throw new TokenException(TokenException::INVALID, 'Invalid JWT header format.');
    }

    return $header;
  }
}
