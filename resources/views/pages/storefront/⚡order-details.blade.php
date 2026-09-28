<?php

use App\Enums\HighestDegree;
use App\Enums\MaritalStatus;
use App\Enums\OrderTiming;
use App\Enums\Sex;
use App\Enums\UsState;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Store;
use App\Notifications\OrderDetailsSubmittedNotification;
use App\Services\Cart;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;

/**
 * The Vital Statistics form: what the funeral home needs for the death
 * certificate, filled in by the family after payment is secured (reached via
 * a signed link — see Order::detailsUrl() — so no customer login is needed).
 * Families can save and come back; staff are only emailed once it is
 * submitted complete. The same link is also Stripe's payment return_url.
 */
new #[Layout('layouts::storefront')] class extends Component
{
    // Deliberately not named "$order" — Livewire matches public property
    // names against test/mount parameters, and the route segment is also
    // named {order}; keeping this distinct avoids that collision.
    public Order $currentOrder;

    public string $nextOfKinName = '';

    public string $nextOfKinRelationship = '';

    public string $nextOfKinPhone = '';

    public string $nextOfKinEmail = '';

    public string $deceasedFirstName = '';

    public string $deceasedMiddleName = '';

    public string $deceasedLastName = '';

    public string $deceasedSuffix = '';

    public string $maidenName = '';

    public string $sex = '';

    public ?string $dateOfBirth = null;

    public bool $bornOutsideUs = false;

    public string $birthCity = '';

    public string $birthState = '';

    public string $birthPlaceOutsideUs = '';

    public ?string $dateOfDeath = null;

    public string $placeOfDeath = '';

    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $addressCity = '';

    public string $addressState = '';

    public string $addressZip = '';

    public string $phone = '';

    public string $citizenship = 'United States';

    /**
     * Only ever holds what the family is typing now. A number already on
     * file is never sent back to the browser; see $ssnOnFile.
     */
    public string $ssn = '';

    public ?string $ssnOnFile = null;

    public string $race = '';

    public string $hispanicOrigin = '';

    public ?string $educationYears = null;

    public string $highestDegree = '';

    public string $occupation = '';

    public string $industry = '';

    public string $hasPacemaker = '';

    public string $maritalStatus = '';

    public string $spouseFirstName = '';

    public string $spouseMiddleName = '';

    public string $spouseLastName = '';

    public string $spouseMaidenName = '';

    public string $motherFirstName = '';

    public string $motherMaidenName = '';

    public string $motherLiving = '';

    public string $fatherFirstName = '';

    public string $fatherLastName = '';

    public string $fatherLiving = '';

    public string $veteranStatus = '';

    public string $veteranBranch = '';

    public string $obituaryText = '';

    public string $servicePreferences = '';

    public string $additionalNotes = '';

    public bool $submitted = false;

    public bool $draftSaved = false;

    /** The PaymentIntent status as last confirmed with Stripe, if known. */
    public ?string $paymentStatus = null;

    /**
     * The {order} route parameter arrives as a raw id, not an Eloquent
     * model — we resolve it ourselves, scoped to the current store, rather
     * than relying on implicit route-model binding, which would happily
     * fetch an order belonging to a *different* funeral home if someone
     * guessed or shared the wrong id.
     */
    public function mount(int|string $order): void
    {
        $this->currentOrder = Store::current()->orders()->with('detail')->findOrFail($order);

        $this->confirmPayment(app(CheckoutService::class));

        $this->deceasedFirstName = $this->currentOrder->deceased_first_name ?? '';
        $this->deceasedMiddleName = $this->currentOrder->deceased_middle_name ?? '';
        $this->deceasedLastName = $this->currentOrder->deceased_last_name ?? '';
        $this->deceasedSuffix = $this->currentOrder->deceased_suffix ?? '';

        if ($detail = $this->currentOrder->detail) {
            $this->fillFrom($detail);
        } else {
            // Usually the person who paid is the next of kin.
            $this->nextOfKinName = $this->currentOrder->purchaserName();
            $this->nextOfKinRelationship = $this->currentOrder->relationship_to_deceased ?? '';
            $this->nextOfKinPhone = $this->currentOrder->purchaser_phone ?? '';
            $this->nextOfKinEmail = $this->currentOrder->purchaser_email ?? '';
        }
    }

    private function fillFrom(OrderDetail $detail): void
    {
        $this->nextOfKinName = $detail->next_of_kin_name ?? '';
        $this->nextOfKinRelationship = $detail->next_of_kin_relationship ?? '';
        $this->nextOfKinPhone = $detail->next_of_kin_phone ?? '';
        $this->nextOfKinEmail = $detail->next_of_kin_email ?? '';
        $this->maidenName = $detail->maiden_name ?? '';
        $this->sex = $detail->sex?->value ?? '';
        $this->dateOfBirth = $detail->date_of_birth?->toDateString();
        $this->bornOutsideUs = $detail->born_outside_us;
        $this->birthCity = $detail->birth_city ?? '';
        $this->birthState = $detail->birth_state?->value ?? '';
        $this->birthPlaceOutsideUs = $detail->birth_place_outside_us ?? '';
        $this->dateOfDeath = $detail->date_of_death?->toDateString();
        $this->placeOfDeath = $detail->place_of_death ?? '';
        $this->addressLine1 = $detail->address_line1 ?? '';
        $this->addressLine2 = $detail->address_line2 ?? '';
        $this->addressCity = $detail->address_city ?? '';
        $this->addressState = $detail->address_state?->value ?? '';
        $this->addressZip = $detail->address_zip ?? '';
        $this->phone = $detail->phone ?? '';
        $this->citizenship = $detail->citizenship ?? 'United States';
        $this->ssnOnFile = $detail->maskedSsn();
        $this->race = $detail->race ?? '';
        $this->hispanicOrigin = $this->yesNoValue($detail->hispanic_origin);
        $this->educationYears = $detail->education_years !== null ? (string) $detail->education_years : null;
        $this->highestDegree = $detail->highest_degree?->value ?? '';
        $this->occupation = $detail->occupation ?? '';
        $this->industry = $detail->industry ?? '';
        $this->hasPacemaker = $this->yesNoValue($detail->has_pacemaker);
        $this->maritalStatus = $detail->marital_status?->value ?? '';
        $this->spouseFirstName = $detail->spouse_first_name ?? '';
        $this->spouseMiddleName = $detail->spouse_middle_name ?? '';
        $this->spouseLastName = $detail->spouse_last_name ?? '';
        $this->spouseMaidenName = $detail->spouse_maiden_name ?? '';
        $this->motherFirstName = $detail->mother_first_name ?? '';
        $this->motherMaidenName = $detail->mother_maiden_name ?? '';
        $this->motherLiving = $this->yesNoValue($detail->mother_living);
        $this->fatherFirstName = $detail->father_first_name ?? '';
        $this->fatherLastName = $detail->father_last_name ?? '';
        $this->fatherLiving = $this->yesNoValue($detail->father_living);
        $this->veteranStatus = $this->yesNoValue($detail->veteran_status);
        $this->veteranBranch = $detail->veteran_branch ?? '';
        $this->obituaryText = $detail->obituary_text ?? '';
        $this->servicePreferences = $detail->service_preferences ?? '';
        $this->additionalNotes = $detail->additional_notes ?? '';
        $this->submitted = $detail->isSubmitted();
    }

    /**
     * Usually the first thing to learn a payment went through — confirm it
     * with Stripe directly rather than waiting on the webhook, and empty the
     * cart it was paid from.
     */
    private function confirmPayment(CheckoutService $checkout): void
    {
        try {
            $this->paymentStatus = $checkout->syncPaymentStatus($this->currentOrder);
        } catch (ApiErrorException|\RuntimeException $e) {
            Log::warning('Could not confirm Stripe payment status on return.', [
                'order_id' => $this->currentOrder->id,
                'message' => $e->getMessage(),
            ]);
        }

        if (in_array($this->paymentStatus, [PaymentIntent::STATUS_SUCCEEDED, PaymentIntent::STATUS_PROCESSING], true)) {
            $cart = new Cart($this->currentOrder->store);

            if ($cart->pendingOrderId() === $this->currentOrder->id) {
                $cart->clear();
            }
        }
    }

    public function isPaymentProcessing(): bool
    {
        return ! $this->currentOrder->paid_at && $this->paymentStatus === PaymentIntent::STATUS_PROCESSING;
    }

    public function isAwaitingPayment(): bool
    {
        return ! $this->currentOrder->paid_at && ! $this->isPaymentProcessing();
    }

    public function isImmediate(): bool
    {
        return $this->currentOrder->timing === OrderTiming::Immediate;
    }

    public function hasSpouse(): bool
    {
        return MaritalStatus::tryFrom($this->maritalStatus)?->hasSpouse() ?? false;
    }

    /**
     * Only the loved one's name is required. Families answer as much or as
     * little as they like; everything else just has to be well-formed.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'nextOfKinName' => ['nullable', 'string', 'max:255'],
            'nextOfKinRelationship' => ['nullable', 'string', 'max:255'],
            'nextOfKinPhone' => ['nullable', 'string', 'max:30'],
            'nextOfKinEmail' => ['nullable', 'email', 'max:255'],

            'deceasedFirstName' => ['required', 'string', 'max:255'],
            'deceasedMiddleName' => ['nullable', 'string', 'max:255'],
            'deceasedLastName' => ['required', 'string', 'max:255'],
            'deceasedSuffix' => ['nullable', 'string', 'max:20'],
            'maidenName' => ['nullable', 'string', 'max:255'],
            'sex' => ['nullable', Rule::enum(Sex::class)],
            'dateOfBirth' => ['nullable', 'date', 'before_or_equal:today'],
            'bornOutsideUs' => ['boolean'],
            'birthCity' => ['nullable', 'string', 'max:255'],
            'birthState' => ['nullable', Rule::enum(UsState::class)],
            'birthPlaceOutsideUs' => ['nullable', 'string', 'max:255'],
            'dateOfDeath' => ['nullable', 'date', 'before_or_equal:today'],
            'placeOfDeath' => ['nullable', 'string', 'max:255'],
            'addressLine1' => ['nullable', 'string', 'max:255'],
            'addressLine2' => ['nullable', 'string', 'max:255'],
            'addressCity' => ['nullable', 'string', 'max:255'],
            'addressState' => ['nullable', Rule::enum(UsState::class)],
            'addressZip' => ['nullable', 'regex:/^\d{5}(-\d{4})?$/'],
            'phone' => ['nullable', 'string', 'max:30'],
            'citizenship' => ['nullable', 'string', 'max:255'],
            'ssn' => ['nullable', 'regex:/^\d{3}-?\d{2}-?\d{4}$/'],
            'race' => ['nullable', 'string', 'max:255'],
            'hispanicOrigin' => ['nullable', 'boolean'],
            'educationYears' => ['nullable', 'integer', 'min:0', 'max:30'],
            'highestDegree' => ['nullable', Rule::enum(HighestDegree::class)],
            'occupation' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'hasPacemaker' => ['nullable', 'boolean'],

            'maritalStatus' => ['nullable', Rule::enum(MaritalStatus::class)],
            'spouseFirstName' => ['nullable', 'string', 'max:255'],
            'spouseMiddleName' => ['nullable', 'string', 'max:255'],
            'spouseLastName' => ['nullable', 'string', 'max:255'],
            'spouseMaidenName' => ['nullable', 'string', 'max:255'],

            'motherFirstName' => ['nullable', 'string', 'max:255'],
            'motherMaidenName' => ['nullable', 'string', 'max:255'],
            'motherLiving' => ['nullable', 'boolean'],
            'fatherFirstName' => ['nullable', 'string', 'max:255'],
            'fatherLastName' => ['nullable', 'string', 'max:255'],
            'fatherLiving' => ['nullable', 'boolean'],

            'veteranStatus' => ['nullable', 'boolean'],
            'veteranBranch' => ['nullable', 'string', 'max:255'],

            'obituaryText' => ['nullable', 'string', 'max:10000'],
            'servicePreferences' => ['nullable', 'string', 'max:5000'],
            'additionalNotes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'nextOfKinName' => __('next of kin name'),
            'nextOfKinRelationship' => __('relationship'),
            'nextOfKinPhone' => __('next of kin phone'),
            'nextOfKinEmail' => __('next of kin email'),
            'deceasedFirstName' => __('first name'),
            'deceasedLastName' => __('last name'),
            'sex' => __('sex'),
            'dateOfBirth' => __('date of birth'),
            'birthCity' => __('city of birth'),
            'birthState' => __('state of birth'),
            'birthPlaceOutsideUs' => __('place of birth'),
            'dateOfDeath' => __('date of passing'),
            'placeOfDeath' => __('place of passing'),
            'addressLine1' => __('street address'),
            'addressCity' => __('city'),
            'addressState' => __('state'),
            'addressZip' => __('ZIP code'),
            'citizenship' => __('citizenship'),
            'occupation' => __('last occupation'),
            'hasPacemaker' => __('pacemaker or defibrillator'),
            'maritalStatus' => __('marital status'),
            'spouseFirstName' => __('spouse\'s first name'),
            'spouseLastName' => __('spouse\'s last name'),
            'motherFirstName' => __('mother\'s first name'),
            'motherMaidenName' => __('mother\'s maiden name'),
            'fatherFirstName' => __('father\'s first name'),
            'fatherLastName' => __('father\'s last name'),
            'veteranStatus' => __('veteran'),
            'veteranBranch' => __('branch of service'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'addressZip.regex' => __('Enter a 5-digit ZIP code.'),
            'ssn.regex' => __('Enter the Social Security number as 9 digits, like 123-45-6789.'),
        ];
    }

    /**
     * Keep what's been filled in so far, without requiring the rest.
     */
    public function saveDraft(): void
    {
        $this->validate();

        $this->persist(submitting: false);

        $this->draftSaved = true;
    }

    /**
     * Submit the completed form. This is the only point staff are emailed.
     */
    public function submit(): void
    {
        $this->validate();

        $wasSubmittedBefore = $this->currentOrder->detail()->whereNotNull('submitted_at')->exists();

        $this->persist(submitting: true);

        $this->currentOrder->store->notifyStaff(
            new OrderDetailsSubmittedNotification($this->currentOrder->fresh(), isUpdate: $wasSubmittedBefore),
        );

        $this->submitted = true;
        $this->draftSaved = false;
    }

    /**
     * Yes/no answers live in the form as "1", "0", or "" (unanswered),
     * which is what the radio buttons compare against.
     */
    private function yesNoValue(?bool $answer): string
    {
        return $answer === null ? '' : ($answer ? '1' : '0');
    }

    private function booleanFrom(string $answer): ?bool
    {
        return $answer === '' ? null : $answer === '1';
    }

    private function persist(bool $submitting): void
    {
        $attributes = [
            'next_of_kin_name' => $this->nextOfKinName ?: null,
            'next_of_kin_relationship' => $this->nextOfKinRelationship ?: null,
            'next_of_kin_phone' => $this->nextOfKinPhone ?: null,
            'next_of_kin_email' => $this->nextOfKinEmail ?: null,
            'maiden_name' => $this->maidenName ?: null,
            'sex' => $this->sex ?: null,
            'date_of_birth' => $this->dateOfBirth ?: null,
            'born_outside_us' => $this->bornOutsideUs,
            'birth_city' => $this->bornOutsideUs ? null : ($this->birthCity ?: null),
            'birth_state' => $this->bornOutsideUs ? null : ($this->birthState ?: null),
            'birth_place_outside_us' => $this->bornOutsideUs ? ($this->birthPlaceOutsideUs ?: null) : null,
            'date_of_death' => $this->isImmediate() ? ($this->dateOfDeath ?: null) : null,
            'place_of_death' => $this->isImmediate() ? ($this->placeOfDeath ?: null) : null,
            'address_line1' => $this->addressLine1 ?: null,
            'address_line2' => $this->addressLine2 ?: null,
            'address_city' => $this->addressCity ?: null,
            'address_state' => $this->addressState ?: null,
            'address_zip' => $this->addressZip ?: null,
            'phone' => $this->phone ?: null,
            'citizenship' => $this->citizenship ?: null,
            'race' => $this->race ?: null,
            'hispanic_origin' => $this->booleanFrom($this->hispanicOrigin),
            'education_years' => filled($this->educationYears) ? (int) $this->educationYears : null,
            'highest_degree' => $this->highestDegree ?: null,
            'occupation' => $this->occupation ?: null,
            'industry' => $this->industry ?: null,
            'has_pacemaker' => $this->booleanFrom($this->hasPacemaker),
            'marital_status' => $this->maritalStatus ?: null,
            'spouse_first_name' => $this->hasSpouse() ? ($this->spouseFirstName ?: null) : null,
            'spouse_middle_name' => $this->hasSpouse() ? ($this->spouseMiddleName ?: null) : null,
            'spouse_last_name' => $this->hasSpouse() ? ($this->spouseLastName ?: null) : null,
            'spouse_maiden_name' => $this->hasSpouse() ? ($this->spouseMaidenName ?: null) : null,
            'mother_first_name' => $this->motherFirstName ?: null,
            'mother_maiden_name' => $this->motherMaidenName ?: null,
            'mother_living' => $this->booleanFrom($this->motherLiving),
            'father_first_name' => $this->fatherFirstName ?: null,
            'father_last_name' => $this->fatherLastName ?: null,
            'father_living' => $this->booleanFrom($this->fatherLiving),
            'veteran_status' => $this->booleanFrom($this->veteranStatus),
            'veteran_branch' => $this->veteranStatus === '1' ? ($this->veteranBranch ?: null) : null,
            'obituary_text' => $this->obituaryText ?: null,
            'service_preferences' => $this->servicePreferences ?: null,
            'additional_notes' => $this->additionalNotes ?: null,
        ];

        // A blank box keeps the number already on file.
        if (filled($this->ssn)) {
            $digits = preg_replace('/\D/', '', $this->ssn);
            $attributes['ssn'] = substr($digits, 0, 3).'-'.substr($digits, 3, 2).'-'.substr($digits, 5);
        }

        if ($submitting) {
            $attributes['submitted_at'] = now();
        }

        DB::transaction(function () use ($attributes): void {
            $this->currentOrder->update([
                'deceased_first_name' => $this->deceasedFirstName,
                'deceased_middle_name' => $this->deceasedMiddleName ?: null,
                'deceased_last_name' => $this->deceasedLastName,
                'deceased_suffix' => $this->deceasedSuffix ?: null,
            ]);

            OrderDetail::updateOrCreate(['order_id' => $this->currentOrder->id], $attributes);
        });

        $this->currentOrder->load('detail');
        $this->ssn = '';
        $this->ssnOnFile = $this->currentOrder->detail->maskedSsn();
    }
}; ?>

