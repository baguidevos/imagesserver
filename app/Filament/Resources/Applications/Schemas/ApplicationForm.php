<?php

namespace App\Filament\Resources\Applications\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ApplicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informations de l\'application')
                    ->description('Détails de l\'application mobile Flutter cliente.')
                    ->components([
                        TextInput::make('name')
                            ->label('Nom de l\'application')
                            ->placeholder('Ex: Mon Application Flutter')
                            ->required()
                            ->maxLength(120)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, ?string $state, Set $set): void {
                                if ($operation === 'create' && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->label('Identifiant URL (Slug)')
                            ->placeholder('Ex: mon-application-flutter')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(120)
                            ->helperText('Identifiant unique servant pour les dossiers de stockage et l\'API.'),

                        Toggle::make('is_active')
                            ->label('Application active')
                            ->helperText('Si désactivée, les requêtes avec la clé API de cette application seront rejetées (401).')
                            ->default(true),
                    ]),
            ]);
    }
}
