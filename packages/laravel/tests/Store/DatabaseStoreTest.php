<?php

declare(strict_types=1);

namespace HttpIdempotency\Laravel\Tests\Store;

use HttpIdempotency\Clock\FrozenClock;
use HttpIdempotency\Laravel\Store\DatabaseStore;
use HttpIdempotency\Store\StoreInterface;
use HttpIdempotency\Tests\Store\StoreContractTestCase;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Runs the shared store contract against the relational store on an in-memory
 * SQLite database — no full Laravel app required.
 */
final class DatabaseStoreTest extends StoreContractTestCase
{
    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->connection = $capsule->getConnection();

        $this->connection->getSchemaBuilder()->create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('lookup_key', 64)->unique();
            $table->string('idempotency_key');
            $table->string('fingerprint', 64);
            $table->string('state', 16);
            $table->string('lock_token', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('expires_at');
            $table->unsignedInteger('completed_at')->nullable();
        });
    }

    protected function createStore(FrozenClock $clock): StoreInterface
    {
        return new DatabaseStore($this->connection, 'idempotency_keys', $clock);
    }
}
