<?php

use SlimeSystems\Hatsume\Event;
use SlimeSystems\Hatsume\EventQueue;
use SlimeSystems\Hatsume\EventRouter;

class QueueEventA {
    use Event;
}

class QueueEventB {
    use Event;
}

describe('EventQueue', function () {
    test('dispatches each event in an array individually to handlers', function () {
        $received = [];

        $handler = function ($event, array $metadata = []) use (&$received) {
            $received[] = [
                'event' => $event,
                'metadata' => $metadata,
            ];
        };

        $queue = new EventQueue($handler);

        $event1 = new QueueEventA(['id' => 1]);
        $event2 = new QueueEventB(['id' => 2]);
        $metadata = ['batch_id' => 'batch-99'];

        $queue([$event1, $event2], $metadata);

        expect($received)->toHaveCount(2)
            ->and($received[0]['event'])->toBe($event1)
            ->and($received[0]['metadata'])->toBe($metadata)
            ->and($received[1]['event'])->toBe($event2)
            ->and($received[1]['metadata'])->toBe($metadata);
    });

    test('works seamlessly with EventRouter as handler', function () {
        $aHandled = [];
        $bHandled = [];

        $router = new EventRouter([
            QueueEventA::class => function (QueueEventA $event, array $metadata) use (&$aHandled) {
                $aHandled[] = $event->data;
            },
            QueueEventB::class => function (QueueEventB $event, array $metadata) use (&$bHandled) {
                $bHandled[] = $event->data;
            },
        ]);

        $queue = new EventQueue($router);

        $queue([
            new QueueEventA(['val' => 'first']),
            new QueueEventB(['val' => 'second']),
            new QueueEventA(['val' => 'third']),
        ]);

        expect($aHandled)->toBe([['val' => 'first'], ['val' => 'third']])
            ->and($bHandled)->toBe([['val' => 'second']]);
    });

    test('handles single non-iterable event as well', function () {
        $received = [];
        $queue = new EventQueue(function ($event) use (&$received) {
            $received[] = $event;
        });

        $event = new QueueEventA(['val' => 'single']);
        $queue->call($event);

        expect($received)->toHaveCount(1)
            ->and($received[0])->toBe($event);
    });
});
