<?php

namespace App\Enums;

enum OrderSource: string
{
    case Storefront = 'storefront';
    case Embed = 'embed';
}
