<?php

declare(strict_types = 1);

use Firebase\JWT\JWT;
use SlimeSystems\Tokens\Exceptions\KeyNotFoundException;
use SlimeSystems\Tokens\Exceptions\TokenExpiredException;
use SlimeSystems\Tokens\Exceptions\TokenSchemaViolationException;
use SlimeSystems\Tokens\Firebase\FirebaseVerifier;
use SlimeSystems\Tokens\Firebase\KeyCache;

describe('FirebaseVerifier & KeyCache', function () {
  // Generate test RSA keypair and certificate
  $dn = ['commonName' => 'securetoken.google.com'];
  $privKeyResource = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
  ]);
  $csr = openssl_csr_new($dn, $privKeyResource);
  $certResource = openssl_csr_sign($csr, null, $privKeyResource, 365);
  openssl_x509_export($certResource, $certPem);
  openssl_pkey_export($privKeyResource, $privateKeyPem);

  $projectId = 'test-firebase-project';
  $kid = 'test-key-1';

  it('verifies a valid Firebase ID token against cached x509 certificate', function () use ($projectId, $kid, $certPem, $privateKeyPem) {
    $now = 1700000000;
    $payload = [
      'aud' => $projectId,
      'iss' => "https://securetoken.google.com/{$projectId}",
      'sub' => 'firebase-user-999',
      'auth_time' => $now - 60,
      'iat' => $now - 60,
      'exp' => $now + 3600,
      'email' => 'user@example.com',
      'email_verified' => true,
    ];

    $token = JWT::encode($payload, $privateKeyPem, 'RS256', $kid, [
      'typ' => 'JWT',
      'alg' => 'RS256',
      'kid' => $kid,
    ]);

    $keyCache = new KeyCache();
    $keyCache->setKeys([$kid => $certPem], ttl: 3600, currentTime: $now);

    $verifier = new FirebaseVerifier(projectId: $projectId, keyCache: $keyCache);
    $claims = $verifier->verify($token, currentTime: $now);

    expect($claims['sub'])->toBe('firebase-user-999');
    expect($claims['email'])->toBe('user@example.com');
    expect($claims['email_verified'])->toBeTrue();
  });

  it('rejects token with mismatched audience', function () use ($projectId, $kid, $certPem, $privateKeyPem) {
    $now = 1700000000;
    $payload = [
      'aud' => 'different-project',
      'iss' => "https://securetoken.google.com/{$projectId}",
      'sub' => 'user-1',
      'auth_time' => $now - 10,
      'iat' => $now - 10,
      'exp' => $now + 3600,
    ];

    $token = JWT::encode($payload, $privateKeyPem, 'RS256', $kid, ['kid' => $kid]);

    $keyCache = new KeyCache();
    $keyCache->setKeys([$kid => $certPem], currentTime: $now);

    $verifier = new FirebaseVerifier(projectId: $projectId, keyCache: $keyCache);

    expect(fn() => $verifier->verify($token, currentTime: $now))
      ->toThrow(TokenSchemaViolationException::class);
  });

  it('rejects token with expired exp claim', function () use ($projectId, $kid, $certPem, $privateKeyPem) {
    $now = 1700000000;
    $payload = [
      'aud' => $projectId,
      'iss' => "https://securetoken.google.com/{$projectId}",
      'sub' => 'user-1',
      'auth_time' => $now - 4000,
      'iat' => $now - 4000,
      'exp' => $now - 100, // expired
    ];

    $token = JWT::encode($payload, $privateKeyPem, 'RS256', $kid, ['kid' => $kid]);

    $keyCache = new KeyCache();
    $keyCache->setKeys([$kid => $certPem], currentTime: $now);

    $verifier = new FirebaseVerifier(projectId: $projectId, keyCache: $keyCache);

    expect(fn() => $verifier->verify($token, currentTime: $now))
      ->toThrow(TokenExpiredException::class);
  });

  it('throws KeyNotFoundException when token kid is not in certificate distribution', function () use ($projectId, $certPem, $privateKeyPem) {
    $now = 1700000000;
    $token = JWT::encode([
      'aud' => $projectId,
      'iss' => "https://securetoken.google.com/{$projectId}",
      'sub' => 'user-1',
      'auth_time' => $now - 10,
      'iat' => $now - 10,
      'exp' => $now + 3600,
    ], $privateKeyPem, 'RS256', 'unknown_kid', ['kid' => 'unknown_kid']);

    $keyCache = new KeyCache();
    $keyCache->setKeys(['other_kid' => $certPem], currentTime: $now);

    $verifier = new FirebaseVerifier(projectId: $projectId, keyCache: $keyCache);

    expect(fn() => $verifier->verify($token, currentTime: $now))
      ->toThrow(KeyNotFoundException::class);
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

    $key1 = $keyCache->fetchKey('mock_kid', currentTime: $now);
    expect($key1)->toBe($certPem);
    expect($fetchCount)->toBe(1);

    // Within TTL (1200 - 200 = 1000s) -> should use cache
    $key2 = $keyCache->fetchKey('mock_kid', currentTime: $now + 500);
    expect($key2)->toBe($certPem);
    expect($fetchCount)->toBe(1);

    // After TTL expired -> should refetch
    $key3 = $keyCache->fetchKey('mock_kid', currentTime: $now + 1050);
    expect($key3)->toBe($certPem);
    expect($fetchCount)->toBe(2);
  });

  it('allows handleResult to return custom transformed object or throw in FirebaseVerifier', function () use ($projectId, $kid, $certPem, $privateKeyPem) {
    $now = 1700000000;
    $token = JWT::encode([
      'aud' => $projectId,
      'iss' => "https://securetoken.google.com/{$projectId}",
      'sub' => 'uid-42',
      'auth_time' => $now - 10,
      'iat' => $now - 10,
      'exp' => $now + 3600,
    ], $privateKeyPem, 'RS256', $kid, ['kid' => $kid]);

    $keyCache = new KeyCache();
    $keyCache->setKeys([$kid => $certPem], currentTime: $now);

    $verifier = new FirebaseVerifier(
      projectId: $projectId,
      keyCache: $keyCache,
      handleResult: fn(array $claims) => "User: {$claims['sub']}"
    );

    $result = $verifier->verify($token, currentTime: $now);
    expect($result)->toBe('User: uid-42');

    $throwingVerifier = new FirebaseVerifier(
      projectId: $projectId,
      keyCache: $keyCache,
      handleResult: function () {
        throw new \DomainException('Banned user');
      }
    );

    expect(fn() => $throwingVerifier->verify($token, currentTime: $now))
      ->toThrow(\DomainException::class, 'Banned user');
  });
});
