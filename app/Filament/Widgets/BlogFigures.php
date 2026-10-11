<?php

namespace App\Filament\Widgets;

use App\Support\DashboardOverview;
use Filament\Widgets\Widget;

/** The blog in one ruled line: how much is live, how much is waiting, and when it last moved. */
class BlogFigures extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected string $view = 'filament.widgets.blog-figures';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return ['overview' => new DashboardOverview(auth()->user())];
    }
}
