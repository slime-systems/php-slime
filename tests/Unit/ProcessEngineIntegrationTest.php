<?php

use SlimeSystems\Hatsume\Command;
use SlimeSystems\Hatsume\Event;
use SlimeSystems\Hatsume\EventDispatcher;
use SlimeSystems\Hatsume\EventQueue;
use SlimeSystems\Hatsume\EventRouter;
use SlimeSystems\Hatsume\ProcessHelper;
use SlimeSystems\Hatsume\TransitoryEvent;
use SlimeSystems\Hatsume\UnknownCommand;

class CreateAccountCommand {
  use Command;

  public function __construct(public string $email = '') {
  }

  protected function rules(): array {
    return [];
  }
}

class RenameAccountCommand {
  use Command;

  public function __construct(public string $displayName = '') {
  }

  protected function rules(): array {
    return [];
  }
}

class AccountCreated {
  use Event;
}

class AccountRenamed {
  use Event;
}

class WelcomeEmailSent implements TransitoryEvent {
  use Event;
}

class AccountProcess {
  use ProcessHelper;

  public function __construct(?array $state = null) {
    $this->state = $this->mergeState(['id' => null, 'displayName' => null], $state);

    $this->on(AccountCreated::class, fn(AccountCreated $e) => $this->state['id'] = $e->data['id']);
    $this->on(AccountRenamed::class, fn(AccountRenamed $e) => $this->state['displayName'] = $e->data['displayName']);
  }

  public function __invoke(object $cmd): void {
    match ($cmd::class) {
      CreateAccountCommand::class => $this->apply(
        new AccountCreated(['id' => 42, 'email' => $cmd->email]),
        new WelcomeEmailSent(['email' => $cmd->email]),
      ),
      RenameAccountCommand::class => $this->apply(
        new AccountRenamed(['displayName' => $cmd->displayName]),
      ),
      default => throw new UnknownCommand($cmd),
    };
  }
}

class AccountProjection {
  public array $accounts = [];

  public function onCreated(AccountCreated $event, array $meta = []): void {
    $this->accounts[$event->data['id']] = [
      'email' => $event->data['email'],
      'created_at' => $meta['timestamp'] ?? null,
    ];
  }

  public function onRenamed(AccountRenamed $event, array $meta = []): void {
    $this->accounts[$meta['account_id']]['displayName'] = $event->data['displayName'];
  }
}

describe('Process Engine Integration (Hatsume Core)', function () {
  test('executes end-to-end command-process-event cycle with projections and persistence filtering', function () {
    $projection = new AccountProjection();
    $router = new EventRouter([
      AccountCreated::class => [$projection, 'onCreated'],
      AccountRenamed::class => [$projection, 'onRenamed'],
    ]);

    $journal = [];
    $bus = new EventDispatcher(
      new EventQueue($router),
      function (array $events, array $meta) use (&$journal) {
        foreach ($events as $event) {
          if (!($event instanceof TransitoryEvent)) {
            $journal[] = ['event' => $event, 'meta' => $meta];
          }
        }
      }
    );

    // 1. Initial command execution
    $process = new AccountProcess();
    $process(new CreateAccountCommand(email: 'alice@example.com'));

    expect($process->state()['id'])->toBe(42)
      ->and($process->pendingEvents())->toHaveCount(2);

    $bus($process->pendingEvents(), ['timestamp' => 1700000000, 'account_id' => 42]);

    expect($projection->accounts[42]['email'])->toBe('alice@example.com')
      ->and($projection->accounts[42]['created_at'])->toBe(1700000000)
      ->and($journal)->toHaveCount(1)
      ->and($journal[0]['event'])->toBeInstanceOf(AccountCreated::class);

    // 2. Rehydration and subsequent command
    $rehydrated = new AccountProcess($process->state());
    $rehydrated(new RenameAccountCommand(displayName: 'Alice Wonderland'));

    $bus($rehydrated->pendingEvents(), ['account_id' => 42]);

    expect($rehydrated->state()['displayName'])->toBe('Alice Wonderland')
      ->and($projection->accounts[42]['displayName'])->toBe('Alice Wonderland')
      ->and($journal)->toHaveCount(2);

    // 3. Unregistered command handling
    expect(fn() => $process(new stdClass()))->toThrow(UnknownCommand::class);
  });
});
