<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 personal_access_tokens 增加 site_id 列，支持 Token 绑定 niucloud 租户站点。
 *
 * 多租户适配 Phase 1.2：Token 发行时绑定 site_id，API 面通过 token 绑定值解析租户上下文。
 * 存量 token site_id = NULL，走 Header 过渡通道（ResolveTenant 中间件）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('site_id')->nullable()->after('abilities');
            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['site_id']);
            $table->dropColumn('site_id');
        });
    }
};
