<?php

namespace SlimeSystems\Eloquent;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;
use SlimeSystems\Hatsume\EventDispatcher;

class Interactor {
  /**
   * @param mixed $stateKey Scalar ID, composite array key, or callable returning state key
   * @param callable(array<string, mixed>, ?Model=): object $loadProcess Factory creating the process aggregate
   * @param (callable(mixed, array<string, mixed>): void)|null $eventHandler Optional handler to dispatch events
   * @param class-string<Model>|Model $model Model class name or instance
   */
  public function __construct(
    private readonly mixed $stateKey,
    private readonly mixed $loadProcess,
    private readonly mixed $eventHandler = null,
    private readonly string|Model $model = State::class,
  ) {
  }

  /**
   * Invokes the interactor with domain commands.
   *
   * @param object|array<int, object> ...$commands
   * @return list<object> Pending events produced and dispatched
   * @throws StaleRecordException If concurrent write or duplicate key is detected
   */
  public function __invoke(object|array ...$commands): array {
    return $this->call(...$commands);
  }

  /**
   * Executes domain commands, persists the aggregate state, and dispatches events.
   *
   * @param object|array<int, object> ...$commands
   * @return list<object>
   * @throws StaleRecordException
   */
  public function call(object|array ...$commands): array {
    if (count($commands) === 1 && is_array($commands[0])) {
      $commands = $commands[0];
    }

    $isLazyKey = is_callable($this->stateKey);
    /** @var class-string<Model> $modelClass */
    $modelClass = is_string($this->model) ? $this->model : $this->model::class;
    $instance = new $modelClass();

    if ($isLazyKey) {
      $stateRecord = new $modelClass();
      $this->applyDefaultAttributes($stateRecord);
    } else {
      $keyAttributes = $this->resolveKeyAttributes($this->stateKey, $instance);
      $stateRecord = $this->findOrInitializeStateRecord($modelClass, $keyAttributes);
    }

    $currentState = $stateRecord->getAttribute('state') ?? [];
    $process = $this->loadProcessInstance($currentState, $stateRecord);

    $initialNextPosition = (int) ($stateRecord->getAttribute('next_position') ?? 1);
    $metadataBuffer = [
      'next_position' => $initialNextPosition,
    ];

    foreach ($commands as $command) {
      if (method_exists($process, 'call')) {
        $process->call($command);
      } elseif (is_callable($process)) {
        $process($command);
      }
    }

    /** @var list<object> $pendingEvents */
    $pendingEvents = method_exists($process, 'pendingEvents')
      ? $process->pendingEvents()
      : ($process->pendingEvents ?? []);

    if (empty($pendingEvents)) {
      return [];
    }

    $newState = method_exists($process, 'state')
      ? $process->state()
      : ($process->state ?? []);

    $stateRecord->setAttribute('state', $newState);
    $stateRecord->setAttribute('next_position', $initialNextPosition + count($pendingEvents));

    $savedStateKey = null;
    $activeKeyAttributes = [];

    if ($isLazyKey) {
      $savedStateKey = ($this->stateKey)();
      $activeKeyAttributes = $this->resolveKeyAttributes($savedStateKey, $instance);
      foreach ($activeKeyAttributes as $k => $v) {
        $stateRecord->setAttribute($k, $v);
      }
    } else {
      $savedStateKey = $this->stateKey;
      $activeKeyAttributes = $this->resolveKeyAttributes($this->stateKey, $instance);
    }

    if (!$stateRecord->exists) {
      try {
        $stateRecord->save();
      } catch (UniqueConstraintViolationException $e) {
        throw new StaleRecordException(
          message: 'Concurrent write detected: record already exists for state key.',
          previous: $e,
        );
      } catch (QueryException $e) {
        if ($this->isDuplicateKeyException($e)) {
          throw new StaleRecordException(
            message: 'Concurrent write detected: record already exists for state key.',
            previous: $e,
          );
        }
        throw $e;
      }
    } else {
      $currentLockVersion = (int) ($stateRecord->getAttribute('lock_version') ?? 0);
      $query = $modelClass::query();

      foreach ($activeKeyAttributes as $col => $val) {
        $query->where($col, $val);
      }

      $rawState = $stateRecord->getAttributes()['state'] ?? $stateRecord->getAttribute('state');

      $affected = $query
        ->where('lock_version', $currentLockVersion)
        ->update([
          'state' => $rawState,
          'next_position' => $stateRecord->getAttribute('next_position'),
          'lock_version' => $currentLockVersion + 1,
        ]);

      if ($affected === 0) {
        throw new StaleRecordException('Concurrent write detected for state record.');
      }

      $stateRecord->setAttribute('lock_version', $currentLockVersion + 1);
    }

    $metadataBuffer['state_key'] = $savedStateKey;

    if ($this->eventHandler !== null) {
      $this->dispatchEvents($pendingEvents, $metadataBuffer);
    }

    return $pendingEvents;
  }

