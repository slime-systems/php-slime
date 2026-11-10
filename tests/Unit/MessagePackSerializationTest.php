<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\FieldType;
use SlimeSystems\Eloquent\Serialization\MessagePackSerializer;
use SlimeSystems\Eloquent\State;
use SlimeSystems\Eloquent\StateType;
use SlimeSystems\ObjectId;

describe('MessagePack State Serialization & Custom Type Support', function () {
  beforeEach(function () {
    FieldType::clearCache();
    StateType::clearCache();
    Schema::dropIfExists('process_states');
    Schema::dropIfExists('state_types');
    Schema::dropIfExists('field_types');

    Schema::create('field_types', fn(Blueprint $table) => FieldType::createBlueprint($table));
    Schema::create('state_types', fn(Blueprint $table) => StateType::createBlueprint($table));
    Schema::create('process_states', fn(Blueprint $table) => State::createBlueprint($table));
  });

  afterEach(function () {
    FieldType::clearCache();
    StateType::clearCache();
  });

  test('serializes and deserializes primitives, maps, and arrays', function () {
    $original = [
      'name' => 'John Doe',
      'balance' => 1250,
      'is_verified' => true,
      'limits' => ['daily' => 5000, 'monthly' => 50000],
      'tags' => ['vip', 'early_adopter'],
      'null_value' => null,
    ];

    $packed = MessagePackSerializer::pack($original);
    $unpacked = MessagePackSerializer::unpack($packed);

    expect($unpacked)->toBe($original)
      ->and(strlen($packed))->toBeLessThan(strlen(json_encode($original)));
  });

  test('serializes and deserializes Carbon, past dates, and ObjectIds', function () {
    $carbon = Carbon::parse('2026-10-18 14:35:22.654321 UTC');
    $pastDate = Carbon::parse('1965-08-12 09:15:00 UTC');
    $objectId = ObjectId::fromString('6ad3d413151add4a9ebd9fcf');

    $data = [
      'carbon' => $carbon,
      'past_date' => $pastDate,
      'object_id' => $objectId,
    ];

    $unpacked = MessagePackSerializer::unpack(MessagePackSerializer::pack($data));

    expect($unpacked['carbon'])->toBeInstanceOf(DateTimeInterface::class)
      ->and($unpacked['carbon']->equalTo($carbon))->toBeTrue()
      ->and($unpacked['carbon']->format('u'))->toBe('654321')
      ->and($unpacked['past_date'])->toBeInstanceOf(DateTimeInterface::class)
      ->and($unpacked['past_date']->equalTo($pastDate))->toBeTrue()
      ->and($unpacked['object_id'])->toBeInstanceOf(ObjectId::class)
      ->and($unpacked['object_id']->equals($objectId))->toBeTrue()
      ->and((string)$unpacked['object_id'])->toBe('6ad3d413151add4a9ebd9fcf');
  });

  test('persists and restores custom types through State model database roundtrip', function () {
    $typeId = StateType::idFor('order');
    $orderId = new ObjectId();
    $registeredAt = Carbon::parse('2026-10-18 08:00:00.123456 UTC');
    $expiresAt = new DateTimeImmutable('2028-10-18 08:00:00+00:00');

    $state = new State([
      'type_id' => $typeId,
      'state_id' => (string)$orderId,
      'state' => [
        'order_id' => $orderId,
        'registered_at' => $registeredAt,
        'expires_at' => $expiresAt,
        'balance' => 777,
      ],
    ]);
    $state->save();

    $loaded = State::findByTypeAndId('order', (string)$orderId);
    expect($loaded)->not->toBeNull();

    $loadedState = $loaded->state;
    expect($loadedState['balance'])->toBe(777)
      ->and($loadedState['order_id'])->toBeInstanceOf(ObjectId::class)
      ->and($loadedState['order_id']->equals($orderId))->toBeTrue()
      ->and($loadedState['registered_at'])->toBeInstanceOf(DateTimeInterface::class)
      ->and($loadedState['registered_at']->equalTo($registeredAt))->toBeTrue()
      ->and($loadedState['registered_at']->format('u'))->toBe('123456')
      ->and($loadedState['expires_at'])->toBeInstanceOf(DateTimeInterface::class)
      ->and($loadedState['expires_at']->getTimestamp())->toBe($expiresAt->getTimestamp());
  });
});
