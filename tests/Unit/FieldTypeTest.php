<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\FieldType;
use SlimeSystems\Eloquent\Serialization\MessagePackSerializer;

describe('FieldType Eloquent Model & Key Interning Optimization', function () {
    beforeEach(function () {
        FieldType::clearCache();
        Schema::dropIfExists('field_types');

        Schema::create('field_types', function (Blueprint $table) {
            FieldType::createBlueprint($table);
        });
    });

    afterEach(function () {
        FieldType::clearCache();
    });

    test('creates schema with correct columns', function () {
        expect(Schema::hasTable('field_types'))->toBeTrue()
            ->and(Schema::hasColumn('field_types', 'id'))->toBeTrue()
            ->and(Schema::hasColumn('field_types', 'name'))->toBeTrue();
    });

    test('resolves and caches field ID for string name via idFor', function () {
        $id1 = FieldType::idFor('intermediate_certificates');
        $id2 = FieldType::idFor('signing_certificate');

        expect($id1)->toBeInt()->toBe(1)
            ->and($id2)->toBeInt()->toBe(2);

        // Subsequent lookup uses memory cache
        expect(FieldType::idFor('intermediate_certificates'))->toBe(1)
            ->and(FieldType::count())->toBe(2);
    });

    test('resolves string name for id via nameFor', function () {
        $id = FieldType::idFor('transaction_id');

        expect(FieldType::nameFor($id))->toBe('transaction_id')
            ->and(FieldType::nameFor(999))->toBeNull();
    });

    test('primes cache in bulk with a single query', function () {
        FieldType::idFor('field_a');
        FieldType::idFor('field_b');
        FieldType::clearCache();

        FieldType::primeCache();

        expect(FieldType::idFor('field_a'))->toBe(1)
            ->and(FieldType::idFor('field_b'))->toBe(2);
    });

    test('internKeys and restoreKeys perfectly roundtrip arbitrary structures', function () {
        $payload = [
            'transaction_id' => 'tx_999',
            'amount_in_cents' => 15000,
            'is_settled' => true,
            'line_items' => [
                ['item_id' => 'item_1', 'unit_price' => 5000, 'qty' => 2],
                ['item_id' => 'item_2', 'unit_price' => 5000, 'qty' => 1],
            ],
            'metadata' => [
                'seller_info_version' => 3,
                'intermediate_certificates' => ['cert_a', 'cert_b'],
            ],
        ];

        $packed = MessagePackSerializer::pack($payload);
        $unpacked = MessagePackSerializer::unpack($packed);

        expect($unpacked)->toBe($payload);
    });

    test('demonstrates wire size reduction by encoding keys as fixints', function () {
        $payload = [
            'intermediate_certificates' => 'cert_data',
            'signing_certificate' => 'cert_sign',
            'seller_info_version' => 1,
            'transaction_id' => 'tx_123',
            'document_sequence' => 456,
        ];

        // Pack with interned keys
        $compactPacked = MessagePackSerializer::pack($payload, internKeys: true);

        // Pack without interned keys (raw strings)
        $rawPacked = MessagePackSerializer::pack($payload, internKeys: false);

        // Compact version should be significantly smaller
        expect(strlen($compactPacked))->toBeLessThan(strlen($rawPacked));

        // Exact savings: 5 long string keys (~99 bytes) replaced with 5 single-byte fixints (5 bytes)
        $savedBytes = strlen($rawPacked) - strlen($compactPacked);
        expect($savedBytes)->toBeGreaterThanOrEqual(90);
    });

    test('handles fallback when field_types table is not available', function () {
        FieldType::clearCache();
        Schema::dropIfExists('field_types');

        expect(FieldType::isAvailable())->toBeFalse();

        $data = ['username' => 'alice', 'role' => 'admin'];
        $packed = MessagePackSerializer::pack($data);
        $unpacked = MessagePackSerializer::unpack($packed);

        expect($unpacked)->toBe($data);
    });
});
