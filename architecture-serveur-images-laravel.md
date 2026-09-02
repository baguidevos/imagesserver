# Serveur centralisé d'images Laravel pour plusieurs applications Flutter

## Architecture simplifiée

Ce serveur centralise le stockage d'images pour plusieurs applications Flutter. Chaque application possède sa propre base d'utilisateurs isolée. Pas de dépendances lourdes : `Storage::disk()` + un modèle `Image` simple.

```text
Application Flutter (X-Application-Key)
        ↓
Utilisateur Laravel (application_id + token Sanctum)
        ↓
Image privée (scoped via relation user → images)
        ↓
Disque Laravel privé « images » → futur S3 ou Cloudflare R2
```

### Principes directeurs

- **Pas de Spatie Media Library** : un modèle `Image` + `Storage::disk()` suffit. Les thumbnails seront ajoutés plus tard avec Intervention Image si besoin.
- **ULID comme clé primaire** : identifiant unique, triable chronologiquement, URL-safe. Pas de double `id` + `uuid`.
- **Logique dans le contrôleur** : pas de `ImageStorageService` tant qu'un seul contrôleur consomme la logique.
- **Scoping par relation** : on accède toujours aux images via `$user->images()`, pas besoin de Policy redondante.
- **Stockage privé** : les fichiers ne sont jamais dans `public/`. Servis uniquement par une route authentifiée.

---

## 1. Installation

```bash
composer create-project laravel/laravel image-server
cd image-server
php artisan install:api
```

Configurez votre base dans `.env`, puis :

```bash
php artisan key:generate
php artisan migrate
```

> `install:api` installe et configure Sanctum. Pour une API mobile, utilisez des tokens Sanctum personnels ; n'utilisez pas l'authentification SPA par cookies.

## 2. Structure du projet

```text
app/
  Http/
    Controllers/Api/{AuthController.php, ImageController.php}
    Middleware/{ResolveApplication.php, EnsureTokenApplication.php}
    Requests/{LoginRequest.php, RegisterRequest.php, StoreImageRequest.php}
  Models/{Application.php, User.php, Image.php}
config/filesystems.php
database/migrations/
routes/api.php
```

## 3. Migrations

