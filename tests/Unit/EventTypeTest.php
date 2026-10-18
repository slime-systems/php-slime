<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\EventType;

describe('EventType Eloquent Model', function () {
  beforeEach(function () {
    EventType::clearCache();
    Schema::dropIfExists('stored_events');
    Schema::dropIfExists('event_types');

    Schema::create('event_types', function (Blueprint $table) {
      EventType::createBlueprint($table);
    });
  });

  afterEach(function () {
    EventType::clearCache();
  });

  test('creates schema with correct columns', function () {
    expect(Schema::hasTable('event_types'))->toBeTrue()
      ->and(Schema::hasColumn('event_types', 'id'))->toBeTrue()
      ->and(Schema::hasColumn('event_types', 'name'))->toBeTrue();
  });

  test('resolves and caches event_type_id for string name via idFor', function () {
    $id1 = EventType::idFor('App\Events\UserRegistered');
    $id2 = EventType::idFor('App\Events\UserVerified');

    expect($id1)->toBeInt()->toBe(1)
      ->and($id2)->toBeInt()->toBe(2);

    // Subsequent call returns cached id
    $id1Cached = EventType::idFor('App\Events\UserRegistered');
    expect($id1Cached)->toBe($id1);

    // Only two records exist in database
    expect(EventType::count())->toBe(2);
  });

  test('resolves string name for event_type_id via nameFor', function () {
    $id = EventType::idFor('App\Events\OrderPlaced');

    expect(EventType::nameFor($id))->toBe('App\Events\OrderPlaced')
      ->and(EventType::nameFor(999))->toBeNull();
  });

  test('checks existence with hasType', function () {
    expect(EventType::hasType('App\Events\PaymentAuthorized'))->toBeFalse();

    EventType::idFor('App\Events\PaymentAuthorized');

    expect(EventType::hasType('App\Events\PaymentAuthorized'))->toBeTrue();
  });

  test('clears in-memory caches via clearCache', function () {
    $id = EventType::idFor('App\Events\InvoiceGenerated');
    EventType::clearCache();

    // Still resolves from DB and re-caches
    expect(EventType::idFor('App\Events\InvoiceGenerated'))->toBe($id);
  });

  test('primes cache in bulk with a single query', function () {
    EventType::idFor('EventA');
    EventType::idFor('EventB');
    EventType::idFor('EventC');

    EventType::clearCache();

    // Prime cache explicitly
    EventType::primeCache();

    // Lookups hit memory without creating duplicate DB records
    expect(EventType::idFor('EventA'))->toBe(1)
      ->and(EventType::idFor('EventB'))->toBe(2)
      ->and(EventType::idFor('EventC'))->toBe(3)
      ->and(EventType::count())->toBe(3);
  });
});
