<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\Interactor;
use SlimeSystems\Eloquent\StaleRecordException;
use SlimeSystems\Eloquent\State;
use SlimeSystems\Eloquent\StateType;
use SlimeSystems\Hatsume\Command;
use SlimeSystems\Hatsume\Event;
use SlimeSystems\Hatsume\EventDispatcher;
use SlimeSystems\Hatsume\ProcessHelper;
use SlimeSystems\Hatsume\UnknownCommand;

// Test Commands
class OpenWalletCommand {
    use Command;

    public function __construct(
        public ?string $owner = null,
        public int $initialBalance = 0,
    ) {}

    protected function rules(): array {
        return [
            'owner' => ['required', 'string'],
            'initialBalance' => ['integer', 'min:0'],
        ];
    }
}

class DepositFundsCommand {
    use Command;

    public function __construct(
        public int $amount = 0,
    ) {}

    protected function rules(): array {
        return [
            'amount' => ['required', 'integer', 'min:1'],
        ];
    }
}

class NoopCommand {
    use Command;

    protected function rules(): array {
        return [];
    }
}

// Test Events
class WalletOpened {
    use Event;

    protected static function rules(): array {
        return [
            'owner' => ['required', 'string'],
            'balance' => ['required', 'integer'],
        ];
    }
}

class FundsDeposited {
    use Event;

    protected static function rules(): array {
        return [
            'amount' => ['required', 'integer'],
            'newBalance' => ['required', 'integer'],
        ];
    }
}

// Test Aggregate Process
class WalletProcess {
    use ProcessHelper;

    public function __construct(?array $state = null) {
        $this->state = $this->mergeState([
            'opened' => false,
            'owner' => null,
            'balance' => 0,
        ], $state);
    }

    protected function registerEventHandlers(): void {
        $this->on(WalletOpened::class, function (WalletOpened $event) {
            $this->state['opened'] = true;
            $this->state['owner'] = $event->data['owner'];
            $this->state['balance'] = $event->data['balance'];
        });

        $this->on(FundsDeposited::class, function (FundsDeposited $event) {
            $this->state['balance'] = $event->data['newBalance'];
        });
    }

    public function call(object $command): void {
        match ($command::class) {
            OpenWalletCommand::class => $this->handleOpen($command),
            DepositFundsCommand::class => $this->handleDeposit($command),
            NoopCommand::class => null, // produces no events
            default => throw new UnknownCommand($command),
        };
    }

    private function handleOpen(OpenWalletCommand $command): void {
        if ($this->state['opened']) {
            throw new RuntimeException('Wallet already opened.');
        }

        $attrs = $command->validatedAttributes();
        $this->apply(WalletOpened::strict([
            'owner' => $attrs['owner'],
            'balance' => $attrs['initialBalance'],
        ]));
    }

    private function handleDeposit(DepositFundsCommand $command): void {
        if (!$this->state['opened']) {
            throw new RuntimeException('Cannot deposit to unopened wallet.');
        }

        $attrs = $command->validatedAttributes();
        $newBalance = $this->state['balance'] + $attrs['amount'];

        $this->apply(FundsDeposited::strict([
            'amount' => $attrs['amount'],
            'newBalance' => $newBalance,
        ]));
    }
}

