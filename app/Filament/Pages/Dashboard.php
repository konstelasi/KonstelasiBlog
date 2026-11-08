<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Filament\Widgets\BlogFigures;
use App\Filament\Widgets\NeedsDecision;
use App\Filament\Widgets\PublishingRhythm;
use App\Filament\Widgets\SiteStatus;
use App\Filament\Widgets\TwoFactorAdoption;
use App\Filament\Widgets\YourDrafts;
use App\Filament\Widgets\YourLivePosts;
use App\Models\Post;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A writing desk. The wide column is the signed-in account's own work, the
 * draft to pick up and the posts that are live, and the narrow column beside
 * it holds what concerns the blog as a whole, which is what waits for a
 * publisher, the state of the site, the figures and the sign-in list. A phone
 * gets one column, the desk first.
 *
 * Each widget decides who sees it in its own `canView()`.
 */
class Dashboard extends BaseDashboard
{
    /** @var list<class-string> */
    private const DESK = [YourDrafts::class, YourLivePosts::class];

    /** @var list<class-string> */
    private const SIDE = [NeedsDecision::class, SiteStatus::class, BlogFigures::class, PublishingRhythm::class, TwoFactorAdoption::class];

    public function getWidgets(): array
    {
        return [...self::DESK, ...self::SIDE];
    }

    public function getHeading(): string|Htmlable
    {
        return 'Your desk';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newPost')
                ->label('New post')
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => PostResource::getUrl('create'))
                ->visible(fn (): bool => (bool) auth()->user()?->can('create', Post::class)),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Flex::make([
                    Group::make()
                        ->schema(fn (): array => $this->getWidgetsSchemaComponents(self::DESK))
                        ->extraAttributes(['class' => 'desk-main']),
                    Group::make()
                        ->schema(fn (): array => $this->getWidgetsSchemaComponents(self::SIDE))
                        ->extraAttributes(['class' => 'desk-side'])
                        ->grow(false),
                ])->from('lg'),
            ]);
    }
}
