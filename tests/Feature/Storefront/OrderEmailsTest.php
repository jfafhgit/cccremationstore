<?php

use App\Enums\OrderTiming;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\StoreUser;
use App\Notifications\NewOrderNotification;
use App\Notifications\OrderDetailsSubmittedNotification;
use App\Notifications\OrderPaidNotification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->store = Store::factory()->create([
        'name' => 'Riverside Cremation',
        'slug' => 'riverside',
        'general_email' => 'care@riverside.test',
        'contact_phone' => '555-0142',
        'timezone' => 'America/Chicago',
    ]);

    $this->order = Order::factory()->for($this->store)->paid()->create([
        'purchaser_first_name' => 'Sam',
        'purchaser_last_name' => 'Rivera',
        'purchaser_email' => 'sam@example.com',
        'deceased_first_name' => 'Pat',
        'deceased_last_name' => 'Rivera',
        'subtotal_cents' => 110000,
        'tax_cents' => 5000,
        'processing_fee_cents' => 0,
        'total_cents' => 115000,
        'paid_at' => '2026-09-28 15:30:00',
    ]);

    OrderItem::factory()->for($this->order)->create(['name_snapshot' => 'Direct Cremation', 'quantity' => 1, 'total_price_cents' => 100000]);
    OrderItem::factory()->for($this->order)->create(['name_snapshot' => 'Keepsake Pendant', 'variant_snapshot' => 'Silver', 'quantity' => 2, 'total_price_cents' => 10000]);
});

describe('the customer confirmation', function () {
    test('is a receipt from the funeral home with a link to share more details', function () {
        $mail = (new OrderPaidNotification($this->order))->toMail($this->order);
        $html = (string) $mail->render();

        expect($mail->from)->toBe([config('mail.from.address'), 'Riverside Cremation'])
            ->and($mail->replyTo)->toBe([['care@riverside.test', 'Riverside Cremation']])
            ->and($mail->subject)->toBe("Your order with Riverside Cremation is confirmed ({$this->order->order_number})")
            ->and($html)
            ->toContain('Direct Cremation')
            ->toContain('Keepsake Pendant — Silver')
            ->toContain('$50.00')
            ->toContain('$1,150.00')
            ->toContain('September 28, 2026 at 10:30 AM CDT')
            ->toContain('555-0142')
            ->toContain('The Riverside Cremation Team')
            ->toContain(e($this->order->detailsUrl()))
            ->not->toContain('Processing fee');
    });

    test('offers condolences only when the loved one has passed', function (OrderTiming $timing, bool $expectsCondolences) {
        $this->order->update(['timing' => $timing]);

        $html = (string) (new OrderPaidNotification($this->order))->toMail($this->order)->render();

        expect(str_contains($html, 'sorry for your loss'))->toBe($expectsCondolences);
    })->with([
        'at-need' => [OrderTiming::Immediate, true],
        'imminent' => [OrderTiming::Imminent, false],
        'pre-need' => [OrderTiming::PreNeed, false],
    ]);

    test('does not invite a reply when the store has no main email', function () {
        $this->store->update(['general_email' => null]);

        $mail = (new OrderPaidNotification($this->order->fresh()))->toMail($this->order);

        expect($mail->replyTo)->toBe([])
            ->and((string) $mail->render())->not->toContain('Reply to this email');
    });
});

/**
 * The contents of the email's header cell (above the white body card).
 */
function mailHeader(string $html): string
{
    preg_match('/<td class="header"[^>]*>(.*?)<\/td>/s', $html, $matches);

    return $matches[1] ?? '';
}

describe('the funeral home heading the emails', function () {
    test('the header shows the funeral home, and the platform only in the footer', function () {
        $html = (string) (new OrderPaidNotification($this->order))->toMail($this->order)->render();

        expect(mailHeader($html))->toContain('Riverside Cremation')
            ->not->toContain(config('app.name'))
            ->and($html)->toContain('Online arrangements powered by '.config('app.name'));
    });

    test('the header shows the funeral home logo when it has one', function () {
        Storage::fake('public');
        $this->store->update(['brand_logo_path' => 'logos/riverside.png']);
        $staff = StoreUser::factory()->forStore($this->store)->create();

        $html = (string) (new NewOrderNotification($this->order->fresh()))->toMail($staff)->render();

        expect(mailHeader($html))->toContain(Storage::disk('public')->url('logos/riverside.png'));
    });
});

