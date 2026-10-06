<?php

use App\Enums\CatalogCopyStatus;
use App\Enums\PlatformFeeModel;
use App\Enums\StorePath;
use App\Enums\StoreSaleType;
use App\Enums\StoreStatus;
use App\Enums\StoreUserRole;
use App\Models\Store;
use App\Models\StoreUser;
use App\Notifications\StripeSetupRequestNotification;
use Flux\Flux;
use Illuminate\Support\Facades\Notification;
use Illuminate\Http\UploadedFile;
use App\Services\PlatformBillingService;
use App\Services\StoreDeleter;
use App\Services\StoreDuplicator;
use App\Services\StripeConnectService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Stripe\Exception\ApiErrorException;

new class extends Component
{
    use WithFileUploads;

    public Store $currentStore;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:255|alpha_dash')]
    public string $slug = '';

    public string $status = '';

    public string $saleType = '';

    public string $checkoutPath = '';

    public bool $requiresContainer = false;

    public bool $requiresUrn = false;

    public bool $requiresUrnVault = false;

    public bool $preselectContainer = false;

    public bool $preselectUrn = false;

    public bool $preselectUrnVault = false;

    public bool $offersFamilyProvidedContainer = false;

    public bool $offersFamilyProvidedUrn = false;

    /** The funeral home's own Vital Statistics form, used instead of ours when set. */
    public string $vitalStatisticsUrl = '';

    #[Validate('nullable|string|max:255')]
    public string $contactName = '';

    #[Validate('nullable|email|max:255')]
    public string $contactEmail = '';

    #[Validate('nullable|email|max:255')]
    public string $generalEmail = '';

    /** The funeral home's own website, linked from the storefront header. */
    public string $websiteUrl = '';

    #[Validate('nullable|string|max:30')]
    public string $contactPhone = '';

    #[Validate('nullable|string|max:255')]
    public string $timezone = '';

    #[Validate('nullable|string|max:7')]
    public string $brandPrimaryColor = '';

    /** A newly-chosen General Price List PDF waiting to be saved. */
    public ?UploadedFile $generalPriceListFile = null;

    public bool $removeGeneralPriceList = false;

    /** A newly-chosen logo image waiting to be saved. */
    public ?UploadedFile $brandLogoFile = null;

    public bool $removeBrandLogo = false;

    public string $platformFeeModel = 'percentage';

    public string $platformFeePercent = '5.00';

    public string $platformFeeFlat = '0.00';

    public string $subscriptionMonthly = '';

    #[Validate('required|numeric|min:0|max:100')]
    public string $taxRatePercent = '0.00';

    #[Validate('boolean')]
    public bool $processingFeeEnabled = false;

    #[Validate('required|numeric|min:0|max:100')]
    public string $processingFeePercent = '3.50';

    public bool $showStaffForm = false;

    #[Validate('required|string|max:255')]
    public string $staffName = '';

    #[Validate('required|email|max:255')]
    public string $staffEmail = '';

    public string $staffRole = 'staff';

    public string $duplicateName = '';

    public string $duplicateSlug = '';

    /** The store's name, typed to confirm deleting or archiving it. */
    public string $removeConfirmation = '';

    public function mount(Store $store): void
    {
        $this->currentStore = $store;
        $this->name = $store->name;
        $this->slug = $store->slug;
        $this->status = $store->status->value;
        $this->saleType = $store->sale_type->value;
        $this->checkoutPath = $store->checkout_path->value;
        $this->requiresContainer = $store->requires_container;
        $this->requiresUrn = $store->requires_urn;
        $this->requiresUrnVault = $store->requires_urn_vault;
        $this->preselectContainer = $store->preselect_container;
        $this->preselectUrn = $store->preselect_urn;
        $this->preselectUrnVault = $store->preselect_urn_vault;
        $this->offersFamilyProvidedContainer = $store->offers_family_provided_container;
        $this->offersFamilyProvidedUrn = $store->offers_family_provided_urn;
        $this->vitalStatisticsUrl = $store->vital_statistics_url ?? '';
        $this->contactName = $store->contact_name ?? '';
        $this->contactEmail = $store->contact_email ?? '';
        $this->contactPhone = $store->contact_phone ?? '';
        $this->generalEmail = $store->general_email ?? '';
        $this->websiteUrl = $store->website_url ?? '';
        $this->timezone = $store->timezone;
        $this->brandPrimaryColor = $store->brand_primary_color ?? '';
        $this->platformFeeModel = $store->platform_fee_model->value;
        $this->platformFeePercent = number_format($store->platform_fee_bps / 100, 2, '.', '');
        $this->platformFeeFlat = number_format($store->platform_fee_flat_cents / 100, 2, '.', '');
        $this->subscriptionMonthly = $store->subscription_monthly_cents > 0
            ? number_format($store->subscription_monthly_cents / 100, 2, '.', '')
            : '';
        $this->taxRatePercent = number_format($store->tax_rate_bps / 100, 2, '.', '');
        $this->processingFeeEnabled = $store->processing_fee_enabled;
        $this->processingFeePercent = number_format($store->processing_fee_bps / 100, 2, '.', '');
    }

    public function save(PlatformBillingService $billing): void
    {
        // Accept "29564b" as well as "#29564b".
        $this->brandPrimaryColor = preg_replace('/^([0-9a-fA-F]{6})$/', '#$1', trim($this->brandPrimaryColor));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:stores,slug,'.$this->currentStore->id],
            'status' => ['required', 'in:draft,active,suspended'],
            'saleType' => ['required', Rule::enum(StoreSaleType::class)],
            'checkoutPath' => ['required', Rule::enum(StorePath::class)],
            'requiresContainer' => ['boolean'],
            'requiresUrn' => ['boolean'],
            'requiresUrnVault' => ['boolean'],
            'preselectContainer' => ['boolean'],
            'preselectUrn' => ['boolean'],
            'preselectUrnVault' => ['boolean'],
            'offersFamilyProvidedContainer' => ['boolean'],
            'offersFamilyProvidedUrn' => ['boolean'],
            'vitalStatisticsUrl' => ['nullable', 'url:http,https', 'max:2048'],
            'contactName' => ['nullable', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:30'],
            'generalEmail' => ['nullable', 'email', 'max:255'],
            'websiteUrl' => ['nullable', 'url:http,https', 'max:2048'],
            'timezone' => ['required', 'string', 'max:255'],
            'brandPrimaryColor' => ['nullable', 'string', 'regex:'.Store::BRAND_COLOR_PATTERN],
            'generalPriceListFile' => ['nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'],
            // Raster formats only: an SVG served from our own domain can carry script.
            'brandLogoFile' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'platformFeeModel' => ['required', Rule::enum(PlatformFeeModel::class)],
            'platformFeePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'platformFeeFlat' => ['required', 'numeric', 'min:0', 'max:10000'],
            'subscriptionMonthly' => [Rule::requiredIf($this->platformFeeModel === PlatformFeeModel::Subscription->value), 'nullable', 'numeric', 'min:1', 'max:100000'],
            'taxRatePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'processingFeeEnabled' => ['boolean'],
            'processingFeePercent' => ['required', 'numeric', 'min:0', 'max:100'],
        ], [
            'brandPrimaryColor.regex' => __('Enter the brand color as a hex code, like #29564b.'),
        ]);

        $this->currentStore->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'status' => StoreStatus::from($validated['status']),
            'sale_type' => StoreSaleType::from($validated['saleType']),
            'checkout_path' => StorePath::from($validated['checkoutPath']),
            'requires_container' => $validated['requiresContainer'],
            'requires_urn' => $validated['requiresUrn'],
            'requires_urn_vault' => $validated['requiresUrnVault'],
            'preselect_container' => $validated['preselectContainer'],
            'preselect_urn' => $validated['preselectUrn'],
            'preselect_urn_vault' => $validated['preselectUrnVault'],
            'offers_family_provided_container' => $validated['offersFamilyProvidedContainer'],
            'offers_family_provided_urn' => $validated['offersFamilyProvidedUrn'],
            'vital_statistics_url' => $validated['vitalStatisticsUrl'] ?: null,
            'contact_name' => $validated['contactName'] ?: null,
            'contact_email' => $validated['contactEmail'] ?: null,
            'contact_phone' => $validated['contactPhone'] ?: null,
            'general_email' => $validated['generalEmail'] ?: null,
            'website_url' => $validated['websiteUrl'] ?: null,
            'timezone' => $validated['timezone'],
            'brand_primary_color' => $validated['brandPrimaryColor'] ?: null,
            'platform_fee_model' => PlatformFeeModel::from($validated['platformFeeModel']),
            'platform_fee_bps' => (int) round(((float) $validated['platformFeePercent']) * 100),
            'platform_fee_flat_cents' => (int) round(((float) $validated['platformFeeFlat']) * 100),
            // Only the subscription model shows this field; keep the last amount otherwise.
            'subscription_monthly_cents' => filled($validated['subscriptionMonthly'])
                ? (int) round(((float) $validated['subscriptionMonthly']) * 100)
                : $this->currentStore->subscription_monthly_cents,
            'tax_rate_bps' => (int) round(((float) $validated['taxRatePercent']) * 100),
            'processing_fee_enabled' => $validated['processingFeeEnabled'],
            'processing_fee_bps' => (int) round(((float) $validated['processingFeePercent']) * 100),
        ]);

        // Apple Pay / Google Pay only work on a domain registered with the store's Stripe account.
        if ($this->currentStore->wasChanged('slug')) {
            app(StripeConnectService::class)->ensurePaymentMethodDomain($this->currentStore);
        }

        if ($this->currentStore->wasChanged('status') && $this->canInviteStaff()) {
            $this->sendHeldBackInvitations();
        }

        $this->currentStore->syncFamilyProvidedProducts();
        $this->saveGeneralPriceList();
        $this->saveBrandLogo();

        $this->currentStore->refresh();

        if (! $this->syncSubscriptionTerms($billing)) {
            return;
        }

        Flux::toast(variant: 'success', text: __('Store updated.'));
    }

    /**
     * Push a changed monthly amount or fee model to the store's live
     * subscription. The store's own settings are already saved either way.
     */
    private function syncSubscriptionTerms(PlatformBillingService $billing): bool
    {
        if (! $this->currentStore->hasLiveSubscription()) {
            return true;
        }

        try {
            $this->currentStore = $billing->applyStoreTerms($this->currentStore);
        } catch (ApiErrorException|\RuntimeException $e) {
            Log::error('Could not update the store subscription in Stripe.', [
                'store_id' => $this->currentStore->id,
                'message' => $e->getMessage(),
            ]);

            Flux::toast(variant: 'warning', text: __('Store saved, but its Stripe subscription could not be updated. Please save again in a moment.'));

            return false;
        }

        return true;
    }

    /**
     * Store a newly-uploaded General Price List (replacing any previous one),
     * or delete the current one if the admin marked it for removal.
     */
    private function saveGeneralPriceList(): void
    {
        $this->replaceStoredFile('general_price_list_path', $this->generalPriceListFile, $this->removeGeneralPriceList, 'price-lists');

        $this->reset(['generalPriceListFile', 'removeGeneralPriceList']);
    }

    /**
     * Store a newly-uploaded logo (replacing any previous one), or delete the
     * current one if the admin marked it for removal.
     */
    private function saveBrandLogo(): void
    {
        $this->replaceStoredFile('brand_logo_path', $this->brandLogoFile, $this->removeBrandLogo, 'logos');

        $this->reset(['brandLogoFile', 'removeBrandLogo']);
    }

    private function replaceStoredFile(string $column, ?UploadedFile $upload, bool $remove, string $directory): void
    {
        $previousPath = $this->currentStore->{$column};

        if ($upload) {
            $this->currentStore->update([$column => $upload->store($directory, 'public')]);
        } elseif ($remove) {
            $this->currentStore->update([$column => null]);
        } else {
            return;
        }

        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }
    }

    /**
     * Add a login for this store. An email that already has a login (at
     * another location) gets access here too, keeping its one password.
     */
    public function createStaffUser(): void
    {
        $validated = $this->validate([
            'staffName' => ['required', 'string', 'max:255'],
            'staffEmail' => ['required', 'email', 'max:255'],
            'staffRole' => ['required', Rule::enum(StoreUserRole::class)],
        ]);

        $existingLogin = StoreUser::where('email', $validated['staffEmail'])->first();

        if ($existingLogin?->belongsToStore($this->currentStore)) {
            $this->addError('staffEmail', __('This person already has access to this store.'));

            return;
        }

        // Nobody knows this password; the staff member chooses their own
        // from the invitation email.
        $storeUser = $existingLogin ?? StoreUser::create([
            'name' => $validated['staffName'],
            'email' => $validated['staffEmail'],
            'password' => Hash::make(Str::random(40)),
        ]);

        $storeUser->stores()->attach($this->currentStore, ['role' => $validated['staffRole']]);

        $this->reset(['staffName', 'staffEmail', 'staffRole', 'showStaffForm']);
        $this->currentStore->refresh();

        if ($storeUser->hasAcceptedInvitation()) {
            Flux::toast(
                variant: 'success',
                heading: __('Access added.'),
                text: __(':name already has a staff login, so they can sign in here with their existing password or use "Switch location" in their portal.', ['name' => $storeUser->name]),
            );

            return;
        }

        if (! $this->canInviteStaff()) {
            Flux::toast(
                heading: __('Staff login created.'),
                text: __('Their invitation email will go out automatically when this store goes live.'),
            );

            return;
        }

        $storeUser->sendInvitation($this->currentStore, auth()->user());

        Flux::toast(variant: 'success', heading: __('Staff login created.'), text: __('An invitation to choose a password was emailed to :email.', ['email' => $storeUser->email]));
    }

    public function updatedDuplicateName(): void
    {
        if (! $this->duplicateSlug) {
            $this->duplicateSlug = str($this->duplicateName)->slug();
        }
    }

    public function duplicateStore(StoreDuplicator $duplicator): void
    {
        $validated = $this->validate([
            'duplicateName' => ['required', 'string', 'max:255'],
            'duplicateSlug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:stores,slug'],
        ], attributes: [
            'duplicateName' => __('name'),
            'duplicateSlug' => __('subdomain'),
        ]);

        $duplicate = $duplicator->duplicate($this->currentStore, $validated['duplicateName'], $validated['duplicateSlug']);

        Flux::toast(variant: 'success', text: __('Store created as a draft. Its products and images are being copied now.'));

        $this->redirect(route('admin.stores.show', $duplicate), navigate: true);
    }

    /**
     * Polled while a duplicated store's products are copied in the background.
     */
    public function checkCatalogCopy(): void
    {
        $this->currentStore->refresh();
    }

    /**
     * Whether removing this store archives it (it has paid orders) rather
     * than deleting it.
     */
    public function removalArchives(): bool
    {
        return app(StoreDeleter::class)->archives($this->currentStore);
    }

    public function removeStore(StoreDeleter $deleter): void
    {
        $this->validate(
            ['removeConfirmation' => ['required', Rule::in([$this->currentStore->name])]],
            ['removeConfirmation.in' => __('Type the store name exactly as shown to confirm.')],
            ['removeConfirmation' => __('store name')],
        );

        try {
            $archived = $deleter->remove($this->currentStore);
        } catch (ApiErrorException|\RuntimeException $e) {
            Log::error('Could not cancel the platform subscription while removing a store.', [
                'store_id' => $this->currentStore->id,
                'message' => $e->getMessage(),
            ]);

            $this->addError('removeConfirmation', __('Could not cancel this store\'s subscription in Stripe, so nothing was removed. Please try again in a moment.'));

            return;
        }

        Flux::toast(variant: 'success', text: $archived
            ? __(':store was archived. Its orders are kept under Archived stores.', ['store' => $this->currentStore->name])
            : __(':store was deleted.', ['store' => $this->currentStore->name]));

        $this->redirect(route('admin.stores.index'), navigate: true);
    }

    public function restoreStore(StoreDeleter $deleter): void
    {
        $deleter->restore($this->currentStore);

        Flux::toast(variant: 'success', text: __('Store restored. It is suspended until you set it active again.'));
    }

    /**
     * Email a new invitation link, which also cancels any link sent before it.
     */
    public function sendStaffInvitation(int $storeUserId): void
    {
        $storeUser = $this->currentStore->staff()->whereNull('invitation_accepted_at')->findOrFail($storeUserId);

        if (! $this->canInviteStaff()) {
            Flux::toast(variant: 'danger', text: __('Invitations can only be sent once the store is live.'));

            return;
        }

        $storeUser->sendInvitation($this->currentStore, auth()->user());
        $this->currentStore->refresh();

        Flux::toast(variant: 'success', text: __('Invitation sent to :email.', ['email' => $storeUser->email]));
    }

    /**
     * Staff added while the store was a draft have never been emailed.
     */
    protected function sendHeldBackInvitations(): void
    {
        $this->currentStore->staff()
            ->whereNull('invited_at')
            ->whereNull('invitation_accepted_at')
            ->each(fn (StoreUser $storeUser) => $storeUser->sendInvitation($this->currentStore, auth()->user()));
    }

    /**
     * Invitation links open on the store's own subdomain, which only serves
     * active stores, so a link sent earlier could not be used.
     */
    protected function canInviteStaff(): bool
    {
        return $this->currentStore->status === StoreStatus::Active;
    }

    /**
     * Take away this store's access only. A login left with no locations at
     * all is deleted.
     */
    public function removeStaffUser(int $storeUserId): void
    {
        $storeUser = $this->currentStore->staff()->findOrFail($storeUserId);

        $storeUser->stores()->detach($this->currentStore);

        if (! $storeUser->stores()->exists()) {
            $storeUser->delete();
        }

        $this->currentStore->refresh();
    }

    /**
     * Ask the store's owners to connect their own Stripe account from the
     * staff portal, for funeral homes whose Stripe we don't have access to.
     */
    public function requestStripeSetup(): void
    {
        $owners = $this->currentStore->signedUpOwners();

        if ($owners->isEmpty()) {
            Flux::toast(variant: 'danger', text: __('Add an owner login first. Once they have chosen a password, they can be asked to connect Stripe.'));

            return;
        }

        Notification::send($owners, new StripeSetupRequestNotification($this->currentStore, auth()->user()));

        Flux::toast(variant: 'success', text: __('Stripe setup request emailed to :emails.', ['emails' => $owners->pluck('email')->join(', ')]));
    }

    public function resetStripeConnection(StripeConnectService $stripeConnect): void
    {
        try {
            $this->currentStore = $stripeConnect->resetUnfinishedAccount($this->currentStore);
        } catch (ApiErrorException|\RuntimeException $e) {
            Log::warning('Could not reset Stripe connection.', [
                'store_id' => $this->currentStore->id,
                'message' => $e->getMessage(),
            ]);

            $this->currentStore->refresh();

            Flux::toast(variant: 'danger', text: $this->currentStore->stripe_details_submitted
                ? __('This Stripe account has already been set up, so it can no longer be reset.')
                : __('Could not reach Stripe just now. Please try again in a moment.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Stripe connection reset. Connect Stripe again to start over with :email.', ['email' => $this->currentStore->contact_email]));
    }
}; ?>

