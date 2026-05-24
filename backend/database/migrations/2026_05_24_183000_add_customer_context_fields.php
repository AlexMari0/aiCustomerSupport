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
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('source_channel', 80)->nullable()->after('phone');
            $table->json('tags')->nullable()->after('source_channel');
            $table->timestamp('last_contacted_at')->nullable()->after('tags');

            $table->index(['organization_id', 'source_channel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'source_channel']);
            $table->dropColumn(['source_channel', 'tags', 'last_contacted_at']);
        });
    }
};
