<?php

use App\Enums\StoreStatus;
use App\Enums\StoreUserRole;
use App\Models\Store;
use App\Models\StoreUser;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public Store $currentStore;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:255|alpha_dash')]
    public string $slug = '';

    public string $status = '';

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

    #[Validate('nullable|url|max:255')]
    public string $generalPriceListUrl = '';

    #[Validate('required|integer|min:0|max:10000')]
    public int $platformFeeBps = 500;

    #[Validate('required|numeric|min:0|max:100')]
    public string $taxRatePercent = '0.00';

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
        $this->contactName = $store->contact_name ?? '';
        $this->contactEmail = $store->contact_email ?? '';
        $this->contactPhone = $store->contact_phone ?? '';
        $this->timezone = $store->timezone;
        $this->brandPrimaryColor = $store->brand_primary_color ?? '';
        $this->generalPriceListUrl = $store->general_price_list_url ?? '';
        $this->platformFeeBps = $store->platform_fee_bps;
        $this->taxRatePercent = number_format($store->tax_rate_bps / 100, 2, '.', '');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:stores,slug,'.$this->currentStore->id],
            'status' => ['required', 'in:draft,active,suspended'],
            'contactName' => ['nullable', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:30'],
            'timezone' => ['required', 'string', 'max:255'],
            'brandPrimaryColor' => ['nullable', 'string', 'max:7'],
            'generalPriceListUrl' => ['nullable', 'url', 'max:255'],
            'platformFeeBps' => ['required', 'integer', 'min:0', 'max:10000'],
            'taxRatePercent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $this->currentStore->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'status' => StoreStatus::from($validated['status']),
            'contact_name' => $validated['contactName'] ?: null,
            'contact_email' => $validated['contactEmail'] ?: null,
            'contact_phone' => $validated['contactPhone'] ?: null,
            'timezone' => $validated['timezone'],
            'brand_primary_color' => $validated['brandPrimaryColor'] ?: null,
            'general_price_list_url' => $validated['generalPriceListUrl'] ?: null,
            'platform_fee_bps' => $validated['platformFeeBps'],
            'tax_rate_bps' => (int) round(((float) $validated['taxRatePercent']) * 100),
        ]);

        $this->currentStore->refresh();

        Flux::toast(variant: 'success', text: __('Store updated.'));
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
                        <flux:label>{{ __('General Price List URL') }}</flux:label>
                        <flux:description>{{ __('Linked in the storefront footer, per FTC Funeral Rule requirements.') }}</flux:description>
                        <flux:input type="url" wire:model="generalPriceListUrl" />
                        <flux:error name="generalPriceListUrl" />
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
