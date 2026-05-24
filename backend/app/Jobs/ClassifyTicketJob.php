<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\OpenAIService;
use App\Support\TicketPriorities;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ClassifyTicketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Ticket $ticket;

    /**
     * Create a new job instance.
     */
    public function __construct(Ticket $ticket)
    {
        $this->ticket = $ticket;
    }

    /**
     * Execute the job.
     */
    public function handle(OpenAIService $openai): void
    {
        // 1. Get first message
        $firstMessage = $this->ticket->messages()->orderBy('created_at')->first();
        $messageBody = $firstMessage ? $firstMessage->body : '';

        // 2. Classify ticket
        $result = $openai->classifyTicket($this->ticket->subject, $messageBody);

        // 3. Save AI suggestion metadata
        $this->ticket->update([
            'ai_category' => $result['category'],
            'ai_sentiment' => $result['sentiment'],
            'ai_priority' => $result['priority'],
            'ai_confidence' => $result['confidence'],
        ]);

        // 4. Auto-populate if empty or default
        $updates = [];
        if (empty($this->ticket->category)) {
            $updates['category'] = $result['category'];
        }
        if ($this->ticket->priority === TicketPriorities::MEDIUM) {
            $updates['priority'] = $result['priority'];
        }

        if (!empty($updates)) {
            $this->ticket->update($updates);
        }

        // 5. Fire Workflow Automation Triggers
        app(\App\Services\WorkflowEngine::class)->trigger('category_detected', $this->ticket);
        app(\App\Services\WorkflowEngine::class)->trigger('sentiment_detected', $this->ticket);

        Log::info("Ticket #{$this->ticket->id} automatically classified: Category={$result['category']}, Sentiment={$result['sentiment']}, Priority={$result['priority']} (Confidence={$result['confidence']})");
    }
}
