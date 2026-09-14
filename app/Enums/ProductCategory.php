<?php

namespace App\Enums;

enum ProductCategory: string
{
    case Package = 'package';
    case Container = 'container';
    case Urn = 'urn';
    case Keepsake = 'keepsake';
    case Addon = 'addon';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Package => 'Package',
            self::Container => 'Cremation Container',
            self::Urn => 'Urn',
            self::Keepsake => 'Keepsake',
            self::Addon => 'Add-on',
            self::Service => 'Service',
        };
    }
}
