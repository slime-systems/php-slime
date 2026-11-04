<?php

declare(strict_types = 1);

namespace SlimeSystems\Tokens\Http\Concerns;

use Illuminate\Http\JsonResponse;
use SlimeSystems\Exceptions\RapidException;
use Symfony\Component\HttpFoundation\Response as ResponseStatus;

trait HandlesApiResponse {
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
    int   $status = ResponseStatus::HTTP_OK,
    array $headers = []
  ): JsonResponse {
    return new JsonResponse([
      'data' => $data,
    ], $status, $headers);
  }

  /**
   * Render a standardized JSON exception response.
   *
   * @param string|RapidException $codeOrException Identifier string or RapidException instance
   * @param string|null $message
   * @param mixed|null $metadata
   * @param int $status
   * @param array $headers
   * @return JsonResponse
   */
  protected function renderException(
    string|RapidException $codeOrException,
    ?string               $message = null,
    mixed                 $metadata = null,
    int                   $status = ResponseStatus::HTTP_UNPROCESSABLE_ENTITY,
    array                 $headers = []
  ): JsonResponse {
    if ($codeOrException instanceof RapidException) {
      $code = $codeOrException->identifier;
      $message = $message ?? ($codeOrException->getMessage() ?: null);
      $metadata = $metadata ?? (!empty($codeOrException->metadata) ? $codeOrException->metadata : null);
    } else {
      $code = $codeOrException;
    }

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
