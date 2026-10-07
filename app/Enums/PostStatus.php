<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PostStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Published = 'published';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }

    // Violet marks the active state on this site, and a published post is
    // the one that is live.
    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'primary',
        };
    }
}
