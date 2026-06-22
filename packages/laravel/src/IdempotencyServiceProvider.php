<?php

declare(strict_types=1);

namespace HttpIdempotency\Laravel;

use HttpIdempotency\Config\IdempotencyConfig;
use HttpIdempotency\Engine\IdempotencyHandler;
use HttpIdempotency\Engine\NullScopeResolver;
use HttpIdempotency\Engine\ScopeResolver;
use HttpIdempotency\Fingerprint\FingerprintGenerator;
use HttpIdempotency\Laravel\Console\PurgeExpiredCommand;
use HttpIdempotency\Laravel\Http\IdempotencyMiddleware;
use HttpIdempotency\Laravel\Store\DatabaseStore;
use HttpIdempotency\Laravel\Store\RedisStore;
use HttpIdempotency\Store\ArrayStore;
use HttpIdempotency\Store\StoreInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'idempotency');

        $this->app->singleton(IdempotencyConfig::class, function (Container $app): IdempotencyConfig {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('idempotency', []);

            return IdempotencyConfig::fromArray($config);
        });

        $this->app->singleton(FingerprintGenerator::class, fn (Container $app) => new FingerprintGenerator(
            $app->make(IdempotencyConfig::class),
        ));

        // Default scope resolver; applications may bind their own.
        $this->app->bindIf(ScopeResolver::class, NullScopeResolver::class);

        $this->app->singleton(StoreInterface::class, fn (Container $app) => $this->makeStore($app));

        $this->app->singleton(IdempotencyHandler::class, fn (Container $app) => new IdempotencyHandler(
            $app->make(StoreInterface::class),
            $app->make(FingerprintGenerator::class),
            $app->make(IdempotencyConfig::class),
            $app->make(ScopeResolver::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('idempotency', IdempotencyMiddleware::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([$this->configPath() => $this->app->configPath('idempotency.php')], 'idempotency-config');
            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'idempotency-migrations');

            $this->commands([PurgeExpiredCommand::class]);
        }

        $this->schedulePurge();
    }

    private function makeStore(Container $app): StoreInterface
    {
        /** @var array<string, mixed> $config */
        $config = $app['config']->get('idempotency', []);
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : 'database';

        return match ($driver) {
            'database' => $this->makeDatabaseStore($app, $config),
            'redis' => $this->makeRedisStore($app, $config),
            'array' => new ArrayStore,
            default => throw new RuntimeException("Unsupported idempotency store driver [{$driver}]."),
        };
    }

    /** @param array<string, mixed> $config */
    private function makeDatabaseStore(Container $app, array $config): DatabaseStore
    {
        /** @var array{connection?: string|null, table?: string} $db */
        $db = is_array($config['database'] ?? null) ? $config['database'] : [];

        /** @var DatabaseManager $manager */
        $manager = $app->make('db');

        return new DatabaseStore(
            $manager->connection($db['connection'] ?? null),
            $db['table'] ?? 'idempotency_keys',
        );
    }

    /** @param array<string, mixed> $config */
    private function makeRedisStore(Container $app, array $config): RedisStore
    {
        /** @var array{connection?: string, prefix?: string} $redis */
        $redis = is_array($config['redis'] ?? null) ? $config['redis'] : [];

        /** @var RedisFactory $factory */
        $factory = $app->make('redis');

        return new RedisStore(
            $factory->connection($redis['connection'] ?? 'default'),
            $redis['prefix'] ?? 'idempotency:',
        );
    }

    private function schedulePurge(): void
    {
        /** @var array{schedule?: bool, cron?: string} $purge */
        $purge = (array) $this->app['config']->get('idempotency.purge', []);

        if (($purge['schedule'] ?? false) !== true) {
            return;
        }

        $this->app->booted(function (): void {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);
            /** @var array{cron?: string} $purge */
            $purge = (array) $this->app['config']->get('idempotency.purge', []);
            $schedule->command('idempotency:purge')->cron($purge['cron'] ?? '0 3 * * *');
        });
    }

    private function configPath(): string
    {
        return __DIR__.'/../config/idempotency.php';
    }
}
