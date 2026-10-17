<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
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
        Schema::dropIfExists('state_types');

        Schema::create('state_types', function ($table) {
            StateType::createBlueprint($table);
        });

        Schema::create('stored_events', function ($table) {
            StoredEvent::createBlueprint($table);
        });

        StateType::clearCache();
    });

    test('createBlueprint sets up table with primary key and indexes', function () {
        expect(Schema::hasTable('stored_events'))->toBeTrue()
            ->and(Schema::hasColumns('stored_events', [
                'type_id', 'state_id', 'position', 'event_class', 'data', 'metadata', 'occurred_at',
            ]))->toBeTrue();
    });

    test('persists non-transitory events with sequential positions', function () {
        $event1 = new StoredTestDomainEvent(['amount' => 100], ['source' => 'web']);
        $event2 = new StoredTestDomainEvent(['amount' => 200], ['source' => 'api']);

        $metadata = [
            'state_key' => ['type' => 'order', 'id' => 'ord-123'],
            'next_position' => 1,
        ];

        $savedCount = StoredEvent::persistEvents([$event1, $event2], $metadata);

        expect($savedCount)->toBe(2);

        $records = StoredEvent::forAggregate(['type' => 'order', 'id' => 'ord-123'])->get();

        expect($records)->toHaveCount(2)
            ->and($records[0]->position)->toBe(1)
            ->and($records[0]->event_class)->toBe(StoredTestDomainEvent::class)
            ->and($records[0]->data)->toBe(['amount' => 100])
            ->and($records[0]->metadata)->toBe(['source' => 'web'])
            ->and($records[1]->position)->toBe(2)
            ->and($records[1]->data)->toBe(['amount' => 200]);
    });

    test('filters out TransitoryEvent from persistence', function () {
        $persistentEvent = new StoredTestDomainEvent(['name' => 'Persistent']);
        $transitoryEvent = new StoredTestTransitoryEvent(['name' => 'Ephemeral']);

        $metadata = [
            'state_key' => 'account:456',
            'next_position' => 5,
        ];

        $count = StoredEvent::persistEvents([$persistentEvent, $transitoryEvent], $metadata);

        expect($count)->toBe(1);

        $records = StoredEvent::forAggregate('account:456')->get();
        expect($records)->toHaveCount(1)
            ->and($records[0]->position)->toBe(5)
            ->and($records[0]->data)->toBe(['name' => 'Persistent']);
    });

    test('returns 0 without touching database when all events are transitory', function () {
        $transitoryEvent = new StoredTestTransitoryEvent(['alert' => 'ping']);

        // Even without state_key and next_position, transitory events exit early
        $count = StoredEvent::persistEvents([$transitoryEvent], []);

        expect($count)->toBe(0)
            ->and(StoredEvent::count())->toBe(0);
    });

    test('throws InvalidArgumentException when metadata lacks state_key or next_position for persistent events', function () {
        $event = new StoredTestDomainEvent(['valid' => true]);

        expect(fn () => StoredEvent::persistEvents([$event], ['state_key' => 'test']))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => StoredEvent::persistEvents([$event], ['next_position' => 1]))
            ->toThrow(InvalidArgumentException::class);
    });

    test('reconstitutes stored event into original domain event via toEvent', function () {
        $original = new StoredTestDomainEvent(['key' => 'val'], ['occurred_at' => Carbon::now()]);

        StoredEvent::persistEvents([$original], [
            'state_key' => 'user:999',
            'next_position' => 1,
        ]);

        $stored = StoredEvent::forAggregate('user:999')->first();

        $reconstituted = $stored->toEvent();

        expect($reconstituted)->toBeInstanceOf(StoredTestDomainEvent::class)
            ->and($reconstituted->data)->toBe(['key' => 'val'])
            ->and($reconstituted->metadata)->toHaveKey('occurred_at');
    });

    test('resolves state key across array, colon string, and scalar formats', function () {
        $arrayKey = StoredEvent::resolveStateKey(['type' => 'invoice', 'id' => 'inv-1']);
        $stringKey = StoredEvent::resolveStateKey('invoice:inv-1');
        $scalarKey = StoredEvent::resolveStateKey('simple-id');

        $invoiceTypeId = StateType::idFor('invoice');
        $defaultTypeId = StateType::idFor('default');

        expect($arrayKey)->toBe(['type_id' => $invoiceTypeId, 'state_id' => 'inv-1'])
            ->and($stringKey)->toBe(['type_id' => $invoiceTypeId, 'state_id' => 'inv-1'])
            ->and($scalarKey)->toBe(['type_id' => $defaultTypeId, 'state_id' => 'simple-id']);
    });

    test('scopeFromPosition filters events starting from position threshold', function () {
        $events = [
            new StoredTestDomainEvent(['step' => 1]),
            new StoredTestDomainEvent(['step' => 2]),
            new StoredTestDomainEvent(['step' => 3]),
        ];

        StoredEvent::persistEvents($events, [
            'state_key' => 'stream:1',
            'next_position' => 10,
        ]);

        $filtered = StoredEvent::forAggregate('stream:1')
            ->fromPosition(11)
            ->get();

        expect($filtered)->toHaveCount(2)
            ->and($filtered[0]->position)->toBe(11)
            ->and($filtered[1]->position)->toBe(12);
    });
});
