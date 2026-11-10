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

    expect($claims['sub'])->toBe('system/fund')
      ->and($claims['api:hash'])->toBe(hash('sha256', $body))
      ->and($claims)->toHaveKey('iat');
  });

  it('throws TokenException::MISMATCHED_HASH when body is tampered with', function () use ($secret, $keyId, $keys) {
    $token = ApiTokenIssuer::issue($keyId, $secret, 'original-body');
    $verifier = new ApiTokenVerifier($keys);

    expect(fn() => $verifier->verify($token, 'tampered-body'))
      ->toThrow(fn(TokenException $e) => expect($e->identifier)->toBe(TokenException::MISMATCHED_HASH));
  });

  it('throws TokenException::EXPIRED when iat is older than maxAgeSeconds', function () use ($secret, $keyId, $keys) {
    $now = 1000000;
    $token = ApiTokenIssuer::issue($keyId, $secret, 'body', iat: $now - 301);
    $verifier = new ApiTokenVerifier($keys, maxAgeSeconds: 300);

    expect(fn() => $verifier->verify($token, 'body', currentTime: $now))
      ->toThrow(fn(TokenException $e) => expect($e->identifier)->toBe(TokenException::EXPIRED));
  });

  it('throws TokenException::CLOCK_SKEW when iat is in future beyond allowable skew', function () use ($secret, $keyId, $keys) {
    $now = 1000000;
    $token = ApiTokenIssuer::issue($keyId, $secret, 'body', iat: $now + 35);
    $verifier = new ApiTokenVerifier($keys, futureSkewSeconds: 30);

    expect(fn() => $verifier->verify($token, 'body', currentTime: $now))
      ->toThrow(fn(TokenException $e) => expect($e->identifier)->toBe(TokenException::CLOCK_SKEW));
  });

  it('throws TokenException::KEY_NOT_FOUND when kid does not match any registered key', function () use ($secret) {
    $token = ApiTokenIssuer::issue('unknown_kid', $secret, 'test');
    $verifier = new ApiTokenVerifier(['registered_kid' => $secret]);

    expect(fn() => $verifier->verify($token, 'test'))
      ->toThrow(fn(TokenException $e) => expect($e->identifier)->toBe(TokenException::KEY_NOT_FOUND));
  });

  it('supports dynamic fetchSecret callback and custom exceptions', function () use ($secret, $keyId) {
    $token = ApiTokenIssuer::issue($keyId, $secret, 'body');

    $calledKid = null;
    $verifier = new ApiTokenVerifier(function (string $kid) use ($secret, &$calledKid) {
      $calledKid = $kid;
      return $secret;
    });

    expect($verifier->verify($token, 'body'))->toBeArray()
      ->and($calledKid)->toBe($keyId);

    $revokingVerifier = new ApiTokenVerifier(fn(string $kid) => throw new RuntimeException("Revoked: [{$kid}]"));
    expect(fn() => $revokingVerifier->verify($token, 'body'))
      ->toThrow(RuntimeException::class, "Revoked: [{$keyId}]");
  });

  it('extracts token from Laravel Request header or query/input fallback', function () use ($secret, $keyId, $keys) {
    $body = 'request-body-payload';
    $token = ApiTokenIssuer::issue($keyId, $secret, $body, ['user' => 'alice']);
    $verifier = new ApiTokenVerifier($keys);

    // 1. Authorization Bearer header
    $headerReq = Request::create('/api', 'POST', server: ['HTTP_AUTHORIZATION' => "Bearer {$token}"], content: $body);
    expect($verifier->verify($headerReq)['user'])->toBe('alice');

    // 2. Query/input fallback
    $queryReq = Request::create('/api', 'POST', parameters: ['request' => $token], content: $body);
    expect($verifier->verify($queryReq)['user'])->toBe('alice');
  });

  it('supports disabling api:hash requirement for standard bearer tokens', function () use ($secret, $keyId, $keys) {
    $token = ApiTokenIssuer::issue($keyId, $secret, includeApiHash: false);
    $verifier = new ApiTokenVerifier($keys, requireApiHash: false);
    $claims = $verifier->verify($token);

    expect($claims)->not->toHaveKey('api:hash')
      ->and($claims)->toHaveKey('iat');
  });

  it('allows handleResult to return custom transformed values or throw custom exceptions', function () use ($secret, $keyId, $keys) {
    $token = ApiTokenIssuer::issue($keyId, $secret, 'payload', ['user_id' => 42]);

    $transformer = new ApiTokenVerifier($keys, handleResult: fn(array $c) => (object)['user' => $c['user_id']]);
    expect($transformer->verify($token, 'payload')->user)->toBe(42);

    $thrower = new ApiTokenVerifier($keys, handleResult: fn() => throw new DomainException('Account suspended'));
    expect(fn() => $thrower->verify($token, 'payload'))
      ->toThrow(DomainException::class, 'Account suspended');
  });

  it('throws TokenException::INVALID on malformed token strings', function () use ($keys) {
    $verifier = new ApiTokenVerifier($keys);
    expect(fn() => $verifier->verify('not.a.valid.jwt', ''))
      ->toThrow(fn(TokenException $e) => expect($e->identifier)->toBe(TokenException::INVALID));
  });
});
