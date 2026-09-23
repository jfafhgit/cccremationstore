<?php

namespace Database\Seeders;

use App\Enums\ProductCategory;
use App\Enums\StoreStatus;
use App\Enums\StoreUserRole;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one fully-populated demo funeral home store so the storefront,
 * admin dashboard, and staff portal all have something realistic to show
 * immediately after a fresh install — no manual data entry required.
 */
class TreasuredMemoriesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::updateOrCreate(
            ['slug' => 'sample'],
            [
                'name' => 'Chicagoland Cremation Care',
                'status' => StoreStatus::Active,
                'contact_name' => 'Morgan Reyes',
                'contact_email' => 'info@chicagolandcremationcare.test',
                'contact_phone' => '312-555-0142',
                'timezone' => 'America/Chicago',
                'platform_fee_bps' => 500,
                'tax_rate_bps' => 1025, // 10.25% — Chicago's combined sales tax rate.
                'requires_container' => true, // required by law for dignified handling.
                'brand_primary_color' => '#29564b',
            ],
        );

        StoreUser::updateOrCreate(
            ['email' => 'owner@chicagolandcremationcare.test'],
            [
                'store_id' => $store->id,
                'name' => 'Morgan Reyes',
                'password' => Hash::make('password'),
                'role' => StoreUserRole::Owner,
            ],
        );

        $this->seedPackages($store);
        $this->seedContainers($store);
        $this->seedUrns($store);
        $this->seedKeepsakes($store);

        $this->command?->info("Demo store ready: https://{$store->slug}.".config('app.root_domain').'  (staff portal login: owner@chicagolandcremationcare.test / password)');
    }

    private function seedPackages(Store $store): void
    {
        $packages = [
            [
                'name' => 'Direct Cremation',
                'description' => 'A simple, dignified cremation without a formal service. Includes transportation, filing of permits, and the cremation itself.',
                'price_cents' => 129500,
                'sort_order' => 1,
            ],
            [
                'name' => 'Cremation with Memorial Gathering',
                'description' => 'Includes everything in Direct Cremation, plus coordination of a memorial gathering at a time and place of your choosing.',
                'price_cents' => 249500,
                'sort_order' => 2,
            ],
            [
                'name' => 'Cremation with Visitation & Service',
                'description' => 'Our full-service option: a formal visitation, a service at our facility, and cremation to follow.',
                'price_cents' => 399500,
                'sort_order' => 3,
            ],
        ];

        foreach ($packages as $data) {
            Product::updateOrCreate(
                ['store_id' => $store->id, 'category' => ProductCategory::Package, 'slug' => str($data['name'])->slug()],
                [...$data, 'store_id' => $store->id, 'category' => ProductCategory::Package, 'is_active' => true],
            );
        }
    }

    private function seedContainers(Store $store): void
    {
        $containers = [
            [
                'name' => 'Alternative Container',
                'description' => 'A simple, sturdy container that meets all legal requirements for cremation. Included at no extra cost with every package.',
                'price_cents' => 0,
                'sort_order' => 1,
            ],
            [
                'name' => 'Cloth-Covered Container',
                'description' => 'A softly upholstered container, a gentle step up from the alternative container.',
                'price_cents' => 19500,
                'sort_order' => 2,
            ],
            [
                'name' => 'Hardwood Cremation Casket',
                'description' => 'A solid hardwood casket for families who wish to have a viewing before cremation.',
                'price_cents' => 89500,
                'sort_order' => 3,
            ],
        ];

        foreach ($containers as $data) {
            Product::updateOrCreate(
                ['store_id' => $store->id, 'category' => ProductCategory::Container, 'slug' => str($data['name'])->slug()],
                [...$data, 'store_id' => $store->id, 'category' => ProductCategory::Container, 'is_active' => true],
            );
        }
    }

    private function seedUrns(Store $store): void
    {
        $urns = [
            [
                'name' => 'Classic Brass Urn',
                'description' => 'A timeless, solid brass urn available in three finishes.',
                'price_cents' => 24500,
                'sort_order' => 1,
                'variants' => [
                    ['name' => 'Pewter finish', 'price_delta_cents' => 0],
                    ['name' => 'Gold finish', 'price_delta_cents' => 3000],
                    ['name' => 'Walnut finish', 'price_delta_cents' => 2000],
                ],
            ],
            [
                'name' => 'Wood Keepsake Urn',
                'description' => 'A handcrafted wooden urn, sized for a portion of ashes — often chosen alongside a larger urn.',
                'price_cents' => 8500,
                'sort_order' => 2,
                'variants' => [],
            ],
            [
                'name' => 'Biodegradable Urn',
                'description' => 'An eco-friendly urn made from natural materials, suitable for scattering or burial ceremonies.',
                'price_cents' => 12500,
                'sort_order' => 3,
                'variants' => [],
            ],
        ];

        foreach ($urns as $data) {
            $variants = $data['variants'];
            unset($data['variants']);

            $product = Product::updateOrCreate(
                ['store_id' => $store->id, 'category' => ProductCategory::Urn, 'slug' => str($data['name'])->slug()],
                [...$data, 'store_id' => $store->id, 'category' => ProductCategory::Urn, 'is_active' => true],
            );

            foreach ($variants as $index => $variant) {
                ProductVariant::updateOrCreate(
                    ['product_id' => $product->id, 'name' => $variant['name']],
                    [...$variant, 'sort_order' => $index],
                );
            }
        }
    }

    private function seedKeepsakes(Store $store): void
    {
        $keepsakes = [
            [
                'name' => 'Thumbprint Charm',
                'description' => 'A sterling silver charm cast from a loved one\'s thumbprint.',
                'price_cents' => 6500,
                'sort_order' => 1,
            ],
            [
                'name' => 'Cremation Jewelry Pendant',
                'description' => 'A small pendant designed to hold a keepsake portion of ashes.',
                'price_cents' => 8900,
                'sort_order' => 2,
            ],
            [
                'name' => 'Memorial Photo Book',
                'description' => 'A guided, keepsake photo book for gathering memories and photos.',
                'price_cents' => 4500,
                'sort_order' => 3,
            ],
        ];

        foreach ($keepsakes as $data) {
            Product::updateOrCreate(
                ['store_id' => $store->id, 'category' => ProductCategory::Keepsake, 'slug' => str($data['name'])->slug()],
                [...$data, 'store_id' => $store->id, 'category' => ProductCategory::Keepsake, 'is_active' => true, 'allow_multiple_quantity' => true],
            );
        }
    }
}
