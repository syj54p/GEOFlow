<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_inflow_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('action_type', 30); // rewrite_heavy, rewrite_medium, rewrite_light, redistribute, pause_channel, optimize_metadata
            $table->string('trigger_source', 30); // visibility, distribution, traffic, quality_trend, manual
            $table->integer('severity_score')->default(0);
            $table->json('reasons')->nullable(); // 触发原因列表
            $table->json('context')->nullable(); // 额外上下文数据
            $table->string('status', 20)->default('pending'); // pending, executing, completed, failed, cancelled
            $table->text('result_message')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'status']);
            $table->index(['trigger_source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_inflow_actions');
    }
};
