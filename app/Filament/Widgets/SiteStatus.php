<?php

namespace App\Filament\Widgets;

use App\Jobs\RebuildSite;
use App\Support\Rbac;
use Filament\Widgets\Widget;

/**
 * When the public site was last asked to rebuild. It says "asked", because
 * the request to GitHub going through says nothing about how the workflow
 * ends. A failed request has its own banner on every page.
 */
class SiteStatus extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected string $view = 'filament.widgets.site-status';

    protected int|string|array $columnSpan = ['default' => 1, 'lg' => 2];

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can(Rbac::POST_PUBLISH);
    }

    protected function getViewData(): array
    {
        return [
            'enabled' => filled(config('services.github.token')),
            'requestedAt' => RebuildSite::requestedAt(),
            'actionsUrl' => sprintf(
                'https://github.com/%s/actions/workflows/%s',
                config('services.github.repo'),
                config('services.github.workflow'),
            ),
        ];
    }
}
