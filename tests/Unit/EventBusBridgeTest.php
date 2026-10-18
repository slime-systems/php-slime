<?php

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use SlimeSystems\Bridge\Laravel\EventBusBridge;
use SlimeSystems\Hatsume\Event;

class BridgeTestOrderPlaced {
  use Event;
}

class BridgeTestOrderShipped {
  use Event;
}

describe('EventBusBridge', function () {
  test('forwards domain events to Illuminate\\Events\\Dispatcher', function () {
    $container = new Container();
    $dispatcher = new Dispatcher($container);

    $capturedPlaced = [];
    $capturedShipped = [];

    $dispatcher->listen(BridgeTestOrderPlaced::class, function ($event) use (&$capturedPlaced) {
      $capturedPlaced[] = $event;
    });

    $dispatcher->listen(BridgeTestOrderShipped::class, function ($event) use (&$capturedShipped) {
      $capturedShipped[] = $event;
    });

    $bridge = new EventBusBridge($dispatcher);

    $event1 = new BridgeTestOrderPlaced(['order_id' => 101]);
    $event2 = new BridgeTestOrderShipped(['order_id' => 101, 'tracking' => 'XYZ']);

    $bridge([$event1, $event2], ['state_key' => 'order:101']);

    expect($capturedPlaced)->toHaveCount(1)
      ->and($capturedPlaced[0]->data['order_id'])->toBe(101)
      ->and($capturedShipped)->toHaveCount(1)
      ->and($capturedShipped[0]->data['tracking'])->toBe('XYZ');
  });

  test('supports single event dispatch and explicit call method', function () {
    $dispatcher = new Dispatcher(new Container());

    $received = null;
    $dispatcher->listen(BridgeTestOrderPlaced::class, function ($event) use (&$received) {
      $received = $event;
    });

    $bridge = new EventBusBridge($dispatcher);
    $singleEvent = new BridgeTestOrderPlaced(['order_id' => 999]);

    $bridge->call($singleEvent);

    expect($received)->not->toBeNull()
      ->and($received->data['order_id'])->toBe(999);
  });
});
