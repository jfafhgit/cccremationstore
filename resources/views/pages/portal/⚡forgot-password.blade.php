<?php

use App\Models\Store;
use App\Models\StoreUser;
use App\Notifications\StaffPasswordResetNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::portal')] class extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    public bool $linkRequested = false;

    /**
     * Always reports the same outcome, so the page never reveals whether an
     * email has a staff login at this location.
     */
    public function sendResetLink(): void
    {
        $this->validate();

        $store = Store::current();
        $throttleKey = 'staff-password-reset|'.$store->id.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->addError('email', __('Too many requests. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($throttleKey)]));

            return;
        }

        RateLimiter::hit($throttleKey, 60);

        // Only staff with access to this location get a link, and it opens
        // on this location's subdomain.
        Password::broker('store_users')->sendResetLink(
            [
                'email' => Str::lower($this->email),
                'store' => fn (Builder $query) => $query->whereHas('stores', fn (Builder $stores) => $stores->whereKey($store->id)),
            ],
            fn (StoreUser $storeUser, string $token) => $storeUser->notify(new StaffPasswordResetNotification($storeUser, $store, $token)),
        );

        $this->linkRequested = true;
    }
}; ?>

<div class="w-full max-w-sm rounded-2xl border border-brand-100 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <x-portal-store-mark :store="\App\Models\Store::current()" class="mb-6" />
    <flux:heading size="xl" class="font-serif">{{ __('Reset your password') }}</flux:heading>

    @if ($linkRequested)
        <flux:subheading class="mt-2">
            {{ __('If :email has a staff login here, we\'ve emailed it a link to choose a new password. The link expires in :minutes minutes.', ['email' => $email, 'minutes' => config('auth.passwords.store_users.expire')]) }}
        </flux:subheading>
    @else
        <flux:subheading class="mt-1">{{ __('Enter your staff email and we\'ll send you a link to choose a new password.') }}</flux:subheading>

        <form wire:submit="sendResetLink" class="mt-6 space-y-4">
            <flux:field>
                <flux:label>{{ __('Email') }}</flux:label>
                <flux:input type="email" wire:model="email" autofocus autocomplete="username" />
                <flux:error name="email" />
            </flux:field>

            <flux:button type="submit" variant="primary" class="w-full !bg-brand-700 hover:!bg-brand-800">
                {{ __('Email reset link') }}
            </flux:button>
        </form>
    @endif

    <flux:link :href="route('portal.login')" wire:navigate class="mt-6 block text-center text-sm">
        {{ __('Back to sign in') }}
    </flux:link>
</div>
