<?php

namespace App\Filament\Resources\Applications\Pages;

use App\Filament\Resources\Applications\ApplicationResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\HtmlString;

class CreateApplication extends CreateRecord
{
    protected static string $resource = ApplicationResource::class;

    public ?string $generatedApiKey = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $plainKey = 'app_'.bin2hex(random_bytes(32));

        $data['api_key_hash'] = hash('sha256', $plainKey);
        $this->generatedApiKey = $plainKey;

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->generatedApiKey) {
            Notification::make()
                ->title("Application \"{$this->record->name}\" créée !")
                ->body(new HtmlString(
                    '<div style="margin-top: 8px;">'.
                    '<p style="margin-bottom: 6px;">Voici votre clé API secrète :</p>'.
                    '<div style="padding: 10px; background: rgba(0,0,0,0.06); border-radius: 6px; font-family: monospace; font-size: 13px; word-break: break-all; user-select: all; font-weight: bold; border: 1px dashed #10b981;">'.
                    e($this->generatedApiKey).
                    '</div>'.
                    '<p style="margin-top: 8px; font-size: 11px; color: #ef4444; font-weight: bold;">⚠ Copiez cette clé maintenant dans votre code Flutter. Elle ne pourra plus jamais être affichée.</p>'.
                    '</div>'
                ))
                ->success()
                ->persistent()
                ->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
