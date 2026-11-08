<?php

namespace SlimeSystems\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IsCallable implements ValidationRule {
  public function validate(string $attribute, mixed $value, Closure $fail): void {
    if (!is_callable($value)) {
      $fail('The :attribute must be a valid callable.');
    }
  }
}
