<?php

use App\Models\Lead;
use App\Notifications\NewLeadNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The root-domain marketing page: a simple overview of the platform for
 * funeral homes considering it, plus a "request a demo" contact form. Every
 * actual store lives on its own subdomain (see routes/storefront.php) — this
 * page is not a store and never processes payments.
 */
new #[Layout('layouts::marketing')] class extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email|max:255')]
    public string $email = '';

    #[Validate('nullable|string|max:30')]
    public string $phone = '';

    #[Validate('nullable|string|max:255')]
    public string $funeralHomeName = '';

    #[Validate('nullable|string|max:2000')]
    public string $message = '';

    public bool $submitted = false;

    public function submit(): void
    {
        $validated = $this->validate();

        $lead = Lead::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?: null,
            'funeral_home_name' => $validated['funeralHomeName'] ?: null,
            'message' => $validated['message'] ?: null,
        ]);

        if ($adminEmail = config('services.platform.admin_email')) {
            Notification::route('mail', $adminEmail)->notify(new NewLeadNotification($lead));
        }

        $this->reset(['name', 'email', 'phone', 'funeralHomeName', 'message']);
        $this->submitted = true;
    }
}; ?>

<div>
    <section class="mx-auto max-w-5xl px-4 py-16 sm:px-6 sm:py-24">
        <div class="max-w-2xl">
            <flux:heading size="xl" class="font-serif text-4xl text-brand-900">
                {{ __('A calmer way to offer cremation arrangements online.') }}
            </flux:heading>
            <p class="mt-4 text-lg text-zinc-600">
                {{ __('Planning by Treasured Memories gives your funeral home a dedicated, branded online store — simple packages, inline secure payment, and a dignified experience for families arranging services at any hour.') }}
            </p>
            <div class="mt-8 flex flex-wrap gap-4">
                <flux:button href="#contact" variant="primary" class="!bg-brand-700 hover:!bg-brand-800">
                    {{ __('Request a demo') }}
                </flux:button>
                <flux:button href="#features" variant="ghost">
                    {{ __('See how it works') }}
                </flux:button>
            </div>
        </div>
    </section>

    <section id="features" class="border-y border-brand-100 bg-white py-16">
        <div class="mx-auto grid max-w-5xl gap-8 px-4 sm:grid-cols-3 sm:px-6">
            <div>
                <flux:heading size="lg">{{ __('Your own branded store') }}</flux:heading>
                <p class="mt-2 text-sm text-zinc-500">
                    {{ __('Every funeral home gets its own address and branding — set up your packages, containers, urns, and keepsakes once, and families see only your offerings.') }}
                </p>
            </div>
            <div>
                <flux:heading size="lg">{{ __('Simple, respectful checkout') }}</flux:heading>
                <p class="mt-2 text-sm text-zinc-500">
                    {{ __('A short, guided flow collects only what is needed to secure payment. The fuller intake — obituary details, service preferences — comes after, on the family\'s own time.') }}
                </p>
            </div>
            <div>
                <flux:heading size="lg">{{ __('Payments go straight to you') }}</flux:heading>
                <p class="mt-2 text-sm text-zinc-500">
                    {{ __('Payments are processed securely by Stripe directly into your own account — we never hold your funds.') }}
                </p>
            </div>
        </div>
    </section>

    <section id="contact" class="mx-auto max-w-2xl px-4 py-16 sm:px-6">
        <flux:heading size="xl" class="font-serif">{{ __('Request a demo') }}</flux:heading>
        <flux:subheading class="mt-1">{{ __("Tell us a little about your funeral home and we'll be in touch.") }}</flux:subheading>

        @if ($submitted)
            <div class="mt-8 rounded-xl border border-brand-200 bg-brand-50 p-6 text-center">
                <flux:icon.check-circle class="mx-auto size-8 text-brand-700" />
                <p class="mt-3 font-medium text-brand-900">{{ __('Thank you for reaching out!') }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __("We'll be in touch shortly.") }}</p>
            </div>
        @else
            <form wire:submit="submit" class="mt-8 space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Your name') }}</flux:label>
                        <flux:input wire:model="name" required />
                        <flux:error name="name" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Funeral home name') }}</flux:label>
                        <flux:input wire:model="funeralHomeName" />
                        <flux:error name="funeralHomeName" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Email') }}</flux:label>
                        <flux:input type="email" wire:model="email" required />
                        <flux:error name="email" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Phone') }}</flux:label>
                        <flux:input type="tel" wire:model="phone" />
                        <flux:error name="phone" />
                    </flux:field>
                </div>
                <flux:field>
                    <flux:label>{{ __('Anything you would like us to know?') }}</flux:label>
                    <flux:textarea wire:model="message" rows="4" />
                    <flux:error name="message" />
                </flux:field>
                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" class="!bg-brand-700 hover:!bg-brand-800">
                        {{ __('Send') }}
                    </flux:button>
                </div>
            </form>
        @endif
    </section>
</div>
