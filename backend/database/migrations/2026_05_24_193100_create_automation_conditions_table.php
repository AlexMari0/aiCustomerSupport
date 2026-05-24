<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('automation_conditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->string('field'); // category, priority, sentiment, status
            $table->string('operator')->default('equals');
            $table->string('value'); // e.g. refund, urgent, angry, open
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_conditions');
    }
};
