<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\Concerns\ModelHelpers;

class KeyValueItem extends Model
{
    use ModelHelpers;

    protected $table = 'key_value_items';
    protected $guarded = [];
    public $timestamps = false;
}

describe('ModelHelpers Eloquent Concern (indate & upsert)', function () {
    beforeEach(function () {
        Schema::dropIfExists('key_value_items');

        Schema::create('key_value_items', function (Blueprint $table) {
            $table->string('item_key')->primary();
            $table->string('item_value');
            $table->integer('version')->default(1);
        });
    });

    test('indate inserts new record when key does not exist', function () {
        $item = KeyValueItem::indateRecord(
            ['item_key' => 'token_1'],
            ['item_value' => 'first_value', 'version' => 1]
        );

        expect($item)->toBeInstanceOf(KeyValueItem::class)
            ->and($item->item_key)->toBe('token_1')
            ->and($item->item_value)->toBe('first_value')
            ->and($item->version)->toBe(1);

        expect(KeyValueItem::count())->toBe(1);
    });

    test('indate updates existing record on duplicate key collision', function () {
        KeyValueItem::create([
            'item_key' => 'token_1',
            'item_value' => 'initial_value',
            'version' => 1,
        ]);

        $item = KeyValueItem::indateRecord(
            ['item_key' => 'token_1'],
            ['item_value' => 'updated_value', 'version' => 2]
        );

        expect($item->item_key)->toBe('token_1')
            ->and($item->item_value)->toBe('updated_value')
            ->and($item->version)->toBe(2);

        expect(KeyValueItem::count())->toBe(1);
    });

    test('upsertRecord creates record when not present', function () {
        $item = KeyValueItem::upsertRecord(
            ['item_key' => 'token_2'],
            ['item_value' => 'upsert_val', 'version' => 1]
        );

        expect($item->item_key)->toBe('token_2')
            ->and($item->item_value)->toBe('upsert_val');

        expect(KeyValueItem::count())->toBe(1);
    });

    test('upsertRecord updates existing record when present', function () {
        KeyValueItem::create([
            'item_key' => 'token_2',
            'item_value' => 'old_val',
            'version' => 1,
        ]);

        $item = KeyValueItem::upsertRecord(
            ['item_key' => 'token_2'],
            ['item_value' => 'new_val', 'version' => 2]
        );

        expect($item->item_key)->toBe('token_2')
            ->and($item->item_value)->toBe('new_val')
            ->and($item->version)->toBe(2);

        expect(KeyValueItem::count())->toBe(1);
    });
});
