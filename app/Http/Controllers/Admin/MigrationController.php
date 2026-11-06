<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

class MigrationController extends Controller
{
    public function __invoke(Request $request): Response|JsonResponse
    {
        $isAuthorizedAdmin = auth('admin')->check();
        $configuredMigrationSecret = (string) config('app.migration_secret');
        $providedSecurityToken = (string) $request->query('token', '');

        $hasValidToken = $configuredMigrationSecret !== ''
            && hash_equals($configuredMigrationSecret, $providedSecurityToken);

        if (! $isAuthorizedAdmin && ! $hasValidToken) {
            abort(403, 'Accès interdit. Veuillez vous connecter au panel admin ou fournir un token valide.');
        }

        Artisan::call('migrate', ['--force' => true]);
        $migrationOutput = trim(Artisan::output());

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Migrations exécutées avec succès.',
                'output' => $migrationOutput ?: 'Aucune migration en attente.',
            ]);
        }

        $htmlContent = $this->renderHtmlReport($migrationOutput ?: 'Aucune migration en attente.');

        return response($htmlContent);
    }

    private function renderHtmlReport(string $output): string
    {
        $escapedOutput = htmlspecialchars($output, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Migrations — Serveur d'images</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 2rem; margin: 0; }
                .container { max-width: 720px; margin: 0 auto; background: #1e293b; border-radius: 12px; padding: 2rem; border: 1px solid #334155; }
                h1 { margin-top: 0; font-size: 1.5rem; display: flex; align-items: center; gap: 0.5rem; color: #38bdf8; }
                pre { background: #0b1120; padding: 1.25rem; border-radius: 8px; overflow-x: auto; font-family: monospace; font-size: 0.9rem; color: #a5f3fc; border: 1px solid #1e293b; }
                .btn { display: inline-block; margin-top: 1.5rem; background: #38bdf8; color: #0f172a; font-weight: 600; padding: 0.6rem 1.2rem; border-radius: 8px; text-decoration: none; }
                .btn:hover { background: #7dd3fc; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>✓ Migrations exécutées</h1>
                <p>Résultat de l'exécution de <code>php artisan migrate --force</code> :</p>
                <pre>{$escapedOutput}</pre>
                <a href="/admin" class="btn">← Retour au tableau de bord Admin</a>
            </div>
        </body>
        </html>
        HTML;
    }
}
