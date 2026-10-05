<?php

namespace App\Services;

use App\Enums\StoreStatus;
use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Takes a store off the platform.
 *
 * A store that has taken payments is archived: its storefront and staff
 * portal go offline, but its orders, payments, and refunds are kept and it
 * can be restored. A store that never took a payment is deleted outright,
 * along with its products, files, and any staff logins used only there.
 * Either way, its platform subscription is canceled first.
 */
class StoreDeleter
{
    public function __construct(private readonly PlatformBillingService $billing) {}

    /**
     * Whether removing this store archives it rather than deleting it.
     */
    public function archives(Store $store): bool
    {
        return $store->orders()->whereNotNull('paid_at')->exists();
    }

    /**
     * Archive or delete the store, and report whether it was archived.
     */
    public function remove(Store $store): bool
    {
        $this->billing->cancelSubscription($store);

        if ($this->archives($store)) {
            // Restoring brings it back suspended, so it isn't live again until an admin says so.
            $store->update(['status' => StoreStatus::Suspended]);
            $store->delete();

            return true;
        }

        $this->deletePermanently($store);

        return false;
    }

    public function restore(Store $store): void
    {
        $store->restore();
    }

    private function deletePermanently(Store $store): void
    {
        $files = $store->products()->whereNotNull('image_path')->pluck('image_path')
            ->push($store->brand_logo_path, $store->general_price_list_path)
            ->filter()
            ->all();

        DB::transaction(function () use ($store): void {
            $staffOnlyHereIds = $store->staff()->pluck('store_users.id')
                ->filter(fn (int $storeUserId) => ! DB::table('store_memberships')
                    ->where('store_user_id', $storeUserId)
                    ->where('store_id', '!=', $store->id)
                    ->exists());

            // Products, locations, orders, and memberships go with the store (cascading foreign keys).
            $store->forceDelete();

            StoreUser::whereKey($staffOnlyHereIds)->delete();
        });

        Storage::disk('public')->delete($files);
    }
}
