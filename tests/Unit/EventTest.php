<?php

use SlimeSystems\Hatsume\Event;
use SlimeSystems\Hatsume\ValidationException;

class StandardEvent {
  use Event;
}

class ValidatedEvent {
  use Event;

  protected static function rules(): array {
    return [
      'user_id' => ['required', 'integer'],
      'email' => ['required', 'email'],
      'status' => ['sometimes', 'string'],
    ];
  }
}

describe('Event', function () {
  test('instantiates via constructor without validation', function () {
    $data = ['user_id' => 'abc', 'email' => 'invalid-email'];
    $metadata = ['timestamp' => 1234567890];

    $event = new StandardEvent($data, $metadata);

    expect($event->data)->toBe($data)
      ->and($event->metadata)->toBe($metadata);
  });

  test('creates valid event using strict factory constructor', function () {
    $data = ['user_id' => 42, 'email' => 'test@example.com'];
    $metadata = ['ip' => '127.0.0.1'];

    $event = ValidatedEvent::strict($data, $metadata);

    expect($event)->toBeInstanceOf(ValidatedEvent::class)
      ->and($event->data)->toBe($data)
      ->and($event->metadata)->toBe($metadata);
  });

  test('throws ValidationException when strict validation fails', function () {
    $invalidData = ['user_id' => 'not-an-int', 'email' => 'not-an-email'];

    expect(fn() => ValidatedEvent::strict($invalidData))
      ->toThrow(ValidationException::class);
  });

  test('strict constructor allows empty rules on base event', function () {
    $data = ['any_key' => 'any_value'];
    $event = StandardEvent::strict($data);

    expect($event)->toBeInstanceOf(StandardEvent::class)
      ->and($event->data)->toBe($data)
      ->and($event->metadata)->toBeNull();
  });
});
