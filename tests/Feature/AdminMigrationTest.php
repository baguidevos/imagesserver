<?php

use App\Models\Admin;

it('denies migration route access to unauthenticated guests without secret token', function () {
    $response = $this->get('/admin/migrate');

    $response->assertStatus(403);
});

it('allows authenticated admin to run migrations via web route', function () {
    $admin = Admin::factory()->create();

    $response = $this->actingAs($admin, 'admin')->get('/admin/migrate');

    $response->assertStatus(200)
        ->assertSee('Migrations exécutées');
});

it('allows execution with valid migration secret token and returns json', function () {
    config()->set('app.migration_secret', 'secret_migration_token_12345');

    $response = $this->getJson('/admin/migrate?token=secret_migration_token_12345');

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'message' => 'Migrations exécutées avec succès.',
        ])
        ->assertJsonStructure(['output']);
});

it('renders the custom dashboard with migration action for authenticated admin', function () {
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin')
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('Exécuter les migrations');
});
