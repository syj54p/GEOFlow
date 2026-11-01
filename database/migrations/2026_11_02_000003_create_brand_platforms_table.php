<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_platforms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('name', 50);
            $table->string('code', 30)->unique();
            $table->string('icon_color', 20)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->integer('check_interval_minutes')->default(60);
            $table->json('api_config')->default('{}');
            $table->timestamps();

            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_platforms');
    }
};
