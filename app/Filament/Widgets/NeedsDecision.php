<?php

namespace App\Filament\Widgets;

use App\Support\DashboardOverview;
use Filament\Widgets\Widget;

/**
 * Drafts that need someone. A publisher sees everyone's, a writer only their
 * own. It draws nothing when there is nothing to do.
 */
class NeedsDecision extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.needs-decision';

    protected int|string|array $columnSpan = ['default' => 1, 'lg' => 2];

    protected function getViewData(): array
    {
        $overview = new DashboardOverview(auth()->user());

        return [
            'publisher' => $overview->isPublisher(),
            'ready' => $overview->readyToPublish(),
            'halfDone' => $overview->halfDone(),
        ];
    }
}
