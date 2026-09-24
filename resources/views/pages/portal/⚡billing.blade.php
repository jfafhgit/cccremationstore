<?php

use App\Enums\PlatformFeeModel;
use App\Models\Store;
use App\Services\PlatformBillingService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

/**
 * The funeral home's own subscription to the platform. Card entry and
 * invoices are handled on Stripe-hosted pages (Checkout and the billing
 * portal), so no payment details ever pass through this app.
 */
new #[Layout('layouts::portal')] class extends Component
{
    public Store $currentStore;

    public ?string $billingError = null;

    public function mount(PlatformBillingService $billing): void
    {
        $this->currentStore = Store::current();

        // Stripe sends the owner back here with the completed session's id.
        // Record the subscription now rather than waiting on the webhook.
        if ($sessionId = request()->query('checkout_session')) {
            try {
                $this->currentStore = $billing->completeCheckout($this->currentStore, (string) $sessionId);
            } catch (ApiErrorException|\RuntimeException $e) {
                Log::warning('Could not confirm subscription checkout on return.', [
                    'store_id' => $this->currentStore->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    public function canManageBilling(): bool
    {
        $user = Auth::guard('store')->user();

        return $user !== null && $user->store_id === $this->currentStore->id && $user->isOwner();
    }

    public function startSubscription(PlatformBillingService $billing): void
    {
        abort_unless($this->canManageBilling(), 403);

        try {
            $url = $billing->createCheckoutUrl(
                $this->currentStore,
                successUrl: route('portal.billing').'?checkout_session={CHECKOUT_SESSION_ID}',
                cancelUrl: route('portal.billing'),
            );
        } catch (ApiErrorException|\RuntimeException $e) {
            $this->reportBillingError($e);

            return;
        }

        $this->redirect($url);
    }

    public function manageBilling(PlatformBillingService $billing): void
    {
        abort_unless($this->canManageBilling(), 403);

        try {
            $url = $billing->billingPortalUrl($this->currentStore, route('portal.billing'));
        } catch (ApiErrorException|\RuntimeException $e) {
            $this->reportBillingError($e);

            return;
        }

        $this->redirect($url);
    }

    private function reportBillingError(\Throwable $e): void
    {
        Log::error('Portal billing action failed.', [
            'store_id' => $this->currentStore->id,
            'message' => $e->getMessage(),
        ]);

        $this->billingError = __('We could not reach our billing provider just now. Please try again in a moment.');
    }
}; ?>

<div class="max-w-2xl">
    <flux:heading size="xl" class="font-serif">{{ __('Billing') }}</flux:heading>
    <flux:subheading class="mt-1">{{ __('Your subscription to :app.', ['app' => config('app.name')]) }}</flux:subheading>

    <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
        @if ($currentStore->platform_fee_model === PlatformFeeModel::Subscription)
            <div class="flex items-baseline justify-between gap-4">
                <p class="text-sm text-zinc-500">{{ __('Monthly plan') }}</p>
                <p class="text-2xl font-semibold text-zinc-900 dark:text-white">
                    ${{ number_format($currentStore->subscription_monthly_cents / 100, 2) }}<span class="text-sm font-normal text-zinc-500">/{{ __('month') }}</span>
                </p>
            </div>
        @endif

        <div class="mt-4 flex items-center gap-2 text-sm">
            <span class="text-zinc-500">{{ __('Status:') }}</span>
            @if ($currentStore->isSubscriptionPastDue())
                <flux:badge color="red">{{ __('Past due') }}</flux:badge>
            @elseif ($currentStore->hasLiveSubscription())
                <flux:badge color="green">{{ __('Active') }}</flux:badge>
            @elseif ($currentStore->needsSubscriptionSetup())
                <flux:badge color="amber">{{ __('Not set up') }}</flux:badge>
            @else
                <flux:badge color="zinc">{{ __('No subscription') }}</flux:badge>
            @endif
        </div>

        @if ($currentStore->isSubscriptionPastDue())
            <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4" :heading="__('Your last subscription payment did not go through. Please update your payment method.')" />
        @endif

        @if ($billingError)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mt-4" :heading="$billingError" />
        @endif

        @if ($this->canManageBilling())
            <div class="mt-6 flex flex-wrap gap-3">
                @if ($currentStore->needsSubscriptionSetup() && $currentStore->subscription_monthly_cents > 0)
                    <flux:button variant="primary" wire:click="startSubscription" class="!bg-brand-700 hover:!bg-brand-800">{{ __('Set up billing') }}</flux:button>
                @endif
                @if ($currentStore->stripe_customer_id)
                    <flux:button wire:click="manageBilling">{{ __('Payment method & invoices') }}</flux:button>
                @endif
            </div>
            <p class="mt-3 text-xs text-zinc-500">{{ __('You\'ll be taken to our payment provider, Stripe, to enter or update your card.') }}</p>
        @else
            <p class="mt-6 text-sm text-zinc-500">{{ __('Only the account owner can manage billing.') }}</p>
        @endif
    </div>
</div>
