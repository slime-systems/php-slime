---
name: php-slime
description: >-
  Architectural patterns, domain modeling, CQRS, event sourcing, aggregate persistence,
  and token infrastructure for applications using slime-systems/php-slime. Use whenever
  creating or modifying domain commands, process state machines, aggregate roots,
  interactors, event stores, read model projectors, query sharding, or API/state tokens.
---

# PHP Slime Library Guide

`slime-systems/php-slime` provides lightweight Domain-Driven Design (DDD), CQRS, event-sourced aggregate persistence, and high-assurance token infrastructure for modern PHP and Laravel applications.

## High-Level Architecture

The framework separates the write model (business processes and state mutation) from infrastructure (storage, HTTP, event bus) using functional boundaries:

```
[HTTP Request / Job]
       │
       ▼
 [Controller / Service] ──(Injects ambient context: auth, tenant, check callbacks)──┐
       │                                                                            │
       ▼                                                                            ▼
 [Command (Validated DTO)] ────► [Interactor] ──(Loads state)──► [Process (Aggregate State Machine)]
                                       │                               │
                                       │ (Applies events via on())     │ ($this->apply($event))
                                       ▼                               ▼
                           [State Record (MessagePack)]        [Pending Events]
                                       │                               │
                         (Optimistic lock check)                       ▼
                                       │                   [ApplicationEventHandler]
                                       ▼                     ├── Read Model Projector (EventQueue)
                                  [Database]                 ├── Persistent Event Store (StoredEvent)
                                                             └── Laravel Event Bus (EventBusBridge)
```

---

## Quick Cheatsheet

### 1. Business Intent: Command DTO
Commands are self-validating, immutable DTOs using Laravel's validation engine:
```php
use SlimeSystems\Hatsume\Command;

class PlaceOrderCommand implements \ArrayAccess {
    use Command;

    public function __construct(
        public ?string $orderId = null,
        public int $amount = 0,
        public ?string $currency = 'USD',
    ) {}

    protected function rules(): array {
        return [
            'orderId' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }
}
```
* Access attributes via array syntax: `$command['amount']` or `$command->validatedAttributes()`.

### 2. Domain State Machine: Aggregate Process
Aggregates maintain state, enforce business rules, and emit events:
```php
use SlimeSystems\Hatsume\ProcessHelper;
use SlimeSystems\Hatsume\UnknownCommand;

class OrderProcess {
    use ProcessHelper;

    public function __construct(?array $state = null) {
        $this->state = $this->mergeState([
            'status' => 'draft',
            'amount' => 0,
        ], $state);
        $this->registerEventHandlers();
    }

    protected function registerEventHandlers(): void {
        $this->on(OrderPlaced::class, function (OrderPlaced $event) {
            $this->state['status'] = 'placed';
            $this->state['amount'] = $event->data['amount'];
        });
    }

    public function call(object $command): void {
        match ($command::class) {
            PlaceOrderCommand::class => $this->placeOrder($command->validatedAttributes()),
            default => throw new UnknownCommand($command),
        };
    }

    private function placeOrder(array $params): void {
        if ($this->state['status'] !== 'draft') {
            throw new \DomainException('Order has already been placed.');
        }
        $this->apply(OrderPlaced::strict(['amount' => $params['amount']]));
    }
}
```

### 3. Aggregate Persistence: Interactor
The `Interactor` manages state hydration, command execution, optimistic concurrency, and event dispatching:
```php
use SlimeSystems\Eloquent\Interactor;

$interactor = new Interactor(
    stateKey: ['type' => 'order', 'id' => $orderId],
    loadProcess: fn (?array $state) => new OrderProcess($state),
    eventHandler: $applicationEventHandler,
);

$pendingEvents = $interactor->call(new PlaceOrderCommand(orderId: $orderId, amount: 2500));
```

---

## Detailed Reference Guides

* **[CQRS & Business Processes](./references/cqrs_and_processes.md)**: Command validation, domain events, ephemeral `TransitoryEvent`, `ProcessHelper` state machines, and event routers.
* **[Aggregate Persistence & Controller Integration](./references/aggregate_persistence.md)**: Database migrations (`createBlueprint`), Eloquent `State` storage, optimistic locking, controller delegation with First-Class Callables, and `ApplicationEventHandler` wiring.
* **[Token Infrastructure & Security](./references/token_infrastructure.md)**: PASETO v4 / JWT tokens with anti-tamper payload hashing (`api:hash`), Google Firebase ID token verification, and stateless `OpaqueToken` continuation cursors.
