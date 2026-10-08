<?php

namespace App\Filament\Resources\Posts\Schemas;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Rules\HouseStyle;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
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
                            ->live(),
                        DateTimePicker::make('published_at')
                            ->label('Published')
                            ->seconds(false)
                            ->helperText('Leave it empty and it takes the moment you publish.'),
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
                        TextInput::make('author')
                            ->required()
                            ->maxLength(255)
                            ->default(Post::DEFAULT_AUTHOR)
                            ->rules([HouseStyle::text()]),
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
