<?php

declare(strict_types = 1);

use Firebase\JWT\JWT;
use SlimeSystems\Tokens\Firebase\FirebaseVerifier;
use SlimeSystems\Tokens\Firebase\KeyCache;
use SlimeSystems\Tokens\TokenException;

describe('FirebaseVerifier & KeyCache', function () {
  $privKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
  $csr = openssl_csr_new(['commonName' => 'securetoken.google.com'], $privKey);
  $cert = openssl_csr_sign($csr, null, $privKey, 365);
  openssl_x509_export($cert, $certPem);
  openssl_pkey_export($privKey, $privateKeyPem);

  $projectId = 'test-firebase-project';
  $kid = 'test-key-1';
  $now = 1700000000;

  $makeToken = function (array $overrides = [], string $key = 'test-key-1') use ($projectId, $privateKeyPem, $now): string {
    $payload = array_merge([
      'aud' => $projectId,
      'iss' => "https://securetoken.google.com/{$projectId}",
      'sub' => 'firebase-user-999',
      'auth_time' => $now - 60,
      'iat' => $now - 60,
      'exp' => $now + 3600,
      'email' => 'user@example.com',
      'email_verified' => true,
    ], $overrides);

    return JWT::encode($payload, $privateKeyPem, 'RS256', $key, ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $key]);
  };

  it('verifies a valid Firebase ID token against cached x509 certificate', function () use ($projectId, $kid, $certPem, $makeToken, $now) {
    $token = $makeToken();

    $keyCache = new KeyCache();
    $keyCache->setKeys([$kid => $certPem], ttl: 3600, currentTime: $now);

    $verifier = new FirebaseVerifier(projectId: $projectId, keyCache: $keyCache);
    $claims = $verifier->verify($token, currentTime: $now);

    expect($claims['sub'])->toBe('firebase-user-999')
      ->and($claims['email'])->toBe('user@example.com')
      ->and($claims['email_verified'])->toBeTrue();
  });

  it('rejects token with mismatched audience', function () use ($projectId, $kid, $certPem, $makeToken, $now) {
    $token = $makeToken(['aud' => 'different-project']);

    $keyCache = new KeyCache();
    $keyCache->setKeys([$kid => $certPem], currentTime: $now);

    $verifier = new FirebaseVerifier(projectId: $projectId, keyCache: $keyCache);
    expect(fn() => $verifier->verify($token, currentTime: $now))
      ->toThrow(fn(TokenException $e) => expect($e->identifier)->toBe(TokenException::SCHEMA_VIOLATION));
  });

  it('rejects token with expired exp claim', function () use ($projectId, $kid, $certPem, $makeToken, $now) {
    $token = $makeToken(['exp' => $now - 100]);

    $keyCache = new KeyCache();
    $keyCache->setKeys([$kid => $certPem], currentTime: $now);

    $verifier = new FirebaseVerifier(projectId: $projectId, keyCache: $keyCache);
    expect(fn() => $verifier->verify($token, currentTime: $now))
      ->toThrow(fn(TokenException $e) => expect($e->identifier)->toBe(TokenException::EXPIRED));
  });

  it('throws TokenException::KEY_NOT_FOUND when token kid is not in certificate distribution', function () use ($projectId, $certPem, $makeToken, $now) {
    $token = $makeToken([], key: 'unknown_kid');

    $keyCache = new KeyCache();
    $keyCache->setKeys(['other_kid' => $certPem], currentTime: $now);

    $verifier = new FirebaseVerifier(projectId: $projectId, keyCache: $keyCache);
    expect(fn() => $verifier->verify($token, currentTime: $now))
      ->toThrow(fn(TokenException $e) => expect($e->identifier)->toBe(TokenException::KEY_NOT_FOUND));
  });

  it('handles KeyCache http fetching and cache expiration correctly', function () use ($certPem) {
    $fetchCount = 0;
    $now = 1000;

    $keyCache = new KeyCache(
      keyDistributionUrl: 'https://mock.google.certs',
      httpFetcher: function () use (&$fetchCount, $certPem) {
        $fetchCount++;
        return [
          'body' => json_encode(['mock_kid' => $certPem]),
          'headers' => [
            'cache-control' => 'public, max-age=1200, must-revalidate',
            'age' => '200',
          ],
        ];
      }
    );

    expect($keyCache->fetchKey('mock_kid', currentTime: $now))->toBe($certPem)
      ->and($fetchCount)->toBe(1);

    // Within TTL (1200 - 200 = 1000s) -> should hit cache
    expect($keyCache->fetchKey('mock_kid', currentTime: $now + 500))->toBe($certPem)
      ->and($fetchCount)->toBe(1);

    // After TTL expired -> should refetch
    expect($keyCache->fetchKey('mock_kid', currentTime: $now + 1050))->toBe($certPem)
      ->and($fetchCount)->toBe(2);
  });

  it('allows handleResult to return custom transformed object or throw in FirebaseVerifier', function () use ($projectId, $kid, $certPem, $makeToken, $now) {
    $token = $makeToken(['sub' => 'uid-42']);

    $keyCache = new KeyCache();
    $keyCache->setKeys([$kid => $certPem], currentTime: $now);

    $verifier = new FirebaseVerifier(projectId: $projectId, keyCache: $keyCache, handleResult: fn(array $c) => "User: {$c['sub']}");
    expect($verifier->verify($token, currentTime: $now))->toBe('User: uid-42');

    $throwing = new FirebaseVerifier(projectId: $projectId, keyCache: $keyCache, handleResult: fn() => throw new DomainException('Banned user'));
    expect(fn() => $throwing->verify($token, currentTime: $now))
      ->toThrow(DomainException::class, 'Banned user');
  });
});
