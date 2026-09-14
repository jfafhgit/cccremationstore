<?php

namespace App\Models;

use App\Enums\ProductCategory;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $store_id
 * @property ProductCategory $category
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $price_cents
 * @property bool $is_taxable
 * @property string|null $image_path
 * @property bool $is_active
 * @property int $sort_order
 * @property bool $allow_multiple_quantity
 * @property bool $requires_engraving
 * @property bool $available_for_immediate
 * @property bool $available_for_pre_need
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'category',
        'name',
        'slug',
        'description',
        'price_cents',
        'is_taxable',
        'image_path',
        'is_active',
        'sort_order',
        'allow_multiple_quantity',
        'requires_engraving',
        'available_for_immediate',
        'available_for_pre_need',
    ];

    protected function casts(): array
    {
        return [
            'category' => ProductCategory::class,
            'price_cents' => 'integer',
            'is_taxable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'allow_multiple_quantity' => 'boolean',
            'requires_engraving' => 'boolean',
            'available_for_immediate' => 'boolean',
            'available_for_pre_need' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    #[Scope]
    protected function ofCategory(Builder $query, ProductCategory $category): Builder
    {
        return $query->where('category', $category);
    }

    #[Scope]
    protected function availableForTiming(Builder $query, string $timing): Builder
    {
        return $timing === 'pre_need'
            ? $query->where('available_for_pre_need', true)
            : $query->where('available_for_immediate', true);
    }

    public function priceInDollars(): string
    {
        return number_format($this->price_cents / 100, 2);
    }

    /**
     * Public URL for a stored product image path (as saved on this model,
     * or snapshotted onto a Cart line/order item), or null if there isn't
     * one. Centralized here since every one of those callers needs the same
     * "public" disk resolved the same way.
     */
    public static function imageUrlFor(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    public function imageUrl(): ?string
    {
        return static::imageUrlFor($this->image_path);
    }
}
