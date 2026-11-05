<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('happyarif_licenses', function (Blueprint $table): void {
            // A single activation per Laravel installation, matching the legacy file.
            $table->unsignedTinyInteger('id')->primary();
            $table->longText('license_token');
            $table->boolean('is_activated')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('happyarif_licenses');
    }
};
