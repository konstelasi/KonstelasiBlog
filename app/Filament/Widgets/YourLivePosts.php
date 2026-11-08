<?php

namespace App\Filament\Widgets;

use App\Support\DashboardOverview;
use Filament\Widgets\Widget;

/** The account's own posts that readers can see now, newest first, with links to their pages on the site. */
class YourLivePosts extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.your-live-posts';

    protected function getViewData(): array
    {
        $overview = new DashboardOverview(auth()->user());

        return ['posts' => $overview->yourLivePosts(), 'total' => $overview->yourLiveCount()];
    }
}
