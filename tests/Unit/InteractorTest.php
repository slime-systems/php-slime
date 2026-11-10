<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\Interactor;
use SlimeSystems\Eloquent\StaleRecordException;
use SlimeSystems\Eloquent\State;
use SlimeSystems\Eloquent\StateType;

class TestProcess {
  public function __construct(public array $state = [], public array $events = []) {
  }

  public function __invoke(object $cmd): void {
    if (isset($cmd->event)) {
      $this->events[] = $cmd->event;
    }
    $this->state = array_merge($this->state, (array)($cmd->state ?? []));
  }

  public function pendingEvents(): array {
    return $this->events;
  }

  public function state(): array {
    return $this->state;
  }
}

describe('Eloquent Interactor', function () {
  beforeEach(function () {
    StateType::clearCache();
    Schema::dropIfExists('process_states');
    Schema::dropIfExists('state_types');

    Schema::create('state_types', fn(Blueprint $table) => StateType::createBlueprint($table));
    Schema::create('process_states', fn(Blueprint $table) => State::createBlueprint($table));
  });

  afterEach(function () {
    StateType::clearCache();
  });

  test('executes batch commands, persists state, and dispatches events with context', function () {
    $dispatched = [];
    $interactor = (new Interactor(
      stateKey: ['type' => 'wallet', 'id' => 'w-1'],
      loadProcess: fn(?array $state = null) => new TestProcess($state ?? []),
      eventHandler: function ($events, $context) use (&$dispatched) {
        $dispatched = ['events' => $events, 'context' => $context];
      },
    ))->withContext(['actor' => 'usr-1']);

    $events = $interactor(
      (object)['event' => 'opened', 'state' => ['owner' => 'Alice']],
      (object)['event' => 'deposited', 'state' => ['balance' => 100]],
    );

    expect($events)->toBe(['opened', 'deposited']);

    $record = State::findByTypeAndId('wallet', 'w-1');
    expect($record->state)->toBe(['owner' => 'Alice', 'balance' => 100])
      ->and($record->next_position)->toBe(3)
      ->and($record->lock_version)->toBe(0);

    expect($dispatched['events'])->toBe(['opened', 'deposited'])
      ->and($dispatched['context'])->toMatchArray([
        'actor' => 'usr-1',
        '_state_key' => ['type' => 'wallet', 'id' => 'w-1'],
        '_position' => 1,
        '_is_replay' => false,
      ]);
  });

  test('advances lock_version and next_position on rehydrated calls', function () {
    $interactor = new Interactor(
      stateKey: ['type' => 'wallet', 'id' => 'w-2'],
      loadProcess: fn(?array $state = null) => new TestProcess($state ?? []),
    );

    $interactor((object)['event' => 'opened', 'state' => ['balance' => 10]]);
    $interactor((object)['event' => 'deposited', 'state' => ['balance' => 50]]);

    $record = State::findByTypeAndId('wallet', 'w-2');
    expect($record->state['balance'])->toBe(50)
      ->and($record->next_position)->toBe(3)
      ->and($record->lock_version)->toBe(1);
  });

  test('returns empty array without touching database when no events produced', function () {
    $lazyKeyCalled = false;
    $interactor = new Interactor(
      stateKey: function () use (&$lazyKeyCalled) {
        $lazyKeyCalled = true;
        return ['type' => 'wallet', 'id' => 'w-none'];
      },
      loadProcess: fn(?array $state = null) => new TestProcess($state ?? []),
    );

    expect($interactor((object)[]))->toBeEmpty()
      ->and($lazyKeyCalled)->toBeFalse()
      ->and(State::count())->toBe(0);
  });

  test('retries on collision and throws StaleRecordException when exhausted', function () {
    $typeId = StateType::idFor('wallet');
    (new State([
      'type_id' => $typeId,
      'state_id' => 'w-retry',
      'state' => ['balance' => 100],
      'next_position' => 2,
      'lock_version' => 0,
    ]))->save();

    // Recovers when collision occurs before retry limit
    $attempts = 0;
    $interactor = new Interactor(
      stateKey: ['type' => 'wallet', 'id' => 'w-retry'],
      loadProcess: function (?array $state = null) use ($typeId, &$attempts) {
        if (++$attempts === 1) {
          State::where('type_id', $typeId)->where('state_id', 'w-retry')->increment('lock_version');
        }
        return new TestProcess($state ?? []);
      },
      maxRetries: 3,
    );

    $events = $interactor((object)['event' => 'added', 'state' => ['balance' => 150]]);
    expect($events)->toBe(['added'])
      ->and($attempts)->toBe(2)
      ->and(State::findByTypeAndId('wallet', 'w-retry')->lock_version)->toBe(2);

    // Throws StaleRecordException when retries are exhausted
    $failing = new Interactor(
      stateKey: ['type' => 'wallet', 'id' => 'w-retry'],
      loadProcess: function (?array $state = null) use ($typeId) {
        State::where('type_id', $typeId)->where('state_id', 'w-retry')->increment('lock_version');
        return new TestProcess($state ?? []);
      },
      maxRetries: 1,
    );

    expect(fn() => $failing((object)['event' => 'fail']))
      ->toThrow(StaleRecordException::class);
  });

  test('supports single-key model and lazy key generation', function () {
    Schema::dropIfExists('single_key_states');
    Schema::create('single_key_states', function (Blueprint $table) {
      $table->string('id')->primary();
      $table->json('state')->nullable();
      $table->unsignedBigInteger('next_position')->default(1);
      $table->unsignedBigInteger('lock_version')->default(0);
    });

    $model = new class extends Model {
      protected $table = 'single_key_states';
      public $timestamps = false;
      public $incrementing = false;
      protected $guarded = [];
      protected $casts = ['state' => 'array'];
    };

    $interactor = new Interactor(
      stateKey: fn() => 'single-001',
      loadProcess: fn(?array $state = null) => new TestProcess($state ?? []),
      model: $model::class,
    );

    $interactor((object)['event' => 'created', 'state' => ['user' => 'Sam']]);

    $record = $model::find('single-001');
    expect($record)->not->toBeNull()
      ->and($record->state)->toBe(['user' => 'Sam'])
      ->and($record->next_position)->toBe(2);
  });
});
