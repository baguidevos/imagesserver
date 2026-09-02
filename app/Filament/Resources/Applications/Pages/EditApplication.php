<?php

namespace App\Filament\Resources\Applications\Pages;

use App\Filament\Resources\Applications\ApplicationResource;
use App\Models\Application;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class EditApplication extends EditRecord
{
    protected static string $resource = ApplicationResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('regenerateApiKey')
                ->label('Régénérer la clé API')
                ->icon(Heroicon::OutlinedKey)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Régénérer la clé API')
                ->modalDescription(fn (Application $record): string => "Attention : La clé API de l'application \"{$record->name}\" sera immédiatement remplacée. L'ancienne clé ne fonctionnera plus.")
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

            DeleteAction::make(),
        ];
    }
}
