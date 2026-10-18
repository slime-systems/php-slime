<?php

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Bridge\Laravel\EventBusBridge;
use SlimeSystems\Eloquent\ApplicationEventHandler;
use SlimeSystems\Eloquent\EventType;
use SlimeSystems\Eloquent\Interactor;
use SlimeSystems\Eloquent\Replay\EventReplayer;
use SlimeSystems\Eloquent\State;
use SlimeSystems\Eloquent\StateType;
use SlimeSystems\Eloquent\StoredEvent;
use SlimeSystems\Hatsume\Command;
use SlimeSystems\Hatsume\Event;
use SlimeSystems\Hatsume\EventQueue;
use SlimeSystems\Hatsume\EventRouter;
use SlimeSystems\Hatsume\ProcessHelper;
use SlimeSystems\Hatsume\TransitoryEvent;

// Domain Events
class AppTestAccountCreated {
  use Event;
}

class AppTestAccountCredited {
  use Event;
}

class AppTestBalanceAlertEmitted implements TransitoryEvent {
  use Event;
}

// Commands
class AppTestOpenAccountCommand implements ArrayAccess {
  use Command;

  public function __construct(
    public ?string $accountId = null,
    public ?string $owner = null,
  ) {
  }

  protected function rules(): array {
    return [
      'accountId' => ['required', 'string'],
      'owner' => ['required', 'string'],
    ];
  }
}

class AppTestCreditAccountCommand implements ArrayAccess {
  use Command;

  public function __construct(
    public mixed $amount = null,
  ) {
  }

  protected function rules(): array {
    return [
      'amount' => ['required', 'numeric', 'min:1'],
    ];
  }
}

// Domain Process (Aggregate)
class AppTestBankAccountProcess {
  use ProcessHelper;

  public function __construct(array $state = []) {
    $this->state = $this->mergeState([
      'opened' => false,
      'owner' => null,
      'balance' => 0.0,
    ], $state);
  }

  protected static function bootEventHandlers(): void {
    static::on(AppTestAccountCreated::class, function (AppTestAccountCreated $event) {
      $this->state['opened'] = true;
      $this->state['owner'] = $event->data['owner'];
    });

    static::on(AppTestAccountCredited::class, function (AppTestAccountCredited $event) {
      $this->state['balance'] = $event->data['balance'];
    });

    static::on(AppTestBalanceAlertEmitted::class, function (AppTestBalanceAlertEmitted $event) {
      // Transitory events don't mutate aggregate state
    });
  }

  public function call(object $command): void {
    if ($command instanceof AppTestOpenAccountCommand) {
      $attrs = $command->validatedAttributes();
      $this->apply(new AppTestAccountCreated([
        'account_id' => $attrs['accountId'],
        'owner' => $attrs['owner'],
      ]));
    } elseif ($command instanceof AppTestCreditAccountCommand) {
      $attrs = $command->validatedAttributes();
      $newBalance = $this->state['balance'] + (float)$attrs['amount'];

      $this->apply(new AppTestAccountCredited([
        'amount' => (float)$attrs['amount'],
        'balance' => $newBalance,
      ]));

      // If balance exceeds 500, emit a transitory alert event
      if ($newBalance >= 500) {
        $this->apply(new AppTestBalanceAlertEmitted([
          'balance' => $newBalance,
          'message' => 'High balance threshold reached!',
        ]));
      }
    }
  }
}

