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
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_media_id', 120)->nullable();
            $table->string('disk', 60)->default('s3');
            $table->string('bucket')->nullable();
            $table->string('object_key')->unique();
            $table->string('media_type', 20);
            $table->string('mime_type', 140);
            $table->string('original_filename', 255);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->json('variants')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['submission_id', 'client_media_id']);
            $table->index(['submission_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
