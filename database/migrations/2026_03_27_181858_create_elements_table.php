<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The live database created this table under the earlier file name
        // 2026_03_27_181855_create_elements_table (renamed to fix the migration
        // ordering). Without this guard that database would try to create it
        // a second time the first time it runs this file.
        if (Schema::hasTable('elements')) {
            return;
        }

        Schema::create('elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slide_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('name')->nullable();
            $table->json('content')->nullable();
            $table->json('style')->nullable();
            $table->json('transform')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('locked')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('elements');
    }
};
