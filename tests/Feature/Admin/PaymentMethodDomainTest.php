<?php

use App\Models\Store;
use App\Models\User;
use App\Services\StripeConnectService;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

beforeEach(function () {
    config(['services.stripe.secret' => 'sk_test_fake', 'app.root_domain' => 'example.com']);

    $this->actingAs(User::factory()->create());

    $this->stripe = new class implements ClientInterface
    {
        /** @var array<int, array{method: string, path: string, params: array<string, mixed>, headers: array<int, string>}> */
        public array $requests = [];

        /** Domains already registered on the connected account. */
        public array $domains = [];

        public bool $failDomainRequests = false;

        public array $account = [
            'id' => 'acct_test123',
            'object' => 'account',
            'details_submitted' => true,
            'charges_enabled' => true,
            'payouts_enabled' => true,
        ];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $path = parse_url($absUrl, PHP_URL_PATH);
            $params = is_array($params) ? $params : [];

            if (! str_starts_with($path, '/v1/payment_method_domains')) {
                return [json_encode($this->account), 200, []];
            }

            $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params, 'headers' => $headers];

            if ($this->failDomainRequests) {
                return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid domain']]), 400, []];
            }

            if ($method === 'get') {
                return [json_encode(['object' => 'list', 'data' => $this->domains, 'has_more' => false]), 200, []];
            }

            return [json_encode(['id' => 'pmd_1', 'object' => 'payment_method_domain', 'enabled' => true, ...$params]), 200, []];
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        public function writes(): array
        {
            return array_values(array_filter($this->requests, fn (array $request) => $request['method'] === 'post'));
        }
    };

    ApiRequestor::setHttpClient($this->stripe);

    $this->store = Store::factory()->create([
        'slug' => 'riverside',
        'stripe_account_id' => 'acct_test123',
        'stripe_charges_enabled' => false,
    ]);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('a store\'s storefront domain is registered on its own Stripe account once it can take charges', function () {
    app(StripeConnectService::class)->syncAccountStatus($this->store);

    $writes = $this->stripe->writes();

    expect($writes)->toHaveCount(1)
        ->and($writes[0]['path'])->toBe('/v1/payment_method_domains')
        ->and($writes[0]['params'])->toBe(['domain_name' => 'riverside.example.com'])
        ->and($writes[0]['headers'])->toContain('Stripe-Account: acct_test123')
        ->and($this->store->fresh()->stripe_payment_method_domain)->toBe('riverside.example.com');
});

test('a store that cannot take charges yet is not registered', function () {
    $this->stripe->account['charges_enabled'] = false;

    app(StripeConnectService::class)->syncAccountStatus($this->store);

    expect($this->stripe->requests)->toBeEmpty()
        ->and($this->store->fresh()->stripe_payment_method_domain)->toBeNull();
});

test('a domain already registered for the store is not registered again', function () {
    $this->store->forceFill(['stripe_payment_method_domain' => 'riverside.example.com'])->save();

    app(StripeConnectService::class)->syncAccountStatus($this->store);

    expect($this->stripe->requests)->toBeEmpty();
});

test('a domain that exists on the Stripe account but is disabled is re-enabled', function () {
    $this->stripe->domains = [['id' => 'pmd_old', 'object' => 'payment_method_domain', 'domain_name' => 'riverside.example.com', 'enabled' => false]];

    app(StripeConnectService::class)->syncAccountStatus($this->store);

    $writes = $this->stripe->writes();

    expect($writes)->toHaveCount(1)
        ->and($writes[0]['path'])->toBe('/v1/payment_method_domains/pmd_old')
        ->and($writes[0]['params'])->toBe(['enabled' => 'true']);
});

test('a failed registration is logged and retried later without blocking the account sync', function () {
    Log::spy();
    $this->stripe->failDomainRequests = true;

    app(StripeConnectService::class)->syncAccountStatus($this->store);

    $store = $this->store->fresh();
    expect($store->stripe_charges_enabled)->toBeTrue()
        ->and($store->stripe_payment_method_domain)->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

test('changing a store\'s subdomain registers the new domain', function () {
    $this->store->forceFill([
        'stripe_charges_enabled' => true,
        'stripe_payment_method_domain' => 'riverside.example.com',
    ])->save();

    Livewire::test('pages::admin.stores.show', ['store' => $this->store])
        ->set('slug', 'lakeside')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->stripe->writes()[0]['params'])->toBe(['domain_name' => 'lakeside.example.com'])
        ->and($this->store->fresh()->stripe_payment_method_domain)->toBe('lakeside.example.com');
});

test('the command registers every store that can take charges', function () {
    $this->store->forceFill(['stripe_charges_enabled' => true])->save();
    Store::factory()->create(['slug' => 'not-connected']);

    $this->artisan('stripe:register-payment-domains')
        ->expectsOutputToContain('Registered riverside.example.com')
        ->assertSuccessful();

    expect($this->stripe->writes())->toHaveCount(1);
});
