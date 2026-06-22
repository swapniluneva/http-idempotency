<?php

declare(strict_types=1);

namespace HttpIdempotency\Laravel\Tests\Feature;

use HttpIdempotency\Laravel\IdempotencyServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** Counts how often the protected endpoint's body actually executed. */
    public static int $sideEffects = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::$sideEffects = 0;
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [IdempotencyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $config->set('idempotency.driver', 'database');
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->post('/payments', function (Request $request) {
            self::$sideEffects++;

            return response()->json([
                'id' => (string) Str::uuid(),
                'amount' => $request->input('amount'),
            ], 201);
        })->middleware('idempotency');

        $router->post('/optional', fn () => response()->json(['ok' => true]))
            ->middleware('idempotency:optional');

        $router->post('/boom', function () {
            self::$sideEffects++;

            return response()->json(['error' => 'unavailable'], 503);
        })->middleware('idempotency');
    }
}
