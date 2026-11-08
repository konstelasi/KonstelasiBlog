<?php

namespace App\Filament\Widgets;

use App\Support\DashboardOverview;
use App\Support\Rbac;
use Filament\Widgets\Widget;

/**
 * Other people's drafts that wait for someone who can publish. It is for
 * publishers only, since a Writer's own drafts, with what is left on each,
 * are on their desk. It sits in the side column.
 */
class NeedsDecision extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected string $view = 'filament.widgets.needs-decision';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can(Rbac::POST_PUBLISH);
    }

    protected function getViewData(): array
    {
        $overview = new DashboardOverview(auth()->user());

        return [
            'ready' => $overview->readyToPublish(),
            'halfDone' => $overview->halfDone(),
        ];
    }
}
