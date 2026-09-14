<?php

use App\Models\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::portal')] class extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();

        $store = Store::current();
        $throttleKey = Str::lower($this->email).'|'.$store->id.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            $this->addError('email', __('Too many login attempts. Please try again in :seconds seconds.', ['seconds' => $seconds]));

            return;
        }

        // Scope the credential check to this store — a staff account at one
        // funeral home must never be able to sign in on another one's
        // subdomain, even with the exact right email/password.
        $credentials = [
            'email' => $this->email,
            'password' => $this->password,
            'store_id' => $store->id,
        ];

        if (! Auth::guard('store')->attempt($credentials, $this->remember)) {
            RateLimiter::hit($throttleKey, 60);

            $this->addError('email', __('These credentials do not match our records.'));

            return;
        }

        RateLimiter::clear($throttleKey);
        request()->session()->regenerate();

        $this->redirect(route('portal.orders'), navigate: true);
    }
}; ?>

<div class="w-full max-w-sm rounded-2xl border border-brand-100 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <flux:heading size="xl" class="font-serif">{{ __('Staff sign in') }}</flux:heading>
    <flux:subheading class="mt-1">{{ __(':store staff portal', ['store' => \App\Models\Store::current()?->name]) }}</flux:subheading>

    <form wire:submit="login" class="mt-6 space-y-4">
        <flux:field>
            <flux:label>{{ __('Email') }}</flux:label>
            <flux:input type="email" wire:model="email" autofocus autocomplete="username" />
            <flux:error name="email" />
        </flux:field>

        <flux:field>
            <flux:label>{{ __('Password') }}</flux:label>
            <flux:input type="password" wire:model="password" autocomplete="current-password" />
            <flux:error name="password" />
        </flux:field>

        <flux:checkbox wire:model="remember" :label="__('Remember me')" />

        <flux:button type="submit" variant="primary" class="w-full !bg-brand-700 hover:!bg-brand-800">
            {{ __('Sign in') }}
        </flux:button>
    </form>
</div>
