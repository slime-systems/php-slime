<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\EventType;
use SlimeSystems\Eloquent\Replay\EventReplayer;
use SlimeSystems\Eloquent\StateType;
use SlimeSystems\Eloquent\StoredEvent;
use SlimeSystems\Hatsume\Event;

class ReplayTestAccountCreated {
  use Event;
}

class ReplayTestAccountRenamed {
  use Event;
}

describe('EventReplayer', function () {
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

  test('replays events for an aggregate in sequential position order', function () {
    $events = [
      new ReplayTestAccountCreated(['id' => 1, 'name' => 'Alice']),
      new ReplayTestAccountRenamed(['id' => 1, 'name' => 'Alicia']),
      new ReplayTestAccountRenamed(['id' => 1, 'name' => 'Alicia B.']),
    ];

    StoredEvent::persistEvents($events, [
      'state_key' => 'account:1',
      'next_position' => 1,
    ]);

    $replayed = [];
    $replayer = new EventReplayer();
    $count = $replayer->replayAggregate('account:1', function ($event, $metadata) use (&$replayed) {
      $replayed[] = [
        'class' => $event::class,
        'data' => $event->data,
        'position' => $metadata['position'],
      ];
    }, chunkSize: 2); // Small chunk to test multi-chunk pagination

    expect($count)->toBe(3)
      ->and($replayed)->toHaveCount(3)
      ->and($replayed[0]['position'])->toBe(1)
      ->and($replayed[0]['class'])->toBe(ReplayTestAccountCreated::class)
      ->and($replayed[0]['data']['name'])->toBe('Alice')
      ->and($replayed[1]['position'])->toBe(2)
      ->and($replayed[1]['data']['name'])->toBe('Alicia')
      ->and($replayed[2]['position'])->toBe(3)
      ->and($replayed[2]['data']['name'])->toBe('Alicia B.');
  });

  test('respects fromPosition and toPosition range', function () {
    $events = [
      new ReplayTestAccountCreated(['id' => 2, 'name' => 'Bob']),
      new ReplayTestAccountRenamed(['id' => 2, 'name' => 'Bob 1']),
      new ReplayTestAccountRenamed(['id' => 2, 'name' => 'Bob 2']),
      new ReplayTestAccountRenamed(['id' => 2, 'name' => 'Bob 3']),
    ];

    StoredEvent::persistEvents($events, [
      'state_key' => 'account:2',
      'next_position' => 1,
    ]);

    $positions = [];
    $count = EventReplayer::forAggregate('account:2', function ($event, $metadata) use (&$positions) {
      $positions[] = $metadata['position'];
    }, fromPosition: 2, toPosition: 3);

    expect($count)->toBe(2)
      ->and($positions)->toBe([2, 3]);
  });

  test('replays all events across all aggregates in chronological order', function () {
    $now = Carbon::now();

    StoredEvent::persistEvents([
      new ReplayTestAccountCreated(['id' => 10, 'name' => 'Ten'], ['occurred_at' => $now->copy()->subMinutes(10)]),
    ], [
      'state_key' => 'account:10',
      'next_position' => 1,
    ]);

    StoredEvent::persistEvents([
      new ReplayTestAccountCreated(['id' => 20, 'name' => 'Twenty'], ['occurred_at' => $now->copy()->subMinutes(5)]),
    ], [
      'state_key' => 'account:20',
      'next_position' => 1,
    ]);

    StoredEvent::persistEvents([
      new ReplayTestAccountCreated(['id' => 30, 'name' => 'Thirty'], ['occurred_at' => $now->copy()->subMinutes(1)]),
    ], [
      'state_key' => 'account:30',
      'next_position' => 1,
    ]);

    $names = [];
    $count = EventReplayer::all(function ($event) use (&$names) {
      $names[] = $event->data['name'];
    });

    expect($count)->toBe(3)
      ->and($names)->toBe(['Ten', 'Twenty', 'Thirty']);
  });
});
