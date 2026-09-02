# Guide d'intégration Flutter — Serveur d'images

Ce guide explique comment intégrer le serveur d'images dans une application Flutter.

## Prérequis

Ajoutez ces dépendances dans votre `pubspec.yaml` :

```yaml
dependencies:
  http: ^1.2.0
  flutter_secure_storage: ^9.0.0
  # OU utilisez dio pour plus de fonctionnalités :
  # dio: ^5.4.0
```

## Configuration

### Constantes

```dart
class ApiConfig {
  static const String baseUrl = 'https://votre-serveur.com/api';
  static const String applicationKey = 'app_...'; // Votre clé applicative
}
```

> ⚠️ En production, stockez `applicationKey` dans les variables d'environnement de compilation (`--dart-define`) plutôt qu'en dur dans le code.

---

## Service d'authentification

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class AuthService {
  final _storage = const FlutterSecureStorage();
  static const _tokenKey = 'auth_token';

  Map<String, String> get _headers => {
    'X-Application-Key': ApiConfig.applicationKey,
    'Accept': 'application/json',
  };

  Map<String, String> get _authHeaders => {
    ..._headers,
    'Authorization': 'Bearer ${_cachedToken ?? ''}',
  };

  String? _cachedToken;

  /// Charge le token depuis le stockage sécurisé au démarrage.
  Future<void> init() async {
    _cachedToken = await _storage.read(key: _tokenKey);
  }

  bool get isAuthenticated => _cachedToken != null;

  /// Inscription d'un nouvel utilisateur.
  Future<AuthResponse> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) async {
    final response = await http.post(
      Uri.parse('${ApiConfig.baseUrl}/v1/auth/register'),
      headers: {..._headers, 'Content-Type': 'application/json'},
      body: jsonEncode({
        'name': name,
        'email': email,
        'password': password,
        'password_confirmation': passwordConfirmation,
      }),
    );

    if (response.statusCode == 201) {
      final data = jsonDecode(response.body);
      await _saveToken(data['token']);
      return AuthResponse.success(data);
    }

    return AuthResponse.error(response);
  }

  /// Connexion d'un utilisateur existant.
  Future<AuthResponse> login({
    required String email,
    required String password,
  }) async {
    final response = await http.post(
      Uri.parse('${ApiConfig.baseUrl}/v1/auth/login'),
      headers: {..._headers, 'Content-Type': 'application/json'},
      body: jsonEncode({
        'email': email,
        'password': password,
      }),
    );

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body);
      await _saveToken(data['token']);
      return AuthResponse.success(data);
    }

    return AuthResponse.error(response);
  }

  /// Déconnexion.
  Future<void> logout() async {
    await http.post(
      Uri.parse('${ApiConfig.baseUrl}/v1/auth/logout'),
      headers: _authHeaders,
    );
    await _clearToken();
  }

  Future<void> _saveToken(String token) async {
    _cachedToken = token;
    await _storage.write(key: _tokenKey, value: token);
  }

  Future<void> _clearToken() async {
    _cachedToken = null;
    await _storage.delete(key: _tokenKey);
  }
}

class AuthResponse {
  final bool success;
  final Map<String, dynamic>? data;
  final String? errorMessage;
  final Map<String, dynamic>? validationErrors;

  AuthResponse._({
    required this.success,
    this.data,
    this.errorMessage,
    this.validationErrors,
  });

  factory AuthResponse.success(Map<String, dynamic> data) {
    return AuthResponse._(success: true, data: data);
  }

  factory AuthResponse.error(http.Response response) {
    final body = jsonDecode(response.body);
    return AuthResponse._(
      success: false,
      errorMessage: body['message'],
      validationErrors: body['errors'],
    );
  }
}
```

---

## Service d'images

```dart
import 'dart:convert';
import 'dart:typed_data';
import 'package:http/http.dart' as http;

class ImageService {
  final AuthService _auth;

  ImageService(this._auth);

  Map<String, String> get _headers => {
    'X-Application-Key': ApiConfig.applicationKey,
    'Authorization': 'Bearer ${_auth._cachedToken ?? ''}',
    'Accept': 'application/json',
  };

  /// Upload une image depuis des bytes (caméra, galerie, etc.).
  Future<ImageUploadResult> upload({
    required Uint8List imageBytes,
    required String filename,
  }) async {
    final request = http.MultipartRequest(
      'POST',
      Uri.parse('${ApiConfig.baseUrl}/v1/images'),
    );

    request.headers.addAll(_headers);
    request.files.add(http.MultipartFile.fromBytes(
      'image',
      imageBytes,
      filename: filename,
    ));

    final streamedResponse = await request.send();
    final response = await http.Response.fromStream(streamedResponse);

    if (response.statusCode == 201) {
      return ImageUploadResult.success(jsonDecode(response.body));
    }

    return ImageUploadResult.error(response.body);
  }

  /// Liste les images de l'utilisateur connecté (paginée).
  Future<ImageListResult> list({int page = 1}) async {
    final response = await http.get(
      Uri.parse('${ApiConfig.baseUrl}/v1/images?page=$page'),
      headers: _headers,
    );

    if (response.statusCode == 200) {
      return ImageListResult.fromJson(jsonDecode(response.body));
    }

    throw Exception('Erreur lors du chargement des images');
  }

