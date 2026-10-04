<?php

use App\Enums\MaritalStatus;
use App\Enums\OrderTiming;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreUser;
use App\Notifications\OrderDetailsSubmittedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();

    $this->store = Store::factory()->create();
    actingAsTenant($this->store);
    $this->staff = StoreUser::factory()->forStore($this->store)->create();

    $this->order = Order::factory()->create([
        'store_id' => $this->store->id,
        'paid_at' => now(),
        'timing' => OrderTiming::Immediate,
        'purchaser_first_name' => 'Sam',
        'purchaser_last_name' => 'Rivera',
        'purchaser_email' => 'sam@example.com',
        'relationship_to_deceased' => 'Adult child',
    ]);
});

/**
 * Answer every question the death certificate needs.
 */
function completeVitalStatistics(Testable $form): Testable
{
    return $form
        ->set('nextOfKinPhone', '555-0100')
        ->set('sex', 'female')
        ->set('dateOfBirth', '1948-03-14')
        ->set('birthCity', 'Springfield')
        ->set('birthState', 'IL')
        ->set('dateOfDeath', now()->subDay()->toDateString())
        ->set('placeOfDeath', 'Home')
        ->set('addressLine1', '12 Oak Street')
        ->set('addressCity', 'Springfield')
        ->set('addressState', 'IL')
        ->set('addressZip', '62701')
        ->set('occupation', 'Teacher (retired)')
        ->set('hasPacemaker', '0')
        ->set('maritalStatus', MaritalStatus::Widowed->value)
        ->set('spouseFirstName', 'Robert')
        ->set('spouseLastName', 'Rivera')
        ->set('motherFirstName', 'Helen')
        ->set('motherMaidenName', 'Brooks')
        ->set('fatherFirstName', 'George')
        ->set('fatherLastName', 'Brooks')
        ->set('veteranStatus', '0');
}

function vitalStatisticsForm(Order $order): Testable
{
    return Livewire::test('pages::storefront.order-details', ['order' => $order->id]);
}

test('the next of kin starts out as the person who paid', function () {
    vitalStatisticsForm($this->order)
        ->assertSet('nextOfKinName', 'Sam Rivera')
        ->assertSet('nextOfKinRelationship', 'Adult child')
        ->assertSet('nextOfKinEmail', 'sam@example.com');
});

test('someone planning their own arrangements is not made their own next of kin', function () {
    $this->order->update(['timing' => OrderTiming::PreNeed, 'relationship_to_deceased' => 'Self']);

    vitalStatisticsForm($this->order)
        ->assertSet('nextOfKinName', '')
        ->assertSet('nextOfKinRelationship', '')
        ->assertSet('nextOfKinEmail', '');
});

test('a family can save part of the form and come back without staff being emailed', function () {
    vitalStatisticsForm($this->order)
        ->set('dateOfBirth', '1948-03-14')
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->assertSet('draftSaved', true);

    $detail = $this->order->fresh()->detail;

    expect($detail->date_of_birth->toDateString())->toBe('1948-03-14')
        ->and($detail->isSubmitted())->toBeFalse();
    Notification::assertNothingSent();

    vitalStatisticsForm($this->order)->assertSet('dateOfBirth', '1948-03-14');
});

test('a family can submit with as little as their loved one\'s name', function () {
    vitalStatisticsForm($this->order)
        ->set('nextOfKinName', '')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    expect($this->order->fresh()->detail->isSubmitted())->toBeTrue();
    Notification::assertSentToTimes($this->staff, OrderDetailsSubmittedNotification::class, 1);
});

test('the loved one\'s name is the only thing required', function () {
    vitalStatisticsForm($this->order)
        ->set('deceasedFirstName', '')
        ->set('deceasedLastName', '')
        ->call('submit')
        ->assertHasErrors(['deceasedFirstName' => 'required', 'deceasedLastName' => 'required']);

    expect($this->order->fresh()->detail)->toBeNull();
    Notification::assertNothingSent();
});

test('submitting the completed form saves it and emails staff once', function () {
    completeVitalStatistics(vitalStatisticsForm($this->order))
        ->set('deceasedMiddleName', 'Ann')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    $order = $this->order->fresh();

    expect($order->detail->isSubmitted())->toBeTrue()
        ->and($order->detail->marital_status)->toBe(MaritalStatus::Widowed)
        ->and($order->detail->has_pacemaker)->toBeFalse()
        ->and($order->deceased_middle_name)->toBe('Ann');

    Notification::assertSentToTimes($this->staff, OrderDetailsSubmittedNotification::class, 1);
});

