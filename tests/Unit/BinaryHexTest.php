<?php

declare(strict_types = 1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Database\Schema\SlimeBlueprint;
use SlimeSystems\Eloquent\Casts\BinaryHexCast;
use SlimeSystems\Support\BinaryHex;

class TestBinarySession extends Model {
  public $timestamps = false;
  public $incrementing = false;
  protected $table = 'test_binary_sessions';
  protected $keyType = 'string';
  protected $guarded = [];

  protected $casts = [
    'id' => BinaryHexCast::class,
    'device_id' => BinaryHexCast::class,
  ];
}

describe('BinaryHex & BinaryHexCast', function () {
  beforeEach(function () {
    SlimeBlueprint::registerMacros();

    Schema::dropIfExists('test_binary_sessions');
    Schema::create('test_binary_sessions', function (Blueprint $table) {
      $table->binaryUuid('id')->primary();
      $table->fixedBinary('device_id', 16)->nullable();
      $table->string('name')->nullable();
    });
  });

  afterEach(function () {
    Schema::dropIfExists('test_binary_sessions');
  });

  it('registers blueprint macros and compatibility aliases', function () {
    expect(Blueprint::hasMacro('binaryUuid'))->toBeTrue();
    expect(Blueprint::hasMacro('fixedBinary'))->toBeTrue();
    expect(Blueprint::hasMacro('bytes'))->toBeTrue();
  });

  it('handles value object creation, conversions, and serialization', function () {
    $rawBytes = random_bytes(16);
    $hexString = bin2hex($rawBytes);

    $fromBinary = BinaryHex::fromBinary($rawBytes);
    $fromHex = BinaryHex::fromHex($hexString);
    $fromMixed = BinaryHex::from($hexString);

    expect($fromBinary->toHex())->toBe($hexString);
    expect($fromBinary->toBinary())->toBe($rawBytes);
    expect($fromBinary->toString())->toBe($hexString);
    expect((string)$fromBinary)->toBe($rawBytes);
    expect($fromBinary->jsonSerialize())->toBe($hexString);

    expect($fromBinary->equals($fromHex))->toBeTrue();
    expect($fromBinary->equals($hexString))->toBeTrue();
    expect($fromBinary->equals($rawBytes))->toBeTrue();
    expect($fromBinary->equals('different'))->toBeFalse();
    expect($fromMixed->equals($fromBinary))->toBeTrue();
  });

  it('rejects invalid hex strings in fromHex', function () {
    expect(fn() => BinaryHex::fromHex('not-hex!'))->toThrow(InvalidArgumentException::class);
    expect(fn() => BinaryHex::fromHex('12345'))->toThrow(InvalidArgumentException::class); // odd length
  });

  it('resolves raw binary via val() helper across all supported input formats', function () {
    $rawBytes = random_bytes(16);
    $hexString = bin2hex($rawBytes);
    $obj = BinaryHex::fromBinary($rawBytes);

    expect(BinaryHex::val(null))->toBeNull();
    expect(BinaryHex::val($obj))->toBe($rawBytes);
    expect(BinaryHex::val($hexString))->toBe($rawBytes);
    expect(BinaryHex::val($rawBytes))->toBe($rawBytes);

    // Test stream resource handling
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $rawBytes);
    rewind($stream);
    expect(BinaryHex::val($stream))->toBe($rawBytes);
    fclose($stream);
  });

  it('casts model binary attributes to BinaryHex and serializes to hex for JSON', function () {
    $idBytes = random_bytes(16);
    $idHex = bin2hex($idBytes);
    $deviceIdBytes = random_bytes(16);
    $deviceIdHex = bin2hex($deviceIdBytes);

    // Create via hex strings
    $model = TestBinarySession::create([
      'id' => $idHex,
      'device_id' => $deviceIdHex,
      'name' => 'Session 1',
    ]);

    expect($model->id)->toBeInstanceOf(BinaryHex::class);
    expect($model->id->toHex())->toBe($idHex);
    expect($model->device_id)->toBeInstanceOf(BinaryHex::class);
    expect($model->device_id->toHex())->toBe($deviceIdHex);

    // Query via BinaryHex::val
    $found = TestBinarySession::find(BinaryHex::val($idHex));
    expect($found)->not->toBeNull();
    expect($found->name)->toBe('Session 1');
    expect($found->id->toHex())->toBe($idHex);

    // Array and JSON serialization
    $array = $found->toArray();
    expect($array['id'])->toBe($idHex);
    expect($array['device_id'])->toBe($deviceIdHex);

    $json = json_decode($found->toJson(), true);
    expect($json['id'])->toBe($idHex);
    expect($json['device_id'])->toBe($deviceIdHex);
  });
});
