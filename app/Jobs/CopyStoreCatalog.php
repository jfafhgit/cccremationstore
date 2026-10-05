<?php

namespace App\Jobs;

use App\Enums\CatalogCopyStatus;
use App\Models\Store;
use App\Services\StoreDuplicator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies a duplicated store's products and images from the original in the
 * background, so a large catalog doesn't time out the admin's request.
 */
class CopyStoreCatalog implements ShouldQueue
{
    use Queueable;

    /** Not retried: the admin is shown the failure and duplicates again. */
    public int $tries = 1;

    /**
     * Room for a large catalog's images, while staying under the database
     * queue's 90-second retry_after so a slow copy is never started twice.
     */
    public int $timeout = 85;

    public function __construct(public Store $source, public Store $duplicate) {}

    public function handle(StoreDuplicator $duplicator): void
    {
        $duplicator->copyCatalog($this->source, $this->duplicate);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Could not copy products into a duplicated store.', [
            'source_store_id' => $this->source->id,
            'store_id' => $this->duplicate->id,
            'message' => $exception?->getMessage(),
        ]);

        $this->duplicate->update(['catalog_copy_status' => CatalogCopyStatus::Failed]);
    }
}
