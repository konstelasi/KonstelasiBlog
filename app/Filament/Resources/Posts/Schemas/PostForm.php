<?php

namespace App\Filament\Resources\Posts\Schemas;

use App\Enums\PostStatus;
use App\Filament\Resources\Tags\Schemas\TagForm;
use App\Models\Post;
use App\Models\Tag;
use App\Rules\HouseStyle;
use App\Support\Rbac;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PostForm
{
    private const LANGUAGE_NAMES = ['en' => 'English', 'id' => 'Indonesian'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Publishing')
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('status')
                            ->options(PostStatus::class)
                            ->default(PostStatus::Draft)
                            ->inline()
                            ->required()
                            // Publishing makes every language field required, so the
                            // form has to know the moment the status changes.
                            ->live()
                            // Only the UI. The create and edit pages and the post
                            // observer enforce it, since form state can be sent by hand.
                            ->disabled(fn (): bool => ! self::mayPublish())
                            ->helperText(fn (): ?string => self::mayPublish()
                                ? null
                                : 'Only an Editor or an Admin can publish. Save your draft and ask one of them.'),
                        DateTimePicker::make('published_at')
                            ->label('Published')
                            ->seconds(false)
                            ->disabled(fn (): bool => ! self::mayPublish())
                            ->helperText(fn (): string => self::mayPublish()
                                ? 'Leave it empty and it takes the moment you publish.'
                                : 'Set by an Editor or an Admin when they publish.'),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                            ->validationMessages([
                                'regex' => 'Use lowercase letters, digits and single hyphens only.',
                            ])
                            // The slug is the post's URL on konstelasi.co.id. Once the
                            // post has gone out, changing it would break that link.
                            ->disabled(fn (?Post $record): bool => $record?->slugIsLocked() ?? false)
                            ->helperText(fn (?Post $record): string => $record?->slugIsLocked()
                                ? 'Locked, because the post is live at this address.'
                                : 'Filled in from the English title. It becomes the URL, so check it before you publish.'),
                        // Never typed. A new post takes the name of the account that
                        // creates it (see CreatePost), and the public byline follows
                        // that account's name. Imported posts show their stored text.
                        TextInput::make('author')
                            ->label('Written by')
                            ->default(fn (): string => auth()->user()?->name ?? Post::DEFAULT_AUTHOR)
                            ->formatStateUsing(fn (?Post $record, ?string $state): ?string => $record?->byline() ?? $state)
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Taken from the account that created the post, and shown to readers as the author.'),
                    ]),
                Section::make('Tags')
                    ->schema([
                        // Both languages share the tags, each tag having a name in
                        // each. Not required, since a post reads fine without any.
                        Select::make('tags')
                            ->label('Tags')
                            ->relationship('tags', 'name_en')
                            ->getOptionLabelFromRecordUsing(fn (Tag $tag): string => $tag->name_en === $tag->name_id
                                ? $tag->name_en
                                : "{$tag->name_en} / {$tag->name_id}")
                            ->multiple()
                            ->preload()
                            ->searchable(['name_en', 'name_id'])
                            ->maxItems(6)
                            // A new tag is a decision about the vocabulary of the
                            // site, so only a person who manages tags may add one here.
                            ->createOptionForm(self::mayManageTags() ? TagForm::fields() : null)
                            ->helperText(self::mayManageTags()
                                ? 'Up to six. A tag gets its own page on the site once two live posts carry it.'
                                : 'Up to six. Ask an Editor or an Admin if a tag is missing.'),
                    ]),
                Tabs::make('Languages')
                    ->tabs(array_map(
                        fn (string $lang): Tab => self::languageTab($lang),
                        Post::LANGUAGES,
                    )),
            ]);
    }

    private static function languageTab(string $lang): Tab
    {
        $title = TextInput::make("title_{$lang}")
            ->label('Title')
            ->maxLength(255)
            ->required(fn (Get $get): bool => self::publishing($get))
            ->rules([HouseStyle::title()])
            ->validationMessages(self::requiredMessage());

        // The English title drives the slug, until the slug is locked or
        // someone has typed their own.
        if ($lang === 'en') {
            $title->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state, ?Post $record): void {
                    if ($record?->slugIsLocked()) {
                        return;
                    }
                    if (blank($get('slug')) || $get('slug') === Str::slug((string) $old)) {
                        $set('slug', Str::slug((string) $state));
                    }
                });
        }

        return Tab::make(self::LANGUAGE_NAMES[$lang])
            ->schema([
                $title,
                Textarea::make("description_{$lang}")
                    ->label('Description')
                    // A hint only. Link previews tend to cut the text off near 160
                    // characters, but a longer description still saves.
                    ->helperText(fn (Get $get): string => 'One or two sentences, shown in the post list and in link previews. '
                        .mb_strlen((string) $get("description_{$lang}")).' of about 160 characters.')
                    ->live(debounce: 500)
                    ->rows(2)
                    ->required(fn (Get $get): bool => self::publishing($get))
                    ->rules([HouseStyle::text()])
                    ->validationMessages(self::requiredMessage()),
                MarkdownEditor::make("body_{$lang}")
                    ->label('Body')
                    // Sent when the cursor leaves the editor, not on every key,
                    // because the body is long.
                    ->helperText(fn (Get $get): string => self::readingFigures((string) $get("body_{$lang}")))
                    ->live(onBlur: true)
                    ->required(fn (Get $get): bool => self::publishing($get))
                    ->rules([HouseStyle::markdown()])
                    ->validationMessages(self::requiredMessage())
                    // Served from blog.konstelasi.co.id/storage/posts/... once
                    // `php artisan storage:link` has run on the host.
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('posts'),
            ]);
    }

    private static function readingFigures(string $body): string
    {
        $words = Post::wordCount($body);
        $minutes = Post::readingMinutes($body);

        return sprintf('%s %s, about %d %s to read.', number_format($words), $words === 1 ? 'word' : 'words', $minutes, $minutes === 1 ? 'minute' : 'minutes');
    }

    /** Whether the signed-in account may set the status and the date. */
    private static function mayPublish(): bool
    {
        return auth()->user()?->can('publish', Post::class) ?? false;
    }

    /** Whether the signed-in account may add tags from the post form. */
    private static function mayManageTags(): bool
    {
        return auth()->user()?->can(Rbac::TAG_MANAGE) ?? false;
    }

    /** Whether the form is about to publish the post. */
    private static function publishing(Get $get): bool
    {
        $status = $get('status');

        return ($status instanceof PostStatus ? $status : PostStatus::tryFrom((string) $status)) === PostStatus::Published;
    }

    /** @return array<string, string> */
    private static function requiredMessage(): array
    {
        return ['required' => 'A published post needs this field in English and in Indonesian.'];
    }
}
