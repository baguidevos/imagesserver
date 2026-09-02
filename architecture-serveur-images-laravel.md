# Serveur centralisé d’images Laravel pour plusieurs applications Flutter

## Plan d’implémentation final — Spatie Media Library dès le départ

Cette version du plan adopte **Spatie Media Library** comme couche de gestion de médias. Ne créez donc pas la migration ni le modèle `Image` présentés dans une précédente ébauche : la migration `media` et le modèle `Media` de Spatie les remplacent.

```text
Application Flutter (X-Application-Key)
        ↓
Utilisateur Laravel (application_id + token Sanctum)
        ↓
Media Spatie attaché au User (collection images / avatar)
        ↓
Disque Laravel privé « images » → futur S3 ou Cloudflare R2
```

### Étape 1 — Créer le projet et les fondations

1. Créer Laravel et installer Sanctum avec `php artisan install:api`.
2. Créer les tables `applications` et `users` avec `users.application_id`.
3. Installer Spatie et publier sa migration/configuration :

```bash
composer require spatie/laravel-medialibrary
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-config"
php artisan migrate
```

### Étape 2 — Configurer le stockage privé

Ajoutez le disque `images` dans `config/filesystems.php` :

```php
'images' => [
    'driver' => 'local',
    'root' => storage_path('app/private/images'),
    'throw' => true,
],
```

Puis définissez `images` comme disque par défaut de Media Library dans `config/media-library.php` :

```php
'disk_name' => 'images',
```

Il n’y a pas de `storage:link` à créer : les fichiers restent hors du dossier public et sont servis uniquement par une route authentifiée.

### Étape 3 — Attacher les médias aux utilisateurs

Dans `app/Models/User.php`, implémentez `HasMedia` :

```php
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class User extends Authenticatable implements HasMedia
{
    use HasApiTokens, Notifiable, InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk('images')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection('avatar')
            ->useDisk('images')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(400)->height(400)->format('webp')->queued();
    }
}
```

### Étape 4 — Conserver l’isolation de sécurité

Les middlewares `application` et `token.application` restent obligatoires. Ils vérifient successivement :

1. la validité de `X-Application-Key` ;
2. l’existence du token Sanctum ;
3. que `user.application_id` correspond à l’application trouvée avec la clé.

Une fois ce contrôle réalisé, on ne récupère jamais un `Media` seulement par son ID : toutes les requêtes sont limitées à l’utilisateur connecté.

### Étape 5 — Remplacer le contrôleur d’upload

Le contrôleur n’écrit plus dans `ImageStorageService`. Pour l’upload :

```php
$media = $request->user()
    ->addMediaFromRequest('image')
    ->withCustomProperties(['application_id' => $request->user()->application_id])
    ->toMediaCollection('images');
```

Pour retrouver un élément demandé par son ID en sécurité :

```php
$media = $request->user()->media()
    ->whereKey($mediaId)
    ->where('collection_name', 'images')
    ->firstOrFail();
```

Puis retournez le fichier via `Storage::disk($media->disk)->response(...)`, et non via une URL publique. Conservez la validation `image`, les types MIME autorisés et la limite de 10 Mo avant l’appel Spatie.

### Étape 6 — Ajouter la queue et tester

Configurez une queue Laravel avant d’activer beaucoup de conversions ; les miniatures ne bloqueront alors pas l’upload. Ajoutez des tests prouvant que l’utilisateur d’une application A ne peut pas consulter/supprimer un média d’un utilisateur B, même en connaissant son ID.

### Évolution ultérieure — S3 ou R2

Changez seulement la définition du disque `images` en disque `s3`/R2 et les variables `.env`. Le code métier, les collections Spatie et les contrôleurs restent identiques. Pour de gros volumes, servez les fichiers avec des URL temporaires signées au lieu de faire transiter le contenu via PHP.

---

## Première ébauche (référence technique)

