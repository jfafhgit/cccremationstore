<?php

namespace App\Models;

use App\Enums\StoreUserRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A staff login's access to one location, and their role there.
 *
 * @property int $id
 * @property int $store_id
 * @property int $store_user_id
 * @property StoreUserRole $role
 */
class StoreMembership extends Pivot
{
    protected $table = 'store_memberships';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role' => StoreUserRole::class,
        ];
    }
}
