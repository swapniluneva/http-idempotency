<?php

declare(strict_types=1);

namespace HttpIdempotency\Laravel\Console;

use HttpIdempotency\Store\StoreInterface;
use Illuminate\Console\Command;

/**
 * Removes expired idempotency records. The database driver relies on this;
 * the Redis driver self-expires keys, so this reports 0 there.
 */
final class PurgeExpiredCommand extends Command
{
    protected $signature = 'idempotency:purge';

    protected $description = 'Purge expired idempotency-key records from the configured store.';

    public function handle(StoreInterface $store): int
    {
        $purged = $store->purgeExpired();

        $this->info(sprintf('Purged %d expired idempotency record(s).', $purged));

        return self::SUCCESS;
    }
}
