<?php

use App\Models\User;
use Database\Factories\ApplicationFactory;

beforeEach(function () {
    ['factory' => $factory, 'plain_key' => $plainKey] = ApplicationFactory::withPlainKey();
    $this->application = $factory->create();
    $this->plainKey = $plainKey;
});

it('registers a new user and returns a token when authenticated via firebase', function () {
    $response = $this->postJson('/api/v1/auth/firebase', [
        'firebase_uid' => 'fb_uid_123456',
        'email' => 'jane.doe@example.com',
        'name' => 'Jane Doe',
    ], [
        'X-Application-Key' => $this->plainKey,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'token',
            'token_type',
            'user' => ['id', 'name', 'email'],
        ]);

    $this->assertDatabaseHas('users', [
        'application_id' => $this->application->id,
        'firebase_uid' => 'fb_uid_123456',
        'email' => 'jane.doe@example.com',
        'name' => 'Jane Doe',
    ]);
});

it('authenticates an existing user and updates their firebase uid', function () {
    $user = User::factory()->create([
        'application_id' => $this->application->id,
        'email' => 'existing.user@example.com',
        'firebase_uid' => null,
    ]);

    $response = $this->postJson('/api/v1/auth/firebase', [
        'firebase_uid' => 'fb_uid_789012',
        'email' => 'existing.user@example.com',
    ], [
        'X-Application-Key' => $this->plainKey,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'token',
            'token_type',
            'user' => ['id', 'name', 'email'],
        ]);

    expect($user->fresh()->firebase_uid)->toBe('fb_uid_789012');
});

it('rejects firebase authentication if the application key is missing', function () {
    $response = $this->postJson('/api/v1/auth/firebase', [
        'firebase_uid' => 'fb_uid_123456',
        'email' => 'jane.doe@example.com',
    ]);

    $response->assertStatus(401);
});

it('validates required fields for firebase authentication', function () {
    $response = $this->postJson('/api/v1/auth/firebase', [], [
        'X-Application-Key' => $this->plainKey,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['firebase_uid', 'email']);
});
