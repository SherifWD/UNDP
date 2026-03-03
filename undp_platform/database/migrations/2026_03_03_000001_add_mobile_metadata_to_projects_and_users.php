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
        Schema::table('projects', function (Blueprint $table): void {
            $table->json('mobile_meta')->nullable()->after('last_update_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('gender', 30)->nullable()->after('status');
            $table->string('avatar_path')->nullable()->after('preferred_locale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['gender', 'avatar_path']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('mobile_meta');
        });
    }
};
