<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            // The scoped, hashed storage key. UNIQUE constraint is the atomic
            // primitive that makes concurrent begin() calls mutually exclusive.
            $table->string('lookup_key', 64)->unique();

            // Raw client key, kept for diagnostics only.
            $table->string('idempotency_key');

            // SHA-256 hex digest of the canonical request.
            $table->string('fingerprint', 64);

            $table->string('state', 16)->index(); // locked | completed
            $table->string('lock_token', 64);     // optimistic-lock token (CAS on complete)

            // Captured response (null while locked).
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->longText('response_body')->nullable();

            $table->unsignedInteger('created_at');
            $table->unsignedInteger('expires_at')->index(); // drives purge + takeover
            $table->unsignedInteger('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('idempotency.database.table', 'idempotency_keys');

        return is_string($table) ? $table : 'idempotency_keys';
    }
};
