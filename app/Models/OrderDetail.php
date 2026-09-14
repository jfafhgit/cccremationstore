<?php

namespace App\Models;

use Database\Factories\OrderDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The longer-form intake information collected after payment is secured.
 *
 * @property int $id
 * @property int $order_id
 */
class OrderDetail extends Model
{
    /** @use HasFactory<OrderDetailFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'date_of_birth',
        'date_of_death',
        'place_of_death',
        'veteran_status',
        'marital_status',
        'obituary_text',
        'service_preferences',
        'additional_notes',
        'answers',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'date_of_death' => 'date',
            'veteran_status' => 'boolean',
            'answers' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
