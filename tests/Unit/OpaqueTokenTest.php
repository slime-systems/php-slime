<?php

declare(strict_types=1);

use SlimeSystems\Tokens\Exceptions\InvalidTokenException;
use SlimeSystems\Tokens\Exceptions\KeyNotFoundException;
use SlimeSystems\Tokens\State\ContinuationToken;
use SlimeSystems\Tokens\State\OpaqueToken;

describe('OpaqueToken & ContinuationToken (PASETO v4.local)', function () {
    $secret = 'super-secret-system-encryption-key-32!';
    $keyId = 'sys_key_1';

    it('encrypts and decrypts payload in round trip using PASETO v4.local', function () use ($secret, $keyId) {
        $opaque = new OpaqueToken([$keyId => $secret], defaultKeyId: $keyId);
        $payload = ['project_id' => 42, 'ticket' => 'tk100', 'action' => 'download'];

        $token = $opaque->encrypt($payload, keyId: $keyId);

        expect($token)->toBeString();
        expect($token)->toStartWith('v4.local.');
        expect($opaque->peekFooter($token))->toBe($keyId);

        $decrypted = $opaque->decrypt($token);
        expect($decrypted)->toMatchArray($payload);
    });

    it('detects ciphertext tampering and rejects with InvalidTokenException', function () use ($secret) {
        $opaque = new OpaqueToken($secret);
        $token = $opaque->encrypt(['data' => 'confidential']);

        // Tamper with a character in the token payload section
        $parts = explode('.', $token);
        $tamperedPayload = substr_replace($parts[2], 'X', 10, 1);
        $parts[2] = $tamperedPayload;
        $tampered = implode('.', $parts);

        expect(fn() => $opaque->decrypt($tampered))
            ->toThrow(InvalidTokenException::class);
    });

    it('detects footer / key ID tampering and rejects with KeyNotFoundException or InvalidTokenException', function () use ($secret) {
        $opaque = new OpaqueToken([
            'k1' => $secret,
            'k2' => 'another-secret-key-32-bytes-long!',
        ]);
        $token = $opaque->encrypt(['data' => 'confidential'], keyId: 'k1');

        // Swap the footer from k1 to k2
        $parts = explode('.', $token);
        $parts[3] = rtrim(strtr(base64_encode('k2'), '+/', '-_'), '=');
        $tampered = implode('.', $parts);

        // Decrypting with k2's key will fail authentication
        expect(fn() => $opaque->decrypt($tampered))
            ->toThrow(InvalidTokenException::class);
    });

    it('throws KeyNotFoundException when keyId does not exist', function () use ($secret) {
        $opaque = new OpaqueToken(['k1' => $secret]);
        expect(fn() => $opaque->encrypt(['test' => 1], keyId: 'unknown_k'))
            ->toThrow(KeyNotFoundException::class);
    });

    it('issues and decodes ContinuationToken cursors with subject verification', function () use ($secret, $keyId) {
        $cursorManager = new ContinuationToken([$keyId => $secret], defaultKeyId: $keyId);

        $cursor = [
            'from' => '60f1b2c3d4e5f6a1b2c3d4e5',
            'inclusive' => false,
            'project_id' => 99,
        ];

        $token = $cursorManager->issue('trading-document/stream', $cursor);
        expect($token)->toBeString();
        expect($token)->toStartWith('v4.local.');

        $decodedData = $cursorManager->decode($token, 'trading-document/stream');
        expect($decodedData)->toMatchArray($cursor);
    });

    it('rejects ContinuationToken if subject does not match expected subject', function () use ($secret) {
        $cursorManager = new ContinuationToken($secret);
        $token = $cursorManager->issue('stream-A', ['id' => 1]);

        expect(fn() => $cursorManager->decode($token, 'stream-B'))
            ->toThrow(InvalidTokenException::class);
    });

    it('allows fetchSecret and handleResult to throw custom exceptions in OpaqueToken', function () use ($secret, $keyId) {
        $opaque = new OpaqueToken([$keyId => $secret], defaultKeyId: $keyId);
        $token = $opaque->encrypt(['data' => 'sample'], keyId: $keyId);

        $throwingFetcher = new OpaqueToken(function () {
            throw new \UnderflowException('Database connection lost');
        });

        expect(fn() => $throwingFetcher->decrypt($token))
            ->toThrow(\UnderflowException::class, 'Database connection lost');

        // Test handleResult in decrypt
        $result = $opaque->decrypt($token, handleResult: function (array $claims) {
            return strtoupper($claims['data']);
        });
        expect($result)->toBe('SAMPLE');

        expect(fn() => $opaque->decrypt($token, handleResult: function () {
            throw new \LogicException('Permission denied');
        }))->toThrow(\LogicException::class, 'Permission denied');
    });
});