<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
    @if ($this->isAwaitingPayment())
        <div class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8">
            <flux:heading size="xl" class="font-serif">{{ __('We haven\'t received your payment yet.') }}</flux:heading>
            <flux:subheading class="mt-1">
                {{ __('We couldn\'t confirm a payment for order :number. If you just paid, you\'ll receive a confirmation email shortly. Otherwise, you can return to checkout to complete your order — your selections have been saved.', ['number' => $currentOrder->order_number]) }}
            </flux:subheading>
            <flux:button :href="route('storefront.start')" variant="primary" class="mt-6 !bg-store hover:!bg-store-hover !text-store-foreground">{{ __('Return to checkout') }}</flux:button>
        </div>
    @else
        <div class="rounded-2xl border border-brand-100 bg-white p-6 shadow-sm sm:p-8">
            <flux:heading size="xl" class="font-serif">{{ __('Thank you, :name.', ['name' => $currentOrder->purchaser_first_name ?: 'friend']) }}</flux:heading>
            <flux:subheading class="mt-1">
                @if ($this->isPaymentProcessing())
                    {{ __('Your payment for order :number is processing — we\'ll email you as soon as it clears.', ['number' => $currentOrder->order_number]) }}
                @else
                    {{ __('Your payment for order :number is complete.', ['number' => $currentOrder->order_number]) }}
                @endif
                {{ __('Next, we need some information for official records, such as the death certificate. You can save your progress and come back to this page anytime using the link in your email.') }}
            </flux:subheading>
        </div>

        @if ($submitted)
            <div class="mt-6 rounded-2xl border border-brand-200 bg-brand-50 p-6 text-center">
                <flux:icon.check-circle class="mx-auto size-8 text-store" />
                <p class="mt-3 font-medium text-brand-900">{{ __('Thank you — your Vital Statistics have been sent to :store.', ['store' => $currentOrder->store->name]) }}</p>
                <p class="mt-1 text-sm text-zinc-500">{{ __('A member of our staff will be in touch if anything further is needed.') }}</p>
                <flux:button variant="ghost" class="mt-4" wire:click="$set('submitted', false)">{{ __('Make changes') }}</flux:button>
            </div>
        @else
            <form wire:submit="submit" class="mt-6 space-y-6">
                <div>
                    <flux:heading size="lg" class="font-serif">{{ __('Vital Statistics') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Answer as much or as little as you like — anything you leave blank, we can follow up on together. Submit when you\'re ready, and we\'ll let our staff know.') }}</flux:text>
                </div>

                @if ($errors->any())
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('A few answers need a second look. They are highlighted below.')" />
                @endif

                {{-- Next of kin --}}
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
                    <flux:heading size="lg">{{ __('Next of kin') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('The person we should contact about arrangements.') }}</flux:text>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Full name') }}</flux:label>
                            <flux:input wire:model="nextOfKinName" autocomplete="name" />
                            <flux:error name="nextOfKinName" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Relationship') }}</flux:label>
                            <flux:input wire:model="nextOfKinRelationship" placeholder="{{ __('e.g. Daughter') }}" />
                            <flux:error name="nextOfKinRelationship" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Phone') }}</flux:label>
                            <flux:input type="tel" wire:model="nextOfKinPhone" autocomplete="tel" />
                            <flux:error name="nextOfKinPhone" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Email') }}</flux:label>
                            <flux:input type="email" wire:model="nextOfKinEmail" autocomplete="email" />
                            <flux:error name="nextOfKinEmail" />
                        </flux:field>
                    </div>
                </section>

                {{-- Death certificate information --}}
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
                    <flux:heading size="lg">{{ __('Death certificate information') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('About your loved one, exactly as it should appear on official records.') }}</flux:text>
                    <div class="mt-5 grid gap-4 sm:grid-cols-6">
                        <flux:field class="sm:col-span-2">
                            <flux:label>{{ __('First name') }}</flux:label>
                            <flux:input wire:model="deceasedFirstName" />
                            <flux:error name="deceasedFirstName" />
                        </flux:field>
                        <flux:field class="sm:col-span-2">
                            <flux:label>{{ __('Middle name') }}</flux:label>
                            <flux:input wire:model="deceasedMiddleName" />
                            <flux:error name="deceasedMiddleName" />
                        </flux:field>
                        <flux:field class="sm:col-span-2">
                            <flux:label>{{ __('Last name') }}</flux:label>
                            <flux:input wire:model="deceasedLastName" />
                            <flux:error name="deceasedLastName" />
                        </flux:field>
                        <flux:field class="sm:col-span-2">
                            <flux:label>{{ __('Suffix') }}</flux:label>
                            <flux:input wire:model="deceasedSuffix" placeholder="{{ __('e.g. Jr.') }}" />
                            <flux:error name="deceasedSuffix" />
                        </flux:field>
                        <flux:field class="sm:col-span-4">
                            <flux:label>{{ __('Maiden name') }}</flux:label>
                            <flux:input wire:model="maidenName" />
                            <flux:error name="maidenName" />
                        </flux:field>

                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Sex') }}</flux:label>
                            <flux:radio.group wire:model="sex" class="flex gap-6">
                                @foreach (Sex::cases() as $option)
                                    <flux:radio :value="$option->value" :label="__($option->label())" />
                                @endforeach
                            </flux:radio.group>
                            <flux:error name="sex" />
                        </flux:field>
                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Date of birth') }}</flux:label>
                            <flux:input type="date" wire:model="dateOfBirth" />
                            <flux:error name="dateOfBirth" />
                        </flux:field>

                        <div class="sm:col-span-6">
                            <flux:checkbox wire:model.live="bornOutsideUs" :label="__('Born outside the United States')" />
                        </div>
                        @if ($bornOutsideUs)
                            <flux:field class="sm:col-span-6">
                                <flux:label>{{ __('Place of birth') }}</flux:label>
                                <flux:input wire:model="birthPlaceOutsideUs" placeholder="{{ __('City and country') }}" />
                                <flux:error name="birthPlaceOutsideUs" />
                            </flux:field>
                        @else
                            <flux:field class="sm:col-span-3">
                                <flux:label>{{ __('City of birth') }}</flux:label>
                                <flux:input wire:model="birthCity" />
                                <flux:error name="birthCity" />
                            </flux:field>
                            <flux:field class="sm:col-span-3">
                                <flux:label>{{ __('State of birth') }}</flux:label>
                                <flux:select wire:model="birthState">
                                    <option value="">{{ __('Choose a state') }}</option>
                                    @foreach (UsState::cases() as $state)
                                        <option value="{{ $state->value }}">{{ $state->label() }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="birthState" />
                            </flux:field>
                        @endif

                        @if ($this->isImmediate())
                            <flux:field class="sm:col-span-2">
                                <flux:label>{{ __('Date of passing') }}</flux:label>
                                <flux:input type="date" wire:model="dateOfDeath" />
                                <flux:error name="dateOfDeath" />
                            </flux:field>
                            <flux:field class="sm:col-span-4">
                                <flux:label>{{ __('Place of passing') }}</flux:label>
                                <flux:input wire:model="placeOfDeath" placeholder="{{ __('Home address, hospital, or facility name') }}" />
                                <flux:error name="placeOfDeath" />
                            </flux:field>
                        @endif

                        <flux:field class="sm:col-span-6">
                            <flux:label>{{ __('Home address') }}</flux:label>
                            <flux:input wire:model="addressLine1" placeholder="{{ __('Street address') }}" autocomplete="off" />
                            <flux:error name="addressLine1" />
                        </flux:field>
                        <flux:field class="sm:col-span-6">
                            <flux:input wire:model="addressLine2" placeholder="{{ __('Apartment, suite, etc. (optional)') }}" autocomplete="off" :aria-label="__('Address line 2')" />
                            <flux:error name="addressLine2" />
                        </flux:field>
                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('City') }}</flux:label>
                            <flux:input wire:model="addressCity" autocomplete="off" />
                            <flux:error name="addressCity" />
                        </flux:field>
                        <flux:field class="sm:col-span-2">
                            <flux:label>{{ __('State') }}</flux:label>
                            <flux:select wire:model="addressState">
                                <option value="">{{ __('Choose') }}</option>
                                @foreach (UsState::cases() as $state)
                                    <option value="{{ $state->value }}">{{ $state->label() }}</option>
                                @endforeach
                            </flux:select>
                            <flux:error name="addressState" />
                        </flux:field>
                        <flux:field class="sm:col-span-1">
                            <flux:label>{{ __('ZIP') }}</flux:label>
                            <flux:input wire:model="addressZip" inputmode="numeric" autocomplete="off" />
                            <flux:error name="addressZip" />
                        </flux:field>

                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Phone') }}</flux:label>
                            <flux:input type="tel" wire:model="phone" autocomplete="off" />
                            <flux:error name="phone" />
                        </flux:field>
                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Citizen of') }}</flux:label>
                            <flux:input wire:model="citizenship" />
                            <flux:error name="citizenship" />
                        </flux:field>

                        <flux:field class="sm:col-span-6">
                            <flux:label>{{ __('Social Security number') }}</flux:label>
                            @if ($ssnOnFile)
                                <flux:description>{{ __('We have :number on file. Leave this blank to keep it, or enter a new number to replace it.', ['number' => $ssnOnFile]) }}</flux:description>
                            @else
                                <flux:description>{{ __('Needed for the death certificate. It is stored securely and never sent by email. If you don\'t have it handy, leave it blank.') }}</flux:description>
                            @endif
                            <flux:input wire:model="ssn" inputmode="numeric" autocomplete="off" placeholder="123-45-6789" class="max-w-48" />
                            <flux:error name="ssn" />
                        </flux:field>

                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Race') }}</flux:label>
                            <flux:input wire:model="race" />
                            <flux:error name="race" />
                        </flux:field>
                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Of Hispanic origin?') }}</flux:label>
                            <flux:radio.group wire:model="hispanicOrigin" class="flex gap-6">
                                <flux:radio value="1" :label="__('Yes')" />
                                <flux:radio value="0" :label="__('No')" />
                            </flux:radio.group>
                            <flux:error name="hispanicOrigin" />
                        </flux:field>

                        <flux:field class="sm:col-span-2">
                            <flux:label>{{ __('Education (total years)') }}</flux:label>
                            <flux:input type="number" min="0" max="30" wire:model="educationYears" />
                            <flux:error name="educationYears" />
                        </flux:field>
                        <flux:field class="sm:col-span-4">
                            <flux:label>{{ __('Highest degree earned') }}</flux:label>
                            <flux:select wire:model="highestDegree">
                                <option value="">{{ __('None or not applicable') }}</option>
                                @foreach (HighestDegree::cases() as $degree)
                                    <option value="{{ $degree->value }}">{{ $degree->label() }}</option>
                                @endforeach
                            </flux:select>
                            <flux:error name="highestDegree" />
                        </flux:field>

                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Last occupation') }}</flux:label>
                            <flux:input wire:model="occupation" placeholder="{{ __('e.g. Teacher (retired)') }}" />
                            <flux:error name="occupation" />
                        </flux:field>
                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Business or industry') }}</flux:label>
                            <flux:input wire:model="industry" placeholder="{{ __('e.g. Education') }}" />
                            <flux:error name="industry" />
                        </flux:field>

                        <flux:field class="sm:col-span-6">
                            <flux:label>{{ __('Did they have a pacemaker and/or defibrillator?') }}</flux:label>
                            <flux:description>{{ __('These must be removed before cremation, so please let us know.') }}</flux:description>
                            <flux:radio.group wire:model="hasPacemaker" class="flex gap-6">
                                <flux:radio value="0" :label="__('No')" />
                                <flux:radio value="1" :label="__('Yes')" />
                            </flux:radio.group>
                            <flux:error name="hasPacemaker" />
                        </flux:field>
                    </div>
                </section>

                {{-- Marital information --}}
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
                    <flux:heading size="lg">{{ __('Marital information') }}</flux:heading>
                    <div class="mt-5 grid gap-4 sm:grid-cols-6">
                        <flux:field class="sm:col-span-6">
                            <flux:label>{{ __('Marital status') }}</flux:label>
                            <flux:radio.group wire:model.live="maritalStatus" class="flex flex-wrap gap-x-6 gap-y-2">
                                @foreach (MaritalStatus::cases() as $status)
                                    <flux:radio :value="$status->value" :label="__($status->label())" />
                                @endforeach
                            </flux:radio.group>
                            <flux:error name="maritalStatus" />
                        </flux:field>
                        @if ($this->hasSpouse())
                            <flux:field class="sm:col-span-2">
                                <flux:label>{{ __('Spouse\'s first name') }}</flux:label>
                                <flux:input wire:model="spouseFirstName" />
                                <flux:error name="spouseFirstName" />
                            </flux:field>
                            <flux:field class="sm:col-span-2">
                                <flux:label>{{ __('Middle name') }}</flux:label>
                                <flux:input wire:model="spouseMiddleName" />
                                <flux:error name="spouseMiddleName" />
                            </flux:field>
                            <flux:field class="sm:col-span-2">
                                <flux:label>{{ __('Last name') }}</flux:label>
                                <flux:input wire:model="spouseLastName" />
                                <flux:error name="spouseLastName" />
                            </flux:field>
                            <flux:field class="sm:col-span-3">
                                <flux:label>{{ __('Spouse\'s maiden name (if applicable)') }}</flux:label>
                                <flux:input wire:model="spouseMaidenName" />
                                <flux:error name="spouseMaidenName" />
                            </flux:field>
                        @endif
                    </div>
                </section>

                {{-- Parents --}}
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
                    <flux:heading size="lg">{{ __('Parents') }}</flux:heading>
                    <div class="mt-5 grid gap-4 sm:grid-cols-6">
                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Mother\'s first name') }}</flux:label>
                            <flux:input wire:model="motherFirstName" />
                            <flux:error name="motherFirstName" />
                        </flux:field>
                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Mother\'s maiden name') }}</flux:label>
                            <flux:input wire:model="motherMaidenName" />
                            <flux:error name="motherMaidenName" />
                        </flux:field>
                        <flux:field class="sm:col-span-6">
                            <flux:radio.group wire:model="motherLiving" class="flex gap-6" :aria-label="__('Is their mother living?')">
                                <flux:radio value="1" :label="__('Living')" />
                                <flux:radio value="0" :label="__('Deceased')" />
                            </flux:radio.group>
                        </flux:field>

                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Father\'s first name') }}</flux:label>
                            <flux:input wire:model="fatherFirstName" />
                            <flux:error name="fatherFirstName" />
                        </flux:field>
                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Father\'s last name') }}</flux:label>
                            <flux:input wire:model="fatherLastName" />
                            <flux:error name="fatherLastName" />
                        </flux:field>
                        <flux:field class="sm:col-span-6">
                            <flux:radio.group wire:model="fatherLiving" class="flex gap-6" :aria-label="__('Is their father living?')">
                                <flux:radio value="1" :label="__('Living')" />
                                <flux:radio value="0" :label="__('Deceased')" />
                            </flux:radio.group>
                        </flux:field>
                    </div>
                </section>

                {{-- Military service --}}
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
                    <flux:heading size="lg">{{ __('Military service') }}</flux:heading>
                    <div class="mt-5 grid gap-4 sm:grid-cols-6">
                        <flux:field class="sm:col-span-3">
                            <flux:label>{{ __('Did they serve in the U.S. armed forces?') }}</flux:label>
                            <flux:radio.group wire:model.live="veteranStatus" class="flex gap-6">
                                <flux:radio value="1" :label="__('Yes')" />
                                <flux:radio value="0" :label="__('No')" />
                            </flux:radio.group>
                            <flux:error name="veteranStatus" />
                        </flux:field>
                        @if ($veteranStatus === '1')
                            <flux:field class="sm:col-span-3">
                                <flux:label>{{ __('Branch of service') }}</flux:label>
                                <flux:input wire:model="veteranBranch" placeholder="{{ __('e.g. U.S. Army') }}" />
                                <flux:error name="veteranBranch" />
                            </flux:field>
                        @endif
                    </div>
                </section>

                {{-- Obituary & service wishes --}}
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
                    <flux:heading size="lg">{{ __('Obituary & service wishes') }} <span class="text-sm font-normal text-zinc-500">{{ __('(optional)') }}</span></flux:heading>
                    <flux:text class="mt-1">{{ __('Share as much or as little as you have right now — nothing here is final.') }}</flux:text>
                    <div class="mt-5 space-y-4">
                        <flux:field>
                            <flux:label>{{ __('Obituary (a draft is fine)') }}</flux:label>
                            <flux:textarea wire:model="obituaryText" rows="6" />
                            <flux:error name="obituaryText" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Service preferences') }}</flux:label>
                            <flux:description>{{ __('Any thoughts on timing, location, readings, music, or who should be involved.') }}</flux:description>
                            <flux:textarea wire:model="servicePreferences" rows="4" />
                            <flux:error name="servicePreferences" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Anything else we should know?') }}</flux:label>
                            <flux:textarea wire:model="additionalNotes" rows="3" />
                            <flux:error name="additionalNotes" />
                        </flux:field>
                    </div>
                </section>

                <div class="flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-end">
                    @if ($draftSaved)
                        <p class="text-sm text-zinc-500 sm:mr-auto" role="status">
                            <flux:icon.check class="inline size-4 text-store" />
                            {{ __('Saved. You can come back anytime using the link in your email.') }}
                        </p>
                    @endif
                    @unless ($currentOrder->detail?->isSubmitted())
                        <flux:button type="button" variant="ghost" wire:click="saveDraft">{{ __('Save and finish later') }}</flux:button>
                    @endunless
                    <flux:button type="submit" variant="primary" class="!bg-store hover:!bg-store-hover !text-store-foreground">
                        {{ $currentOrder->detail?->isSubmitted() ? __('Submit changes') : __('Submit Vital Statistics') }}
                    </flux:button>
                </div>
            </form>
        @endif
    @endif
</div>
