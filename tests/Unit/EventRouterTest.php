<?php

use SlimeSystems\Hatsume\Event;
use SlimeSystems\Hatsume\EventRouter;

class UserRegistered {
    use Event;
}

class UserDeactivated {
    use Event;
}

class UnhandledEvent {
    use Event;
}

class UserProjection {
    public array $recorded = [];

    public function onUserRegistered(UserRegistered $event, array $metadata = []): void {
        $this->recorded[] = [
            'type' => 'registered',
            'data' => $event->data,
            'metadata' => $metadata,
        ];
    }
}

describe('EventRouter', function () {
    test('routes event to registered closure with metadata', function () {
        $captured = [];

        $router = new EventRouter([
            UserRegistered::class => function (UserRegistered $event, array $metadata) use (&$captured) {
                $captured[] = ['event' => $event, 'metadata' => $metadata];
            },
        ]);

        $event = new UserRegistered(['user_id' => 123]);
        $metadata = ['actor' => 'admin'];

        $router($event, $metadata);

        expect($captured)->toHaveCount(1)
            ->and($captured[0]['event'])->toBe($event)
            ->and($captured[0]['metadata'])->toBe($metadata);
    });

    test('routes event to array callable handler', function () {
        $projection = new UserProjection();

        $router = new EventRouter([
            UserRegistered::class => [$projection, 'onUserRegistered'],
        ]);

        $event = new UserRegistered(['user_id' => 456]);
        $metadata = ['source' => 'web'];

        $router->call($event, $metadata);

        expect($projection->recorded)->toHaveCount(1)
            ->and($projection->recorded[0]['type'])->toBe('registered')
            ->and($projection->recorded[0]['data'])->toBe(['user_id' => 456])
            ->and($projection->recorded[0]['metadata'])->toBe(['source' => 'web']);
    });

    test('ignores unhandled events without raising an error', function () {
        $invoked = false;

        $router = new EventRouter([
            UserRegistered::class => function () use (&$invoked) {
                $invoked = true;
            },
        ]);

        $router(new UnhandledEvent());

        expect($invoked)->toBeFalse();
    });

    test('handles empty handlers map gracefully', function () {
        $router = new EventRouter();

        expect(fn () => $router(new UserRegistered()))->not->toThrow(Throwable::class);
    });
});
