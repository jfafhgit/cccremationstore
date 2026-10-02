<?php

namespace App\Models;

use App\Enums\UsState;
use Database\Factories\StoreLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A city a store serves, which packages can be priced for individually.
 *
 * @property int $id
 * @property int $store_id
 * @property UsState $state
 * @property string $city
 */
class StoreLocation extends Model
{
    /** @use HasFactory<StoreLocationFactory> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'state',
        'city',
    ];

    protected function casts(): array
    {
        return [
            'state' => UsState::class,
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
     * E.g. "Springfield, IL".
     */
    public function label(): string
    {
        return "{$this->city}, {$this->state->value}";
    }
}
