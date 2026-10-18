<?php

use SlimeSystems\Hatsume\Event;
use SlimeSystems\Hatsume\ProcessHelper;

class OrderItemAdded {
  use Event;
}

class OrderSubmitted {
  use Event;
}

class OrderCancelled {
  use Event;
}

/**
 * Note: In full CQRS implementations, aggregate processes typically expose
 * a `call(object $command)` method with an `UnknownCommand` fallback (see
 * ProcessEngineIntegrationTest). DummyProcess below uses direct helper methods
 * solely to test ProcessHelper's internal event-application and state-machine
 * mechanics in isolation.
 */
class DummyProcess {
  use ProcessHelper;

  public function __construct(?array $initialState = null) {
    $this->state = $this->mergeState([
      'status' => 'draft',
      'items_count' => 0,
    ], $initialState);
  }

  public function addItem(): void {
    $this->apply(new OrderItemAdded());
  }

  public function submit(): void {
    $this->apply(new OrderSubmitted());
  }

  public function cancel(): void {
    $this->apply(new OrderCancelled());
  }

  public function applyMultiple(array $events): void {
    $this->apply(...$events);
  }

  public function applyWithNulls(): void {
    $this->apply(new OrderItemAdded(), null, [new OrderItemAdded(), null]);
  }

  protected function registerEventHandlers(): void {
    $this->on(OrderItemAdded::class, function (OrderItemAdded $event) {
      $this->state['items_count']++;
    });

    $this->on(OrderSubmitted::class, function (OrderSubmitted $event) {
      $this->state['status'] = 'submitted';
    });

    $this->on(OrderCancelled::class, function (OrderCancelled $event) {
      $this->state['status'] = 'cancelled';
    });
  }
}

class SubProcessA {
  use ProcessHelper;

  public function trigger(): void {
    $this->apply(new OrderItemAdded());
  }

  protected function registerEventHandlers(): void {
    $this->on(OrderItemAdded::class, function () {
      $this->state['sub'] = 'A';
    });
  }
}

class SubProcessB {
  use ProcessHelper;

  public function trigger(): void {
    $this->apply(new OrderItemAdded());
  }

  protected function registerEventHandlers(): void {
    $this->on(OrderItemAdded::class, function () {
      $this->state['sub'] = 'B';
    });
  }
}

describe('ProcessHelper', function () {
  test('initializes state using mergeState with defaults', function () {
    $process = new DummyProcess();

    expect($process->state())->toBe([
      'status' => 'draft',
      'items_count' => 0,
    ]);
  });

  test('overrides default state with recorded state', function () {
    $process = new DummyProcess([
      'status' => 'submitted',
      'items_count' => 3,
      'extra' => 'custom',
    ]);

    expect($process->state())->toBe([
      'status' => 'submitted',
      'items_count' => 3,
      'extra' => 'custom',
    ]);
  });

  test('allows state mutation via reference', function () {
    $process = new DummyProcess();
    $process->state()['note'] = 'updated by ref';

    expect($process->state())->toHaveKey('note', 'updated by ref');
  });

  test('applies single event and updates state via handler', function () {
    $process = new DummyProcess();
    $process->addItem();

    expect($process->state()['items_count'])->toBe(1)
      ->and($process->pendingEvents())->toHaveCount(1)
      ->and($process->pendingEvents()[0])->toBeInstanceOf(OrderItemAdded::class);
  });

  test('applies multiple events in order', function () {
    $process = new DummyProcess();
    $process->addItem();
    $process->addItem();
    $process->submit();

    expect($process->state()['items_count'])->toBe(2)
      ->and($process->state()['status'])->toBe('submitted')
      ->and($process->pendingEvents())->toHaveCount(3);
  });

  test('applies events passed as array or varargs', function () {
    $process = new DummyProcess();
    $process->applyMultiple([
      new OrderItemAdded(),
      [new OrderItemAdded(), new OrderSubmitted()],
    ]);

    expect($process->state()['items_count'])->toBe(2)
      ->and($process->state()['status'])->toBe('submitted')
      ->and($process->pendingEvents())->toHaveCount(3);
  });

  test('safely ignores null values during apply', function () {
    $process = new DummyProcess();
    $process->applyWithNulls();

    expect($process->state()['items_count'])->toBe(2)
      ->and($process->pendingEvents())->toHaveCount(2);
  });

  test('isolates event handlers between different process instances and classes', function () {
    $processA = new SubProcessA();
    $processB = new SubProcessB();

    $processA->trigger();
    $processB->trigger();

    expect($processA->state()['sub'])->toBe('A')
      ->and($processB->state()['sub'])->toBe('B');
  });

  test('lazily registers event handlers on getEventHandlers', function () {
    $process = new DummyProcess();

    expect($process->getEventHandlers())->toHaveKeys([
      OrderItemAdded::class,
      OrderSubmitted::class,
      OrderCancelled::class,
    ]);
  });
});
