<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_diagnosis_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable()->unique();

            $table->json('dimension_weights')->default('{"content_structure":0.25,"knowledge_alignment":0.30,"technical_geo":0.20,"ai_visibility":0.25}');
            $table->json('enabled_checks')->default('["CS-01","CS-02","CS-03","CS-04","KA-01","KA-02","KA-03","TG-01","TG-02","TG-03","AV-01","AV-02"]');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_diagnosis_settings');
    }
};
