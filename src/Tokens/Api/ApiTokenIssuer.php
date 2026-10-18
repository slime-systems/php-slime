<?php

declare(strict_types=1);

namespace SlimeSystems\Tokens\Api;

use Firebase\JWT\JWT;
use Illuminate\Http\Request;

class ApiTokenIssuer
{
    /**
     * Issue a signed JWT token with kid header, iat, and optional api:hash body binding.
     *
     * @param string $keyId The key identifier (kid)
     * @param string $secret The shared secret
     * @param string $rawBody The raw HTTP request body to hash
     * @param array $extraClaims Additional claims to embed in payload
     * @param string $algorithm Signing algorithm (default: 'HS256')
     * @param int|null $iat Issued-at timestamp (defaults to current time)
     * @param bool $includeApiHash Whether to compute and include api:hash (default: true)
     * @param string $hashAlgorithm Hashing algorithm for api:hash (default: 'sha256')
     * @return string Signed JWT string
     */
    public static function issue(
        string $keyId,
        string $secret,
        string $rawBody = '',
        array $extraClaims = [],
        string $algorithm = 'HS256',
        ?int $iat = null,
        bool $includeApiHash = true,
        string $hashAlgorithm = 'sha256'
    ): string {
        $payload = array_merge([
            'iat' => $iat ?? time(),
        ], $extraClaims);

        if ($includeApiHash) {
            $payload['api:hash'] = hash($hashAlgorithm, $rawBody);
        }

        return JWT::encode(
            payload: $payload,
            key: $secret,
            alg: $algorithm,
            keyId: $keyId,
            head: [
                'typ' => 'JWT',
                'alg' => $algorithm,
                'kid' => $keyId,
            ]
        );
    }

    /**
     * Issue a signed JWT token bound to a Laravel Request instance.
     *
     * @param string $keyId
     * @param string $secret
     * @param Request $request
     * @param array $extraClaims
     * @param string $algorithm
     * @param int|null $iat
     * @return string
     */
    public static function issueForRequest(
        string $keyId,
        string $secret,
        Request $request,
        array $extraClaims = [],
        string $algorithm = 'HS256',
        ?int $iat = null
    ): string {
        return self::issue(
            keyId: $keyId,
            secret: $secret,
            rawBody: $request->getContent(),
            extraClaims: $extraClaims,
            algorithm: $algorithm,
            iat: $iat
        );
    }
}
