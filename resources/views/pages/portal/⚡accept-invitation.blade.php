<?php

use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::portal')] class extends Component
{
    #[Locked]
    public int $storeUserId;

    #[Locked]
    public string $token;

    public bool $invitationIsValid = false;

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Looked up through the current store, so a link for another funeral
     * home's staff member 404s instead of revealing that it exists.
     */
    public function mount(int|string $storeUser, string $token): void
    {
        $invitee = Store::current()->staff()->findOrFail($storeUser);

        $this->storeUserId = $invitee->id;
        $this->token = $token;
        $this->invitationIsValid = $invitee->hasValidInvitation($token);
    }

    /**
     * The invitation is checked again here, not trusted from page load, in
     * case it was used or replaced in the meantime.
     */
    public function acceptInvitation(): void
    {
        $invitee = Store::current()->staff()->findOrFail($this->storeUserId);

        if (! $invitee->hasValidInvitation($this->token)) {
            $this->invitationIsValid = false;

            return;
        }

        $this->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $invitee->acceptInvitation($this->password);

        Auth::guard('store')->login($invitee);
        Session::regenerate();

        $this->redirect(route('portal.orders'), navigate: true);
    }
}; ?>

<div class="w-full max-w-sm rounded-2xl border border-brand-100 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    @if ($invitationIsValid)
        <flux:heading size="xl" class="font-serif">{{ __('Welcome aboard') }}</flux:heading>
        <flux:subheading class="mt-1">{{ __('Choose a password for your :store staff login.', ['store' => \App\Models\Store::current()?->name]) }}</flux:subheading>

        <form wire:submit="acceptInvitation" class="mt-6 space-y-4">
            <flux:field>
                <flux:label>{{ __('Password') }}</flux:label>
                <flux:input type="password" wire:model="password" autofocus autocomplete="new-password" />
                <flux:error name="password" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Confirm password') }}</flux:label>
                <flux:input type="password" wire:model="password_confirmation" autocomplete="new-password" />
            </flux:field>

            <flux:button type="submit" variant="primary" class="w-full !bg-brand-700 hover:!bg-brand-800">
                {{ __('Set password and sign in') }}
            </flux:button>
        </form>
    @else
        <flux:heading size="xl" class="font-serif">{{ __('This link is no longer valid') }}</flux:heading>
        <flux:subheading class="mt-2">
            {{ __('Invitation links can be used once and expire after :days days. If you already chose a password, sign in below. Otherwise, ask us to send you a new invitation.', ['days' => StoreUser::INVITATION_LIFETIME_DAYS]) }}
        </flux:subheading>

        <flux:button :href="route('portal.login')" variant="primary" class="mt-6 w-full !bg-brand-700 hover:!bg-brand-800">
            {{ __('Go to sign in') }}
        </flux:button>
    @endif
</div>