describe('the Vital Statistics alert', function () {
    beforeEach(function () {
        $this->staff = StoreUser::factory()->forStore($this->store)->create();

        OrderDetail::factory()->for($this->order)->submitted()->create([
            'date_of_birth' => '1945-06-01',
            'veteran_status' => true,
            'veteran_branch' => 'U.S. Navy',
            'mother_living' => false,
            'ssn' => '123-45-6789',
            'obituary_text' => "Pat loved the lake.\nShe taught for 30 years.\n\n    [Donate](https://evil.test) in lieu of flowers.",
            'service_preferences' => null,
        ]);
    });

    test('shows what the family submitted, keeping their line breaks', function () {
        $mail = (new OrderDetailsSubmittedNotification($this->order))->toMail($this->staff);
        $html = (string) $mail->render();

        expect($mail->subject)->toBe("Vital Statistics received for Pat Rivera ({$this->order->order_number})")
            ->and($mail->replyTo)->toBe([['sam@example.com', 'Sam Rivera']])
            ->and($html)
            ->toContain('June 1, 1945')
            ->toContain('U.S. Navy')
            ->toContain('Helen (maiden name Brooks) — deceased')
            ->not->toContain('Pacemaker and/or defibrillator reported')
            ->toContain('Pat loved the lake.<br')
            ->toContain('[Donate](https://evil.test)')
            ->not->toContain('href="https://evil.test"')
            ->not->toContain('<code>')
            ->not->toContain('Service preferences')
            ->toContain('https://riverside.'.config('app.root_domain').'/portal/orders/'.$this->order->id);
    });

    test('never includes the full Social Security number', function () {
        $html = (string) (new OrderDetailsSubmittedNotification($this->order))->toMail($this->staff)->render();

        expect($html)->not->toContain('123-45')
            ->not->toContain('123456789')
            ->toContain('6789');
    });

    test('calls out a pacemaker, which must be removed before cremation', function () {
        $this->order->detail->update(['has_pacemaker' => true]);

        $html = (string) (new OrderDetailsSubmittedNotification($this->order->fresh()))->toMail($this->staff)->render();

        expect($html)->toContain('Pacemaker and/or defibrillator reported');
    });

    test('says when the family changed answers they already sent', function () {
        $mail = (new OrderDetailsSubmittedNotification($this->order, isUpdate: true))->toMail($this->staff);

        expect($mail->subject)->toStartWith('Vital Statistics updated for Pat Rivera')
            ->and((string) $mail->render())->toContain('Here is everything as it stands now');
    });
});

describe('the staff alert', function () {
    test('shows who bought what and links to the order in the staff portal', function () {
        $staff = StoreUser::factory()->forStore($this->store)->create();

        $mail = (new NewOrderNotification($this->order))->toMail($staff);
        $html = (string) $mail->render();

        expect($mail->subject)->toBe("New order {$this->order->order_number} — Pat Rivera")
            ->and($mail->replyTo)->toBe([['sam@example.com', 'Sam Rivera']])
            ->and($html)
            ->toContain('Sam Rivera')
            ->toContain('At-need (loved one has passed)')
            ->toContain('Direct Cremation')
            ->toContain('https://riverside.'.config('app.root_domain').'/portal/orders/'.$this->order->id);
    });

    test('shows what families type as plain text, never as links or formatting', function () {
        $this->order->update(['purchaser_first_name' => '[Click here](https://evil.test)', 'deceased_first_name' => '**Pat**']);
        $staff = StoreUser::factory()->forStore($this->store)->create();

        $html = (string) (new NewOrderNotification($this->order))->toMail($staff)->render();

        expect($html)->not->toContain('href="https://evil.test"')
            ->not->toContain('<strong>Pat</strong>')
            ->toContain('[Click here](https://evil.test)')
            ->toContain('**Pat**');
    });
});

describe('a store with its own vital statistics form', function () {
    beforeEach(function () {
        $this->store->update(['vital_statistics_url' => 'https://riverside.example/vital-statistics']);
    });

    test('the family receipt links to that form', function () {
        $html = (string) (new OrderPaidNotification($this->order->fresh()))->toMail($this->order)->render();

        expect($html)
            ->toContain('https://riverside.example/vital-statistics')
            ->not->toContain(e($this->order->detailsUrl()))
            ->not->toContain('this link doesn');
    });

    test('the staff alert does not promise a follow-up email', function () {
        $staff = StoreUser::factory()->forStore($this->store)->create();

        $html = (string) (new NewOrderNotification($this->order->fresh()))->toMail($staff)->render();

        expect($html)
            ->toContain('Vital Statistics form on your website')
            ->not->toContain('once they submit it');
    });
});
