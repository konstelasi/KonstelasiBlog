<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Filament's dashboard with a three column grid from `lg` up, one column on a
 * phone. The widgets set their own spans so that no two rows share a shape.
 */
class Dashboard extends BaseDashboard
{
    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 3];
    }
}
