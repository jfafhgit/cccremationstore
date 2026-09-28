<?php

use App\Models\Store;
use App\Services\StripeConnectService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

/**
 * The funeral home connects its own Stripe account here, so platform admins
 * never need access to it. Stripe's onboarding links expire within minutes,
 * so a fresh one is made on every click, and Stripe sends the owner back
 * here with ?stripe=return when they finish or ?stripe=refresh when a link
 * went stale.
 */
new #[Layout('layouts::portal')] class extends Component
{
    public Store $currentStore;

    public ?string $stripeError = null;

    public function mount(StripeConnectService $stripeConnect): void
    {
        $this->currentStore = Store::current();

        match (request()->query('stripe')) {
            'return' => $this->syncStatus($stripeConnect),
            'refresh' => $this->canManagePayments() ? $this->connectStripe($stripeConnect) : null,
            default => null,
        };
    }

    public function canManagePayments(): bool
    {
        return Auth::guard('store')->user()?->isOwnerOf($this->currentStore) ?? false;
    }

    public function connectStripe(StripeConnectService $stripeConnect): void
    {
        abort_unless($this->canManagePayments(), 403);

        try {
            $link = $stripeConnect->createOnboardingLink(
                $this->currentStore,
                returnUrl: route('portal.payments', ['stripe' => 'return']),
                refreshUrl: route('portal.payments', ['stripe' => 'refresh']),
                email: Auth::guard('store')->user()->email,
            );
        } catch (ApiErrorException|\RuntimeException $e) {
            $this->reportStripeError($e);

            return;
        }

        $this->redirect($link->url);
    }

    private function syncStatus(StripeConnectService $stripeConnect): void
    {
        try {
            $this->currentStore = $stripeConnect->syncAccountStatus($this->currentStore);
        } catch (ApiErrorException|\RuntimeException $e) {
            // The account.updated webhook will catch the status up.
            Log::warning('Could not sync Stripe status on return to the portal.', [
                'store_id' => $this->currentStore->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function reportStripeError(\Throwable $e): void
    {
        Log::error('Portal Stripe onboarding failed.', [
            'store_id' => $this->currentStore->id,
            'message' => $e->getMessage(),
        ]);

        $this->stripeError = __('We could not reach Stripe just now. Please try again in a moment.');
    }
}; ?>

<div class="max-w-2xl">
    <flux:heading size="xl" class="font-serif">{{ __('Payments') }}</flux:heading>
    <flux:subheading class="mt-1">{{ __('Online orders are paid straight into your own Stripe account.') }}</flux:subheading>

    <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-center gap-2 text-sm">
            <span class="text-zinc-500">{{ __('Status:') }}</span>
            @if ($currentStore->isStripeReady())
                <flux:badge color="green">{{ __('Connected & accepting payments') }}</flux:badge>
            @elseif ($currentStore->stripe_details_submitted)
                <flux:badge color="amber">{{ __('Under review by Stripe') }}</flux:badge>
            @elseif ($currentStore->stripe_account_id)
                <flux:badge color="amber">{{ __('Setup not finished') }}</flux:badge>
            @else
                <flux:badge color="zinc">{{ __('Not connected') }}</flux:badge>
            @endif
        </div>

        @if ($currentStore->isStripeReady())
            <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-300">
                {{ __('Payouts, refunds, and your bank details are managed in your Stripe dashboard.') }}
            </p>
        @elseif ($currentStore->stripe_details_submitted)
            <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-300">
                {{ __('Stripe is reviewing your details. This usually takes a few minutes, but Stripe may ask for more information by email.') }}
            </p>
        @else
            <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-300">
                {{ __('Your storefront can\'t take payments until this is done. You can create a new Stripe account or sign in to one you already have. Have your business details, tax ID, and bank account information handy.') }}
            </p>
        @endif

        @if ($stripeError)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mt-4" :heading="$stripeError" />
        @endif

        @if ($this->canManagePayments())
            @unless ($currentStore->isStripeReady())
                <flux:button variant="primary" wire:click="connectStripe" class="mt-6 !bg-brand-700 hover:!bg-brand-800">
                    {{ $currentStore->stripe_account_id ? __('Continue Stripe setup') : __('Connect Stripe') }}
                </flux:button>
                <p class="mt-3 text-xs text-zinc-500">{{ __('You\'ll be taken to Stripe and brought back here when you\'re done.') }}</p>
            @endunless
        @else
            <p class="mt-6 text-sm text-zinc-500">{{ __('Only the account owner can set up payments.') }}</p>
        @endif
    </div>
</div>
