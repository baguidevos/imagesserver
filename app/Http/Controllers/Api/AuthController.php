<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FirebaseAuthRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
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

    public function login(LoginRequest $request): JsonResponse
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

    public function firebaseAuth(FirebaseAuthRequest $request): JsonResponse
    {
        $application = $request->attributes->get('application');
        $firebaseUid = $request->validated('firebase_uid');
        $email = mb_strtolower($request->validated('email'));
        $displayName = $request->validated('name') ?: Str::before($email, '@');

        $user = User::where('application_id', $application->id)
            ->where(function ($query) use ($firebaseUid, $email) {
                $query->where('firebase_uid', $firebaseUid)
                    ->orWhere('email', $email);
            })
            ->first();

        if (! $user) {
            $createdUser = User::create([
                'application_id' => $application->id,
                'firebase_uid' => $firebaseUid,
                'email' => $email,
                'name' => $displayName,
            ]);

            return $this->tokenResponse($createdUser, 201);
        }

        $user->update([
            'firebase_uid' => $firebaseUid,
            'name' => $user->name ?: $displayName,
        ]);

        return $this->tokenResponse($user, 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse
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