Les sections suivantes conservent des éléments utiles — applications, Sanctum, middlewares, routes et sécurité — mais leurs extraits de modèle/migration/contrôleur `Image` sont remplacés par le plan Spatie ci-dessus.

Cette architecture isole rigoureusement les données suivant la chaîne :

```text
Application Flutter (API key) → Utilisateur (token Sanctum) → Image privée
```

Une requête doit toujours contenir une clé d’application. Un token utilisateur n’est accepté que si son `application_id` est celui de cette clé. Toutes les recherches d’images sont ensuite limitées à cette même application et à cet utilisateur (sauf rôle administrateur explicitement ajouté plus tard).

Le disque de stockage est référencé uniquement avec `Storage::disk('images')` : le passage de disque local privé à S3 / Cloudflare R2 ne change ni les contrôleurs ni Flutter.

## 1. Installation

```bash
composer create-project laravel/laravel image-server
cd image-server
php artisan install:api
php artisan make:policy ImagePolicy --model=Image
php artisan storage:link # facultatif : les images de cette architecture ne sont pas publiques
```

Configurez votre base dans `.env`, puis générez le secret initial :

```bash
php artisan key:generate
php artisan migrate
```

> `install:api` installe et configure Sanctum. Pour une API mobile, utilisez des tokens Sanctum personnels ; n’utilisez pas l’authentification SPA par cookies.

## 2. Structure recommandée

```text
app/
  Http/
    Controllers/Api/{AuthController.php,ImageController.php}
    Middleware/ResolveApplication.php
    Requests/{LoginRequest.php,RegisterRequest.php,StoreImageRequest.php}
  Models/{Application.php,User.php,Image.php}
  Policies/ImagePolicy.php
  Services/ImageStorageService.php
config/filesystems.php
database/migrations/
routes/api.php
```

## 3. Migrations

Conservez la migration Laravel de base pour `users`, mais adaptez-la comme suit (et conservez les tables Sanctum créées par `install:api`). Les identifiants sont des UUID publics ; les clés étrangères restent efficaces en base.

### `create_applications_table`

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('api_key_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('applications'); }
};
```

### Modification de `create_users_table`

Ajoutez `application_id` et rendez l’e-mail unique *par application*, pas globalement :

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->foreignId('application_id')->constrained()->cascadeOnDelete();
    $table->uuid('uuid')->unique();
    $table->string('name');
    $table->string('email');
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->rememberToken();
    $table->timestamps();

    $table->unique(['application_id', 'email']);
});
```

Si la migration existe déjà sur un environnement partagé, créez plutôt une migration d’altération, ajoutez la colonne nullable, remplissez-la, puis rendez-la obligatoire dans une migration suivante.

### `create_images_table`

```php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('images');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'user_id', 'created_at']);
            $table->unique(['disk', 'path']);
        });
    }

    public function down(): void { Schema::dropIfExists('images'); }
};
```

## 4. Modèles et relations

