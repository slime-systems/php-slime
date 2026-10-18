<?php

use SlimeSystems\Hatsume\Event;
use SlimeSystems\Hatsume\TransitoryEvent;

class EphemeralNotificationEvent implements TransitoryEvent {
  use Event;
}

class PersistentDomainEvent {
  use Event;
}

describe('TransitoryEvent', function () {
  test('serves as marker interface on domain event without requiring Throwable', function () {
    $event = new EphemeralNotificationEvent(['message' => 'hello']);

    expect($event)->toBeInstanceOf(TransitoryEvent::class)
      ->and($event)->not->toBeInstanceOf(Throwable::class)
      ->and($event->data)->toBe(['message' => 'hello']);
  });

  test('can be filtered out from event stream for persistence', function () {
    $events = [
      new PersistentDomainEvent(['id' => 1]),
      new EphemeralNotificationEvent(['temp' => true]),
      new PersistentDomainEvent(['id' => 2]),
    ];

    $persisted = array_values(array_filter(
      $events,
      fn($event) => !($event instanceof TransitoryEvent)
    ));

    expect($persisted)->toHaveCount(2)
      ->and($persisted[0]->data)->toBe(['id' => 1])
      ->and($persisted[1]->data)->toBe(['id' => 2]);
  });
});
