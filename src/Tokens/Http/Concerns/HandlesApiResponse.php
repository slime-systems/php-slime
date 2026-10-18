<?php

declare(strict_types=1);

namespace SlimeSystems\Tokens\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as ResponseStatus;

trait HandlesApiResponse
{
    /**
     * Render a standardized JSON success response.
     *
     * @param mixed $data
     * @param int $status
     * @param array $headers
     * @return JsonResponse
     */
    protected function renderSuccess(
        mixed $data = null,
        int $status = ResponseStatus::HTTP_OK,
        array $headers = []
    ): JsonResponse {
        return new JsonResponse([
            'data' => $data,
        ], $status, $headers);
    }

    /**
     * Render a standardized JSON exception response.
     *
     * @param string $code
     * @param string|null $message
     * @param mixed|null $metadata
     * @param int $status
     * @param array $headers
     * @return JsonResponse
     */
    protected function renderException(
        string $code,
        ?string $message = null,
        mixed $metadata = null,
        int $status = ResponseStatus::HTTP_UNPROCESSABLE_ENTITY,
        array $headers = []
    ): JsonResponse {
        $exception = [
            'code' => $code,
        ];

        if ($message !== null && $message !== '') {
            $exception['message'] = $message;
        }

        if ($metadata !== null) {
            $exception['metadata'] = $metadata;
        }

        return new JsonResponse([
            'exception' => $exception,
        ], $status, $headers);
    }
}
