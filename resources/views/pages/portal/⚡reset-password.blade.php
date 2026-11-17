<?php

use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::portal')] class extends Component
{
    #[Locked]
    public string $token;

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function resetPassword(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $store = Store::current();
        $resetStoreUser = null;

        // Scoped to this location, like sign-in: a link only works on the
        // subdomain of a store the staff member has access to.
        $status = Password::broker('store_users')->reset(
            [
                'email' => Str::lower($this->email),
                'password' => $this->password,
                'token' => $this->token,
                'store' => fn (Builder $query) => $query->whereHas('stores', fn (Builder $stores) => $stores->whereKey($store->id)),
            ],
            function (StoreUser $storeUser, string $password) use (&$resetStoreUser): void {
                $storeUser->resetPassword($password);
                event(new PasswordReset($storeUser));
                $resetStoreUser = $storeUser;
            },
        );

        if ($status !== Password::PasswordReset) {
            $this->addError('email', __('This reset link is invalid or has expired. Please request a new one.'));

            return;
        }

        Auth::guard('store')->login($resetStoreUser);
        Session::regenerate();

        $this->redirect(route('portal.orders'), navigate: true);
    }
}; ?>

<div class="w-full max-w-sm rounded-2xl border border-brand-100 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <x-portal-store-mark :store="\App\Models\Store::current()" class="mb-6" />
    <flux:heading size="xl" class="font-serif">{{ __('Choose a new password') }}</flux:heading>
    <flux:subheading class="mt-1">{{ __('For your :store staff login.', ['store' => \App\Models\Store::current()?->name]) }}</flux:subheading>

    <form wire:submit="resetPassword" class="mt-6 space-y-4">
        <flux:field>
            <flux:label>{{ __('Email') }}</flux:label>
            <flux:input type="email" wire:model="email" autocomplete="username" />
            <flux:error name="email" />
        </flux:field>

        <flux:field>
            <flux:label>{{ __('New password') }}</flux:label>
            <flux:input type="password" wire:model="password" autofocus autocomplete="new-password" />
            <flux:error name="password" />
        </flux:field>

        <flux:field>
            <flux:label>{{ __('Confirm new password') }}</flux:label>
            <flux:input type="password" wire:model="password_confirmation" autocomplete="new-password" />
        </flux:field>

        <flux:button type="submit" variant="primary" class="w-full !bg-brand-700 hover:!bg-brand-800">
            {{ __('Reset password and sign in') }}
        </flux:button>
    </form>

    <flux:link :href="route('portal.password.request')" wire:navigate class="mt-6 block text-center text-sm">
        {{ __('Request a new link') }}
    </flux:link>
</div>
