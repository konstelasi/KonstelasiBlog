<?php

namespace App\Filament\Widgets;

use App\Support\DashboardOverview;
use App\Support\Rbac;
use Filament\Widgets\Widget;

/** For an Admin, which accounts still sign in with a password alone. */
class TwoFactorAdoption extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected string $view = 'filament.widgets.two-factor-adoption';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can(Rbac::USER_MANAGE);
    }

    protected function getViewData(): array
    {
        $overview = new DashboardOverview(auth()->user());

        return ['total' => $overview->accountCount(), 'without' => $overview->withoutTwoFactor()];
    }
}
