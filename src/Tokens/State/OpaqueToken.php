<?php

declare(strict_types = 1);

namespace SlimeSystems\Tokens\State;

use InvalidArgumentException;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Exception\PasetoException;
use ParagonIE\Paseto\Keys\SymmetricKey;
use ParagonIE\Paseto\Parser;
use ParagonIE\Paseto\Protocol\Version4;
use ParagonIE\Paseto\ProtocolCollection;
use SlimeSystems\Tokens\Exceptions\InvalidTokenException;
use SlimeSystems\Tokens\Exceptions\KeyNotFoundException;
use SlimeSystems\Tokens\Exceptions\TokenException;
use TypeError;

class OpaqueToken {
  /** @var callable */
  protected $fetchSecret;

  protected Version4 $version;
  protected ProtocolCollection $protocols;

  /**
   * @param callable|array|string $secret Callable (string $kid): ?string, array ['kid' => 'secret'], or single secret string
   * @param string|null $defaultKeyId Default key ID if not specified
   */
  public function __construct(
    callable|array|string      $secret,
    protected readonly ?string $defaultKeyId = null
  ) {
    if (is_string($secret)) {
      $this->fetchSecret = fn(?string $kid) => $secret;
    } elseif (is_array($secret)) {
      $this->fetchSecret = fn(?string $kid) => $secret[$kid ?? $this->defaultKeyId ?? array_key_first($secret)] ?? null;
    } else {
      $this->fetchSecret = $secret;
    }

    $this->version = new Version4();
    $this->protocols = new ProtocolCollection($this->version);
  }

  /**
   * Encrypt an array payload into a standard PASETO v4.local token.
   *
   * @param array $payload
   * @param string|null $keyId Key identifier to store in the token footer
   * @param string|null $implicitAssertion Optional implicit assertion (associated data bound to AEAD)
   * @return string PASETO v4.local token string
   * @throws TokenException
   */
  public function encrypt(array $payload, ?string $keyId = null, ?string $implicitAssertion = null): string {
    $kid = $keyId ?? $this->defaultKeyId;
    $secret = ($this->fetchSecret)($kid);

    if ($secret === null || $secret === '') {
      throw new KeyNotFoundException("Secret key not found for kid [{$kid}].");
    }

    $symmetricKey = new SymmetricKey($this->normalizeKey($secret));

    try {
      $builder = Builder::getLocal($symmetricKey, $this->version)
        ->setClaims($payload);

      if ($kid !== null && $kid !== '') {
        $builder->setFooter($kid);
      }

      return $builder->toString();
    } catch (PasetoException|TypeError|InvalidArgumentException $e) {
      throw new InvalidTokenException("Failed to encrypt PASETO token: {$e->getMessage()}", (int)$e->getCode(), $e);
    }
  }

  /**
   * Ensure key is exactly 32 bytes for PASETO v4 symmetric encryption.
   */
  protected function normalizeKey(string $secret): string {
    if (strlen($secret) === 32) {
      return $secret;
    }

    return hash('sha256', $secret, true);
  }

  /**
   * Decrypt and verify a PASETO v4.local token.
   *
   * @param string $token
   * @param string|null $implicitAssertion Optional implicit assertion passed during encryption
   * @param callable|null $handleResult Optional callback (array $claims, ?string $footer): mixed
   * @return mixed Decrypted claims array or value returned by handleResult
   * @throws TokenException
   */
  public function decrypt(string $token, ?string $implicitAssertion = null, ?callable $handleResult = null): mixed {
    $footer = $this->peekFooter($token);
    $kid = ($footer !== '' && $footer !== null) ? $footer : $this->defaultKeyId;

    $secret = ($this->fetchSecret)($kid);
    if ($secret === null || $secret === '') {
      throw new KeyNotFoundException("Secret key not found for kid [{$kid}].");
    }

    $symmetricKey = new SymmetricKey($this->normalizeKey($secret));

    try {
      $parser = Parser::getLocal($symmetricKey, $this->protocols);
      $parsed = $parser->parse(trim($token));

      $claims = $parsed->getClaims();
      if ($handleResult !== null) {
        return ($handleResult)($claims, $footer);
      }

      return $claims;
    } catch (PasetoException|TypeError|InvalidArgumentException $e) {
      throw new InvalidTokenException("PASETO decryption failed: {$e->getMessage()}", (int)$e->getCode(), $e);
    }
  }

  /**
   * Inspect unencrypted footer from a PASETO token string.
   */
  public function peekFooter(string $token): ?string {
    $parts = explode('.', trim($token));
    if (count($parts) === 4 && $parts[3] !== '') {
      $remainder = strlen($parts[3]) % 4;
      $padded = $remainder ? $parts[3] . str_repeat('=', 4 - $remainder) : $parts[3];
      $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
      return $decoded !== false ? $decoded : null;
    }

    return null;
  }
}
