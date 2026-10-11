<?php

namespace App\Filament\Resources\Tags\Schemas;

use App\Models\Tag;
use App\Rules\HouseStyle;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(self::fields());
    }

    /**
     * The fields on their own, so the post form can offer the same ones in
     * its "create tag" dialog.
     *
     * @return list<TextInput>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name_en')
                ->label('English name')
                ->required()
                ->maxLength(255)
                ->rules([HouseStyle::title()])
                // The English name drives the slug, until the slug is locked or
                // someone has typed their own.
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state, ?Tag $record): void {
                    if ($record?->slugIsLocked()) {
                        return;
                    }
                    if (blank($get('slug')) || $get('slug') === Str::slug((string) $old)) {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('name_id')
                ->label('Indonesian name')
                ->required()
                ->maxLength(255)
                ->rules([HouseStyle::title()])
                ->helperText('Often the same word. Write it the way Indonesian readers say it.'),
            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->validationMessages([
                    'regex' => 'Use lowercase letters, digits and single hyphens only.',
                ])
                // The slug is the tag page's address on konstelasi.co.id, in both
                // languages. Once a live post carries the tag, changing it would
                // break that link.
                ->disabled(fn (?Tag $record): bool => $record?->slugIsLocked() ?? false)
                ->helperText(fn (?Tag $record): string => $record?->slugIsLocked()
                    ? 'Locked, because a live post carries this tag and its page may be live at this address.'
                    : 'Filled in from the English name. It becomes the address of the tag page, so check it.'),
        ];
    }
}
