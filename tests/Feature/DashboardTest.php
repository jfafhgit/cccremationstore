<?php

use App\Models\User;
use Illuminate\Support\Js;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('a wire:navigate click after the session expires ends on a same-origin page that loads the login page in full', function () {
    $navigateHeaders = ['X-Livewire-Navigate' => '1'];

    $this->get('/admin/stores', $navigateHeaders)->assertRedirect('/login');

    $this->get('/login', $navigateHeaders)
        ->assertOk()
        ->assertSee('window.location.replace('.Js::from(route('login')).')', escape: false);
});

test('authenticated users are sent from the dashboard into the admin area', function () {
    $this->actingAs($user = User::factory()->create());

    // "/dashboard" is the fixed post-login destination WorkOS redirects to;
    // this app's actual home base for a super admin is the stores list.
    $this->get('/dashboard')->assertRedirect('/admin/stores');
    $this->get('/admin/stores')->assertOk();
});
