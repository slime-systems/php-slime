<?php

use SlimeSystems\Hatsume\Command;
use SlimeSystems\Hatsume\Event;
use SlimeSystems\Hatsume\EventDispatcher;
use SlimeSystems\Hatsume\EventQueue;
use SlimeSystems\Hatsume\EventRouter;
use SlimeSystems\Hatsume\ProcessHelper;
use SlimeSystems\Hatsume\TransitoryEvent;
use SlimeSystems\Hatsume\UnknownCommand;
use SlimeSystems\Hatsume\ValidationException;

// Domain Commands
class CreateAccountCommand implements ArrayAccess {
  use Command;

  public function __construct(
    public ?string $userId = null,
    public ?string $email = null,
  ) {
  }

  protected function rules(): array {
    return [
      'userId' => ['required', 'string', 'max:50'],
      'email' => ['required', 'email'],
    ];
  }
}

class RenameAccountCommand implements ArrayAccess {
  use Command;

  public function __construct(
    public ?string $displayName = null,
  ) {
  }

  protected function rules(): array {
    return [
      'displayName' => ['required', 'string', 'min:2'],
    ];
  }
}

class UnregisteredCommand implements ArrayAccess {
  use Command;

  protected function rules(): array {
    return [];
  }
}

// Domain Events
class AccountCreated {
  use Event;

  protected static function rules(): array {
    return [
      'id' => ['required', 'integer'],
      'userId' => ['required', 'string'],
      'email' => ['required', 'email'],
    ];
  }
}

class AccountRenamed {
  use Event;

  protected static function rules(): array {
    return [
      'displayName' => ['required', 'string'],
    ];
  }
}

class AccountWelcomeEmailDispatched implements TransitoryEvent {
  use Event;
}

// Domain Exceptions
class AccountAlreadyExists extends RuntimeException {
}

class AccountNotFound extends RuntimeException {
}

// Aggregate Process
class AccountProcess {
  use ProcessHelper;

  public function __construct(
    ?array           $state = null,
    private ?Closure $generateId = null,
  ) {
    $this->state = $this->mergeState([
      'id' => null,
      'created' => false,
      'userId' => null,
      'email' => null,
      'displayName' => null,
    ], $state);
  }

  public function call(object $command): void {
    match ($command::class) {
      CreateAccountCommand::class => $this->handleCreate($command),
      RenameAccountCommand::class => $this->handleRename($command),
      default => throw new UnknownCommand($command),
    };
  }

  private function handleCreate(CreateAccountCommand $command): void {
    if ($this->state['created']) {
      throw new AccountAlreadyExists('Account is already created.');
    }

    $attrs = $command->validatedAttributes();
    $id = $this->generateId ? ($this->generateId)() : 1001;

    $createdEvent = AccountCreated::strict([
      'id' => $id,
      'userId' => $attrs['userId'],
      'email' => $attrs['email'],
    ]);

    $transitoryEvent = new AccountWelcomeEmailDispatched([
      'email' => $attrs['email'],
      'message' => 'Welcome to the platform!',
    ]);

    $this->apply($createdEvent, $transitoryEvent);
  }

  private function handleRename(RenameAccountCommand $command): void {
    if (!$this->state['created']) {
      throw new AccountNotFound('Cannot rename non-existent account.');
    }

    $attrs = $command->validatedAttributes();

    $this->apply(AccountRenamed::strict([
      'displayName' => $attrs['displayName'],
    ]));
  }

  protected function registerEventHandlers(): void {
    $this->on(AccountCreated::class, function (AccountCreated $event) {
      $this->state['id'] = $event->data['id'];
      $this->state['created'] = true;
      $this->state['userId'] = $event->data['userId'];
      $this->state['email'] = $event->data['email'];
    });

    $this->on(AccountRenamed::class, function (AccountRenamed $event) {
      $this->state['displayName'] = $event->data['displayName'];
    });
  }
}

// Read Model Projection
class AccountProjection {
  public array $accounts = [];

  public function onAccountCreated(AccountCreated $event, array $metadata = []): void {
    $this->accounts[$event->data['id']] = [
      'id' => $event->data['id'],
      'userId' => $event->data['userId'],
      'email' => $event->data['email'],
      'displayName' => null,
      'created_at' => $metadata['timestamp'] ?? null,
    ];
  }

