<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_diagnosis_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->unsignedBigInteger('admin_id')->nullable();

            // 评分结果
            $table->integer('total_score')->default(0);
            $table->integer('content_structure_score')->default(0);
            $table->integer('knowledge_alignment_score')->default(0);
            $table->integer('technical_geo_score')->default(0);
            $table->integer('ai_visibility_score')->default(0);

            // 诊断元数据
            $table->string('status', 20)->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();

            // 检查项结果（JSON）
            $table->json('findings')->default('[]');
            $table->json('recommendations')->default('[]');

            $table->timestamps();

            $table->index(['article_id', 'status']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_diagnosis_runs');
    }
};
