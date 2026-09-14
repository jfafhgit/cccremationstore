<?php

namespace App\Models;

use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A "request a demo" / contact submission from the root marketing site.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $funeral_home_name
 * @property string|null $message
 * @property Carbon|null $contacted_at
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'funeral_home_name',
        'message',
        'contacted_at',
    ];

    protected function casts(): array
    {
        return [
            'contacted_at' => 'datetime',
        ];
    }
}
