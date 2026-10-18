<?php

use SlimeSystems\Hatsume\NotImplemented;
use SlimeSystems\Hatsume\UnknownCommand;

describe('UnknownCommand', function () {
  test('formats message with string command name', function () {
    $exception = new UnknownCommand('DoSomething');

    expect($exception)->toBeInstanceOf(NotImplemented::class)
      ->and($exception->getMessage())->toBe('Unknown command: "DoSomething"')
      ->and($exception->command)->toBe('DoSomething');
  });

  test('extracts class name when constructed with command object', function () {
    $dummy = new stdClass();
    $exception = new UnknownCommand($dummy);

    expect($exception->command)->toBe(stdClass::class)
      ->and($exception->getMessage())->toBe('Unknown command: "' . stdClass::class . '"');
  });

  test('defaults to generic message when constructed without command', function () {
    $exception = new UnknownCommand();

    expect($exception->command)->toBeNull()
      ->and($exception->getMessage())->toBe('Unknown command');
  });
});