describe('Eloquent Interactor', function () {
    beforeEach(function () {
        StateType::clearCache();
        Schema::dropIfExists('process_states');
        Schema::dropIfExists('state_types');

        Schema::create('state_types', function (Blueprint $table) {
            StateType::createBlueprint($table);
        });
        Schema::create('process_states', function (Blueprint $table) {
            State::createBlueprint($table);
        });
    });

    afterEach(function () {
        StateType::clearCache();
    });

    test('executes command, persists state, and dispatches events with metadata', function () {
        $dispatchedEvents = [];
        $dispatchedMetadata = [];

        $eventHandler = function (array $events, array $metadata) use (&$dispatchedEvents, &$dispatchedMetadata) {
            $dispatchedEvents = $events;
            $dispatchedMetadata = $metadata;
        };

        $interactor = new Interactor(
            stateKey: ['type' => 'wallet', 'id' => 'wallet-001'],
            loadProcess: fn (?array $state = null) => new WalletProcess($state),
            eventHandler: $eventHandler,
        );

        $events = $interactor->call(new OpenWalletCommand(owner: 'Alice', initialBalance: 100));

        // Returns pending events
        expect($events)->toHaveCount(1)
            ->and($events[0])->toBeInstanceOf(WalletOpened::class)
            ->and($events[0]->data['owner'])->toBe('Alice')
            ->and($events[0]->data['balance'])->toBe(100);

        // Verifies database persistence with type_id and state_id
        $record = State::findByTypeAndId('wallet', 'wallet-001');
        expect($record)->not->toBeNull()
            ->and($record->getTypeName())->toBe('wallet')
            ->and($record->getStateId())->toBe('wallet-001')
            ->and($record->state['opened'])->toBeTrue()
            ->and($record->state['owner'])->toBe('Alice')
            ->and($record->state['balance'])->toBe(100)
            ->and($record->next_position)->toBe(2)
            ->and($record->lock_version)->toBe(0);

        // Verifies event dispatching and metadata
        expect($dispatchedEvents)->toHaveCount(1)
            ->and($dispatchedMetadata['state_key'])->toBe(['type' => 'wallet', 'id' => 'wallet-001'])
            ->and($dispatchedMetadata['next_position'])->toBe(1);
    });

    test('supports integer and bigint state_id values', function () {
        $bigintId = 9007199254740991;

        $interactor = new Interactor(
            stateKey: ['type' => 'account', 'id' => $bigintId],
            loadProcess: fn (?array $state = null) => new WalletProcess($state),
        );

        $events = $interactor->call(new OpenWalletCommand(owner: 'BigIntUser', initialBalance: 1000));
        expect($events)->toHaveCount(1);

        $record = State::findByTypeAndId('account', $bigintId);
        expect($record)->not->toBeNull()
            ->and($record->getStateId())->toBe($bigintId)
            ->and($record->state['owner'])->toBe('BigIntUser');
    });

    test('supports colon-separated type:id string stateKey', function () {
        $interactor = new Interactor(
            stateKey: 'wallet:user-999',
            loadProcess: fn (?array $state = null) => new WalletProcess($state),
        );

        $events = $interactor->call(new OpenWalletCommand(owner: 'ColonUser', initialBalance: 50));
        expect($events)->toHaveCount(1);

        $record = State::findByTypeAndId('wallet', 'user-999');
        expect($record)->not->toBeNull()
            ->and($record->getTypeName())->toBe('wallet')
            ->and($record->getStateId())->toBe('user-999');
    });

    test('supports rehydration and advances lock_version and next_position on subsequent calls', function () {
        $history = [];
        $eventHandler = function (array $events, array $metadata) use (&$history) {
            $history[] = ['events' => $events, 'metadata' => $metadata];
        };

        $loadProcess = fn (?array $state = null) => new WalletProcess($state);

        // First call: Open wallet
        $interactor1 = new Interactor(
            stateKey: ['type' => 'wallet', 'id' => 'wallet-002'],
            loadProcess: $loadProcess,
            eventHandler: $eventHandler,
        );
        $interactor1->call(new OpenWalletCommand(owner: 'Bob', initialBalance: 50));

        // Second call: Deposit funds
        $interactor2 = new Interactor(
            stateKey: ['type' => 'wallet', 'id' => 'wallet-002'],
            loadProcess: $loadProcess,
            eventHandler: $eventHandler,
        );
        $interactor2->call(new DepositFundsCommand(amount: 25));

        // Check database state
        $record = State::findByTypeAndId('wallet', 'wallet-002');
        expect($record->state['balance'])->toBe(75)
            ->and($record->next_position)->toBe(3) // 1 + 1 + 1
            ->and($record->lock_version)->toBe(1); // incremented on update

        // Check metadata history
        expect($history)->toHaveCount(2)
            ->and($history[0]['metadata']['next_position'])->toBe(1)
            ->and($history[1]['metadata']['next_position'])->toBe(2);
    });

    test('supports batching multiple commands in a single call', function () {
        $interactor = new Interactor(
            stateKey: ['type' => 'wallet', 'id' => 'wallet-003'],
            loadProcess: fn (?array $state = null) => new WalletProcess($state),
        );

        $events = $interactor->call(
            new OpenWalletCommand(owner: 'Charlie', initialBalance: 10),
            new DepositFundsCommand(amount: 20),
            new DepositFundsCommand(amount: 30),
        );

        expect($events)->toHaveCount(3);

        $record = State::findByTypeAndId('wallet', 'wallet-003');
        expect($record->state['balance'])->toBe(60)
            ->and($record->next_position)->toBe(4); // 1 + 3 events
    });

    test('supports lazy key generation via callable returning composite array', function () {
        $lazyKeyCalled = false;
        $lazyKeyGenerator = function () use (&$lazyKeyCalled) {
            $lazyKeyCalled = true;
            return ['type' => 'wallet', 'id' => 'lazy-wallet-999'];
        };

        $interactor = new Interactor(
            stateKey: $lazyKeyGenerator,
            loadProcess: fn (?array $state = null) => new WalletProcess($state),
        );

        $interactor->call(new OpenWalletCommand(owner: 'David', initialBalance: 200));

        expect($lazyKeyCalled)->toBeTrue();

        $record = State::findByTypeAndId('wallet', 'lazy-wallet-999');
        expect($record)->not->toBeNull()
            ->and($record->state['owner'])->toBe('David');
    });

    test('returns empty array and does not touch database when no events produced', function () {
        $dispatched = false;
        $lazyKeyCalled = false;

        $interactor = new Interactor(
            stateKey: function () use (&$lazyKeyCalled) {
                $lazyKeyCalled = true;
                return ['type' => 'wallet', 'id' => 'never-created'];
            },
            loadProcess: fn (?array $state = null) => new WalletProcess($state),
            eventHandler: function () use (&$dispatched) {
                $dispatched = true;
            },
        );

        $events = $interactor->call(new NoopCommand());

        expect($events)->toBeEmpty()
            ->and($lazyKeyCalled)->toBeFalse()
            ->and($dispatched)->toBeFalse()
            ->and(State::count())->toBe(0);
    });

    test('propagates aggregate exceptions without persisting or dispatching events', function () {
        $dispatched = false;
        $interactor = new Interactor(
            stateKey: ['type' => 'wallet', 'id' => 'wallet-err'],
            loadProcess: fn (?array $state = null) => new WalletProcess($state),
            eventHandler: function () use (&$dispatched) {
                $dispatched = true;
            },
        );

        // Deposit on unopened wallet throws RuntimeException
        expect(fn () => $interactor->call(new DepositFundsCommand(amount: 50)))
            ->toThrow(RuntimeException::class, 'Cannot deposit to unopened wallet.');

        expect($dispatched)->toBeFalse()
            ->and(State::findByTypeAndId('wallet', 'wallet-err'))->toBeNull();
    });

    test('detects concurrent write on update and throws StaleRecordException', function () {
        $typeId = StateType::idFor('wallet');

        // Prepare initial record in DB with lock_version = 0
        $initialRecord = new State([
            'type_id' => $typeId,
            'state_id' => 'wallet-conflict',
            'state' => ['opened' => true, 'owner' => 'Eve', 'balance' => 100],
            'next_position' => 2,
            'lock_version' => 0,
        ]);
        $initialRecord->save();

        $dispatched = false;
        $interactor = new Interactor(
            stateKey: ['type' => 'wallet', 'id' => 'wallet-conflict'],
            loadProcess: function (?array $state = null) use ($typeId) {
                // Simulate another process modifying the DB record while this process is executing
                State::where('type_id', $typeId)->where('state_id', 'wallet-conflict')->increment('lock_version');
                return new WalletProcess($state);
            },
            eventHandler: function () use (&$dispatched) {
                $dispatched = true;
            },
            maxRetries: 1,
        );

        expect(fn () => $interactor->call(new DepositFundsCommand(amount: 50)))
            ->toThrow(StaleRecordException::class, 'Concurrent write detected for state record.');

        expect($dispatched)->toBeFalse();
    });

    test('detects duplicate key collision on insert and throws StaleRecordException', function () {
        $typeId = StateType::idFor('wallet');

        // Pre-insert conflicting record in DB
        $existing = new State([
            'type_id' => $typeId,
            'state_id' => 'wallet-dup',
            'state' => ['opened' => true, 'owner' => 'Original', 'balance' => 10],
            'next_position' => 2,
            'lock_version' => 0,
        ]);
        $existing->save();

        // New interactor using lazy key that resolves to the same duplicate (type, id)
        $interactor = new Interactor(
            stateKey: fn () => ['type' => 'wallet', 'id' => 'wallet-dup'],
            loadProcess: fn (?array $state = null) => new WalletProcess($state),
        );

        expect(fn () => $interactor->call(new OpenWalletCommand(owner: 'Duplicate', initialBalance: 50)))
            ->toThrow(StaleRecordException::class);
    });

    test('supports callable invoke syntax and EventDispatcher instance', function () {
        $eventsJournal = [];
        $dispatcher = new EventDispatcher(function (array $events, array $metadata) use (&$eventsJournal) {
            $eventsJournal[] = ['count' => count($events), 'metadata' => $metadata];
        });

        $interactor = new Interactor(
            stateKey: ['type' => 'wallet', 'id' => 'wallet-invokable'],
            loadProcess: fn (?array $state = null) => new WalletProcess($state),
            eventHandler: $dispatcher,
        );

        // Invoke using ($interactor)(...) syntax
        $events = $interactor(new OpenWalletCommand(owner: 'Frank', initialBalance: 300));

        expect($events)->toHaveCount(1)
            ->and($eventsJournal)->toHaveCount(1)
            ->and($eventsJournal[0]['metadata']['state_key'])->toBe(['type' => 'wallet', 'id' => 'wallet-invokable']);
    });

    test('automatically retries and succeeds when collision occurs before retry limit', function () {
        $typeId = StateType::idFor('wallet');

        $initialRecord = new State([
            'type_id' => $typeId,
            'state_id' => 'wallet-retry-success',
            'state' => ['opened' => true, 'owner' => 'Grace', 'balance' => 100],
            'next_position' => 2,
            'lock_version' => 0,
        ]);
        $initialRecord->save();

        $callCount = 0;
        $interactor = new Interactor(
            stateKey: ['type' => 'wallet', 'id' => 'wallet-retry-success'],
            loadProcess: function (?array $state = null) use ($typeId, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // Simulate collision ONLY on attempt 1 by advancing lock_version in DB
                    State::where('type_id', $typeId)->where('state_id', 'wallet-retry-success')->update(['lock_version' => 1]);
                }
                return new WalletProcess($state);
            },
            maxRetries: 3,
        );

        $events = $interactor->call(new DepositFundsCommand(amount: 50));

        expect($events)->toHaveCount(1)
            ->and($callCount)->toBe(2);

        $finalRecord = State::findByTypeAndId('wallet', 'wallet-retry-success');
        expect($finalRecord->state['balance'])->toBe(150)
            ->and($finalRecord->lock_version)->toBe(2);
    });

    test('throws StaleRecordException when maxRetries is 1 and collision occurs', function () {
        $typeId = StateType::idFor('wallet');

        $initialRecord = new State([
            'type_id' => $typeId,
            'state_id' => 'wallet-no-retry',
            'state' => ['opened' => true, 'owner' => 'Grace', 'balance' => 100],
            'next_position' => 2,
            'lock_version' => 0,
        ]);
        $initialRecord->save();

        $callCount = 0;
        $interactor = new Interactor(
            stateKey: ['type' => 'wallet', 'id' => 'wallet-no-retry'],
            loadProcess: function (?array $state = null) use ($typeId, &$callCount) {
                $callCount++;
                State::where('type_id', $typeId)->where('state_id', 'wallet-no-retry')->update(['lock_version' => 1]);
                return new WalletProcess($state);
            },
            maxRetries: 1,
        );

        expect(fn () => $interactor->call(new DepositFundsCommand(amount: 50)))
            ->toThrow(StaleRecordException::class);

        expect($callCount)->toBe(1);
    });
});
