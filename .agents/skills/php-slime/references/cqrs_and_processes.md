# CQRS & Business Processes (`SlimeSystems\Hatsume`)

The `Hatsume` module provides core building blocks for CQRS: validated commands, structured domain events, and state-machine business processes.

---

## 1. Commands (`SlimeSystems\Hatsume\Command`)

Commands represent business intent. They wrap input parameters and provide automatic validation and type casting via Laravel's validator.

### Declaration Pattern
```php
namespace App\Domain\Billing\Commands;

use ArrayAccess;
use SlimeSystems\Hatsume\Command;

class ChargeSubscriptionCommand implements ArrayAccess {
    use Command;

    public function __construct(
        public ?string $subscriptionId = null,
        public int $amount = 0,
        public ?string $paymentMethodId = null,
        public ?string $currency = 'USD',
    ) {}

    protected function rules(): array {
        return [
            'subscriptionId' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
            'paymentMethodId' => ['required', 'string'],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }
}
```

### Key Behaviors & Guardrails
* **Automatic normalization:** Empty strings (`""`) are normalized to `null` before validation.
* **Auto-casting:** Values are automatically cast according to their validation rules (`int`, `bool`, `numeric`, `string`).
* **Immutability:** Implements `ArrayAccess` for reading (`$cmd['amount']`), but mutation (`$cmd['amount'] = 10`) throws `NotImplemented`.
* **Validation Exception:** Calling `validatedAttributes()` or array access throws `SlimeSystems\Hatsume\ValidationException` if rules fail.

---

## 2. Events (`SlimeSystems\Hatsume\Event`)

Events represent immutable facts that occurred in the domain.

### Declaration Pattern
```php
namespace App\Domain\Billing\Events;

use SlimeSystems\Hatsume\Event;

class SubscriptionCharged {
    use Event;

    protected static function rules(): array {
        return [
            'subscriptionId' => ['required', 'string'],
            'amount' => ['required', 'integer'],
            'transactionRef' => ['required', 'string'],
        ];
    }
}
```

### Factory Constructors
* **Strict Construction (`Event::strict($data, $metadata)`):** Validates `$data` against `rules()` using Laravel's validator. Throws `ValidationException` on failure.
* **Direct Instantiation (`new SubscriptionCharged($data, $metadata)`):** Lightweight constructor for internal rehydration without re-running validation.

### Ephemeral Events (`TransitoryEvent`)
Implement `SlimeSystems\Hatsume\TransitoryEvent` for events that should be dispatched to live listeners (e.g. notifications, logging, streaming) but **omitted from permanent event store journals**:
```php
use SlimeSystems\Hatsume\Event;
use SlimeSystems\Hatsume\TransitoryEvent;

class ChargeNotificationSent implements TransitoryEvent {
    use Event;
}
```

---

## 3. Aggregate Processes (`SlimeSystems\Hatsume\ProcessHelper`)

An aggregate process is a state machine that applies events sequentially to transition its internal state.

### Canonical Process Implementation
```php
namespace App\Domain\Billing;

use Closure;
use SlimeSystems\Hatsume\ProcessHelper;
use SlimeSystems\Hatsume\UnknownCommand;
use App\Domain\Billing\Commands\ChargeSubscriptionCommand;
use App\Domain\Billing\Events\SubscriptionCharged;

class SubscriptionProcess {
    use ProcessHelper;

    public function __construct(?array $state = null) {
        // 1. Initialize state with defaults merged over recorded state
        $this->state = $this->mergeState([
            'status' => 'pending',
            'totalCharged' => 0,
            'lastChargedAt' => null,
        ], $state);

        // 2. Register event transitions
        $this->registerEventHandlers();
    }

    /**
     * Map events to state mutations.
     * MUST be idempotent and side-effect free.
     */
    protected function registerEventHandlers(): void {
        $this->on(SubscriptionCharged::class, function (SubscriptionCharged $event) {
            $this->state['status'] = 'active';
            $this->state['totalCharged'] += $event->data['amount'];
            $this->state['lastChargedAt'] = $event->metadata['timestamp'] ?? null;
        });
    }

    /**
     * Central command entrypoint.
     */
    public function call(object $command): mixed {
        return match ($command::class) {
            ChargeSubscriptionCommand::class => $this->charge($command->validatedAttributes()),
            default => throw new UnknownCommand($command),
        };
    }

    private function charge(array $params): void {
        if ($this->state['status'] === 'canceled') {
            throw new \DomainException('Cannot charge a canceled subscription.');
        }

        // Apply state transition via domain event
        $this->apply(SubscriptionCharged::strict([
            'subscriptionId' => $params['subscriptionId'],
            'amount' => $params['amount'],
            'transactionRef' => $params['paymentMethodId'],
        ]));
    }
}
```

### Critical Rules
1. **Never mutate `$this->state` directly inside action methods:**
   Always use `$this->apply($event)`. The state transition closure registered via `$this->on(...)` is the single source of truth for mutations. This guarantees deterministic event sourcing replaying.
2. **Pending Events:**
   Every event passed to `$this->apply(...)` updates the state and is staged in `$this->pendingEvents()`.
3. **Handle Unknown Commands:**
   Always include a `default => throw new UnknownCommand($command)` branch in `call(object $command)`.

---

## 4. Routing & Dispatching

* **`EventRouter`:** Maps specific event classes to callables. Unregistered events are ignored cleanly:
  ```php
  use SlimeSystems\Hatsume\EventRouter;

  $router = new EventRouter([
      SubscriptionCharged::class => fn ($event, $meta) => $stats->record($event),
  ]);
  $router($event, $metadata);
  ```
* **`EventDispatcher`:** Invokes multiple handlers in order with an array of events and metadata:
  ```php
  use SlimeSystems\Hatsume\EventDispatcher;

  $dispatcher = new EventDispatcher($projectorHandler, $analyticsHandler);
  $dispatcher->call($pendingEvents, $metadata);
  ```
* **`EventQueue`:** Extends `EventDispatcher` by unpacking arrays of events and invoking handlers sequentially for each individual event.
