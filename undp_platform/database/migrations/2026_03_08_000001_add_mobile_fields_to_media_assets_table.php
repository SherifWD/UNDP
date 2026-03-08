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
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->string('label', 255)->nullable()->after('client_media_id');
            $table->unsignedSmallInteger('display_order')->nullable()->after('label');
            $table->index(['submission_id', 'display_order'], 'media_assets_submission_display_order_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropIndex('media_assets_submission_display_order_idx');
            $table->dropColumn(['label', 'display_order']);
        });
    }
};
