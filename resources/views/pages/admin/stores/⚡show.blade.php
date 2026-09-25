<?php

use App\Enums\PlatformFeeModel;
use App\Enums\StorePath;
use App\Enums\StoreStatus;
use App\Enums\StoreUserRole;
use App\Models\Store;
use App\Models\StoreUser;
use Flux\Flux;
use Illuminate\Http\UploadedFile;
use App\Services\PlatformBillingService;
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

    public string $checkoutPath = '';

    public bool $requiresContainer = false;

    public bool $requiresUrn = false;

    #[Validate('nullable|string|max:255')]
    public string $contactName = '';

    #[Validate('nullable|email|max:255')]
    public string $contactEmail = '';

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

    public function mount(Store $store): void
    {
        $this->currentStore = $store;
        $this->name = $store->name;
        $this->slug = $store->slug;
        $this->status = $store->status->value;
        $this->checkoutPath = $store->checkout_path->value;
        $this->requiresContainer = $store->requires_container;
        $this->requiresUrn = $store->requires_urn;
        $this->contactName = $store->contact_name ?? '';
        $this->contactEmail = $store->contact_email ?? '';
        $this->contactPhone = $store->contact_phone ?? '';
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
            'checkoutPath' => ['required', Rule::enum(StorePath::class)],
            'requiresContainer' => ['boolean'],
            'requiresUrn' => ['boolean'],
            'contactName' => ['nullable', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:30'],
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
            'checkout_path' => StorePath::from($validated['checkoutPath']),
            'requires_container' => $validated['requiresContainer'],
            'requires_urn' => $validated['requiresUrn'],
            'contact_name' => $validated['contactName'] ?: null,
            'contact_email' => $validated['contactEmail'] ?: null,
            'contact_phone' => $validated['contactPhone'] ?: null,
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

        if ($this->currentStore->wasChanged('status') && $this->canInviteStaff()) {
            $this->sendHeldBackInvitations();
        }

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

    public function createStaffUser(): void
    {
        $validated = $this->validate([
            'staffName' => ['required', 'string', 'max:255'],
            'staffEmail' => ['required', 'email', 'max:255', 'unique:store_users,email'],
            'staffRole' => ['required', Rule::enum(StoreUserRole::class)],
        ]);

        // Nobody knows this password; the staff member chooses their own
        // from the invitation email.
        $storeUser = StoreUser::create([
            'store_id' => $this->currentStore->id,
            'name' => $validated['staffName'],
            'email' => $validated['staffEmail'],
            'password' => Hash::make(Str::random(40)),
            'role' => StoreUserRole::from($validated['staffRole']),
        ]);

        $this->reset(['staffName', 'staffEmail', 'staffRole', 'showStaffForm']);
        $this->currentStore->refresh();

        if (! $this->canInviteStaff()) {
            Flux::toast(
                heading: __('Staff login created.'),
                text: __('Their invitation email will go out automatically when this store goes live.'),
            );

            return;
        }

        $storeUser->sendInvitation(auth()->user());

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

        Flux::toast(variant: 'success', text: __('Store duplicated with :count products. It starts as a draft.', ['count' => $duplicate->products()->count()]));

        $this->redirect(route('admin.stores.show', $duplicate), navigate: true);
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

        $storeUser->sendInvitation(auth()->user());
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
            ->each(fn (StoreUser $storeUser) => $storeUser->sendInvitation(auth()->user()));
    }

    /**
     * Invitation links open on the store's own subdomain, which only serves
     * active stores, so a link sent earlier could not be used.
     */
    protected function canInviteStaff(): bool
    {
        return $this->currentStore->status === StoreStatus::Active;
    }

    public function removeStaffUser(int $storeUserId): void
    {
        $this->currentStore->staff()->whereKey($storeUserId)->delete();
        $this->currentStore->refresh();
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
            <flux:button :href="route('admin.stores.products', $currentStore)" wire:navigate variant="ghost">{{ __('Products') }}</flux:button>
            <flux:button :href="route('admin.stores.orders', $currentStore)" wire:navigate variant="ghost">{{ __('Orders') }}</flux:button>
            <flux:button href="https://{{ $currentStore->slug }}.{{ config('app.root_domain') }}" target="_blank" variant="ghost">{{ __('View store') }}</flux:button>
            <flux:modal.trigger name="duplicate-store">
                <flux:button variant="ghost" icon="document-duplicate">{{ __('Duplicate') }}</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

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
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg">{{ __('Store details') }}</flux:heading>
                <form wire:submit="save" class="mt-4 grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Name') }}</flux:label>
                        <flux:input wire:model="name" required />
                        <flux:error name="name" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Subdomain') }}</flux:label>
                        <flux:input wire:model="slug" required />
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
                        <flux:label>{{ __('Contact name') }}</flux:label>
                        <flux:input wire:model="contactName" />
                        <flux:error name="contactName" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Contact email') }}</flux:label>
                        <flux:input type="email" wire:model="contactEmail" />
                        <flux:error name="contactEmail" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Contact phone') }}</flux:label>
                        <flux:input wire:model="contactPhone" />
                        <flux:error name="contactPhone" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Brand color') }}</flux:label>
                        <flux:input type="text" wire:model.live.debounce.500ms="brandPrimaryColor" placeholder="#29564b">
                            @if (preg_match(Store::BRAND_COLOR_PATTERN, $brandPrimaryColor))
                                <x-slot name="iconTrailing">
                                    <span class="block size-5 rounded border border-zinc-300" style="background-color: {{ $brandPrimaryColor }}"></span>
                                </x-slot>
                            @endif
                        </flux:input>
                        <flux:description>{{ __('Used for buttons and the checkout steps on the storefront.') }}</flux:description>
                        <flux:error name="brandPrimaryColor" />
                    </flux:field>
                    <flux:field class="sm:col-span-2">
                        <flux:label>{{ __('Logo') }}</flux:label>
                        <flux:description>{{ __('Shown in the storefront header in place of the store name. PNG, JPG or WebP, up to 2 MB; a wide logo on a transparent background works best.') }}</flux:description>
                        @if ($currentStore->brand_logo_path && ! $removeBrandLogo)
                            <div class="flex items-center gap-4">
                                <img src="{{ $currentStore->brandLogoUrl() }}" alt="{{ __('Current logo') }}" class="h-12 max-w-48 rounded border border-zinc-200 bg-white object-contain p-1 dark:border-zinc-600" />
                                <button type="button" wire:click="$set('removeBrandLogo', true)" class="text-xs text-zinc-400 underline hover:text-red-600">{{ __('Remove') }}</button>
                            </div>
                        @elseif ($removeBrandLogo)
                            <p class="text-sm text-zinc-500">
                                {{ __('The current logo will be removed when you save.') }}
                                <button type="button" wire:click="$set('removeBrandLogo', false)" class="underline">{{ __('Undo') }}</button>
                            </p>
                        @endif
                        <input type="file" wire:model="brandLogoFile" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-800 hover:file:bg-zinc-200 hover:file:text-zinc-900 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-100 dark:hover:file:bg-zinc-600 dark:hover:file:text-white" />
                        <flux:error name="brandLogoFile" />
                    </flux:field>
                    <flux:field class="sm:col-span-2">
                        <flux:label>{{ __('General Price List (PDF)') }}</flux:label>
                        <flux:description>{{ __('Linked on every storefront page, per FTC Funeral Rule requirements. PDF only, up to 10 MB.') }}</flux:description>
                        @if ($currentStore->general_price_list_path && ! $removeGeneralPriceList)
                            <div class="flex items-center gap-3 text-sm">
                                <a href="{{ $currentStore->generalPriceListUrl() }}" target="_blank" rel="noopener" class="text-brand-700 underline dark:text-brand-300">{{ __('View current price list') }}</a>
                                <button type="button" wire:click="$set('removeGeneralPriceList', true)" class="text-xs text-zinc-400 underline hover:text-red-600">{{ __('Remove') }}</button>
                            </div>
                        @elseif ($removeGeneralPriceList)
                            <p class="text-sm text-zinc-500">
                                {{ __('The current price list will be removed when you save.') }}
                                <button type="button" wire:click="$set('removeGeneralPriceList', false)" class="underline">{{ __('Undo') }}</button>
                            </p>
                        @endif
                        <input type="file" wire:model="generalPriceListFile" accept="application/pdf,.pdf" class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-800 hover:file:bg-zinc-200 hover:file:text-zinc-900 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-100 dark:hover:file:bg-zinc-600 dark:hover:file:text-white" />
                        <flux:error name="generalPriceListFile" />
                    </flux:field>
                    <flux:field class="sm:col-span-2">
                        <flux:label>{{ __('Platform fee') }}</flux:label>
                        <flux:description>{{ __('Per-order fees are collected automatically via the Stripe application fee and are never charged on sales tax or the processing fee.') }}</flux:description>
                        <flux:radio.group wire:model.live="platformFeeModel" variant="cards" class="grid! gap-3 sm:grid-cols-2">
                            @foreach (PlatformFeeModel::cases() as $option)
                                <flux:radio :value="$option->value" :label="__($option->label())" :description="__($option->description())" />
                            @endforeach
                        </flux:radio.group>
                        <flux:error name="platformFeeModel" />
                    </flux:field>
                    @if ($platformFeeModel === PlatformFeeModel::Subscription->value)
                        <flux:field>
                            <flux:label>{{ __('Monthly amount ($)') }}</flux:label>
                            <flux:description>{{ __('Changes to an active subscription apply from the next bill.') }}</flux:description>
                            <flux:input type="number" step="0.01" min="1" max="100000" wire:model="subscriptionMonthly" />
                            <flux:error name="subscriptionMonthly" />
                        </flux:field>
                        <div class="text-sm">
                            <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ __('Billing status') }}</p>
                            @if ($currentStore->isSubscriptionPastDue())
                                <flux:badge color="red" class="mt-2">{{ __('Past due') }}</flux:badge>
                                <p class="mt-1 text-zinc-500">{{ __('Stripe is retrying the payment. The store keeps selling in the meantime.') }}</p>
                            @elseif ($currentStore->hasLiveSubscription())
                                <flux:badge color="green" class="mt-2">{{ __('Active') }}</flux:badge>
                            @else
                                <flux:badge color="amber" class="mt-2">{{ __('Not set up') }}</flux:badge>
                                <p class="mt-1 text-zinc-500">{{ __('The store owner starts billing from Billing in their staff portal.') }}</p>
                            @endif
                        </div>
                    @elseif ($platformFeeModel === PlatformFeeModel::None->value)
                        {{-- Nothing to configure. --}}
                    @elseif ($platformFeeModel === PlatformFeeModel::FlatPerOrder->value)
                        <flux:field>
                            <flux:label>{{ __('Fee per order ($)') }}</flux:label>
                            <flux:description>{{ __('Capped at the order subtotal, so it can never exceed what the store earns.') }}</flux:description>
                            <flux:input type="number" step="0.01" min="0" max="10000" wire:model="platformFeeFlat" />
                            <flux:error name="platformFeeFlat" />
                        </flux:field>
                    @else
                        <flux:field>
                            <flux:label>{{ __('Fee percentage (%)') }}</flux:label>
                            <flux:description>{{ __('Of the order subtotal, before sales tax and the processing fee.') }}</flux:description>
                            <flux:input type="number" step="0.01" min="0" max="100" wire:model="platformFeePercent" />
                            <flux:error name="platformFeePercent" />
                        </flux:field>
                    @endif
                    <flux:field>
                        <flux:label>{{ __('Sales tax rate (%)') }}</flux:label>
                        <flux:description>{{ __('Applied at checkout to products marked taxable. Leave at 0 if this store handles tax separately.') }}</flux:description>
                        <flux:input type="number" step="0.01" min="0" max="100" wire:model="taxRatePercent" />
                        <flux:error name="taxRatePercent" />
                    </flux:field>
                    <flux:field class="sm:col-span-2">
                        <flux:label>{{ __('Processing fee') }}</flux:label>
                        <flux:description>{{ __('An extra fee added to the total at checkout to help cover card processing costs. Not itself taxed.') }}</flux:description>
                        <flux:checkbox wire:model.live="processingFeeEnabled" :label="__('Charge a processing fee')" />
                        @if ($processingFeeEnabled)
                            <div class="mt-2 max-w-40">
                                <flux:input type="number" step="0.01" min="0" max="100" wire:model="processingFeePercent" placeholder="3.50" />
                                <flux:error name="processingFeePercent" />
                            </div>
                        @endif
                    </flux:field>
                    <flux:field class="sm:col-span-2">
                        <flux:label>{{ __('Storefront path') }}</flux:label>
                        <flux:radio.group wire:model="checkoutPath" variant="cards" class="max-sm:flex-col">
                            @foreach (StorePath::cases() as $option)
                                <flux:radio :value="$option->value" :label="__($option->label())" :description="__($option->description())" />
                            @endforeach
                        </flux:radio.group>
                        <flux:error name="checkoutPath" />
                    </flux:field>
                    <flux:field class="sm:col-span-2">
                        <flux:label>{{ __('Required selections at checkout') }}</flux:label>
                        <flux:description>{{ __('Customers must choose one before completing their order. Only takes effect if the store offers products in that category.') }}</flux:description>
                        <div class="mt-1 space-y-2">
                            <flux:checkbox wire:model="requiresContainer" :label="__('Require a cremation container selection')" />
                            <flux:checkbox wire:model="requiresUrn" :label="__('Require an urn selection')" />
                        </div>
                    </flux:field>
                    <div class="flex justify-end sm:col-span-2">
                        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
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

                <flux:button
                    href="{{ route('admin.stores.stripe.connect', $currentStore) }}"
                    variant="primary"
                    class="mt-4 w-full !bg-brand-700 hover:!bg-brand-800"
                >
                    {{ $currentStore->stripe_account_id ? __('Continue onboarding') : __('Connect Stripe') }}
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
                            <flux:error name="staffEmail" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Role') }}</flux:label>
                            <flux:select wire:model="staffRole">
                                <option value="{{ StoreUserRole::Staff->value }}">{{ __('Staff — orders only') }}</option>
                                <option value="{{ StoreUserRole::Owner->value }}">{{ __('Owner — orders and billing') }}</option>
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
                    @forelse ($currentStore->staff as $staff)
                        <li class="flex items-center justify-between text-sm" wire:key="staff-{{ $staff->id }}">
                            <div>
                                <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ $staff->name }}</p>
                                <p class="text-xs text-zinc-400">{{ $staff->email }} &middot; {{ $staff->role->value }}</p>
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
                                <flux:button size="sm" variant="ghost" wire:click="removeStaffUser({{ $staff->id }})" wire:confirm="{{ __('Remove this staff login?') }}">
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
