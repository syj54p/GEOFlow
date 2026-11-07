<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 geo_diagnosis_runs 增加 site_id 列，支持按租户隔离诊断记录。
 *
 * 多租户适配 Phase 2.1：诊断记录落库时标记所属 niucloud 站点。
 * 存量记录 site_id = NULL（遗留全局数据），租户视图不可见，仅超管全局视图可见。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_diagnosis_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('site_id')->nullable()->after('admin_id');
            $table->index(['site_id', 'created_at']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('geo_diagnosis_runs', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'created_at']);
            $table->dropIndex(['site_id', 'status']);
            $table->dropColumn('site_id');
        });
    }
};
