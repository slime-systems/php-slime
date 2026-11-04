<?php

declare(strict_types = 1);

use Illuminate\Http\JsonResponse;
use SlimeSystems\Exceptions\RapidException;
use SlimeSystems\Tokens\TokenException;
use SlimeSystems\Tokens\Http\Concerns\HandlesApiResponse;

class DummyApiController {
  use HandlesApiResponse;

  public function success(mixed $data = null): JsonResponse {
    return $this->renderSuccess($data);
  }

  public function failure(string|RapidException $codeOrException, ?string $message = null, mixed $metadata = null, int $status = 422): JsonResponse {
    return $this->renderException($codeOrException, $message, $metadata, $status);
  }
}

describe('HandlesApiResponse & Token Exceptions', function () {
  it('renders standardized success json response', function () {
    $controller = new DummyApiController();
    $response = $controller->success(['user_id' => 123, 'status' => 'active']);

    expect($response)->toBeInstanceOf(JsonResponse::class)
      ->and($response->getStatusCode())->toBe(200)
      ->and($response->getData(true))->toBe([
        'data' => [
          'user_id' => 123,
          'status' => 'active',
        ],
      ]);
  });

  it('renders standardized exception json response with 422 status by default', function () {
    $controller = new DummyApiController();
    $response = $controller->failure('MismatchedAPIHash', 'Invalid body signature', ['expected' => 'abc']);

    expect($response)->toBeInstanceOf(JsonResponse::class)
      ->and($response->getStatusCode())->toBe(422)
      ->and($response->getData(true))->toBe([
        'exception' => [
          'code' => 'MismatchedAPIHash',
          'message' => 'Invalid body signature',
          'metadata' => [
            'expected' => 'abc',
          ],
        ],
      ]);
  });

  it('renders directly from RapidException instance', function () {
    $controller = new DummyApiController();
    $ex = new RapidException(
      'Auth::TOKEN_EXPIRED',
      'The token has expired',
      expired_at: 1700000000
    );

    $response = $controller->failure($ex, status: 401);

    expect($response->getStatusCode())->toBe(401)
      ->and($response->getData(true))->toBe([
        'exception' => [
          'code' => 'Auth::TOKEN_EXPIRED',
          'message' => 'The token has expired',
          'metadata' => [
            'expired_at' => 1700000000,
          ],
        ],
      ]);
  });

  it('renders standardized exception omitting null message and metadata', function () {
    $controller = new DummyApiController();
    $response = $controller->failure('Unauthorized');

    expect($response->getStatusCode())->toBe(422)
      ->and($response->getData(true))->toBe([
        'exception' => [
          'code' => 'Unauthorized',
        ],
      ]);
  });

  it('supports custom http status codes in renderException', function () {
    $controller = new DummyApiController();
    $response = $controller->failure('Unauthorized', 'Missing token', null, 401);

    expect($response->getStatusCode())->toBe(401)
      ->and($response->getData(true))->toBe([
        'exception' => [
          'code' => 'Unauthorized',
          'message' => 'Missing token',
        ],
      ]);
  });

  it('validates TokenException identifiers and metadata', function () {
    $ex = new TokenException(
      TokenException::MISMATCHED_HASH,
      'Mismatched API hash',
      expectedHash: 'expected_hash',
      actualHash: 'actual_hash',
    );
    expect($ex)->toBeInstanceOf(TokenException::class)
      ->and($ex)->toBeInstanceOf(RapidException::class)
      ->and($ex->identifier)->toBe(TokenException::MISMATCHED_HASH)
      ->and($ex->metadata['expectedHash'])->toBe('expected_hash')
      ->and($ex->metadata['actualHash'])->toBe('actual_hash');

    $keyEx = new TokenException(TokenException::KEY_NOT_FOUND, 'Key k123 not found');
    expect($keyEx->identifier)->toBe(TokenException::KEY_NOT_FOUND);

    $expiredEx = new TokenException(TokenException::EXPIRED, 'Token expired');
    expect($expiredEx->identifier)->toBe(TokenException::EXPIRED);

    $skewEx = new TokenException(TokenException::CLOCK_SKEW, 'Skew error');
    expect($skewEx->identifier)->toBe(TokenException::CLOCK_SKEW);

    $invEx = new TokenException(TokenException::INVALID, 'Invalid token');
    expect($invEx->identifier)->toBe(TokenException::INVALID);

    $schemaEx = new TokenException(TokenException::SCHEMA_VIOLATION, 'Schema violation', errors: ['field' => ['required']]);
    expect($schemaEx->identifier)->toBe(TokenException::SCHEMA_VIOLATION)
      ->and($schemaEx->metadata['errors'])->toBe(['field' => ['required']]);
  });
});