  /// Télécharge le contenu binaire d'une image.
  Future<Uint8List> download(String imageId) async {
    final response = await http.get(
      Uri.parse('${ApiConfig.baseUrl}/v1/images/$imageId'),
      headers: _headers,
    );

    if (response.statusCode == 200) {
      return response.bodyBytes;
    }

    throw Exception('Image introuvable');
  }

  /// Supprime une image.
  Future<bool> delete(String imageId) async {
    final response = await http.delete(
      Uri.parse('${ApiConfig.baseUrl}/v1/images/$imageId'),
      headers: _headers,
    );

    return response.statusCode == 204;
  }
}

class ImageUploadResult {
  final bool success;
  final Map<String, dynamic>? data;
  final String? error;

  ImageUploadResult._({required this.success, this.data, this.error});

  factory ImageUploadResult.success(Map<String, dynamic> data) =>
      ImageUploadResult._(success: true, data: data);

  factory ImageUploadResult.error(String body) =>
      ImageUploadResult._(success: false, error: body);

  String? get imageId => data?['id'];
  String? get downloadUrl => data?['download_url'];
}

class ImageListResult {
  final List<Map<String, dynamic>> images;
  final int currentPage;
  final int lastPage;

  ImageListResult({
    required this.images,
    required this.currentPage,
    required this.lastPage,
  });

  factory ImageListResult.fromJson(Map<String, dynamic> json) {
    return ImageListResult(
      images: List<Map<String, dynamic>>.from(json['data']),
      currentPage: json['current_page'],
      lastPage: json['last_page'],
    );
  }

  bool get hasMorePages => currentPage < lastPage;
}
```

---

## Exemple d'utilisation complet

```dart
// Initialisation au démarrage de l'app
final auth = AuthService();
await auth.init();

final images = ImageService(auth);

// --- Inscription ---
final registerResult = await auth.register(
  name: 'Jean Dupont',
  email: 'jean@example.com',
  password: 'motdepasse123!',
  passwordConfirmation: 'motdepasse123!',
);

if (!registerResult.success) {
  print('Erreur: ${registerResult.errorMessage}');
  return;
}

// --- Upload d'une image ---
// Depuis un fichier
final bytes = await File('path/to/image.jpg').readAsBytes();
final uploadResult = await images.upload(
  imageBytes: bytes,
  filename: 'photo.jpg',
);

if (uploadResult.success) {
  print('Image uploadée: ${uploadResult.imageId}');
}

// Depuis image_picker
// final pickedFile = await ImagePicker().pickImage(source: ImageSource.gallery);
// if (pickedFile != null) {
//   final bytes = await pickedFile.readAsBytes();
//   await images.upload(imageBytes: bytes, filename: pickedFile.name);
// }

// --- Lister les images ---
final imageList = await images.list();
for (final img in imageList.images) {
  print('${img['id']} - ${img['name']} (${img['size']} bytes)');
}

// --- Télécharger une image ---
final imageBytes = await images.download('01JARQ...');
// Utilisez imageBytes avec Image.memory(imageBytes) dans un widget

// --- Supprimer une image ---
await images.delete('01JARQ...');

// --- Déconnexion ---
await auth.logout();
```

---

## Référence API

### Headers requis

| Header | Quand | Valeur |
|---|---|---|
| `X-Application-Key` | **Toujours** | Votre clé applicative `app_...` |
| `Authorization` | Routes protégées | `Bearer <token>` |
| `Content-Type` | JSON body | `application/json` |
| `Accept` | **Toujours** | `application/json` |

### Endpoints

| Méthode | URL | Auth | Description |
|---|---|---|---|
| `POST` | `/api/v1/auth/register` | Clé seule | Inscription |
| `POST` | `/api/v1/auth/login` | Clé seule | Connexion |
| `POST` | `/api/v1/auth/logout` | Clé + Token | Déconnexion |
| `GET` | `/api/v1/images` | Clé + Token | Liste paginée (30/page) |
| `POST` | `/api/v1/images` | Clé + Token | Upload (`multipart/form-data`, champ `image`) |
| `GET` | `/api/v1/images/{id}` | Clé + Token | Télécharger le fichier |
| `DELETE` | `/api/v1/images/{id}` | Clé + Token | Supprimer |

### Réponses

**Inscription (201)** :
```json
{
  "token": "1|abc123...",
  "token_type": "Bearer",
  "user": {
    "id": "01JARQ...",
    "name": "Jean Dupont",
    "email": "jean@example.com"
  }
}
```

**Upload (201)** :
```json
{
  "id": "01JARQ...",
  "name": "photo.jpg",
  "mime_type": "image/jpeg",
  "size": 345612,
  "width": 1200,
  "height": 800,
  "created_at": "2026-09-02T12:00:00.000000Z",
  "download_url": "https://api.example.com/api/v1/images/01JARQ..."
}
```

**Erreur de validation (422)** :
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "image": ["The image field must be an image."]
  }
}
```

**Clé invalide (401)** :
```json
{
  "message": "Clé d'application invalide."
}
```

### Limites

- **Taille max d'une image** : 10 Mo
- **Formats acceptés** : JPEG, PNG, WebP
- **Pagination** : 30 images par page
- **Mot de passe** : minimum 12 caractères
