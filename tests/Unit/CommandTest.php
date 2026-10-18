<?php

use SlimeSystems\Hatsume\Command;
use SlimeSystems\Hatsume\NotImplemented;
use SlimeSystems\Hatsume\ValidationException;

class DummyCommand implements ArrayAccess {
  use Command;

  public function __construct(
    public ?string $name = null,
    public mixed   $age = null,
    public mixed   $isActive = null,
    public mixed   $score = null,
    public mixed   $emptyField = null,
  ) {
  }

  protected function rules(): array {
    return [
      'name' => ['required', 'string', 'max:50'],
      'age' => ['nullable', 'integer'],
      'isActive' => ['nullable', 'boolean'],
      'score' => ['nullable', 'numeric'],
      'emptyField' => ['nullable', 'string'],
    ];
  }
}

class InvalidCommand implements ArrayAccess {
  use Command;

  public function __construct(
    public ?string $title = null,
  ) {
  }

  protected function rules(): array {
    return [
      'title' => ['required', 'string'],
    ];
  }
}

describe('Command', function () {
  test('extracts attributes ignoring internal cache', function () {
    $command = new DummyCommand(name: 'Alice', age: 30);
    $attributes = $command->attributes();

    expect($attributes)->toHaveKey('name', 'Alice')
      ->toHaveKey('age', 30)
      ->toHaveKey('isActive', null)
      ->not->toHaveKey('validatedCache');
  });

  test('validates and casts attributes correctly', function () {
    $command = new DummyCommand(
      name: 'Bob',
      age: '25',
      isActive: '1',
      score: '98.5',
      emptyField: ''
    );

    $validated = $command->validatedAttributes();

    expect($validated['name'])->toBe('Bob')
      ->and($validated['age'])->toBe(25)
      ->and($validated['isActive'])->toBeTrue()
      ->and($validated['score'])->toBe(98.5)
      ->and($validated['emptyField'])->toBeNull();
  });

  test('caches validated attributes on subsequent calls', function () {
    $command = new DummyCommand(name: 'Charlie', age: 40);

    $first = $command->validatedAttributes();
    $second = $command->validatedAttributes();

    expect($first)->toBe($second);
  });

  test('throws ValidationException when validation fails', function () {
    $command = new InvalidCommand(title: null);

    expect(fn() => $command->validatedAttributes())
      ->toThrow(ValidationException::class);
  });

  test('supports ArrayAccess for validated properties', function () {
    $command = new DummyCommand(name: 'David', age: '28');

    expect(isset($command['name']))->toBeTrue()
      ->and($command['name'])->toBe('David')
      ->and($command['age'])->toBe(28)
      ->and(isset($command['nonExistent']))->toBeFalse()
      ->and($command['nonExistent'])->toBeNull();
  });

  test('throws NotImplemented on ArrayAccess mutation', function () {
    $command = new DummyCommand(name: 'Eve');

    expect(fn() => $command['name'] = 'New Name')
      ->toThrow(NotImplemented::class, 'Validated attributes are immutable.');

    expect(function () use ($command) {
      unset($command['name']);
    })
      ->toThrow(NotImplemented::class, 'Validated attributes are immutable.');
  });
});
