<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\WebhookEvent;
use App\Support\TicketPriorities;
use App\Support\TicketStatuses;
use App\Services\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected WebhookEvent $webhookEvent;

    /**
     * Create a new job instance.
     */
    public function __construct(WebhookEvent $webhookEvent)
    {
        $this->webhookEvent = $webhookEvent;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->webhookEvent->update(['status' => 'processing']);

        try {
            $payload = $this->webhookEvent->payload;
            $channel = $this->webhookEvent->channel;
            $organizationId = $this->webhookEvent->organization_id;

            $customerName = $payload['customer_name'] ?? 'Anonymous Customer';
            $message = $payload['message'] ?? '';
            $subject = $payload['subject'] ?? null;

            // 1. Resolve or Create Customer based on channel
            $customer = null;
            if ($channel === 'whatsapp') {
                $customerPhone = $payload['customer_phone'] ?? '';
                if (empty($customerPhone)) {
                    throw new \InvalidArgumentException("Missing 'customer_phone' for WhatsApp channel.");
                }

                $customer = Customer::query()->firstOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'phone' => $customerPhone,
                    ],
                    [
                        'name' => $customerName,
                        'source_channel' => 'whatsapp',
                        'tags' => ['Auto-Created'],
                    ]
                );
            } else {
                $customerEmail = $payload['customer_email'] ?? '';
                if (empty($customerEmail)) {
                    throw new \InvalidArgumentException("Missing 'customer_email' for email/website_chat/public_form channel.");
                }

                $customer = Customer::query()->firstOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'email' => $customerEmail,
                    ],
                    [
                        'name' => $customerName,
                        'source_channel' => $channel,
                        'tags' => ['Auto-Created'],
                    ]
                );
            }

            // Update last contacted timestamp
            $customer->update(['last_contacted_at' => now()]);

            // 2. Generate Ticket Subject if not provided
            if (empty($subject)) {
                $subject = Str::limit($message, 50) ?: 'Inbound message via ' . ucfirst($channel);
            }

            // 3. Map channel to ticket source channel
            $ticketSource = 'web';
            if ($channel === 'whatsapp') {
                $ticketSource = 'whatsapp';
            } elseif ($channel === 'email') {
                $ticketSource = 'email';
            }

            // 4. Create Ticket
            $ticket = Ticket::query()->create([
                'organization_id' => $organizationId,
                'customer_id' => $customer->id,
                'subject' => $subject,
                'status' => TicketStatuses::OPEN,
                'priority' => TicketPriorities::MEDIUM,
                'source_channel' => $ticketSource,
            ]);

            // 5. Create Ticket Message
            TicketMessage::query()->create([
                'organization_id' => $organizationId,
                'ticket_id' => $ticket->id,
                'sender_type' => 'customer',
                'body' => $message,
                'is_ai_generated' => false,
            ]);

            // 6. Connect webhook event to newly created ticket
            $this->webhookEvent->update([
                'ticket_id' => $ticket->id,
                'status' => 'processed',
                'error_message' => null,
            ]);

            // 7. Dispatch synchronous or asynchronous AI classification job
            ClassifyTicketJob::dispatch($ticket);

            // 8. Fire Workflow Automation Triggers for ticket creation
            app(WorkflowEngine::class)->trigger('ticket_created', $ticket);

            Log::info("Webhook Event #{$this->webhookEvent->id} processed successfully. Created Ticket #{$ticket->id} for Customer #{$customer->id}.");

        } catch (\Throwable $exception) {
            Log::error("Failed to process Webhook Event #{$this->webhookEvent->id}: " . $exception->getMessage());

            $this->webhookEvent->update([
                'status' => 'failed',
                'retry_count' => $this->webhookEvent->retry_count + 1,
                'error_message' => $exception->getMessage() . "\n" . $exception->getTraceAsString(),
            ]);

            // We do not rethrow since we want it to sit in a 'failed' state for manual retry in our logs.
        }
    }
}
