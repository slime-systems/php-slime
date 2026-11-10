<?php

declare(strict_types = 1);

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as LaravelDispatcher;
use SlimeSystems\Bridge\Laravel\EventBusBridge;
use SlimeSystems\Hatsume\Event;
use SlimeSystems\Hatsume\EventDispatcher;
use SlimeSystems\Hatsume\EventQueue;
use SlimeSystems\Hatsume\EventRouter;

class PlumbingEventA {
  use Event;
}

class PlumbingEventB {
  use Event;
}

describe('Event Plumbing (Dispatcher, Queue, Router, BusBridge)', function () {
  it('dispatches events and metadata across multiple handlers', function () {
    $log1 = [];
    $log2 = [];

    $dispatcher = new EventDispatcher(
      function ($events, $meta) use (&$log1) {
        $log1[] = [$events, $meta];
      },
      function ($events, $meta) use (&$log2) {
        $log2[] = [$events, $meta];
      },
    );

    $e = new PlumbingEventA(['id' => 1]);
    $dispatcher([$e], ['trace' => 'xyz']);

    expect($log1)->toHaveCount(1)
      ->and($log2)->toHaveCount(1)
      ->and($log1[0][1]['trace'])->toBe('xyz');
  });

  it('unpacks batches in EventQueue and routes by class in EventRouter', function () {
    $receivedA = [];
    $receivedB = [];

    $router = new EventRouter([
      PlumbingEventA::class => function ($e, $meta) use (&$receivedA) {
        $receivedA[] = [$e->data, $meta];
      },
      PlumbingEventB::class => function ($e, $meta) use (&$receivedB) {
        $receivedB[] = [$e->data, $meta];
      },
    ]);

    $queue = new EventQueue($router);

    $events = [
      new PlumbingEventA(['val' => 10]),
      new PlumbingEventB(['val' => 20]),
      new class {
        use Event;
      }, // unhandled event safely ignored
    ];

    $queue($events, ['batch' => 'b1']);

    expect($receivedA)->toHaveCount(1)
      ->and($receivedA[0][0]['val'])->toBe(10)
      ->and($receivedA[0][1]['batch'])->toBe('b1')
      ->and($receivedB)->toHaveCount(1)
      ->and($receivedB[0][0]['val'])->toBe(20);
  });

  it('forwards domain events to Laravel EventBus via EventBusBridge', function () {
    $container = new Container();
    $laravelDispatcher = new LaravelDispatcher($container);

    $forwarded = [];
    $laravelDispatcher->listen(PlumbingEventA::class, function ($event) use (&$forwarded) {
      $forwarded[] = $event;
    });

    $bridge = new EventBusBridge($laravelDispatcher);
    $event = new PlumbingEventA(['k' => 'v']);
    $bridge([$event]);

    expect($forwarded)->toHaveCount(1)
      ->and($forwarded[0]->data['k'])->toBe('v');
  });
});
