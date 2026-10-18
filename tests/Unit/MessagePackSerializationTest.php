<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\FieldType;
use SlimeSystems\Eloquent\Serialization\MessagePackSerializer;
use SlimeSystems\Eloquent\State;
use SlimeSystems\Eloquent\StateType;

describe('MessagePack State Serialization & DateTime Support', function () {
    beforeEach(function () {
        FieldType::clearCache();
        StateType::clearCache();
        Schema::dropIfExists('process_states');
        Schema::dropIfExists('state_types');
        Schema::dropIfExists('field_types');

        Schema::create('field_types', function (Blueprint $table) {
            FieldType::createBlueprint($table);
        });
        Schema::create('state_types', function (Blueprint $table) {
            StateType::createBlueprint($table);
        });
        Schema::create('process_states', function (Blueprint $table) {
            State::createBlueprint($table);
        });
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

    test('serializes and deserializes Carbon instances with microsecond precision', function () {
        $carbon = Carbon::parse('2026-10-18 14:35:22.654321 UTC');

        $data = [
            'event_id' => 'evt_1',
            'created_at' => $carbon,
        ];

        $packed = MessagePackSerializer::pack($data);
        $unpacked = MessagePackSerializer::unpack($packed);

        expect($unpacked['created_at'])->toBeInstanceOf(Carbon::class)
            ->and($unpacked['created_at']->equalTo($carbon))->toBeTrue()
            ->and($unpacked['created_at']->format('u'))->toBe('654321');
    });

    test('serializes and deserializes past dates before 1970', function () {
        $pastDate = Carbon::parse('1965-08-12 09:15:00 UTC');

        $data = ['birth_date' => $pastDate];
        $packed = MessagePackSerializer::pack($data);
        $unpacked = MessagePackSerializer::unpack($packed);

        expect($unpacked['birth_date'])->toBeInstanceOf(Carbon::class)
            ->and($unpacked['birth_date']->equalTo($pastDate))->toBeTrue();
    });

    test('persists and restores DateTime and Carbon through State model database roundtrip', function () {
        $typeId = StateType::idFor('account');
        $registeredAt = Carbon::parse('2026-10-18 08:00:00.123456 UTC');
        $expiresAt = new DateTimeImmutable('2028-10-18 08:00:00+00:00');

        $state = new State([
            'type_id' => $typeId,
            'state_id' => 'acc_datetime_test',
            'state' => [
                'registered_at' => $registeredAt,
                'expires_at' => $expiresAt,
                'balance' => 777,
            ],
        ]);
        $state->save();

        // Reload from database
        $loaded = State::findByTypeAndId('account', 'acc_datetime_test');
        expect($loaded)->not->toBeNull();

        $loadedState = $loaded->state;
        expect($loadedState['balance'])->toBe(777)
            ->and($loadedState['registered_at'])->toBeInstanceOf(Carbon::class)
            ->and($loadedState['registered_at']->equalTo($registeredAt))->toBeTrue()
            ->and($loadedState['registered_at']->format('u'))->toBe('123456')
            ->and($loadedState['expires_at'])->toBeInstanceOf(Carbon::class)
            ->and($loadedState['expires_at']->getTimestamp())->toBe($expiresAt->getTimestamp());
    });

    test('serializes and deserializes SlimeSystems\ObjectId instances using 0x07 type', function () {
        $objectId = \SlimeSystems\ObjectId::fromString('6ad3d413151add4a9ebd9fcf');

        $data = [
            'document_id' => $objectId,
            'title' => 'Invoice 101',
        ];

        $packed = MessagePackSerializer::pack($data);
        $unpacked = MessagePackSerializer::unpack($packed);

        expect($unpacked['document_id'])->toBeInstanceOf(\SlimeSystems\ObjectId::class)
            ->and($unpacked['document_id']->equals($objectId))->toBeTrue()
            ->and((string) $unpacked['document_id'])->toBe('6ad3d413151add4a9ebd9fcf');
    });

    test('persists and restores ObjectId inside aggregate state through State model roundtrip', function () {
        $typeId = StateType::idFor('order');
        $orderId = new \SlimeSystems\ObjectId();
        $customerId = new \SlimeSystems\ObjectId();
        $orderedAt = Carbon::now();

        $state = new State([
            'type_id' => $typeId,
            'state_id' => (string) $orderId,
            'state' => [
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'ordered_at' => $orderedAt,
                'items_count' => 3,
            ],
        ]);
        $state->save();

        $loaded = State::findByTypeAndId('order', (string) $orderId);
        expect($loaded)->not->toBeNull();

        $loadedState = $loaded->state;
        expect($loadedState['order_id'])->toBeInstanceOf(\SlimeSystems\ObjectId::class)
            ->and($loadedState['order_id']->equals($orderId))->toBeTrue()
            ->and($loadedState['customer_id'])->toBeInstanceOf(\SlimeSystems\ObjectId::class)
            ->and($loadedState['customer_id']->equals($customerId))->toBeTrue()
            ->and($loadedState['ordered_at'])->toBeInstanceOf(Carbon::class)
            ->and($loadedState['ordered_at']->equalTo($orderedAt))->toBeTrue();
    });
});
