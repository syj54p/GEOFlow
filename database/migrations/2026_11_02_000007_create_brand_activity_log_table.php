<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_activity_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('activity_type', 30);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('related_type', 50)->nullable();
            $table->string('severity', 10)->default('info');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['site_id', 'created_at']);
            $table->index('activity_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_activity_log');
    }
};
