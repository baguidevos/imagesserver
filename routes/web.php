<?php

use App\Http\Controllers\Admin\MigrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::match(['get', 'post'], '/admin/migrate', MigrationController::class)->name('admin.migrate');