<div>
    <flux:link :href="route('admin.stores.index')" wire:navigate class="text-sm text-zinc-500">&larr; {{ __('All stores') }}</flux:link>

    <div class="mt-4 flex items-center justify-between">
        <flux:heading size="xl">{{ $currentStore->name }}</flux:heading>
        <div class="flex gap-2">
            @if ($currentStore->trashed())
                <flux:button :href="route('admin.stores.orders', $currentStore)" wire:navigate variant="ghost">{{ __('Orders') }}</flux:button>
                <flux:button variant="primary" icon="arrow-uturn-left" wire:click="restoreStore">{{ __('Restore store') }}</flux:button>
            @else
                <flux:button :href="route('admin.stores.products', $currentStore)" wire:navigate variant="ghost">{{ __('Products') }}</flux:button>
                <flux:button :href="route('admin.stores.locations', $currentStore)" wire:navigate variant="ghost">{{ __('Locations') }}</flux:button>
                <flux:button :href="route('admin.stores.orders', $currentStore)" wire:navigate variant="ghost">{{ __('Orders') }}</flux:button>
                <flux:button href="https://{{ $currentStore->slug }}.{{ config('app.root_domain') }}" target="_blank" variant="ghost">{{ __('View store') }}</flux:button>
                <flux:modal.trigger name="duplicate-store">
                    <flux:button variant="ghost" icon="document-duplicate">{{ __('Duplicate') }}</flux:button>
                </flux:modal.trigger>
                <flux:modal.trigger name="remove-store">
                    <flux:button variant="ghost" icon="trash" class="!text-red-600">{{ __('Delete') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </div>

    @if ($currentStore->catalog_copy_status === CatalogCopyStatus::Copying)
        <div wire:poll.2s="checkCatalogCopy">
            <flux:callout icon="arrow-path" class="mt-4" :heading="__('Copying products and images…')">
                <flux:callout.text>{{ __('This can take a minute for a large catalog. This page updates on its own when it is done, and you can keep working in the meantime.') }}</flux:callout.text>
            </flux:callout>
        </div>
    @elseif ($currentStore->catalog_copy_status === CatalogCopyStatus::Failed)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-4" :heading="__('The products and images could not be copied into this store.')">
            <flux:callout.text>{{ __('Nothing was copied. Delete this store and duplicate the original again.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($currentStore->trashed())
        <flux:callout variant="warning" icon="archive-box" class="mt-4" :heading="__('This store was archived on :date.', ['date' => $currentStore->deleted_at->format('F j, Y')])">
            <flux:callout.text>{{ __('Its storefront and staff portal are offline, and its orders are kept for your records. Restoring it brings it back as suspended, so it is not live again until you set it active.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:modal name="remove-store" class="md:w-md">
        <form wire:submit="removeStore" class="space-y-5">
            @if ($this->removalArchives())
                <div>
                    <flux:heading size="lg">{{ __('Archive :store?', ['store' => $currentStore->name]) }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Families have paid for orders here, so this store will be archived instead of deleted. Its storefront and staff portal go offline right away, and its subscription is canceled. Its orders, payments, and refunds are kept, and you can restore the store later from Archived stores.') }}</flux:text>
                </div>
            @else
                <div>
                    <flux:heading size="lg">{{ __('Delete :store?', ['store' => $currentStore->name]) }}</flux:heading>
                    <flux:text class="mt-2">{{ __('This store has no paid orders, so it will be permanently deleted with its products, locations, unpaid orders, files, and any staff logins that only belong to it. Its subscription is canceled. This cannot be undone.') }}</flux:text>
                </div>
            @endif

            <flux:field>
                <flux:label>{{ __('Type :name to confirm', ['name' => $currentStore->name]) }}</flux:label>
                <flux:input wire:model="removeConfirmation" autocomplete="off" />
                <flux:error name="removeConfirmation" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger">{{ $this->removalArchives() ? __('Archive store') : __('Delete store') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="duplicate-store" class="md:w-md">
        <form wire:submit="duplicateStore" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Duplicate :store', ['store' => $currentStore->name]) }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Creates a new draft store with a copy of every product (including variants and images) and this store\'s checkout, tax, and fee settings. Contacts, branding, the price list, Stripe, billing, staff logins, and orders are not copied.') }}
                </flux:text>
            </div>

            <flux:field>
                <flux:label>{{ __('New funeral home name') }}</flux:label>
                <flux:input wire:model.blur="duplicateName" required />
                <flux:error name="duplicateName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Subdomain') }}</flux:label>
                <flux:input wire:model="duplicateSlug" required>
                    <x-slot name="iconTrailing">
                        <span class="text-xs text-zinc-400">.{{ config('app.root_domain') }}</span>
                    </x-slot>
                </flux:input>
                <flux:error name="duplicateSlug" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Duplicate store') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <div class="border-b border-zinc-100 px-6 py-4 dark:border-zinc-700">
                    <flux:heading size="lg">{{ __('Store details') }}</flux:heading>
                </div>

                <form wire:submit="save" class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    {{-- General --}}
                    <section class="space-y-4 px-6 py-5">
                        <div>
                            <flux:heading size="sm">{{ __('General') }}</flux:heading>
                            <flux:text class="text-xs">{{ __('How the store is identified and whether it is open.') }}</flux:text>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Name') }}</flux:label>
                                <flux:input wire:model="name" required />
                                <flux:error name="name" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Subdomain') }}</flux:label>
                                <flux:input wire:model="slug" required>
                                    <x-slot name="iconTrailing">
                                        <span class="text-xs text-zinc-400">.{{ config('app.root_domain') }}</span>
                                    </x-slot>
                                </flux:input>
                                <flux:error name="slug" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Status') }}</flux:label>
                                <flux:select wire:model="status">
                                    @foreach (StoreStatus::cases() as $option)
                                        <option value="{{ $option->value }}">{{ ucfirst($option->value) }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="status" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Timezone') }}</flux:label>
                                <flux:input wire:model="timezone" required />
                                <flux:error name="timezone" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Main phone number') }}</flux:label>
                                <flux:input type="tel" wire:model="contactPhone" />
                                <flux:description>{{ __('Shown to families in the storefront header and on their receipt email.') }}</flux:description>
                                <flux:error name="contactPhone" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Main email') }}</flux:label>
                                <flux:input type="email" wire:model="generalEmail" placeholder="info@example.com" />
                                <flux:description>{{ __('Family replies go here, and it gets new order alerts along with staff logins.') }}</flux:description>
                                <flux:error name="generalEmail" />
                            </flux:field>
                            <flux:field class="sm:col-span-2">
                                <flux:label>{{ __('Funeral home website') }}</flux:label>
                                <flux:input type="url" wire:model="websiteUrl" placeholder="https://www.example.com" />
                                <flux:description>{{ __('Linked from the storefront header, and offered to families once they finish their Vital Statistics. Not shown when the store is embedded on that site.') }}</flux:description>
                                <flux:error name="websiteUrl" />
                            </flux:field>
                        </div>
                    </section>

                    {{-- Contact --}}
                    <section class="space-y-4 px-6 py-5">
                        <div>
                            <flux:heading size="sm">{{ __('Contact') }}</flux:heading>
                            <flux:text class="text-xs">{{ __('Only used for Stripe setup and platform billing. Never shown to families.') }}</flux:text>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Name') }}</flux:label>
                                <flux:input wire:model="contactName" />
                                <flux:error name="contactName" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Email') }}</flux:label>
                                <flux:input type="email" wire:model="contactEmail" />
                                <flux:error name="contactEmail" />
                            </flux:field>
                        </div>
                    </section>

                    {{-- Branding & documents --}}
                    <section class="space-y-4 px-6 py-5">
                        <div>
                            <flux:heading size="sm">{{ __('Branding & documents') }}</flux:heading>
                            <flux:text class="text-xs">{{ __('How the storefront looks, and the price list it links to.') }}</flux:text>
                        </div>
                        <div class="grid gap-x-4 gap-y-5 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Brand color') }}</flux:label>
                                <flux:input type="text" wire:model.live.debounce.500ms="brandPrimaryColor" placeholder="#29564b">
                                    @if (preg_match(Store::BRAND_COLOR_PATTERN, $brandPrimaryColor))
                                        <x-slot name="iconTrailing">
                                            <span class="block size-5 rounded border border-zinc-300" style="background-color: {{ $brandPrimaryColor }}"></span>
                                        </x-slot>
                                    @endif
                                </flux:input>
                                <flux:description>{{ __('Buttons and checkout steps.') }}</flux:description>
                                <flux:error name="brandPrimaryColor" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Logo') }}</flux:label>
                                @if ($currentStore->brand_logo_path && ! $removeBrandLogo)
                                    <div class="flex items-center gap-3">
                                        <img src="{{ $currentStore->brandLogoUrl() }}" alt="{{ __('Current logo') }}" class="h-10 max-w-40 rounded border border-zinc-200 bg-white object-contain p-1 dark:border-zinc-600" />
                                        <button type="button" wire:click="$set('removeBrandLogo', true)" class="text-xs text-zinc-400 underline hover:text-red-600">{{ __('Remove') }}</button>
                                    </div>
                                @elseif ($removeBrandLogo)
                                    <p class="text-sm text-zinc-500">
                                        {{ __('Will be removed when you save.') }}
                                        <button type="button" wire:click="$set('removeBrandLogo', false)" class="underline">{{ __('Undo') }}</button>
                                    </p>
                                @endif
                                <input type="file" wire:model="brandLogoFile" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-800 hover:file:bg-zinc-200 hover:file:text-zinc-900 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-100 dark:hover:file:bg-zinc-600 dark:hover:file:text-white" />
                                <flux:description>{{ __('Replaces the store name in the header. PNG, JPG or WebP, up to 2 MB.') }}</flux:description>
                                <flux:error name="brandLogoFile" />
                            </flux:field>
                            <flux:field class="sm:col-span-2">
                                <flux:label>{{ __('General Price List (PDF)') }}</flux:label>
                                @if ($currentStore->general_price_list_path && ! $removeGeneralPriceList)
                                    <div class="flex items-center gap-3 text-sm">
                                        <a href="{{ $currentStore->generalPriceListUrl() }}" target="_blank" rel="noopener" class="text-brand-700 underline dark:text-brand-300">{{ __('View current price list') }}</a>
                                        <button type="button" wire:click="$set('removeGeneralPriceList', true)" class="text-xs text-zinc-400 underline hover:text-red-600">{{ __('Remove') }}</button>
                                    </div>
                                @elseif ($removeGeneralPriceList)
                                    <p class="text-sm text-zinc-500">
                                        {{ __('Will be removed when you save.') }}
                                        <button type="button" wire:click="$set('removeGeneralPriceList', false)" class="underline">{{ __('Undo') }}</button>
                                    </p>
                                @endif
                                <input type="file" wire:model="generalPriceListFile" accept="application/pdf,.pdf" class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-800 hover:file:bg-zinc-200 hover:file:text-zinc-900 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-100 dark:hover:file:bg-zinc-600 dark:hover:file:text-white" />
                                <flux:description>{{ __('Linked on every storefront page, per the FTC Funeral Rule. PDF only, up to 10 MB.') }}</flux:description>
                                <flux:error name="generalPriceListFile" />
                            </flux:field>
                        </div>
                    </section>

                    {{-- Checkout --}}
                    <section class="space-y-4 px-6 py-5">
                        <div>
                            <flux:heading size="sm">{{ __('Checkout') }}</flux:heading>
                            <flux:text class="text-xs">{{ __('How families move through the storefront.') }}</flux:text>
                        </div>
                        <flux:field>
                            <flux:label>{{ __('Store type') }}</flux:label>
                            <flux:radio.group wire:model="saleType" variant="cards" class="max-sm:flex-col">
                                @foreach (StoreSaleType::cases() as $option)
                                    <flux:radio :value="$option->value" :label="__($option->label())" :description="__($option->description())" />
                                @endforeach
                            </flux:radio.group>
                            <flux:description>{{ __('Pre-need funds often must be kept apart from at-need funds, so use a separate store, with its own Stripe account, for each.') }}</flux:description>
                            <flux:error name="saleType" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Storefront path') }}</flux:label>
                            <flux:radio.group wire:model="checkoutPath" variant="cards" class="max-sm:flex-col">
                                @foreach (StorePath::cases() as $option)
                                    <flux:radio :value="$option->value" :label="__($option->label())" :description="__($option->description())" />
                                @endforeach
                            </flux:radio.group>
                            <flux:error name="checkoutPath" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Required selections') }}</flux:label>
                            <div class="mt-1 flex flex-wrap gap-x-6 gap-y-2">
                                <flux:checkbox wire:model="requiresContainer" :label="__('Cremation container')" />
                                <flux:checkbox wire:model="requiresUrn" :label="__('Urn')" />
                                <flux:checkbox wire:model="requiresUrnVault" :label="__('Urn vault')" />
                            </div>
                            <flux:description>{{ __('Customers must choose one to complete their order. Only applies if the store offers products in that category.') }}</flux:description>
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Preselect the first item in these categories') }}</flux:label>
                            <div class="mt-1 flex flex-wrap gap-x-6 gap-y-2">
                                <flux:checkbox wire:model="preselectContainer" :label="__('Cremation container')" />
                                <flux:checkbox wire:model="preselectUrn" :label="__('Urn')" />
                                <flux:checkbox wire:model="preselectUrnVault" :label="__('Urn vault')" />
                            </div>
                            <flux:description>{{ __('The first active item, in the order set on the Products page, is already selected when the family reaches that step. They can still choose another.') }}</flux:description>
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Family provided options') }}</flux:label>
                            <div class="mt-1 flex flex-col gap-y-2">
                                <flux:checkbox wire:model="offersFamilyProvidedContainer" :label="__('Add family provided cremation container option')" />
                                <flux:checkbox wire:model="offersFamilyProvidedUrn" :label="__('Add family provided urn option')" />
                            </div>
                            <flux:description>{{ __('Adds a no-charge option at the end of the cremation containers or urns, after every other option whatever the sort order, explaining that the family brings their own to the facility before cremation.') }}</flux:description>
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Vital Statistics form (optional)') }}</flux:label>
                            <flux:input type="url" wire:model="vitalStatisticsUrl" placeholder="https://www.example.com/vital-statistics" />
                            <flux:description>{{ __('Leave blank to use our built-in form. If the funeral home already has a form on its website, enter its link: families are sent there after paying, and their receipt email links to it.') }}</flux:description>
                            <flux:error name="vitalStatisticsUrl" />
                        </flux:field>
                    </section>

                    {{-- Tax & fees --}}
                    <section class="space-y-4 px-6 py-5">
                        <div>
                            <flux:heading size="sm">{{ __('Tax & fees') }}</flux:heading>
                            <flux:text class="text-xs">{{ __('What is added at checkout, and what the platform collects.') }}</flux:text>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Sales tax rate (%)') }}</flux:label>
                                <flux:input type="number" step="0.01" min="0" max="100" wire:model="taxRatePercent" />
                                <flux:description>{{ __('Applied to taxable products. Use 0 if tax is handled separately.') }}</flux:description>
                                <flux:error name="taxRatePercent" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Processing fee') }}</flux:label>
                                <div class="flex items-center gap-3">
                                    <flux:checkbox wire:model.live="processingFeeEnabled" :label="__('Charge')" />
                                    @if ($processingFeeEnabled)
                                        <div class="w-28">
                                            <flux:input type="number" step="0.01" min="0" max="100" wire:model="processingFeePercent" placeholder="3.50" :aria-label="__('Processing fee percentage')">
                                                <x-slot name="iconTrailing"><span class="text-xs text-zinc-400">%</span></x-slot>
                                            </flux:input>
                                        </div>
                                    @endif
                                </div>
                                <flux:description>{{ __('Helps cover card costs. Not itself taxed.') }}</flux:description>
                                <flux:error name="processingFeePercent" />
                            </flux:field>
                        </div>
                        <flux:field>
                            <flux:label>{{ __('Platform fee') }}</flux:label>
                            <flux:radio.group wire:model.live="platformFeeModel" variant="cards" class="grid! gap-3 sm:grid-cols-2">
                                @foreach (PlatformFeeModel::cases() as $option)
                                    <flux:radio :value="$option->value" :label="__($option->label())" :description="__($option->description())" />
                                @endforeach
                            </flux:radio.group>
                            <flux:description>{{ __('Per-order fees are collected through Stripe and never charged on sales tax or the processing fee.') }}</flux:description>
                            <flux:error name="platformFeeModel" />
                        </flux:field>
                        @if ($platformFeeModel === PlatformFeeModel::Subscription->value)
                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:field>
                                    <flux:label>{{ __('Monthly amount ($)') }}</flux:label>
                                    <flux:input type="number" step="0.01" min="1" max="100000" wire:model="subscriptionMonthly" />
                                    <flux:description>{{ __('Changes to an active subscription apply from the next bill.') }}</flux:description>
                                    <flux:error name="subscriptionMonthly" />
                                </flux:field>
                                <div class="text-sm">
                                    <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ __('Billing status') }}</p>
                                    @if ($currentStore->isSubscriptionPastDue())
                                        <flux:badge color="red" class="mt-2">{{ __('Past due') }}</flux:badge>
                                        <p class="mt-1 text-xs text-zinc-500">{{ __('Stripe is retrying the payment. The store keeps selling in the meantime.') }}</p>
                                    @elseif ($currentStore->hasLiveSubscription())
                                        <flux:badge color="green" class="mt-2">{{ __('Active') }}</flux:badge>
                                    @else
                                        <flux:badge color="amber" class="mt-2">{{ __('Not set up') }}</flux:badge>
                                        <p class="mt-1 text-xs text-zinc-500">{{ __('The store owner starts billing from Billing in their staff portal.') }}</p>
                                    @endif
                                </div>
                            </div>
                        @elseif ($platformFeeModel === PlatformFeeModel::FlatPerOrder->value)
                            <flux:field class="sm:max-w-xs">
                                <flux:label>{{ __('Fee per order ($)') }}</flux:label>
                                <flux:input type="number" step="0.01" min="0" max="10000" wire:model="platformFeeFlat" />
                                <flux:description>{{ __('Capped at the order subtotal.') }}</flux:description>
                                <flux:error name="platformFeeFlat" />
                            </flux:field>
                        @elseif ($platformFeeModel !== PlatformFeeModel::None->value)
                            <flux:field class="sm:max-w-xs">
                                <flux:label>{{ __('Fee percentage (%)') }}</flux:label>
                                <flux:input type="number" step="0.01" min="0" max="100" wire:model="platformFeePercent" />
                                <flux:description>{{ __('Of the order subtotal, before tax and the processing fee.') }}</flux:description>
                                <flux:error name="platformFeePercent" />
                            </flux:field>
                        @endif
                    </section>

                    <div class="flex justify-end rounded-b-xl bg-zinc-50 px-6 py-4 dark:bg-zinc-800/60">
                        <flux:button type="submit" variant="primary">{{ __('Save changes') }}</flux:button>
                    </div>
                </form>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg">{{ __('Stripe Connect') }}</flux:heading>
                @if ($currentStore->isStripeReady())
                    <flux:badge color="green" class="mt-3">{{ __('Connected & accepting payments') }}</flux:badge>
                @elseif ($currentStore->stripe_account_id)
                    <flux:badge color="amber" class="mt-3">{{ __('Onboarding started') }}</flux:badge>
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Details submitted:') }} {{ $currentStore->stripe_details_submitted ? __('Yes') : __('No') }}</p>
                @else
                    <flux:badge color="zinc" class="mt-3">{{ __('Not connected') }}</flux:badge>
                @endif

                @unless ($currentStore->isStripeReady())
                    <flux:text class="mt-4 text-xs">{{ __('The funeral home\'s owner connects their own Stripe account from Payments in their staff portal. Email them a reminder, or connect it here if you manage their Stripe account yourself.') }}</flux:text>
                    <flux:button
                        variant="primary"
                        icon="envelope"
                        class="mt-3 w-full !bg-brand-700 hover:!bg-brand-800"
                        wire:click="requestStripeSetup"
                    >
                        {{ __('Email setup request to owner') }}
                    </flux:button>
                @endunless

                <flux:button
                    href="{{ route('admin.stores.stripe.connect', $currentStore) }}"
                    variant="{{ $currentStore->isStripeReady() ? 'primary' : 'ghost' }}"
                    class="mt-2 w-full"
                >
                    {{ $currentStore->stripe_account_id ? __('Continue onboarding myself') : __('Connect Stripe myself') }}
                </flux:button>

                @if ($currentStore->stripe_account_id && ! $currentStore->stripe_details_submitted)
                    <flux:text class="mt-4 text-xs">{{ __('Wrong email on the Stripe form? Start over to create a new Stripe account using this store\'s current contact email.') }}</flux:text>
                    <flux:button
                        size="sm"
                        variant="ghost"
                        class="mt-2 w-full"
                        wire:click="resetStripeConnection"
                        wire:confirm="{{ __('Start Stripe onboarding over? The unfinished Stripe account will be unlinked from this store, and a new one will be created with :email the next time you connect.', ['email' => $currentStore->contact_email]) }}"
                    >
                        {{ __('Start over') }}
                    </flux:button>
                @endif
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Staff logins') }}</flux:heading>
                    <flux:button size="sm" variant="ghost" wire:click="$set('showStaffForm', true)">{{ __('Add') }}</flux:button>
                </div>

                @if ($showStaffForm)
                    <form wire:submit="createStaffUser" class="mt-4 space-y-3">
                        <flux:field>
                            <flux:label>{{ __('Name') }}</flux:label>
                            <flux:input wire:model="staffName" />
                            <flux:error name="staffName" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Email') }}</flux:label>
                            <flux:input type="email" wire:model="staffEmail" />
                            <flux:description>{{ __('Already a staff member at another location? Use the same email to give them access here too.') }}</flux:description>
                            <flux:error name="staffEmail" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Role') }}</flux:label>
                            <flux:select wire:model="staffRole">
                                <option value="{{ StoreUserRole::Staff->value }}">{{ __('Staff — orders only') }}</option>
                                <option value="{{ StoreUserRole::Owner->value }}">{{ __('Owner — orders, payments and billing') }}</option>
                            </flux:select>
                            <flux:error name="staffRole" />
                        </flux:field>
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" variant="ghost" wire:click="$set('showStaffForm', false)">{{ __('Cancel') }}</flux:button>
                            <flux:button size="sm" type="submit" variant="primary">{{ __('Create') }}</flux:button>
                        </div>
                    </form>
                @endif

                <ul class="mt-4 space-y-2">
                    @forelse ($currentStore->staff()->withCount('stores')->get() as $staff)
                        <li class="flex items-center justify-between text-sm" wire:key="staff-{{ $staff->id }}">
                            <div>
                                <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ $staff->name }}</p>
                                <p class="text-xs text-zinc-400">{{ $staff->email }} &middot; {{ $staff->membership->role->value }}</p>
                                @if ($staff->stores_count > 1)
                                    <p class="text-xs text-zinc-400">{{ trans_choice('Also at :count other location|Also at :count other locations', $staff->stores_count - 1) }}</p>
                                @endif
                                @unless ($staff->hasAcceptedInvitation())
                                    <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                        {{ $staff->invited_at ? __('Invited :date — has not chosen a password yet', ['date' => $staff->invited_at->format('M j')]) : __('Invitation not sent yet') }}
                                    </p>
                                @endunless
                            </div>
                            <div class="flex shrink-0 gap-1">
                                @unless ($staff->hasAcceptedInvitation())
                                    <flux:button size="sm" variant="ghost" wire:click="sendStaffInvitation({{ $staff->id }})">
                                        {{ $staff->invited_at ? __('Resend invitation') : __('Send invitation') }}
                                    </flux:button>
                                @endunless
                                <flux:button size="sm" variant="ghost" wire:click="removeStaffUser({{ $staff->id }})" wire:confirm="{{ __('Remove this person\'s access to :store?', ['store' => $currentStore->name]) }}">
                                    {{ __('Remove') }}
                                </flux:button>
                            </div>
                        </li>
                    @empty
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No staff logins yet.') }}</p>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
