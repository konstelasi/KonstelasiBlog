<?php

namespace App\Filament\Widgets;

use App\Support\DashboardOverview;
use Filament\Widgets\Widget;

/**
 * Where to pick up. The draft the account edited last is shown large, with
 * what is left to do on it, and the rest of their drafts follow in a short
 * list. Everyone with a role has drafts of their own, so everyone sees it.
 */
class YourDrafts extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected string $view = 'filament.widgets.your-drafts';

    protected function getViewData(): array
    {
        $overview = new DashboardOverview(auth()->user());

        return ['drafts' => $overview->yourDrafts(), 'publisher' => $overview->isPublisher()];
    }
}
