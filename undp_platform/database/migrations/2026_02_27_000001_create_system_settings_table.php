<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->json('general')->nullable();
            $table->json('users_roles')->nullable();
            $table->json('workflow')->nullable();
            $table->json('security')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
