<?php

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Bind a Store as the "current tenant" the way the IdentifyStore middleware
 * does for a real subdomain request. Storefront/portal Livewire components
 * are exercised directly via Livewire::test() in these tests rather than
 * through real HTTP calls to a "{slug}.example.test" host, so this
 * reproduces what that middleware would have already set up by the time a
 * component mounts: the resolved Store instance, and the "store" URL
 * default every named route on that subdomain requires.
 */
function actingAsTenant(Store $store): void
{
    app()->instance(Store::class, $store);
    URL::defaults(['store' => $store->slug]);
}

/**
 * Stands in for Stripe's HTTP API so the real SDK code paths run, while
 * letting each test decide what state a PaymentIntent and its refunds are in.
 */
function fakeStripe(): object
{
    $fake = new class implements ClientInterface
    {
        /** @var array<string, array<string, mixed>> */
        public array $intents = [];

        /** @var array<string, array<string, mixed>> */
        public array $refunds = [];

        /** @var array<string, array<string, mixed>> */
        public array $applicationFees = [];

        /** @var array<int, array{method: string, path: string, params: array<string, mixed>}> */
        public array $requests = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $path = parse_url($absUrl, PHP_URL_PATH);
            $params = is_array($params) ? $params : [];
            $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params];

            if ($path === '/v1/refunds') {
                return [json_encode($method === 'post' ? $this->createRefund($params) : $this->listRefunds($params)), 200, []];
            }

            if (preg_match('#^/v1/application_fees/([^/]+)(/refunds)?$#', $path, $matches)) {
                $fee = &$this->applicationFees[$matches[1]];

                if (isset($matches[2])) {
                    $fee['amount_refunded'] += (int) $params['amount'];

                    return [json_encode(['id' => 'fr_'.count($this->requests), 'object' => 'fee_refund', 'amount' => (int) $params['amount'], 'fee' => $matches[1]]), 200, []];
                }

                return [json_encode($fee), 200, []];
            }

            if ($method === 'post' && $path === '/v1/payment_intents') {
                $id = 'pi_'.count($this->intents);
                $this->intents[$id] = [
                    'id' => $id,
                    'object' => 'payment_intent',
                    'status' => 'requires_payment_method',
                    'client_secret' => "{$id}_secret",
                    'amount_received' => 0,
                    ...$params,
                ];
            } else {
                $id = basename($path);

                if ($method === 'post') {
                    $this->intents[$id] = [...$this->intents[$id], ...$params];
                }
            }

            return [json_encode($this->intents[$id]), 200, []];
        }

        public function intent(string $id, array $attributes): void
        {
            $this->intents[$id] = [
                'id' => $id,
                'object' => 'payment_intent',
                'currency' => 'usd',
                'client_secret' => "{$id}_secret",
                'amount_received' => 0,
                ...$attributes,
            ];
        }

        /**
         * A refund that already exists in Stripe, e.g. one issued from the dashboard.
         */
        public function refund(string $id, string $paymentIntent, int $amount, string $status = 'succeeded'): void
        {
            $this->refunds[$id] = ['id' => $id, 'object' => 'refund', 'payment_intent' => $paymentIntent, 'amount' => $amount, 'status' => $status];
        }

        /**
         * A paid intent whose charge carried the platform's application fee.
         */
        public function paidWithFee(string $intentId, string $feeId, int $feeAmount): void
        {
            $this->intent($intentId, [
                'status' => 'succeeded',
                'latest_charge' => ['id' => 'ch_'.$intentId, 'object' => 'charge', 'application_fee' => $feeId],
            ]);
            $this->applicationFees[$feeId] = ['id' => $feeId, 'object' => 'application_fee', 'amount' => $feeAmount, 'amount_refunded' => 0];
        }

        public function updatesTo(string $id): array
        {
            return array_values(array_filter(
                $this->requests,
                fn (array $request): bool => $request['method'] === 'post' && $request['path'] === "/v1/payment_intents/{$id}",
            ));
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        public function refundRequests(): array
        {
            return array_values(array_filter(
                $this->requests,
                fn (array $request): bool => $request['method'] === 'post' && $request['path'] === '/v1/refunds',
            ));
        }

        /**
         * @param  array<string, mixed>  $params
         * @return array<string, mixed>
         */
        private function createRefund(array $params): array
        {
            $id = 're_'.count($this->refunds);
            $this->refund($id, $params['payment_intent'], (int) $params['amount']);

            return $this->refunds[$id];
        }

        /**
         * @param  array<string, mixed>  $params
         * @return array<string, mixed>
         */
        private function listRefunds(array $params): array
        {
            return [
                'object' => 'list',
                'url' => '/v1/refunds',
                'has_more' => false,
                'data' => array_values(array_filter(
                    $this->refunds,
                    fn (array $refund): bool => $refund['payment_intent'] === ($params['payment_intent'] ?? null),
                )),
            ];
        }
    };

    ApiRequestor::setHttpClient($fake);

    return $fake;
}

function stripeEvent(string $type, string $account, array $object): array
{
    return [
        'id' => 'evt_test',
        'object' => 'event',
        'type' => $type,
        'account' => $account,
        'data' => ['object' => $object],
    ];
}
