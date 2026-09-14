<?php

use App\Models\Lead;
use App\Notifications\NewLeadNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('the root domain shows the marketing home page', function () {
    $this->get('/')->assertOk();
});

test('submitting the contact form creates a lead and notifies the platform admin', function () {
    Notification::fake();
    config(['services.platform.admin_email' => 'admin@example.com']);

    $component = Livewire::test('pages::marketing.home');
    $component->set('name', 'Jamie Lee');
    $component->set('email', 'jamie@example.com');
    $component->set('funeralHomeName', 'Lee Family Funeral Home');
    $component->set('message', 'Interested in a demo.');
    $component->call('submit');

    $component->assertHasNoErrors();
    expect($component->get('submitted'))->toBeTrue();

    $lead = Lead::where('email', 'jamie@example.com')->first();
    expect($lead)->not->toBeNull()
        ->and($lead->funeral_home_name)->toBe('Lee Family Funeral Home');

    Notification::assertSentOnDemand(NewLeadNotification::class);
});

test('name and email are required to submit the contact form', function () {
    $component = Livewire::test('pages::marketing.home');
    $component->call('submit');

    $component->assertHasErrors(['name', 'email']);
    expect(Lead::count())->toBe(0);
});
