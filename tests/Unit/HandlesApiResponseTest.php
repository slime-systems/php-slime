<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\MessageBag;
use SlimeSystems\Tokens\Exceptions\IatClockSkewException;
use SlimeSystems\Tokens\Exceptions\InvalidTokenException;
use SlimeSystems\Tokens\Exceptions\KeyNotFoundException;
use SlimeSystems\Tokens\Exceptions\MismatchedApiHashException;
use SlimeSystems\Tokens\Exceptions\TokenException;
use SlimeSystems\Tokens\Exceptions\TokenExpiredException;
use SlimeSystems\Tokens\Exceptions\TokenSchemaViolationException;
use SlimeSystems\Tokens\Http\Concerns\HandlesApiResponse;

class DummyApiController
{
    use HandlesApiResponse;

    public function success(mixed $data = null): JsonResponse
    {
        return $this->renderSuccess($data);
    }

    public function failure(string $code, ?string $message = null, mixed $metadata = null, int $status = 422): JsonResponse
    {
        return $this->renderException($code, $message, $metadata, $status);
    }
}

describe('HandlesApiResponse & Token Exceptions', function () {
    it('renders standardized success json response', function () {
        $controller = new DummyApiController();
        $response = $controller->success(['user_id' => 123, 'status' => 'active']);

        expect($response)->toBeInstanceOf(JsonResponse::class);
        expect($response->getStatusCode())->toBe(200);
        expect($response->getData(true))->toBe([
            'data' => [
                'user_id' => 123,
                'status' => 'active',
            ],
        ]);
    });

    it('renders standardized exception json response with 422 status by default', function () {
        $controller = new DummyApiController();
        $response = $controller->failure('MismatchedAPIHash', 'Invalid body signature', ['expected' => 'abc']);

        expect($response)->toBeInstanceOf(JsonResponse::class);
        expect($response->getStatusCode())->toBe(422);
        expect($response->getData(true))->toBe([
            'exception' => [
                'code' => 'MismatchedAPIHash',
                'message' => 'Invalid body signature',
                'metadata' => [
                    'expected' => 'abc',
                ],
            ],
        ]);
    });

    it('renders standardized exception omitting null message and metadata', function () {
        $controller = new DummyApiController();
        $response = $controller->failure('Unauthorized');

        expect($response->getStatusCode())->toBe(422);
        expect($response->getData(true))->toBe([
            'exception' => [
                'code' => 'Unauthorized',
            ],
        ]);
    });

    it('supports custom http status codes in renderException', function () {
        $controller = new DummyApiController();
        $response = $controller->failure('Unauthorized', 'Missing token', null, 401);

        expect($response->getStatusCode())->toBe(401);
        expect($response->getData(true))->toBe([
            'exception' => [
                'code' => 'Unauthorized',
                'message' => 'Missing token',
            ],
        ]);
    });

    it('validates exception hierarchy and metadata', function () {
        $ex = new MismatchedApiHashException('expected_hash', 'actual_hash');
        expect($ex)->toBeInstanceOf(TokenException::class);
        expect($ex->expectedHash)->toBe('expected_hash');
        expect($ex->actualHash)->toBe('actual_hash');

        $keyEx = new KeyNotFoundException('Key k123 not found');
        expect($keyEx)->toBeInstanceOf(TokenException::class);

        $expiredEx = new TokenExpiredException();
        expect($expiredEx)->toBeInstanceOf(TokenException::class);

        $skewEx = new IatClockSkewException();
        expect($skewEx)->toBeInstanceOf(TokenExpiredException::class);

        $invEx = new InvalidTokenException();
        expect($invEx)->toBeInstanceOf(TokenException::class);

        $bag = new MessageBag(['field' => ['required']]);
        $schemaEx = new TokenSchemaViolationException($bag);
        expect($schemaEx->getErrors()->toArray())->toBe(['field' => ['required']]);
    });
});
