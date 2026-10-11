<?php

namespace App\Filament\Widgets;

use App\Support\DashboardOverview;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/** Live posts per month for the last year, as plain bars with the numbers beside them. */
class PublishingRhythm extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected string $view = 'filament.widgets.publishing-rhythm';

    protected function getViewData(): array
    {
        $months = collect((new DashboardOverview(auth()->user()))->monthlyPublished())
            ->map(fn (int $count, string $key): array => [
                'label' => Carbon::parse("{$key}-01")->format('M'),
                'year' => Carbon::parse("{$key}-01")->format('Y'),
                'count' => $count,
            ])
            ->values();

        return ['months' => $months, 'max' => max(1, $months->max('count')), 'total' => $months->sum('count')];
    }
}
