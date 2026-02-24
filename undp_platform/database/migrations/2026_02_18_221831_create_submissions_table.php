<?php

use App\Enums\SubmissionStatus;
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
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default(SubmissionStatus::UNDER_REVIEW->value)->index();
            $table->string('title');
            $table->text('details')->nullable();
            $table->json('data')->nullable();
            $table->json('media')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('validated_at')->nullable()->index();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('validation_comment')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'status']);
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