  /**
   * Apply default values if not already present.
   */
  private function applyDefaultAttributes(Model $record): void {
    if ($record->getAttribute('state') === null) {
      $record->setAttribute('state', []);
    }
    if ($record->getAttribute('next_position') === null) {
      $record->setAttribute('next_position', 1);
    }
    if ($record->getAttribute('lock_version') === null) {
      $record->setAttribute('lock_version', 0);
    }
  }

  /**
   * Resolves raw key input into database column attributes.
   *
   * @param mixed $key
   * @param Model $instance
   * @return array<string, mixed>
   */
  private function resolveKeyAttributes(mixed $key, Model $instance): array {
    $hasComposite = in_array('type_id', $instance->getFillable(), true)
      || is_array($instance->getKeyName());

    if (is_array($key)) {
      $typeId = $key['type_id'] ?? null;
      if ($typeId === null && isset($key['type'])) {
        $typeId = StateType::idFor((string) $key['type']);
      }

      $stateId = $key['state_id'] ?? $key['id'] ?? null;

      if ($hasComposite && $typeId !== null && $stateId !== null) {
        return [
          'type_id' => (int) $typeId,
          'state_id' => (string) $stateId,
        ];
      }

      return $key;
    }

    if (is_string($key) && str_contains($key, ':')) {
      [$typeName, $id] = explode(':', $key, 2);
      if ($hasComposite) {
        return [
          'type_id' => StateType::idFor($typeName),
          'state_id' => $id,
        ];
      }
    }

    if ($hasComposite) {
      return [
        'type_id' => StateType::idFor('default'),
        'state_id' => (string) $key,
      ];
    }

    return [$instance->getKeyName() => $key];
  }

  /**
   * Locate an existing state record or initialize a new model instance.
   *
   * @param class-string<Model> $modelClass
   * @param array<string, mixed> $keyAttributes
   */
  private function findOrInitializeStateRecord(string $modelClass, array $keyAttributes): Model {
    $query = $modelClass::query();
    foreach ($keyAttributes as $col => $val) {
      $query->where($col, $val);
    }

    $record = $query->first();
    if ($record !== null) {
      $this->applyDefaultAttributes($record);
      return $record;
    }

    $record = new $modelClass($keyAttributes);
    $this->applyDefaultAttributes($record);
    return $record;
  }

  /**
   * Load the domain process instance from the callable.
   *
   * @param array<string, mixed> $state
   */
  private function loadProcessInstance(array $state, Model $stateRecord): object {
    try {
      return ($this->loadProcess)($state, $stateRecord);
    } catch (\ArgumentCountError|\Error) {
      return ($this->loadProcess)(state: $state);
    }
  }

  /**
   * Dispatches events and metadata to the registered handler.
   *
   * @param list<object> $events
   * @param array<string, mixed> $metadata
   */
  private function dispatchEvents(array $events, array $metadata): void {
    if ($this->eventHandler instanceof EventDispatcher) {
      $this->eventHandler->call($events, $metadata);
    } elseif (is_callable($this->eventHandler)) {
      ($this->eventHandler)($events, $metadata);
    } elseif (is_object($this->eventHandler) && method_exists($this->eventHandler, 'call')) {
      $this->eventHandler->call($events, $metadata);
    }
  }

  /**
   * Detects duplicate key / unique constraint exceptions across DB drivers.
   */
  private function isDuplicateKeyException(QueryException $e): bool {
    $code = (string) $e->getCode();
    $message = $e->getMessage();

    // Standard SQLSTATE for unique violation: 23000 (MySQL, SQLite), 23505 (Postgres)
    if ($code === '23505') {
      return true;
    }

    $previous = $e->getPrevious();
    if ($previous instanceof PDOException) {
      $errorInfo = $previous->errorInfo ?? [];
      $driverCode = $errorInfo[1] ?? null;
      if (in_array($driverCode, [1062, 19, 2601, 2627], true)) {
        return true;
      }
    }

    return str_contains($message, 'UNIQUE constraint failed')
      || str_contains($message, 'PRIMARY KEY must be unique')
      || str_contains($message, 'Duplicate entry')
      || str_contains($message, 'unique constraint')
      || str_contains($message, 'duplicate key value');
  }
}
