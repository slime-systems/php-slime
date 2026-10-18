<?php

use SlimeSystems\Eloquent\StaleRecord;
use SlimeSystems\Eloquent\StaleRecordException;
use SlimeSystems\Hatsume\ConcurrentWriteDetected;

describe('StaleRecordException', function () {
  test('implements ConcurrentWriteDetected marker interface', function () {
    $exception = new StaleRecordException();

    expect($exception)->toBeInstanceOf(ConcurrentWriteDetected::class)
      ->and($exception)->toBeInstanceOf(RuntimeException::class)
      ->and($exception->getMessage())->toBe('Concurrent write detected for state record.');
  });

  test('accepts custom message and preserves previous exception', function () {
    $previous = new Exception('DB error');
    $exception = new StaleRecordException('Custom concurrency error', 409, $previous);

    expect($exception->getMessage())->toBe('Custom concurrency error')
      ->and($exception->getCode())->toBe(409)
      ->and($exception->getPrevious())->toBe($previous);
  });

  test('StaleRecord alias extends StaleRecordException for RubySlime parity', function () {
    $staleRecord = new StaleRecord('Conflict');

    expect($staleRecord)->toBeInstanceOf(StaleRecordException::class)
      ->and($staleRecord)->toBeInstanceOf(ConcurrentWriteDetected::class)
      ->and($staleRecord->getMessage())->toBe('Conflict');
  });
});
