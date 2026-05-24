<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('automation_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->string('action_type'); // assign_to_agent, change_priority, add_internal_note, send_notification, mark_as_pending
            $table->text('action_value')->nullable(); // e.g. agent_id, urgent, 'Please check this immediately!', etc.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_actions');
    }
};
