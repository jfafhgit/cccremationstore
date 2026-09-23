<?php

use App\Enums\StorePath;
use App\Enums\StoreStatus;
use App\Enums\StoreUserRole;
use App\Models\Store;
use App\Models\StoreUser;
use Flux\Flux;
use Illuminate\Http\UploadedFile;
use App\Services\StripeConnectService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    #[Validate('required|integer|min:0|max:10000')]
    public int $platformFeeBps = 500;

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
        $this->platformFeeBps = $store->platform_fee_bps;
        $this->taxRatePercent = number_format($store->tax_rate_bps / 100, 2, '.', '');
        $this->processingFeeEnabled = $store->processing_fee_enabled;
        $this->processingFeePercent = number_format($store->processing_fee_bps / 100, 2, '.', '');
    }

    public function save(): void
    {
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
            'brandPrimaryColor' => ['nullable', 'string', 'max:7'],
            'generalPriceListFile' => ['nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'],
            'platformFeeBps' => ['required', 'integer', 'min:0', 'max:10000'],
            'taxRatePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'processingFeeEnabled' => ['boolean'],
            'processingFeePercent' => ['required', 'numeric', 'min:0', 'max:100'],
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
            'platform_fee_bps' => $validated['platformFeeBps'],
            'tax_rate_bps' => (int) round(((float) $validated['taxRatePercent']) * 100),
            'processing_fee_enabled' => $validated['processingFeeEnabled'],
            'processing_fee_bps' => (int) round(((float) $validated['processingFeePercent']) * 100),
        ]);

        $this->saveGeneralPriceList();

        $this->currentStore->refresh();

        Flux::toast(variant: 'success', text: __('Store updated.'));
    }

    /**
     * Store a newly-uploaded General Price List (replacing any previous one),
     * or delete the current one if the admin marked it for removal.
     */
    private function saveGeneralPriceList(): void
    {
        $previousPath = $this->currentStore->general_price_list_path;

        if ($this->generalPriceListFile) {
            $this->currentStore->update([
                'general_price_list_path' => $this->generalPriceListFile->store('price-lists', 'public'),
            ]);
        } elseif ($this->removeGeneralPriceList) {
            $this->currentStore->update(['general_price_list_path' => null]);
        } else {
            return;
        }

        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }

        $this->reset(['generalPriceListFile', 'removeGeneralPriceList']);
    }

    public function createStaffUser(): void
    {
        $validated = $this->validate([
            'staffName' => ['required', 'string', 'max:255'],
            'staffEmail' => ['required', 'email', 'max:255', 'unique:store_users,email'],
        ]);

        $temporaryPassword = str()->password(16);

        StoreUser::create([
            'store_id' => $this->currentStore->id,
            'name' => $validated['staffName'],
            'email' => $validated['staffEmail'],
            'password' => Hash::make($temporaryPassword),
            'role' => StoreUserRole::Staff,
        ]);

        $this->reset(['staffName', 'staffEmail', 'showStaffForm']);
        $this->currentStore->refresh();

        Flux::toast(
            variant: 'success',
            heading: __('Staff account created.'),
            text: __('Temporary password: :password — share this with them securely; they should change it after logging in.', ['password' => $temporaryPassword]),
        );
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
        </div>
    </div>

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
                        <flux:input type="text" wire:model="brandPrimaryColor" placeholder="#29564b" />
                        <flux:error name="brandPrimaryColor" />
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
                    <flux:field>
                        <flux:label>{{ __('Platform fee (basis points)') }}</flux:label>
                        <flux:description>{{ __('500 = 5% of each order, deducted via the Stripe application fee.') }}</flux:description>
                        <flux:input type="number" wire:model="platformFeeBps" min="0" max="10000" />
                        <flux:error name="platformFeeBps" />
                    </flux:field>
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
                            </div>
                            <flux:button size="sm" variant="ghost" wire:click="removeStaffUser({{ $staff->id }})" wire:confirm="{{ __('Remove this staff login?') }}">
                                {{ __('Remove') }}
                            </flux:button>
                        </li>
                    @empty
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No staff logins yet.') }}</p>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
