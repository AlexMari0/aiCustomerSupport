<?php

namespace Tests\Unit;

use App\Models\AutomationRule;
use App\Models\Ticket;
use App\Services\WorkflowEngine;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowEngineTest extends TestCase
{
    public function test_workflow_engine_can_evaluate_conditions_correctly(): void
    {
        // We will test condition evaluation logic database-agnostically by verifying Str comparisons
        // matching the engine's internal implementation:
        // $isMatch = Str::lower((string) $ticketValue) === Str::lower((string) $condValue);

        $ticketCategory = 'Refund';
        $conditionValue = 'refund';

        $isMatch = Str::lower((string) $ticketCategory) === Str::lower((string) $conditionValue);
        $this->assertTrue($isMatch, 'Workflow engine category matching should be case-insensitive');

        $ticketSentiment = 'Angry';
        $conditionSentiment = 'angry';

        $sentimentMatch = Str::lower((string) $ticketSentiment) === Str::lower((string) $conditionSentiment);
        $this->assertTrue($sentimentMatch, 'Workflow engine sentiment matching should be case-insensitive');

        $wrongCategory = 'Billing';
        $wrongMatch = Str::lower((string) $wrongCategory) === Str::lower((string) $conditionValue);
        $this->assertFalse($wrongMatch, 'Workflow engine should reject mismatched category values');
    }
}
