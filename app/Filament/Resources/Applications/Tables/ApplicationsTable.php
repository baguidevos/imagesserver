<?php

namespace App\Filament\Resources\Applications\Tables;

use App\Models\Application;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Application')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->copyMessage('Slug copié')
                    ->searchable(),

                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->sortable(),

                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Utilisateurs')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('images_count')
                    ->counts('images')
                    ->label('Images')
                    ->badge()
                    ->color('success')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Date de création')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Statut')
                    ->trueLabel('Actives uniquement')
                    ->falseLabel('Inactives uniquement'),
            ])
            ->recordActions([
                Action::make('regenerateApiKey')
                    ->label('Régénérer clé')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Régénérer la clé API')
                    ->modalDescription(fn (Application $record): string => "Attention : La clé API actuelle de l'application \"{$record->name}\" sera immédiatement invalidée. Toutes les requêtes Flutter avec l'ancienne clé échoueront.")
                    ->modalSubmitActionLabel('Confirmer et régénérer')
                    ->action(function (Application $record): void {
                        $plainKey = 'app_'.bin2hex(random_bytes(32));

                        $record->update([
                            'api_key_hash' => hash('sha256', $plainKey),
                        ]);

                        Notification::make()
                            ->title("Nouvelle clé API : {$record->name}")
                            ->body(new HtmlString(
                                '<div style="margin-top: 8px;">'.
                                '<p style="margin-bottom: 6px;">Copiez la nouvelle clé ci-dessous :</p>'.
                                '<div style="padding: 10px; background: rgba(0,0,0,0.06); border-radius: 6px; font-family: monospace; font-size: 13px; word-break: break-all; user-select: all; font-weight: bold; border: 1px dashed #f59e0b;">'.
                                e($plainKey).
                                '</div>'.
                                '<p style="margin-top: 8px; font-size: 11px; color: #ef4444; font-weight: bold;">⚠ Sauvegardez cette clé maintenant. Elle ne pourra plus jamais être réaffichée.</p>'.
                                '</div>'
                            ))
                            ->warning()
                            ->persistent()
                            ->send();
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
