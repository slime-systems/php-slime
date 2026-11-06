<?php

use SlimeSystems\Eloquent\ApplicationEventHandler;
use SlimeSystems\Eloquent\StoredEvent;

describe('ApplicationEventHandler', function () {
  test('dispatches events and context to projector, event store, and event bus', function () {
    $projectorCalled = false;
    $eventStoreCalled = false;
    $eventBusCalled = false;

    $events = [(object)['type' => 'test_event']];
    $context = ['state_key' => 'order:1', 'position' => 1];

    $handler = ApplicationEventHandler::make(
      projector: function ($dispatchedEvents, $dispatchedContext) use (&$projectorCalled, $events, $context) {
        $projectorCalled = true;
        expect($dispatchedEvents)->toBe($events)
          ->and($dispatchedContext)->toBe($context);
      },
      eventStore: function ($dispatchedEvents, $dispatchedContext) use (&$eventStoreCalled, $events, $context) {
        $eventStoreCalled = true;
        expect($dispatchedEvents)->toBe($events)
          ->and($dispatchedContext)->toBe($context);
      },
      eventBus: function ($dispatchedEvents, $dispatchedContext) use (&$eventBusCalled, $events, $context) {
        $eventBusCalled = true;
        expect($dispatchedEvents)->toBe($events)
          ->and($dispatchedContext)->toBe($context);
      },
    );

    $handler($events, $context);

    expect($projectorCalled)->toBeTrue()
      ->and($eventStoreCalled)->toBeTrue()
      ->and($eventBusCalled)->toBeTrue();
  });
});
