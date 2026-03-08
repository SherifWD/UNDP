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
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 6);
            $table->string('phone', 20);
            $table->string('phone_e164', 25)->index();
            $table->string('code', 12);
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['phone_e164', 'code']);
            $table->unique(['country_code', 'phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