  public function onAccountRenamed(AccountRenamed $event, array $metadata = []): void {
    $id = $metadata['account_id'] ?? null;
    if ($id && isset($this->accounts[$id])) {
      $this->accounts[$id]['displayName'] = $event->data['displayName'];
    }
  }
}

describe('Process Engine Integration (Hatsume Core)', function () {
  test('executes end-to-end command-process-event cycle with projections and persistence filtering', function () {
    // 1. Setup Read Model Projection & Router
    $projection = new AccountProjection();
    $projectionRouter = new EventRouter([
      AccountCreated::class => [$projection, 'onAccountCreated'],
      AccountRenamed::class => [$projection, 'onAccountRenamed'],
    ]);

    // 2. Setup Bus: EventQueue for individual projection routing, plus persistent store
    $eventJournal = [];
    $persistHandler = function (array $events, array $metadata) use (&$eventJournal) {
      // Filter out TransitoryEvents from persistent storage
      $persistentEvents = array_filter(
        $events,
        fn($e) => !($e instanceof TransitoryEvent)
      );
      foreach ($persistentEvents as $e) {
        $eventJournal[] = ['event' => $e, 'metadata' => $metadata];
      }
    };

    $bus = new EventDispatcher(
      new EventQueue($projectionRouter),
      $persistHandler
    );

    // 3. Create aggregate and execute CreateAccountCommand
    $process = new AccountProcess(generateId: fn() => 42);

    $command = new CreateAccountCommand(
      userId: 'usr_abc123',
      email: 'alice@example.com'
    );

    $process->call($command);

    // Verify Aggregate state updated
    expect($process->state()['created'])->toBeTrue()
      ->and($process->state()['id'])->toBe(42)
      ->and($process->state()['userId'])->toBe('usr_abc123')
      ->and($process->pendingEvents())->toHaveCount(2);

    // Dispatch pending events through bus
    $metadata = ['timestamp' => 1700000000, 'account_id' => 42];
    $bus->call($process->pendingEvents(), $metadata);

    // Verify Projection received domain event
    expect($projection->accounts)->toHaveKey(42)
      ->and($projection->accounts[42]['email'])->toBe('alice@example.com')
      ->and($projection->accounts[42]['created_at'])->toBe(1700000000);

    // Verify Event Journal recorded only non-transitory event
    expect($eventJournal)->toHaveCount(1)
      ->and($eventJournal[0]['event'])->toBeInstanceOf(AccountCreated::class)
      ->and($eventJournal[0]['event'])->not->toBeInstanceOf(TransitoryEvent::class);

    // 4. Re-hydrate aggregate from state and execute RenameAccountCommand
    $rehydratedProcess = new AccountProcess($process->state());

    $renameCommand = new RenameAccountCommand(displayName: 'Alice Wonderland');
    $rehydratedProcess->call($renameCommand);

    expect($rehydratedProcess->state()['displayName'])->toBe('Alice Wonderland')
      ->and($rehydratedProcess->pendingEvents())->toHaveCount(1);

    // Dispatch rename event
    $bus->call($rehydratedProcess->pendingEvents(), ['account_id' => 42]);

    // Projection updated
    expect($projection->accounts[42]['displayName'])->toBe('Alice Wonderland');
    expect($eventJournal)->toHaveCount(2);
  });

  test('throws ValidationException on invalid command data', function () {
    $process = new AccountProcess();
    $invalidCommand = new CreateAccountCommand(userId: '', email: 'not-an-email');

    expect(fn() => $process->call($invalidCommand))
      ->toThrow(ValidationException::class);
  });

  test('throws UnknownCommand on unregistered command', function () {
    $process = new AccountProcess();
    $unregistered = new UnregisteredCommand();

    expect(fn() => $process->call($unregistered))
      ->toThrow(UnknownCommand::class);
  });

  test('enforces aggregate domain invariants', function () {
    $process = new AccountProcess(['created' => true]);
    $command = new CreateAccountCommand(userId: 'usr_xyz', email: 'bob@example.com');

    expect(fn() => $process->call($command))
      ->toThrow(AccountAlreadyExists::class);
  });
});