### `app/Models/Application.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'name', 'slug', 'api_key_hash', 'is_active'];
    protected $hidden = ['api_key_hash'];
    protected function casts(): array { return ['is_active' => 'boolean']; }

    public function users(): HasMany { return $this->hasMany(User::class); }
    public function images(): HasMany { return $this->hasMany(Image::class); }
}
```

### `app/Models/User.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = ['application_id', 'uuid', 'name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array { return ['password' => 'hashed']; }

    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function images(): HasMany { return $this->hasMany(Image::class); }
}
```

### `app/Models/Image.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Image extends Model
{
    protected $fillable = [
        'uuid', 'application_id', 'user_id', 'disk', 'path', 'original_name',
        'mime_type', 'size', 'width', 'height',
    ];

    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
```

## 5. Clé applicative et isolation

La clé n’est jamais stockée en clair. Créez une application avec une clé aléatoire, affichez-la une seule fois à l’opérateur et enregistrez son SHA-256 :

```php
$plainKey = 'app_' . bin2hex(random_bytes(32));
$application = Application::create([
    'uuid' => (string) \Illuminate\Support\Str::uuid(),
    'name' => 'Mon application Flutter',
    'slug' => 'mon-app',
    'api_key_hash' => hash('sha256', $plainKey),
]);
// Retournez $plainKey une seule fois, jamais dans une réponse ultérieure.
```

### `app/Http/Middleware/ResolveApplication.php`

```php
namespace App\Http\Middleware;

use App\Models\Application;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveApplication
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Application-Key');
        if (! is_string($key) || $key === '') {
            return response()->json(['message' => 'Clé d’application manquante.'], 401);
        }

        $application = Application::query()
            ->where('api_key_hash', hash('sha256', $key))
            ->where('is_active', true)
            ->first();

        if (! $application) {
            return response()->json(['message' => 'Clé d’application invalide.'], 401);
        }

        $request->attributes->set('application', $application);
        return $next($request);
    }
}
```

Dans `bootstrap/app.php` :

```php
->withMiddleware(function (\Illuminate\Foundation\Configuration\Middleware $middleware): void {
    $middleware->alias([
        'application' => \App\Http\Middleware\ResolveApplication::class,
        'token.application' => \App\Http\Middleware\EnsureTokenApplication::class,
    ]);
})
```

> Une clé embarquée dans une application mobile peut être extraite. Elle identifie et limite le client mais n’est pas un secret absolu. Combinez-la aux tokens utilisateur, HTTPS, limites de débit et, si le risque est élevé, à une attestation de l’application (Play Integrity / App Attest) ou à un mécanisme d’enregistrement d’appareil.

### `app/Http/Middleware/EnsureTokenApplication.php`

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenApplication
{
    public function handle(Request $request, Closure $next): Response
    {
        $application = $request->attributes->get('application');
        if (! $request->user() || ! $application || $request->user()->application_id !== $application->id) {
            return response()->json(['message' => 'Le token ne correspond pas à cette application.'], 403);
        }
        return $next($request);
    }
}
```

## 6. Validation et contrôleurs

### Form Requests

```php
// app/Http/Requests/RegisterRequest.php
class RegisterRequest extends \Illuminate\Foundation\Http\FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return ['name' => ['required','string','max:120'], 'email' => ['required','email:rfc,dns','max:255'], 'password' => ['required','string','min:12','confirmed']];
    }
}

// app/Http/Requests/LoginRequest.php
class LoginRequest extends \Illuminate\Foundation\Http\FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array { return ['email' => ['required','email'], 'password' => ['required','string']]; }
}

// app/Http/Requests/StoreImageRequest.php
class StoreImageRequest extends \Illuminate\Foundation\Http\FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return ['image' => ['required','file','image','mimetypes:image/jpeg,image/png,image/webp','max:10240']]; // 10 Mo
    }
}
```

Ajoutez les `namespace` usuels (`App\Http\Requests`) et les imports `FormRequest` dans chacun de ces trois fichiers.

### `app/Http/Controllers/Api/AuthController.php`

```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $app = $request->attributes->get('application');
        $email = mb_strtolower($request->validated('email'));
        if (User::where('application_id', $app->id)->where('email', $email)->exists()) {
            return response()->json(['message' => 'Cette adresse est déjà utilisée.'], 422);
        }
        $user = User::create([
            'application_id' => $app->id, 'uuid' => (string) Str::uuid(),
            'name' => $request->validated('name'), 'email' => $email,
            'password' => $request->validated('password'),
        ]);
        return $this->tokenResponse($user, 201);
    }

    public function login(LoginRequest $request)
    {
        $app = $request->attributes->get('application');
        $user = User::where('application_id', $app->id)
            ->where('email', mb_strtolower($request->validated('email')))->first();
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json(['message' => 'Identifiants invalides.'], 422);
        }
        return $this->tokenResponse($user);
    }

    public function logout(Request $request) { $request->user()->currentAccessToken()->delete(); return response()->noContent(); }

    private function tokenResponse(User $user, int $status = 200)
    {
        $token = $user->createToken('flutter')->plainTextToken;
        return response()->json(['token' => $token, 'token_type' => 'Bearer', 'user' => ['id' => $user->uuid, 'name' => $user->name, 'email' => $user->email]], $status);
    }
}
```

