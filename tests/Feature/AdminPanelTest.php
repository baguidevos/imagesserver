<?php

use App\Models\Admin;
use App\Models\Application;

test('unauthenticated users are redirected to login', function () {
    $this->get('/admin')
        ->assertRedirect('/admin/login');
});

test('admin can access the dashboard', function () {
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin')
        ->get('/admin')
        ->assertSuccessful();
});

test('admin can access the applications resource', function () {
    $admin = Admin::factory()->create();
    Application::factory()->create([
        'name' => 'Mon App Flutter Test',
    ]);

    $this->actingAs($admin, 'admin')
        ->get('/admin/applications')
        ->assertSuccessful()
        ->assertSee('Mon App Flutter Test');
});

test('creating application generates a valid sha256 hash', function () {
    $plainKey = 'app_'.bin2hex(random_bytes(32));
    $app = Application::create([
        'name' => 'Test App Key',
        'slug' => 'test-app-key',
        'api_key_hash' => hash('sha256', $plainKey),
        'is_active' => true,
    ]);

    expect($app->api_key_hash)->toBe(hash('sha256', $plainKey));
});

test('regenerating application key changes the hash', function () {
    $oldKey = 'app_old_'.bin2hex(random_bytes(16));
    $app = Application::create([
        'name' => 'Rotation App',
        'slug' => 'rotation-app',
        'api_key_hash' => hash('sha256', $oldKey),
    ]);

    $newKey = 'app_new_'.bin2hex(random_bytes(16));
    $app->update([
        'api_key_hash' => hash('sha256', $newKey),
    ]);

    expect($app->fresh()->api_key_hash)
        ->not->toBe(hash('sha256', $oldKey))
        ->toBe(hash('sha256', $newKey));
});
