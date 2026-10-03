<?php

namespace App\Enums;

/**
 * Cases are declared in the order the storefront wizard presents them,
 * which the admin products page follows too.
 */
enum ProductCategory: string
{
    case Package = 'package';
    case Container = 'container';
    case Urn = 'urn';
    case Addon = 'addon';
    case Choice = 'choice';
    case Keepsake = 'keepsake';

    public function label(): string
    {
        return match ($this) {
            self::Package => 'Package',
            self::Container => 'Cremation Container',
            self::Urn => 'Urn',
            self::Addon => 'Add-on / Service',
            self::Choice => 'Choose-One Item',
            self::Keepsake => 'Keepsake',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Addon => 'Add-ons & Services',
            default => $this->label().'s',
        };
    }
}
