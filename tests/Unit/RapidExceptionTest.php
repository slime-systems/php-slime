<?php

declare(strict_types = 1);

use SlimeSystems\Eloquent\StaleRecordException;
use SlimeSystems\Exceptions\RapidException;
use SlimeSystems\Hatsume\ConcurrentWriteDetected;

describe('RapidException', function () {
  it('instantiates with identifier and message', function () {
    $e = new RapidException('TEST::NOT_FOUND', 'Item was not found.');

    expect($e->identifier)->toBe('TEST::NOT_FOUND')
      ->and($e->getMessage())->toBe('Item was not found.')
      ->and($e->getCode())->toBe(0)
      ->and($e->metadata)->toBe([])
      ->and($e->is('TEST::NOT_FOUND'))->toBeTrue()
      ->and($e->is('OTHER'))->toBeFalse();
  });

  it('correctly sets message, code, and previous when passed as named arguments', function () {
    $prev = new RuntimeException('Root cause');
    $e = new RapidException(
      'ORDER::FAILED',
      message: 'Failed to process order',
      code: 422,
      previous: $prev,
      order_id: 'ord_123',
      amount: 5000,
    );

    expect($e->identifier)->toBe('ORDER::FAILED')
      ->and($e->getMessage())->toBe('Failed to process order')
      ->and($e->getCode())->toBe(422)
      ->and($e->getPrevious())->toBe($prev)
      ->and($e->metadata)->toBe([
        'order_id' => 'ord_123',
        'amount' => 5000,
      ]);
  });

  it('correctly sets message, code, and previous when passed as positional arguments', function () {
    $prev = new RuntimeException('Positional cause');
    $e = new RapidException(
      'GATEWAY::TIMEOUT',
      'Gateway timed out after 30s',
      504,
      $prev,
      retries: 3,
    );

    expect($e->identifier)->toBe('GATEWAY::TIMEOUT')
      ->and($e->getMessage())->toBe('Gateway timed out after 30s')
      ->and($e->getCode())->toBe(504)
      ->and($e->getPrevious())->toBe($prev)
      ->and($e->metadata)->toBe([
        'retries' => 3,
      ]);
  });

  it('wraps a standard throwable via fromThrowable', function () {
    $root = new InvalidArgumentException('Bad parameter', 400);
    $rapid = RapidException::fromThrowable($root, 'VALIDATION::FAILED', field: 'email');

    expect($rapid)->toBeInstanceOf(RapidException::class)
      ->and($rapid->identifier)->toBe('VALIDATION::FAILED')
      ->and($rapid->getMessage())->toBe('Bad parameter')
      ->and($rapid->getCode())->toBe(400)
      ->and($rapid->getPrevious())->toBe($root)
      ->and($rapid->metadata['field'])->toBe('email');

    // Calling fromThrowable on an existing matching RapidException preserves it
    $same = RapidException::fromThrowable($rapid, 'VALIDATION::FAILED');
    expect($same)->toBe($rapid);
  });

  it('serializes to array and json format', function () {
    $e = new RapidException(
      'AUTH::FORBIDDEN',
      message: 'Access denied',
      user_id: 101,
      role: 'guest',
    );

    $array = $e->toArray();
    expect($array)->toBe([
      'code' => 'AUTH::FORBIDDEN',
      'message' => 'Access denied',
      'metadata' => [
        'user_id' => 101,
        'role' => 'guest',
      ],
    ])
      ->and($e->jsonSerialize())->toBe($array);

    $json = json_encode($e);
    expect(json_decode($json, true))->toBe($array);
  });

  it('supports StaleRecordException with ConcurrentWriteDetected', function () {
    $e = new StaleRecordException('Conflict detected', lock_version: 5);
    expect($e)->toBeInstanceOf(ConcurrentWriteDetected::class)
      ->and($e)->toBeInstanceOf(RapidException::class)
      ->and($e->identifier)->toBe(StaleRecordException::CONCURRENT_WRITE)
      ->and($e->metadata['lock_version'])->toBe(5);
  });
});

