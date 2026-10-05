<?php

namespace App\Enums;

/**
 * Where a duplicated store is in copying its products and images from the
 * original, which happens in the background.
 */
enum CatalogCopyStatus: string
{
    case Copying = 'copying';
    case Failed = 'failed';
}