test('changing answers after submitting tells staff it was updated', function () {
    $form = completeVitalStatistics(vitalStatisticsForm($this->order))->call('submit');

    $form->call('$set', 'submitted', false)
        ->set('occupation', 'Librarian (retired)')
        ->call('submit')
        ->assertHasNoErrors();

    $subjects = [];
    Notification::assertSentTo($this->staff, OrderDetailsSubmittedNotification::class, function ($notification) use (&$subjects) {
        $subjects[] = $notification->toMail($this->staff)->subject;

        return true;
    });

    expect($subjects)->toHaveCount(2)
        ->and($subjects[0])->toStartWith('Vital Statistics received')
        ->and($subjects[1])->toStartWith('Vital Statistics updated');
});

test('answers to questions that no longer apply are not kept', function () {
    completeVitalStatistics(vitalStatisticsForm($this->order))
        ->set('maritalStatus', MaritalStatus::NeverMarried->value)
        ->call('submit');

    expect($this->order->fresh()->detail->spouse_first_name)->toBeNull();
});

test('arrangements made before a death do not ask when or where they passed', function (OrderTiming $timing) {
    $this->order->update(['timing' => $timing]);

    vitalStatisticsForm($this->order)->assertDontSee('Date of passing');
})->with([
    'imminent' => [OrderTiming::Imminent],
    'pre-need' => [OrderTiming::PreNeed],
]);

describe('the Social Security number', function () {
    test('is stored encrypted and never sent back to the browser', function () {
        completeVitalStatistics(vitalStatisticsForm($this->order))
            ->set('ssn', '123456789')
            ->call('submit')
            ->assertSet('ssn', '');

        $detail = $this->order->fresh()->detail;
        $storedValue = DB::table('order_details')->where('id', $detail->id)->value('ssn');

        expect($detail->ssn)->toBe('123-45-6789')
            ->and($storedValue)->not->toContain('6789');

        vitalStatisticsForm($this->order)
            ->assertSet('ssn', '')
            ->assertSet('ssnOnFile', '•••-••-6789')
            ->assertDontSee('123-45-6789');
    });

    test('leaving it blank keeps the number on file', function () {
        completeVitalStatistics(vitalStatisticsForm($this->order))->set('ssn', '123-45-6789')->call('saveDraft');

        vitalStatisticsForm($this->order)->set('occupation', 'Nurse')->call('saveDraft');

        expect($this->order->fresh()->detail->ssn)->toBe('123-45-6789');
    });

    test('must be nine digits', function () {
        vitalStatisticsForm($this->order)
            ->set('ssn', '12-345')
            ->call('saveDraft')
            ->assertHasErrors(['ssn' => 'Enter the Social Security number as 9 digits, like 123-45-6789.']);
    });
});

test('an order belonging to a different store cannot be opened', function () {
    $storeB = Store::factory()->create();

    $orderFromOtherStore = Order::factory()->create([
        'store_id' => $storeB->id,
        'purchaser_first_name' => 'Evangeline',
        'deceased_first_name' => 'Rosalind',
    ]);

    // Livewire's test harness renders ModelNotFoundException as a 404
    // response rather than rethrowing it, so assert on that response.
    Livewire::test('pages::storefront.order-details', ['order' => $orderFromOtherStore->id])
        ->assertNotFound()
        ->assertDontSee('Evangeline')
        ->assertDontSee('Rosalind')
        ->assertDontSee($orderFromOtherStore->order_number);
});

describe('a store with its own vital statistics form', function () {
    beforeEach(function () {
        $this->store->update(['vital_statistics_url' => 'https://riverside.example/vital-statistics']);
    });

    test('sends the family on to it instead of showing our form', function () {
        vitalStatisticsForm($this->order)
            ->assertSee('Continue to Vital Statistics form')
            ->assertSeeHtml('href="https://riverside.example/vital-statistics"')
            ->assertSeeHtml('target="_top"')
            ->assertDontSee('Save and finish later');
    });

    test('does not accept our form', function () {
        vitalStatisticsForm($this->order)
            ->set('deceasedFirstName', 'Pat')
            ->call('saveDraft')
            ->assertNotFound();

        expect($this->order->detail()->exists())->toBeFalse();
    });
});

test('once submitted, the family is offered a way back to the funeral home website', function () {
    $this->store->update(['website_url' => 'https://riverside.example']);

    vitalStatisticsForm($this->order)
        ->assertDontSee('Go back to main site')
        ->call('submit')
        ->assertSee('Go back to main site')
        ->assertSeeHtml('href="https://riverside.example"');
});

test('there is no way back to a website the store has not set', function () {
    $this->store->update(['website_url' => null]);

    vitalStatisticsForm($this->order)
        ->call('submit')
        ->assertDontSee('Go back to main site');
});
