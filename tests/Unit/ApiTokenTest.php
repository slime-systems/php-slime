<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use SlimeSystems\Tokens\Api\ApiTokenIssuer;
use SlimeSystems\Tokens\Api\ApiTokenVerifier;
use SlimeSystems\Tokens\Exceptions\IatClockSkewException;
use SlimeSystems\Tokens\Exceptions\InvalidTokenException;
use SlimeSystems\Tokens\Exceptions\KeyNotFoundException;
use SlimeSystems\Tokens\Exceptions\MismatchedApiHashException;
use SlimeSystems\Tokens\Exceptions\TokenExpiredException;
use SlimeSystems\Tokens\Exceptions\TokenSchemaViolationException;

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

    it('throws MismatchedApiHashException when body is tampered with', function () use ($secret, $keyId, $keys) {
        $originalBody = json_encode(['command' => 'fund/transfer', 'amount' => 100]);
        $tamperedBody = json_encode(['command' => 'fund/transfer', 'amount' => 1000000]);

        $token = ApiTokenIssuer::issue($keyId, $secret, $originalBody);
        $verifier = new ApiTokenVerifier($keys);

        expect(fn() => $verifier->verify($token, $tamperedBody))
            ->toThrow(MismatchedApiHashException::class);
    });

    it('throws TokenExpiredException when iat is older than maxAgeSeconds', function () use ($secret, $keyId, $keys) {
        $body = 'hello';
        $now = 1000000;
        $oldIat = $now - 301; // older than 300s

        $token = ApiTokenIssuer::issue($keyId, $secret, $body, iat: $oldIat);
        $verifier = new ApiTokenVerifier($keys, maxAgeSeconds: 300);

        expect(fn() => $verifier->verify($token, $body, currentTime: $now))
            ->toThrow(TokenExpiredException::class);
    });

    it('throws IatClockSkewException when iat is in the future beyond allowable skew', function () use ($secret, $keyId, $keys) {
        $body = 'hello';
        $now = 1000000;
        $futureIat = $now + 35; // beyond 30s skew

        $token = ApiTokenIssuer::issue($keyId, $secret, $body, iat: $futureIat);
        $verifier = new ApiTokenVerifier($keys, futureSkewSeconds: 30);

        expect(fn() => $verifier->verify($token, $body, currentTime: $now))
            ->toThrow(IatClockSkewException::class);
    });

    it('throws KeyNotFoundException when kid does not match any registered key', function () use ($secret) {
        $body = 'test';
        $token = ApiTokenIssuer::issue('unknown_kid', $secret, $body);

        $verifier = new ApiTokenVerifier(['registered_kid' => $secret]);

        expect(fn() => $verifier->verify($token, $body))
            ->toThrow(KeyNotFoundException::class);
    });

    it('supports dynamic fetchSecret callback', function () use ($secret, $keyId) {
        $body = 'test';
        $token = ApiTokenIssuer::issue($keyId, $secret, $body);

        $fetchCount = 0;
        $verifier = new ApiTokenVerifier(function (string $kid, array $header) use (&$fetchCount, $keyId, $secret) {
            $fetchCount++;
            return $kid === $keyId ? $secret : null;
        });

        $claims = $verifier->verify($token, $body);
        expect($claims)->toBeArray();
        expect($fetchCount)->toBe(1);
    });

    it('integrates seamlessly with Laravel Request instances', function () use ($secret, $keyId, $keys) {
        $payload = json_encode(['action' => 'process']);
        $request = Request::create('/api/system', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $token = ApiTokenIssuer::issueForRequest($keyId, $secret, $request, ['aud' => 'my-service']);
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $verifier = new ApiTokenVerifier($keys);
        $claims = $verifier->verify($request);

        expect($claims['aud'])->toBe('my-service');
        expect($claims['api:hash'])->toBe(hash('sha256', $payload));
    });

    it('supports fallback to request query or input parameter', function () use ($secret, $keyId, $keys) {
        $payload = 'direct-payload';
        $token = ApiTokenIssuer::issue($keyId, $secret, $payload);

        $request = Request::create('/api/entry', 'POST', ['request' => $token], [], [], [], $payload);

        $verifier = new ApiTokenVerifier($keys);
        $claims = $verifier->verify($request);

        expect($claims['api:hash'])->toBe(hash('sha256', $payload));
    });

    it('supports custom payload validation callback', function () use ($secret, $keyId, $keys) {
        $body = 'custom';
        $token = ApiTokenIssuer::issue($keyId, $secret, $body, ['role' => 'user']);

        $verifier = new ApiTokenVerifier(
            fetchSecret: $keys,
            validatePayload: function (array $claims) {
                if (($claims['role'] ?? null) !== 'admin') {
                    throw new TokenSchemaViolationException(['role' => ['Admin role required']]);
                }
                return $claims;
            }
        );

        expect(fn() => $verifier->verify($token, $body))
            ->toThrow(TokenSchemaViolationException::class);
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

    it('throws InvalidTokenException on malformed token strings or signature failure', function () use ($keys) {
        $verifier = new ApiTokenVerifier($keys);

        expect(fn() => $verifier->verify('not.a.valid.jwt', ''))
            ->toThrow(InvalidTokenException::class);
    });
});