describe('ApplicationEventHandler & Architecture Integration', function () {
  beforeEach(function () {
    Schema::dropIfExists('stored_events');
    Schema::dropIfExists('process_states');
    Schema::dropIfExists('event_types');
    Schema::dropIfExists('state_types');

    Schema::create('state_types', function ($table) {
      StateType::createBlueprint($table);
    });

    Schema::create('event_types', function ($table) {
      EventType::createBlueprint($table);
    });

    Schema::create('process_states', function ($table) {
      State::createBlueprint($table);
    });

    Schema::create('stored_events', function ($table) {
      StoredEvent::createBlueprint($table);
    });

    StateType::clearCache();
    EventType::clearCache();
  });

  test('executes end-to-end command flow coordinating read model, event store, and Laravel event bus', function () {
    // 1. Read Model Projection Table / State simulation
    $readModel = [
      'accounts' => [],
      'alerts_received' => [],
    ];

    $accountProjectionRouter = new EventRouter([
      AppTestAccountCreated::class => function (AppTestAccountCreated $event) use (&$readModel) {
        $readModel['accounts'][$event->data['account_id']] = [
          'owner' => $event->data['owner'],
          'balance' => 0.0,
        ];
      },
      AppTestAccountCredited::class => function (AppTestAccountCredited $event, array $metadata) use (&$readModel) {
        $accountId = $metadata['state_key']['id'];
        $readModel['accounts'][$accountId]['balance'] = $event->data['balance'];
      },
      AppTestBalanceAlertEmitted::class => function (AppTestBalanceAlertEmitted $event) use (&$readModel) {
        $readModel['alerts_received'][] = $event->data['message'];
      },
    ]);

    $projector = new EventQueue($accountProjectionRouter);

    // 2. Laravel Event Bus simulation
    $laravelDispatcher = new Dispatcher(new Container());
    $busNotifications = [];
    $laravelDispatcher->listen(AppTestBalanceAlertEmitted::class, function ($event) use (&$busNotifications) {
      $busNotifications[] = 'Email sent to owner: ' . $event->data['message'];
    });

    $eventBusBridge = new EventBusBridge($laravelDispatcher);

    // 3. ApplicationEventHandler combining projector, StoredEvent store, and Laravel bus
    $appEventHandler = ApplicationEventHandler::make(
      projector: $projector,
      eventStore: StoredEvent::persistEvents(...),
      eventBus: $eventBusBridge,
    );

    // 4. Interactor setup
    $accountId = 'acc-101';
    $stateKey = ['type' => 'bank_account', 'id' => $accountId];

    $interactor = new Interactor(
      stateKey: $stateKey,
      loadProcess: fn(array $state) => new AppTestBankAccountProcess($state),
      eventHandler: $appEventHandler,
    );

    // Execute Command 1: Open Account
    $events1 = $interactor(new AppTestOpenAccountCommand(
      accountId: $accountId,
      owner: 'Bruce Wayne',
    ));

    expect($events1)->toHaveCount(1)
      ->and($readModel['accounts'][$accountId]['owner'])->toBe('Bruce Wayne')
      ->and($readModel['accounts'][$accountId]['balance'])->toBe(0.0);

    // Execute Command 2: Credit Account with $600 (triggers normal credited event + transitory alert event)
    $events2 = $interactor(new AppTestCreditAccountCommand(amount: 600));

    expect($events2)->toHaveCount(2)
      ->and($events2[0])->toBeInstanceOf(AppTestAccountCredited::class)
      ->and($events2[1])->toBeInstanceOf(AppTestBalanceAlertEmitted::class);

    // VERIFICATION:
    // A. Read model received all events including the transitory alert
    expect($readModel['accounts'][$accountId]['balance'])->toBe(600.0)
      ->and($readModel['alerts_received'])->toHaveCount(1)
      ->and($readModel['alerts_received'][0])->toBe('High balance threshold reached!');

    // B. Laravel Event Bus bridge triggered listener for the alert
    expect($busNotifications)->toHaveCount(1)
      ->and($busNotifications[0])->toBe('Email sent to owner: High balance threshold reached!');

    // C. StoredEvent table contains ONLY the 2 persistent events (TransitoryEvent is excluded!)
    $storedRecords = StoredEvent::forAggregate($stateKey)->get();
    expect($storedRecords)->toHaveCount(2)
      ->and($storedRecords[0]->getEventClass())->toBe(AppTestAccountCreated::class)
      ->and($storedRecords[0]->position)->toBe(1)
      ->and($storedRecords[1]->getEventClass())->toBe(AppTestAccountCredited::class)
      ->and($storedRecords[1]->position)->toBe(2);

    // D. Replayability Test: A completely clean read model can be rebuilt by replaying from StoredEvent
    $rebuiltReadModel = [];
    $replayProjector = new EventQueue(new EventRouter([
      AppTestAccountCreated::class => function (AppTestAccountCreated $event) use (&$rebuiltReadModel) {
        $rebuiltReadModel['owner'] = $event->data['owner'];
      },
      AppTestAccountCredited::class => function (AppTestAccountCredited $event) use (&$rebuiltReadModel) {
        $rebuiltReadModel['balance'] = $event->data['balance'];
      },
    ]));

    $replayedCount = EventReplayer::forAggregate($stateKey, $replayProjector);

    expect($replayedCount)->toBe(2)
      ->and($rebuiltReadModel['owner'])->toBe('Bruce Wayne')
      ->and($rebuiltReadModel['balance'])->toBe(600.0)
      ->and($busNotifications)->toHaveCount(1); // Crucial: Replaying did NOT re-fire Laravel bus side-effects!
  });
});
