<?php

declare(strict_types = 1);

use Illuminate\Http\Request;
use SlimeSystems\Tokens\Api\ApiTokenIssuer;
use SlimeSystems\Tokens\Api\ApiTokenVerifier;
use SlimeSystems\Tokens\TokenException;

describe('ApiTokenVerifier & ApiTokenIssuer', function () {
  $secret = 'super-secret-key-32-bytes-length!';
  $keyId = 'key1';
  $keys = [$keyId => $secret];

  it('issues and verifies a valid token bound to raw body', function () use ($secret, $keyId, $keys) {
    $body = json_encode(['command' => 'fund/deposit', 'amount' => 500]);
    $token = ApiTokenIssuer::issue($keyId, $secret, $body, ['sub' => 'system/fund']);

    $verifier = new ApiTokenVerifier($keys);
    $claims = $verifier->verify($token, $body);

    expect($claims['sub'])->toBe('system/fund');
    expect($claims['api:hash'])->toBe(hash('sha256', $body));
    expect($claims)->toHaveKey('iat');
  });

  it('throws TokenException::MISMATCHED_HASH when body is tampered with', function () use ($secret, $keyId, $keys) {
    $originalBody = json_encode(['command' => 'fund/transfer', 'amount' => 100]);
    $tamperedBody = json_encode(['command' => 'fund/transfer', 'amount' => 1000000]);

    $token = ApiTokenIssuer::issue($keyId, $secret, $originalBody);
    $verifier = new ApiTokenVerifier($keys);

    try {
      $verifier->verify($token, $tamperedBody);
      $this->fail('Expected TokenException to be thrown');
    } catch (TokenException $e) {
      expect($e->identifier)->toBe(TokenException::MISMATCHED_HASH);
    }
  });

  it('throws TokenException::EXPIRED when iat is older than maxAgeSeconds', function () use ($secret, $keyId, $keys) {
    $body = 'hello';
    $now = 1000000;
    $oldIat = $now - 301; // older than 300s

    $token = ApiTokenIssuer::issue($keyId, $secret, $body, iat: $oldIat);
    $verifier = new ApiTokenVerifier($keys, maxAgeSeconds: 300);

    try {
      $verifier->verify($token, $body, currentTime: $now);
      $this->fail('Expected TokenException to be thrown');
    } catch (TokenException $e) {
      expect($e->identifier)->toBe(TokenException::EXPIRED);
    }
  });

  it('throws TokenException::CLOCK_SKEW when iat is in the future beyond allowable skew', function () use ($secret, $keyId, $keys) {
    $body = 'hello';
    $now = 1000000;
    $futureIat = $now + 35; // beyond 30s skew

    $token = ApiTokenIssuer::issue($keyId, $secret, $body, iat: $futureIat);
    $verifier = new ApiTokenVerifier($keys, futureSkewSeconds: 30);

    try {
      $verifier->verify($token, $body, currentTime: $now);
      $this->fail('Expected TokenException to be thrown');
    } catch (TokenException $e) {
      expect($e->identifier)->toBe(TokenException::CLOCK_SKEW);
    }
  });

  it('throws TokenException::KEY_NOT_FOUND when kid does not match any registered key', function () use ($secret) {
    $body = 'test';
    $token = ApiTokenIssuer::issue('unknown_kid', $secret, $body);

    $verifier = new ApiTokenVerifier(['registered_kid' => $secret]);

    try {
      $verifier->verify($token, $body);
      $this->fail('Expected TokenException to be thrown');
    } catch (TokenException $e) {
      expect($e->identifier)->toBe(TokenException::KEY_NOT_FOUND);
    }
  });

  it('supports dynamic fetchSecret callback', function () use ($secret, $keyId) {
    $body = 'test';
    $token = ApiTokenIssuer::issue($keyId, $secret, $body);

    $calledKid = null;
    $verifier = new ApiTokenVerifier(function (string $kid) use ($secret, &$calledKid) {
      $calledKid = $kid;
      return $secret;
    });

    $claims = $verifier->verify($token, $body);
    expect($claims)->toBeArray();
    expect($calledKid)->toBe($keyId);
  });

  it('integrates seamlessly with Laravel Request instances', function () use ($secret, $keyId, $keys) {
    $body = 'request-body-payload';
    $token = ApiTokenIssuer::issue($keyId, $secret, $body, ['user' => 'alice']);

    $request = Request::create(
      uri: '/api/v1/resource',
      method: 'POST',
      server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"],
      content: $body
    );

    $verifier = new ApiTokenVerifier($keys);
    $claims = $verifier->verify($request);

    expect($claims['user'])->toBe('alice');
  });

  it('supports fallback to request query or input parameter', function () use ($secret, $keyId, $keys) {
    $body = 'fallback-payload';
    $token = ApiTokenIssuer::issue($keyId, $secret, $body);

    $request = Request::create(
      uri: '/api/v1/resource',
      method: 'POST',
      parameters: ['request' => $token],
      content: $body
    );

    $verifier = new ApiTokenVerifier($keys);
    $claims = $verifier->verify($request);

    expect($claims)->toBeArray();
  });

  it('supports custom payload validation callback', function () use ($secret, $keyId, $keys) {
    $body = 'custom';
    $token = ApiTokenIssuer::issue($keyId, $secret, $body, ['role' => 'user']);

    $verifier = new ApiTokenVerifier(
      fetchSecret: $keys,
      validatePayload: function (array $claims) {
        if (($claims['role'] ?? null) !== 'admin') {
          throw new TokenException(TokenException::SCHEMA_VIOLATION, 'Admin role required', errors: ['role' => ['Admin role required']]);
        }
        return $claims;
      }
    );

    try {
      $verifier->verify($token, $body);
      $this->fail('Expected TokenException to be thrown');
    } catch (TokenException $e) {
      expect($e->identifier)->toBe(TokenException::SCHEMA_VIOLATION);
    }
  });

  it('supports disabling api:hash requirement for standard bearer tokens', function () use ($secret, $keyId, $keys) {
    $token = ApiTokenIssuer::issue($keyId, $secret, includeApiHash: false);

    $verifier = new ApiTokenVerifier($keys, requireApiHash: false);
    $claims = $verifier->verify($token);

    expect($claims)->not->toHaveKey('api:hash');
    expect($claims)->toHaveKey('iat');
  });

  it('allows fetchSecret to throw custom domain exceptions that bubble out immediately', function () use ($secret, $keyId) {
    $token = ApiTokenIssuer::issue($keyId, $secret, 'body');

    $verifier = new ApiTokenVerifier(function (string $kid) {
      throw new \RuntimeException("Tenant revoked: [{$kid}]");
    });

    expect(fn() => $verifier->verify($token, 'body'))
      ->toThrow(\RuntimeException::class, "Tenant revoked: [{$keyId}]");
  });

  it('allows handleResult to return custom transformed values or throw custom exceptions', function () use ($secret, $keyId, $keys) {
    $body = 'payload';
    $token = ApiTokenIssuer::issue($keyId, $secret, $body, ['user_id' => 42]);

    $verifier = new ApiTokenVerifier(
      fetchSecret: $keys,
      handleResult: function (array $claims) {
        return (object)['user' => $claims['user_id']];
      }
    );

    $result = $verifier->verify($token, $body);
    expect($result)->toBeObject();
    expect($result->user)->toBe(42);

    $throwingVerifier = new ApiTokenVerifier(
      fetchSecret: $keys,
      handleResult: function () {
        throw new \DomainException('Account suspended');
      }
    );

    expect(fn() => $throwingVerifier->verify($token, $body))
      ->toThrow(\DomainException::class, 'Account suspended');
  });

  it('throws TokenException::INVALID on malformed token strings or signature failure', function () use ($keys) {
    $verifier = new ApiTokenVerifier($keys);

    try {
      $verifier->verify('not.a.valid.jwt', '');
      $this->fail('Expected TokenException to be thrown');
    } catch (TokenException $e) {
      expect($e->identifier)->toBe(TokenException::INVALID);
    }
  });
});
