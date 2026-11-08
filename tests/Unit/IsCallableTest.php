<?php

use Illuminate\Support\Facades\Validator;
use SlimeSystems\Validation\Rules\IsCallable;

describe('IsCallable Validation Rule', function () {
  test('validates callables correctly', function () {
    $validator = Validator::make([
      'closure' => fn() => true,
      'string_func' => 'strlen',
      'invalid' => 'not_a_callable_func_name',
    ], [
      'closure' => [new IsCallable()],
      'string_func' => [new IsCallable()],
      'invalid' => [new IsCallable()],
    ]);

    expect($validator->fails())->toBeTrue()
      ->and($validator->errors()->has('closure'))->toBeFalse()
      ->and($validator->errors()->has('string_func'))->toBeFalse()
      ->and($validator->errors()->has('invalid'))->toBeTrue();
  });
});

