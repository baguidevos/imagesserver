<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Artisan;

class Dashboard extends BaseDashboard
{
    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('runMigrations')
                ->label('Exécuter les migrations')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Exécuter les migrations de base de données')
                ->modalDescription('Cette action va exécuter toutes les migrations Laravel en attente.')
                ->modalSubmitActionLabel('Confirmer et migrer')
                ->action(function () {
                    Artisan::call('migrate', ['--force' => true]);
                    $migrationOutput = trim(Artisan::output());

                    Notification::make()
                        ->title('Migrations exécutées')
                        ->body($migrationOutput ?: 'Aucune migration en attente.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
