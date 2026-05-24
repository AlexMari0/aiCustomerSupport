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
        Schema::table('tickets', function (Blueprint $table): void {
            $table->string('ai_category')->nullable();
            $table->string('ai_sentiment')->nullable();
            $table->string('ai_priority')->nullable();
            $table->double('ai_confidence')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropColumn(['ai_category', 'ai_sentiment', 'ai_priority', 'ai_confidence']);
        });
    }
};
