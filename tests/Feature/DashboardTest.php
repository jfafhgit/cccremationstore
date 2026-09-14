<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated users are sent from the dashboard into the admin area', function () {
    $this->actingAs($user = User::factory()->create());

    // "/dashboard" is the fixed post-login destination WorkOS redirects to;
    // this app's actual home base for a super admin is the stores list.
    $this->get('/dashboard')->assertRedirect('/admin/stores');
    $this->get('/admin/stores')->assertOk();
});
