<?php

declare(strict_types=1);

namespace SlimeSystems\Tokens\Firebase;

use SlimeSystems\Tokens\Exceptions\KeyNotFoundException;
use SlimeSystems\Tokens\Exceptions\TokenException;
use Throwable;

class KeyCache
{
    public const DEFAULT_KEY_DISTRIBUTION_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    protected ?array $cachedKeys = null;
    protected ?int $expiresAt = null;

    /** @var callable|null */
    protected $httpFetcher;

    /**
     * @param string $keyDistributionUrl
     * @param callable|null $httpFetcher Optional callable (string $url): array{body: string, headers: array}
     */
    public function __construct(
        protected readonly string $keyDistributionUrl = self::DEFAULT_KEY_DISTRIBUTION_URL,
        ?callable $httpFetcher = null
    ) {
        $this->httpFetcher = $httpFetcher;
    }

    /**
     * Fetch public certificate for a given key ID.
     *
     * @param string $kid
     * @param int|null $currentTime
     * @return string Certificate PEM string
     * @throws KeyNotFoundException|TokenException
     */
    public function fetchKey(string $kid, ?int $currentTime = null): string
    {
        $keys = $this->getAllKeys($currentTime);

        if (!isset($keys[$kid])) {
            throw new KeyNotFoundException("Firebase key [{$kid}] not found in certificate distribution.");
        }

        return $keys[$kid];
    }

    /**
     * Get all cached public certificates, refreshing if expired.
     */
    public function getAllKeys(?int $currentTime = null): array
    {
        $now = $currentTime ?? time();

        if ($this->cachedKeys !== null && $this->expiresAt !== null && $now < $this->expiresAt) {
            return $this->cachedKeys;
        }

        [$keys, $ttl] = $this->fetchFromRemote($now);
        $this->cachedKeys = $keys;
        $this->expiresAt = $now + $ttl;

        return $this->cachedKeys;
    }

    /**
     * Set explicit keys for testing or pre-primed cache.
     */
    public function setKeys(array $keys, int $ttl = 3600, ?int $currentTime = null): void
    {
        $this->cachedKeys = $keys;
        $this->expiresAt = ($currentTime ?? time()) + $ttl;
    }

    /**
     * Clear cached certificates.
     */
    public function clear(): void
    {
        $this->cachedKeys = null;
        $this->expiresAt = null;
    }

    /**
     * Fetch keys from remote URL and determine TTL from headers.
     *
     * @return array{0: array<string, string>, 1: int}
     */
    protected function fetchFromRemote(int $currentTime): array
    {
        if ($this->httpFetcher !== null) {
            $response = ($this->httpFetcher)($this->keyDistributionUrl);
            $body = $response['body'] ?? '';
            $headers = $response['headers'] ?? [];
        } else {
            [$body, $headers] = $this->defaultFetch($this->keyDistributionUrl);
        }

        try {
            $keys = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new TokenException("Failed to decode Firebase certificate keys: {$e->getMessage()}", (int)$e->getCode(), $e);
        }

        if (!is_array($keys) || empty($keys)) {
            throw new TokenException('Empty or invalid certificate payload from Firebase key distribution.');
        }

        $ttl = $this->determineTtl($headers);

        return [$keys, $ttl];
    }

    protected function defaultFetch(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'follow_location' => 1,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new TokenException("Failed to connect to Firebase key distribution URL [{$url}].");
        }

        $headers = [];
        $responseHeaders = $http_response_header ?? [];
        foreach ($responseHeaders as $headerLine) {
            if (str_contains($headerLine, ':')) {
                [$k, $v] = explode(':', $headerLine, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        return [$body, $headers];
    }

    /**
     * Parse Cache-Control max-age and Age header.
     */
    protected function determineTtl(array $headers): int
    {
        $cacheControl = $headers['cache-control'] ?? '';
        $maxAge = 3600; // default 1 hour fallback

        if (preg_match('/max-age=(\d+)/i', $cacheControl, $matches)) {
            $maxAge = (int)$matches[1];
        }

        $age = isset($headers['age']) ? (int)$headers['age'] : 0;
        $ttl = max(60, $maxAge - $age);

        return $ttl;
    }
}
