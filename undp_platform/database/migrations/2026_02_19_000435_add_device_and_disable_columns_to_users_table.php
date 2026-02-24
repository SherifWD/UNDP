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
            $table->string('fcm_token', 255)->nullable()->after('preferred_locale');
            $table->timestamp('disabled_at')->nullable()->after('last_login_at');
            $table->string('disabled_reason', 255)->nullable()->after('disabled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['fcm_token', 'disabled_at', 'disabled_reason']);
        });
    }
};
