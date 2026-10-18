<?php

use SlimeSystems\Hatsume\Event;
use SlimeSystems\Hatsume\EventDispatcher;

class SampleDispatchedEvent {
  use Event;
}

describe('EventDispatcher', function () {
  test('dispatches events and metadata to all registered handlers', function () {
    $handler1Logs = [];
    $handler2Logs = [];

    $handler1 = function ($events, array $metadata = []) use (&$handler1Logs) {
      $handler1Logs[] = ['events' => $events, 'metadata' => $metadata];
    };

    $handler2 = function ($events, array $metadata = []) use (&$handler2Logs) {
      $handler2Logs[] = ['events' => $events, 'metadata' => $metadata];
    };

    $dispatcher = new EventDispatcher($handler1, $handler2);

    $event1 = new SampleDispatchedEvent(['id' => 1]);
    $event2 = new SampleDispatchedEvent(['id' => 2]);
    $events = [$event1, $event2];
    $metadata = ['trace_id' => 'abc-123'];

    $dispatcher($events, $metadata);

    expect($handler1Logs)->toHaveCount(1)
      ->and($handler1Logs[0]['events'])->toBe($events)
      ->and($handler1Logs[0]['metadata'])->toBe($metadata)
      ->and($handler2Logs)->toHaveCount(1)
      ->and($handler2Logs[0]['events'])->toBe($events)
      ->and($handler2Logs[0]['metadata'])->toBe($metadata);
  });

  test('supports explicit call() invocation', function () {
    $invoked = false;
    $dispatcher = new EventDispatcher(function ($events) use (&$invoked) {
      $invoked = true;
    });

    $dispatcher->call([new SampleDispatchedEvent()]);

    expect($invoked)->toBeTrue();
  });

  test('handles zero handlers without error', function () {
    $dispatcher = new EventDispatcher();

    expect(fn() => $dispatcher([new SampleDispatchedEvent()]))->not->toThrow(Throwable::class);
  });
});
