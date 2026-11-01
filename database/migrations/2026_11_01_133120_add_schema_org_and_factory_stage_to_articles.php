<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->jsonb('schema_org_data')->nullable()->after('generation_evidence_snapshot');
            $table->string('factory_stage', 20)->nullable()->after('schema_org_data');
            $table->index('factory_stage');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['factory_stage']);
            $table->dropColumn(['schema_org_data', 'factory_stage']);
        });
    }
};
