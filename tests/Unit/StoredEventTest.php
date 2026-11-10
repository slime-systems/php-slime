<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\EventType;
use SlimeSystems\Eloquent\StateType;
use SlimeSystems\Eloquent\StoredEvent;
use SlimeSystems\Hatsume\Event;
use SlimeSystems\Hatsume\TransitoryEvent;

class StoredTestDomainEvent {
  use Event;
}

class StoredTestTransitoryEvent implements TransitoryEvent {
  use Event;
}

describe('StoredEvent Eloquent Model', function () {
  beforeEach(function () {
    Schema::dropIfExists('stored_events');
    Schema::dropIfExists('event_types');
    Schema::dropIfExists('state_types');

    Schema::create('state_types', function ($table) {
      StateType::createBlueprint($table);
    });

    Schema::create('event_types', function ($table) {
      EventType::createBlueprint($table);
    });

    Schema::create('stored_events', function ($table) {
      StoredEvent::createBlueprint($table);
    });

    StateType::clearCache();
    EventType::clearCache();
  });

  test('createBlueprint sets up table with primary key and indexes', function () {
    expect(Schema::hasTable('stored_events'))->toBeTrue()
      ->and(Schema::hasColumns('stored_events', [
        'type_id', 'state_id', 'position', 'event_type_id', 'data', 'metadata', 'occurred_at',
      ]))->toBeTrue();
  });

  test('persists non-transitory events with sequential positions', function () {
    $event1 = new StoredTestDomainEvent(['amount' => 100], ['source' => 'web']);
    $event2 = new StoredTestDomainEvent(['amount' => 200], ['source' => 'api']);

    $metadata = [
      '_state_key' => ['type' => 'order', 'id' => 'ord-123'],
      '_position' => 1,
    ];

    $savedCount = StoredEvent::persistEvents([$event1, $event2], $metadata);

    expect($savedCount)->toBe(2);

    $records = StoredEvent::forAggregate(['type' => 'order', 'id' => 'ord-123'])->get();

    expect($records)->toHaveCount(2)
      ->and($records[0]->position)->toBe(1)
      ->and($records[0]->event_type_id)->toBe(1)
      ->and($records[0]->getEventClass())->toBe(StoredTestDomainEvent::class)
      ->and($records[0]->data)->toBe(['amount' => 100])
      ->and($records[0]->metadata)->toBe(['source' => 'web'])
      ->and($records[1]->position)->toBe(2)
      ->and($records[1]->event_type_id)->toBe(1)
      ->and($records[1]->getEventClass())->toBe(StoredTestDomainEvent::class)
      ->and($records[1]->data)->toBe(['amount' => 200]);
  });

  test('filters out TransitoryEvent from persistence', function () {
    $persistentEvent = new StoredTestDomainEvent(['name' => 'Persistent']);
    $transitoryEvent = new StoredTestTransitoryEvent(['name' => 'Ephemeral']);

    $metadata = [
      '_state_key' => ['type' => 'account', 'id' => '456'],
      '_position' => 5,
    ];

    $count = StoredEvent::persistEvents([$persistentEvent, $transitoryEvent], $metadata);

    expect($count)->toBe(1);

    $records = StoredEvent::forAggregate(['type' => 'account', 'id' => '456'])->get();
    expect($records)->toHaveCount(1)
      ->and($records[0]->position)->toBe(5)
      ->and($records[0]->data)->toBe(['name' => 'Persistent']);
  });

  test('returns 0 without touching database when all events are transitory', function () {
    $transitoryEvent = new StoredTestTransitoryEvent(['alert' => 'ping']);

    // Even without state_key and position, transitory events exit early
    $count = StoredEvent::persistEvents([$transitoryEvent], []);

    expect($count)->toBe(0)
      ->and(StoredEvent::count())->toBe(0);
  });

  test('reconstitutes stored event into original domain event via toEvent', function () {
    $now = Carbon::parse('2026-01-01 12:00:00');
    $original = new StoredTestDomainEvent(['key' => 'val'], ['source' => 'test'], $now);

    StoredEvent::persistEvents([$original], [
      '_state_key' => ['type' => 'user', 'id' => '999'],
      '_position' => 1,
    ]);

    $stored = StoredEvent::forAggregate(['type' => 'user', 'id' => '999'])->first();

    $reconstituted = $stored->toEvent();

    expect($reconstituted)->toBeInstanceOf(StoredTestDomainEvent::class)
      ->and($reconstituted->data)->toBe(['key' => 'val'])
      ->and($reconstituted->metadata)->toBe(['source' => 'test'])
      ->and($reconstituted->occurredAt->toIso8601String())->toBe($now->toIso8601String());
  });

  test('resolves state key from structured array', function () {
    $arrayKey = StoredEvent::resolveStateKey(['type' => 'invoice', 'id' => 'inv-1']);
    $typeIdKey = StoredEvent::resolveStateKey(['type_id' => 42, 'state_id' => 'inv-2']);

    $invoiceTypeId = StateType::idFor('invoice');

    expect($arrayKey)->toBe(['type_id' => $invoiceTypeId, 'state_id' => 'inv-1'])
      ->and($typeIdKey)->toBe(['type_id' => 42, 'state_id' => 'inv-2']);
  });

  test('scopeFromPosition filters events starting from position threshold', function () {
    $events = [
      new StoredTestDomainEvent(['step' => 1]),
      new StoredTestDomainEvent(['step' => 2]),
      new StoredTestDomainEvent(['step' => 3]),
    ];

    StoredEvent::persistEvents($events, [
      '_state_key' => ['type' => 'stream', 'id' => '1'],
      '_position' => 10,
    ]);

    $filtered = StoredEvent::forAggregate(['type' => 'stream', 'id' => '1'])
      ->fromPosition(11)
      ->get();

    expect($filtered)->toHaveCount(2)
      ->and($filtered[0]->position)->toBe(11)
      ->and($filtered[1]->position)->toBe(12);
  });
});