### `app/Services/ImageStorageService.php`

```php
namespace App\Services;

use App\Models\Image;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageStorageService
{
    public function store(User $user, UploadedFile $file): Image
    {
        $uuid = (string) Str::uuid();
        $extension = $file->extension(); // déterminée par le contenu par Symfony
        $path = sprintf('applications/%s/users/%s/%s.%s', $user->application->uuid, $user->uuid, $uuid, $extension);
        Storage::disk('images')->put($path, $file->getContent(), ['visibility' => 'private']);

        $size = @getimagesize($file->getRealPath()) ?: [null, null];
        return Image::create([
            'uuid' => $uuid, 'application_id' => $user->application_id, 'user_id' => $user->id,
            'disk' => 'images', 'path' => $path, 'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
            'width' => $size[0], 'height' => $size[1],
        ]);
    }

    public function delete(Image $image): void
    {
        Storage::disk($image->disk)->delete($image->path);
        $image->delete();
    }
}
```

### Policy : `app/Policies/ImagePolicy.php`

```php
namespace App\Policies;

use App\Models\Image;
use App\Models\User;

class ImagePolicy
{
    public function view(User $user, Image $image): bool { return $user->application_id === $image->application_id && $user->id === $image->user_id; }
    public function delete(User $user, Image $image): bool { return $this->view($user, $image); }
}
```

Laravel découvre normalement automatiquement la policy selon ses conventions. Sinon, enregistrez-la dans `AppServiceProvider` avec `Gate::policy(Image::class, ImagePolicy::class)`.

### `app/Http/Controllers/Api/ImageController.php`

```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImageRequest;
use App\Models\Image;
use App\Services\ImageStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    public function index(Request $request)
    {
        $images = Image::query()->where('application_id', $request->user()->application_id)
            ->where('user_id', $request->user()->id)->latest()->paginate(30);
        return response()->json($images);
    }

    public function store(StoreImageRequest $request, ImageStorageService $storage)
    {
        $image = $storage->store($request->user()->loadMissing('application'), $request->file('image'));
        return response()->json($this->resource($image), 201);
    }

    public function show(Request $request, Image $image)
    {
        $this->authorize('view', $image);
        return Storage::disk($image->disk)->response($image->path, $image->original_name, ['Content-Type' => $image->mime_type]);
    }

    public function destroy(Request $request, Image $image, ImageStorageService $storage)
    {
        $this->authorize('delete', $image);
        $storage->delete($image);
        return response()->noContent();
    }

    private function resource(Image $image): array
    {
        return ['id' => $image->uuid, 'name' => $image->original_name, 'mime_type' => $image->mime_type, 'size' => $image->size, 'width' => $image->width, 'height' => $image->height, 'created_at' => $image->created_at->toISOString(), 'download_url' => route('images.show', $image->uuid)];
    }
}
```

Pour que le binding par UUID fonctionne, ajoutez dans `Image` :

```php
public function getRouteKeyName(): string { return 'uuid'; }
```

## 7. Routes API

```php
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['application', 'throttle:auth'])->group(function () {
    Route::post('/v1/auth/register', [AuthController::class, 'register']);
    Route::post('/v1/auth/login', [AuthController::class, 'login']);
});

Route::middleware(['application', 'auth:sanctum', 'token.application', 'throttle:api'])->group(function () {
    Route::post('/v1/auth/logout', [AuthController::class, 'logout']);
    Route::get('/v1/images', [ImageController::class, 'index'])->name('images.index');
    Route::post('/v1/images', [ImageController::class, 'store'])->name('images.store');
    Route::get('/v1/images/{image}', [ImageController::class, 'show'])->name('images.show');
    Route::delete('/v1/images/{image}', [ImageController::class, 'destroy'])->name('images.destroy');
});
```

