<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth'), Title('Awaiting approval')] class extends Component
{
    public function mount(): void
    {
        if (auth()->user()->isApproved()) {
            $this->redirectRoute('dashboard');
        }
    }
}; ?>

<div class="flex flex-col gap-6 text-center">
    <div>
        <flux:heading size="xl">{{ __('Awaiting approval') }}</flux:heading>
        <flux:subheading class="mt-2">
            {{ __('You are signed in as :email, but an administrator has to approve your account before you can use the admin area. You will be able to sign in once you are approved.', ['email' => auth()->user()->email]) }}
        </flux:subheading>
    </div>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <flux:button type="submit" variant="primary" class="w-full" data-test="logout-button">
            {{ __('Log out') }}
        </flux:button>
    </form>
</div>
