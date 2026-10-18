# Aggregate Persistence & Controller Integration (`SlimeSystems\Eloquent`)

The `Eloquent` module bridges aggregate processes (`ProcessHelper`) with Laravel's database persistence, event store, read-model projections, and optimistic locking.

---

## 1. Database Migrations & Schema Blueprints

`php-slime` encapsulates optimal schema definitions inside the persistence models themselves via static `createBlueprint(Blueprint $table): void` methods. This prevents migration schema drift and guarantees optimal column types, composite primary keys, compact binary MessagePack columns, and indexes.

### Core Framework Tables

Creating the required tables in a Laravel migration only requires passing their method references as first-class callables (`...`):

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\EventType;
use SlimeSystems\Eloquent\FieldType;
use SlimeSystems\Eloquent\GlobalCount;
use SlimeSystems\Eloquent\State;
use SlimeSystems\Eloquent\StateType;
use SlimeSystems\Eloquent\StoredEvent;

return new class extends Migration {
    public function up(): void
    {
        // 1. Lookup Type Dictionaries (maps string names to compact 32-bit integer IDs)
        Schema::create('state_types', StateType::createBlueprint(...));
        Schema::create('event_types', EventType::createBlueprint(...));
        Schema::create('field_types', FieldType::createBlueprint(...));

        // 2. Aggregate States Table
        Schema::create('process_states', State::createBlueprint(...));

        // 3. Event Store Journal
        Schema::create('stored_events', StoredEvent::createBlueprint(...));

        // 4. Optional: Distributed Atomic Sequence Generator
        Schema::create('global_counts', GlobalCount::createBlueprint(...));
    }

    public function down(): void
    {
        Schema::dropIfExists('global_counts');
        Schema::dropIfExists('stored_events');
        Schema::dropIfExists('process_states');
        Schema::dropIfExists('field_types');
        Schema::dropIfExists('event_types');
        Schema::dropIfExists('state_types');
    }
};
```

### Read Model Projections & Query Sharding Blueprint

When building CQRS read models that will be queried concurrently by parallel queue workers, use `QuerySharding::createBlueprint($table)` to index the shard column:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\QuerySharding;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_projections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status');
            $table->unsignedBigInteger('amount');

            // Adds `unsignedInteger('query_shard')->default(0)->index()`
            QuerySharding::createBlueprint($table);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_projections');
    }
};
```

---

## 2. The Interactor (`SlimeSystems\Eloquent\Interactor`)

The `Interactor` orchestrates the complete write-path lifecycle:
1. Loads current aggregate state from `process_states` by `stateKey`.
2. Instantiates the aggregate process via `loadProcess`.
3. Runs commands sequentially through `$process->call($command)`.
4. Detects and applies state changes.
5. Performs atomic optimistic concurrency checks (`lock_version`).
6. Saves updated state (compact MessagePack serialized).
7. Dispatches newly generated events (`pendingEvents`) to `ApplicationEventHandler`.

### Basic Execution
```php
use SlimeSystems\Eloquent\Interactor;

$interactor = new Interactor(
    stateKey: ['type' => 'subscription', 'id' => $subscriptionId],
    loadProcess: fn (?array $state) => new SubscriptionProcess($state),
    eventHandler: $applicationEventHandler,
);

$events = $interactor->call($chargeCommand);
```

* **Automatic Concurrency Retries:** If another process writes to the same aggregate concurrently (`lock_version` mismatch or duplicate initial insert), `Interactor` automatically reloads the freshly committed state and retries command execution (defaults to `maxRetries: 100` with zero delay). Callers do not need to write manual retry loops.

---

## 3. Controller Delegation Pattern (First-Class Callables)

In clean architecture, the aggregate process remains a **pure domain object** without dependencies on HTTP requests, session storage, or external third-party SDKs. The controller acts as the orchestrator and ambient bridge.

### Pattern Structure
The controller injects delegates into the process using PHP 8.4+ First-Class Callables (`$this->methodName(...)`):
1. **Validation & Enrichment:** `$this->checkSomething(...)` validates request constraints and can throw an exception to halt execution if invalid.
2. **Side-Effect / Query Services:** Injects callables to query read models or interact with external services.
3. **Ambient Event Metadata:** `$this->initializeEvent(...)` attaches ambient request context to domain events.

