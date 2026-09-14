<?php

namespace App\Enums;

enum StoreUserRole: string
{
    case Owner = 'owner';
    case Staff = 'staff';
}
