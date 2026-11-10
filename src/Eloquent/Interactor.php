<?php

namespace SlimeSystems\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

class Interactor {
  private readonly mixed $eventHandler;
  private readonly string|Model $model;
  private readonly int $maxRetries;
  private array $context;

  /**
   * @param mixed $stateKey Scalar ID, composite array key, or callable returning state key
   * @param callable(array<string, mixed>, ?Model=): object $loadProcess Factory creating the process aggregate
   * @param mixed ...$options Optional dependencies: eventHandler, model, maxRetries, context
   */
  public function __construct(
    private readonly mixed $stateKey,
    private readonly mixed $loadProcess,
    ...$options,
  ) {
    $this->eventHandler = $options['eventHandler'] ?? null;
    $this->model = $options['model'] ?? State::class;
    $this->maxRetries = $options['maxRetries'] ?? 100;
    $this->context = $options['context'] ?? [];
  }

  /**
   * Returns a clone of the interactor with additional execution context.
   *
   * @param array<string, mixed> $context
   */
  public function withContext(array $context): self {
    $clone = clone $this;
    $clone->context = array_merge($clone->context, $context);
    return $clone;
  }

  /**
   * Invokes the interactor with domain commands.
   *
   * @param object|array<int, object> ...$commands
   * @return list<object> Pending events produced and dispatched
   * @throws StaleRecordException If concurrent write or duplicate key is detected
   */
  public function __invoke(object|array ...$commands): array {
    $commands = (count($commands) === 1 && is_array($commands[0])) ? $commands[0] : $commands;
    $retries = max(1, $this->maxRetries);

    for ($attempt = 1; $attempt <= $retries; $attempt++) {
      try {
        return $this->executeOnce($commands);
      } catch (StaleRecordException $e) {
        if ($attempt >= $retries) {
          throw $e;
        }
      }
    }

    return [];
  }

  /**
   * Performs a single command execution cycle.
   *
   * @param list<object> $commands
   * @return list<object>
   * @throws StaleRecordException
   */
  private function executeOnce(array $commands): array {
    $isLazyKey = is_callable($this->stateKey);
    /** @var class-string<Model> $modelClass */
    $modelClass = is_string($this->model) ? $this->model : $this->model::class;

    $keyAttributes = $isLazyKey ? [] : $this->resolveKeyAttributes($this->stateKey, $modelClass);
    $record = $isLazyKey ? new $modelClass() : $modelClass::firstOrNew($keyAttributes);

    $initialNextPosition = (int)($record->next_position ?? 1);
    $process = ($this->loadProcess)($record->state ?? [], $record);

    foreach ($commands as $command) {
      is_callable($process) ? $process($command) : $process->call($command);
    }

    $pendingEvents = $process->pendingEvents();
    if (empty($pendingEvents)) {
      return [];
    }

    $record->state = $process->state();
    $record->next_position = $initialNextPosition + count($pendingEvents);

    $savedStateKey = $isLazyKey ? ($this->stateKey)() : $this->stateKey;
    $activeKeyAttributes = $isLazyKey
      ? $this->resolveKeyAttributes($savedStateKey, $modelClass)
      : $keyAttributes;

    if ($isLazyKey) {
      $record->fill($activeKeyAttributes);
    }

    $this->persistRecord($record, $activeKeyAttributes);

    if ($this->eventHandler !== null) {
      ($this->eventHandler)($pendingEvents, array_merge($this->context, [
        '_state_key' => $savedStateKey,
        '_position' => $initialNextPosition,
        '_is_replay' => false,
      ]));
    }

    return $pendingEvents;
  }

  /**
   * Resolves raw key input into database column attributes.
   *
   * @param class-string<Model> $modelClass
   * @return array<string, mixed>
   */
  private function resolveKeyAttributes(mixed $key, string $modelClass): array {
    if (is_subclass_of($modelClass, State::class) || $modelClass === State::class) {
      return State::resolveKey($key);
    }

    return is_array($key) ? $key : [new $modelClass()->getKeyName() => $key];
  }

  /**
   * Persists new or existing model with optimistic locking.
   *
   * @param array<string, mixed> $keyAttributes
   * @throws StaleRecordException
   */
  private function persistRecord(Model $record, array $keyAttributes): void {
    if (!$record->exists) {
      try {
        $record->save();
      } catch (UniqueConstraintViolationException $e) {
        throw new StaleRecordException('Concurrent write detected: record already exists for state key.', previous: $e);
      } catch (QueryException $e) {
        if ($e->getCode() === '23505' || $e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE')) {
          throw new StaleRecordException('Concurrent write detected: record already exists for state key.', previous: $e);
        }
        throw $e;
      }
      return;
    }

    $lockVersion = (int)($record->lock_version ?? 0);

    $affected = $record::query()
      ->where($keyAttributes)
      ->where('lock_version', $lockVersion)
      ->update([
        'state' => $record->getAttributes()['state'],
        'next_position' => $record->next_position,
        'lock_version' => $lockVersion + 1,
      ]);

    if ($affected === 0) {
      throw new StaleRecordException('Concurrent write detected for state record.');
    }

    $record->lock_version = $lockVersion + 1;
  }
}
