<?php

namespace SlimeSystems\Tests;

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpContainer();
    }

    protected function setUpContainer(): void
    {
        $this->app = new Container();
        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);

        $this->app->singleton('files', fn () => new Filesystem());
        $this->app->singleton('translator', function () {
            $loader = new ArrayLoader();
            return new Translator($loader, 'en');
        });
        $this->app->singleton('validator', function ($app) {
            return new ValidationFactory($app['translator'], $app);
        });

        $this->setUpDatabase();
    }

    protected function setUpDatabase(): void
    {
        $events = new \Illuminate\Events\Dispatcher($this->app);
        $this->app->singleton('events', fn () => $events);

        $capsule = new \Illuminate\Database\Capsule\Manager($this->app);
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setEventDispatcher($events);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->app->singleton('db', fn () => $capsule->getDatabaseManager());
        $this->app->bind('db.schema', fn ($app) => $app['db']->connection()->getSchemaBuilder());
    }

    protected function tearDown(): void
    {
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }
}