Le middleware `token.application`, placé après `auth:sanctum`, empêche explicitement un token valide de l’app A d’être utilisé avec la clé de l’app B.

## 8. Filesystem : local privé, puis S3/R2

Dans `config/filesystems.php`, ajoutez :

```php
'images' => [
    'driver' => 'local',
    'root' => storage_path('app/private/images'),
    'throw' => true,
],
```

Ne mettez jamais ce chemin sous `public/`. Pour migrer vers S3 ou R2, remplacez seulement la configuration :

```php
'images' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'auto'),
    'bucket' => env('AWS_BUCKET'),
    'endpoint' => env('AWS_ENDPOINT'), // endpoint R2 si besoin
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'throw' => true,
],
```

Conservez les objets privés. Le contrôleur peut les diffuser comme ci-dessus ou, pour de gros fichiers, retourner une URL temporaire : `Storage::disk($image->disk)->temporaryUrl($image->path, now()->addMinutes(5))` (compatible S3/R2 selon configuration).

## 9. Réponses et erreurs

Création réussie (`201`) :

```json
{"id":"9b7…","name":"avatar.jpg","mime_type":"image/jpeg","size":345612,"width":1200,"height":800,"created_at":"2026-08-30T12:00:00.000000Z","download_url":"https://api.example.com/api/v1/images/9b7…"}
```

Validation (`422`) :

```json
{"message":"The given data was invalid.","errors":{"image":["The image field must be an image."]}}
```

Normalisez les autres exceptions dans `bootstrap/app.php` avec `withExceptions()` : ne divulguez ni chemins de disque, ni messages de base de données ; journalisez l’exception côté serveur avec un identifiant de requête.

## 10. Appels Flutter

Tous les appels incluent les deux en-têtes :

```text
X-Application-Key: app_…
Authorization: Bearer <token Sanctum>
```

Pour la connexion et l’inscription, envoyez seulement `X-Application-Key`. Envoyez l’upload en `multipart/form-data` avec le champ `image`. Conservez le token dans `flutter_secure_storage`, jamais dans `SharedPreferences`.

## 11. Sécurité et exploitation

- Forcez HTTPS, activez HSTS en production et ne journalisez jamais `Authorization` ou `X-Application-Key`.
- Limitez le débit (connexion, inscription et upload séparément), la taille du corps (`post_max_size`, `upload_max_filesize`, proxy) et l’espace disque par utilisateur/application.
- Fiez-vous au MIME détecté côté serveur ; limitez les formats (JPEG, PNG, WebP), rejetez SVG par défaut et, si possible, réencodez l’image avec Intervention Image pour retirer les métadonnées/EXIF et éviter les fichiers polyglottes.
- Utilisez des noms générés par le serveur, jamais le nom client comme chemin. Sauvegardez seulement `original_name` à titre d’affichage.
- Préparez des tokens par appareil avec une capacité Sanctum limitée, par ex. `['images:read', 'images:write']`; révoquez-les au logout et à la déconnexion d’un appareil.
- Mettez en file d’attente les miniatures, la compression, l’antivirus et le nettoyage. N’effacez pas un fichier si la transaction DB échoue : utilisez une transaction et un job de compensation ou une tâche de nettoyage des orphelins.
- Faites des sauvegardes de la base et du bucket ; surveillez les erreurs de stockage, les quotas et les tentatives 401/403 répétées.
- Ajoutez des tests feature prouvant qu’un utilisateur A ne peut ni lister, ni télécharger, ni supprimer l’image d’un utilisateur B — y compris si B est dans une autre application.

## 12. Vérification minimale avant mise en production

```bash
php artisan route:list --path=v1
php artisan test
php artisan config:cache
php artisan route:cache
```

Le point de sécurité déterminant est double : le middleware vérifie que **clé d’application et token correspondent**, puis les requêtes et policy vérifient que **l’image appartient à cet utilisateur**. Une simple relation `user_id` sans ces deux barrières n’est pas suffisante pour un serveur multi-applications.