### Full Controller Example
```php
namespace App\Http\Controllers;

use App\Domain\Subscription\SubscriptionProcess;
use App\Domain\Subscription\Commands\ChargeSubscriptionCommand;
use App\Services\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SlimeSystems\Eloquent\ApplicationEventHandler;
use SlimeSystems\Eloquent\Interactor;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly PaymentGateway $paymentGateway,
        private readonly ApplicationEventHandler $eventHandler,
    ) {}

    public function charge(Request $request, string $id): JsonResponse
    {
        $command = new ChargeSubscriptionCommand(
            subscriptionId: $id,
            amount: (int) $request->input('amount'),
            paymentMethodId: $request->input('payment_method_id'),
        );

        $events = $this->interactor($id, $request)->call($command);

        return response()->json([
            'status' => 'success',
            'dispatched_events' => count($events),
        ]);
    }

    private function interactor(string $id, Request $request): Interactor
    {
        return new Interactor(
            stateKey: ['type' => 'subscription', 'id' => $id],
            loadProcess: fn (?array $state) => new SubscriptionProcess(
                state: $state,
                initializeEvent: fn ($class, $data) => $this->initializeEvent($class, $data, $request),
                checkQuota: $this->checkTenantQuota(...),
                chargePayment: $this->paymentGateway->processCharge(...),
            ),
            eventHandler: $this->eventHandler,
        );
    }

    /**
     * Taps into aggregate flow: validates against ambient rules or interrupts flow.
     */
    private function checkTenantQuota(int $amount, Request $request): void
    {
        $tenantId = $request->route('tenant_id');
        $currentUsage = $this->fetchTenantUsage($tenantId);

        if ($currentUsage + $amount > 100000) {
            abort(422, 'Monthly billing limit exceeded for this tenant.');
        }
    }

    /**
     * Enriches domain events with ambient request and auth metadata.
     */
    private function initializeEvent(string $eventClass, array $data, Request $request): object
    {
        return $eventClass::strict(
            data: $data,
            metadata: [
                'actor_id' => $request->user()?->id,
                'tenant_id' => $request->route('tenant_id'),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toIso8601String(),
            ]
        );
    }
}
```

**Note:** `initializeEvent` is typically implemented in a base controller extended by application controllers. Furthermore, the metadata fields shown above are purely illustrative and can be customized to fit your application's requirements.

---

## 4. Event Bus & Event Store Wiring (`ApplicationEventHandler`)

`ApplicationEventHandler` coordinates three targets when an aggregate produces events:
1. **Read Model Projector (Synchronous):** Updates query models in-memory immediately using `EventQueue` and `EventRouter`.
2. **Persistent Event Store (Audit Log):** Appends events to `stored_events` via `StoredEvent::persistEvents(...)` (automatically skipping `TransitoryEvent`s).
3. **Laravel Event Bus (Asynchronous):** Bridges domain events to Laravel's native event dispatcher via `EventBusBridge`, allowing queued listeners and notification jobs to react.

### Registration in Laravel Service Provider
```php
namespace App\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use SlimeSystems\Bridge\Laravel\EventBusBridge;
use SlimeSystems\Eloquent\ApplicationEventHandler;
use SlimeSystems\Eloquent\StoredEvent;
use SlimeSystems\Hatsume\EventQueue;
use SlimeSystems\Hatsume\EventRouter;
use App\Domain\Subscription\Events\SubscriptionCharged;
use App\Domain\Subscription\Projectors\SubscriptionProjector;

class DomainEventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ApplicationEventHandler::class, function ($app) {
            // 1. In-memory Projection Router
            $projector = new EventQueue(new EventRouter([
                SubscriptionCharged::class => $app->make(SubscriptionProjector::class),
            ]));

            // 2. Persistent Event Store (skips TransitoryEvents)
            $eventStore = StoredEvent::persistEvents(...);

            // 3. Laravel Event Bus Bridge
            $eventBus = new EventBusBridge($app->make(Dispatcher::class));

            return ApplicationEventHandler::make(
                projector: $projector,
                eventStore: $eventStore,
                eventBus: $eventBus,
            );
        });
    }
}
```

---

## 5. Distributed Scaling: Sharding & Global Counters

### Query Sharding (`SlimeSystems\Eloquent\QuerySharding`)
Apply `QuerySharding` on Eloquent models only when explicitly requested, to distribute queries across parallel workers:
```php
use Illuminate\Database\Eloquent\Model;
use SlimeSystems\Eloquent\QuerySharding;

class Transaction extends Model {
    use QuerySharding;
}

// In worker command: process shard 0 of 16
$records = Transaction::queryShard(shardCount: 16, shardNumber: 0)->get();
```

### Global Counters (`SlimeSystems\Eloquent\GlobalCount`)
Atomic sequence generator backed by the database:
```php
use SlimeSystems\Eloquent\GlobalCount;

// Atomically fetch the next sequential identifier
$sequenceNumber = GlobalCount::nextValue();
```
