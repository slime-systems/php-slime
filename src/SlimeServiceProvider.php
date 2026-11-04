<?php

declare(strict_types = 1);

namespace SlimeSystems;

use Illuminate\Support\ServiceProvider;
use SlimeSystems\Database\Schema\SlimeBlueprint;

class SlimeServiceProvider extends ServiceProvider {
  /**
   * Bootstrap package services and macros.
   */
  public function boot(): void {
    SlimeBlueprint::registerMacros();
  }
}

