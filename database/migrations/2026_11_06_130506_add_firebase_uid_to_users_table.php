<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('firebase_uid')->nullable()->after('application_id');
            $table->string('password')->nullable()->change();

            $table->unique(['application_id', 'firebase_uid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['application_id', 'firebase_uid']);
            $table->string('password')->nullable(false)->change();
            $table->dropColumn('firebase_uid');
        });
    }
};
