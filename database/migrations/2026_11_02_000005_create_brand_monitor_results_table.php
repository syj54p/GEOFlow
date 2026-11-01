<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_monitor_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->foreignId('keyword_id')->constrained('brand_keywords')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('brand_platforms')->cascadeOnDelete();

            // 检测结果
            $table->integer('search_rank')->nullable();
            $table->decimal('visibility_score', 5, 2)->nullable();
            $table->boolean('is_in_top10')->default(false);

            // 信源数据
            $table->json('source_analysis')->default('{}');

            // 舆情数据
            $table->json('sentiment_analysis')->default('{}');

            // 元数据
            $table->timestamp('detected_at')->useCurrent();
            $table->timestamps();

            $table->index(['keyword_id', 'detected_at']);
            $table->index(['platform_id', 'detected_at']);
            $table->index(['site_id', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_monitor_results');
    }
};