### `create_applications_table`

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('api_key_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
```

### Modification de `create_users_table`

Ajoutez `application_id` et rendez l'e-mail unique *par application*, pas globalement :

```php
Schema::create('users', function (Blueprint $table) {
    $table->ulid('id')->primary();
    $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('email');
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->rememberToken();
    $table->timestamps();

    $table->unique(['application_id', 'email']);
});
```

> Si la migration existe déjà sur un environnement partagé, créez une migration d'altération.

### `create_images_table`

```php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
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

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
```

## 4. Modèles

### `app/Models/Application.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = ['name', 'slug', 'api_key_hash', 'is_active'];

    protected $hidden = ['api_key_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }
}
```

### `app/Models/User.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasApiTokens, HasUlids, Notifiable;

    protected $fillable = ['application_id', 'name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }
}
```

### `app/Models/Image.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Image extends Model
{
    use HasUlids;

    protected $fillable = [
        'application_id', 'user_id', 'disk', 'path',
        'original_name', 'mime_type', 'size', 'width', 'height',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

## 5. Clé applicative et middlewares

La clé n'est jamais stockée en clair. On enregistre son SHA-256 :

```php
$plainKey = 'app_' . bin2hex(random_bytes(32));
$application = Application::create([
    'name' => 'Mon application Flutter',
    'slug' => 'mon-app',
    'api_key_hash' => hash('sha256', $plainKey),
]);
// Retournez $plainKey une seule fois, jamais dans une réponse ultérieure.
```

> Une clé embarquée dans une application mobile peut être extraite. Elle identifie et limite le client mais n'est pas un secret absolu. Combinez-la aux tokens utilisateur et HTTPS.

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
            return response()->json(['message' => 'Clé d\'application manquante.'], 401);
        }

        $application = Application::query()
            ->where('api_key_hash', hash('sha256', $key))
            ->where('is_active', true)
            ->first();

        if (! $application) {
            return response()->json(['message' => 'Clé d\'application invalide.'], 401);
        }

        $request->attributes->set('application', $application);

        return $next($request);
    }
}
```

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

### Enregistrement dans `bootstrap/app.php`

```php
->withMiddleware(function (\Illuminate\Foundation\Configuration\Middleware $middleware): void {
    $middleware->alias([
        'application' => \App\Http\Middleware\ResolveApplication::class,
        'token.application' => \App\Http\Middleware\EnsureTokenApplication::class,
    ]);
})
```

## 6. Validation (Form Requests)

### `app/Http/Requests/RegisterRequest.php`

```php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ];
    }
}
```

### `app/Http/Requests/LoginRequest.php`

```php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
```

### `app/Http/Requests/StoreImageRequest.php`

```php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
        ];
    }
}
```

## 7. Contrôleurs

### `app/Http/Controllers/Api/AuthController.php`

```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
            'application_id' => $app->id,
            'name' => $request->validated('name'),
            'email' => $email,
            'password' => $request->validated('password'),
        ]);

        return $this->tokenResponse($user, 201);
    }

    public function login(LoginRequest $request)
    {
        $app = $request->attributes->get('application');
        $user = User::where('application_id', $app->id)
            ->where('email', mb_strtolower($request->validated('email')))
            ->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json(['message' => 'Identifiants invalides.'], 422);
        }

        return $this->tokenResponse($user);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function tokenResponse(User $user, int $status = 200)
    {
        $token = $user->createToken('flutter')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], $status);
    }
}
```

### `app/Http/Controllers/Api/ImageController.php`

La logique de stockage est directement dans le contrôleur. On refactorisera dans un service si un deuxième consommateur apparaît.

```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImageRequest;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageController extends Controller
{
    public function index(Request $request)
    {
        $images = $request->user()->images()->latest()->paginate(30);

        return response()->json($images);
    }

    public function store(StoreImageRequest $request)
    {
        $user = $request->user();
        $file = $request->file('image');

        $extension = $file->extension();
        $path = sprintf(
            'applications/%s/users/%s/%s.%s',
            $user->application_id,
            $user->id,
            Str::ulid(),
            $extension
        );

        Storage::disk('images')->put($path, $file->getContent(), ['visibility' => 'private']);

        $dimensions = @getimagesize($file->getRealPath()) ?: [null, null];

        $image = $user->images()->create([
            'application_id' => $user->application_id,
            'disk' => 'images',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ]);

        return response()->json([
            'id' => $image->id,
            'name' => $image->original_name,
            'mime_type' => $image->mime_type,
            'size' => $image->size,
            'width' => $image->width,
            'height' => $image->height,
            'created_at' => $image->created_at->toISOString(),
            'download_url' => route('images.show', $image),
        ], 201);
    }

    public function show(Request $request, string $imageId)
    {
        $image = $request->user()->images()->findOrFail($imageId);

        return Storage::disk($image->disk)->response(
            $image->path,
            $image->original_name,
            ['Content-Type' => $image->mime_type]
        );
    }

    public function destroy(Request $request, string $imageId)
    {
        $image = $request->user()->images()->findOrFail($imageId);

        Storage::disk($image->disk)->delete($image->path);
        $image->delete();

        return response()->noContent();
    }
}
```

> **Note sur le scoping** : `$request->user()->images()->findOrFail($imageId)` garantit qu'un utilisateur ne peut accéder qu'à ses propres images. Pas besoin de Policy.

## 8. Routes API

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

Le middleware `token.application`, placé après `auth:sanctum`, empêche un token valide de l'app A d'être utilisé avec la clé de l'app B.

## 9. Stockage privé

Dans `config/filesystems.php` :

```php
'images' => [
    'driver' => 'local',
    'root' => storage_path('app/private/images'),
    'throw' => true,
],
```

Ne mettez jamais ce chemin sous `public/`. Pas de `storage:link` nécessaire.

### Migration vers S3 ou R2

Remplacez seulement la configuration du disque :

```php
'images' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'auto'),
    'bucket' => env('AWS_BUCKET'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'throw' => true,
],
```

Pour de gros fichiers, retournez une URL temporaire signée :

```php
Storage::disk($image->disk)->temporaryUrl($image->path, now()->addMinutes(5));
```

## 10. Appels Flutter

Tous les appels incluent les en-têtes :

```text
X-Application-Key: app_…
Authorization: Bearer <token Sanctum>
```

Pour la connexion et l'inscription, envoyez seulement `X-Application-Key`. Envoyez l'upload en `multipart/form-data` avec le champ `image`. Conservez le token dans `flutter_secure_storage`, jamais dans `SharedPreferences`.

## 11. Sécurité et exploitation

- Forcez HTTPS, activez HSTS en production.
- Ne journalisez jamais `Authorization` ou `X-Application-Key`.
- Limitez le débit (connexion, inscription et upload séparément).
- Limitez la taille du corps (`post_max_size`, `upload_max_filesize`).
- Fiez-vous au MIME détecté côté serveur ; rejetez SVG par défaut.
- Utilisez des noms générés par le serveur (ULID), jamais le nom client comme chemin.
- Si possible, réencodez l'image avec Intervention Image pour retirer les métadonnées EXIF.
- Faites des sauvegardes de la base et du disque de stockage.

## 12. Vérification avant mise en production

```bash
php artisan route:list --path=v1
php artisan test
php artisan config:cache
php artisan route:cache
```

Le point de sécurité déterminant est double : le middleware vérifie que **clé d'application et token correspondent**, puis le scoping par relation vérifie que **l'image appartient à cet utilisateur**.
