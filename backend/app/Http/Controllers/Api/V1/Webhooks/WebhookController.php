<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use App\Models\WebhookEvent;
use App\Jobs\ProcessWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends ApiController
{
    /**
     * Public endpoint to ingest external webhook messages.
     * POST /api/webhooks/inbound-message?token=ORG_WEBHOOK_TOKEN
     */
    public function inboundMessage(Request $request): JsonResponse
    {
        $token = $request->query('token');

        if (empty($token)) {
            return $this->error('Missing organization webhook token.', 401);
        }

        $organization = Organization::query()->where('webhook_token', $token)->first();

        if (!$organization) {
            return $this->error('Invalid organization webhook token.', 401);
        }

        $request->validate([
            'channel' => 'required|string|in:whatsapp,email,website_chat,public_form',
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'required_if:channel,whatsapp|nullable|string|max:50',
            'customer_email' => 'required_unless:channel,whatsapp|nullable|email|max:255',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string',
        ]);

        $payload = $request->only([
            'channel',
            'customer_name',
            'customer_phone',
            'customer_email',
            'subject',
            'message',
        ]);

        $webhookEvent = WebhookEvent::query()->create([
            'organization_id' => $organization->id,
            'channel' => $payload['channel'],
            'payload' => $payload,
            'status' => 'pending',
        ]);

        // Dispatch background processing job
        ProcessWebhookJob::dispatch($webhookEvent);

        return $this->success(
            $webhookEvent,
            'Webhook received and processing scheduled.',
            JsonResponse::HTTP_ACCEPTED
        );
    }

    /**
     * Retrieve webhook events logs for the organization.
     * GET /api/v1/organizations/{organization}/webhooks/logs
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $logs = WebhookEvent::query()
            ->where('organization_id', $organization->id)
            ->with(['ticket:id,subject,status,priority'])
            ->latest()
            ->get();

        return $this->success($logs, 'Webhook logs retrieved.');
    }

    /**
     * Manually retry a failed webhook event.
     * POST /api/v1/organizations/{organization}/webhooks/logs/{webhookEvent}/retry
     */
    public function retry(Request $request, Organization $organization, WebhookEvent $webhookEvent): JsonResponse
    {
        if ($webhookEvent->organization_id !== $organization->id) {
            return $this->error('Webhook event not found.', 404);
        }

        if ($webhookEvent->status !== 'failed') {
            return $this->error('Only failed webhook events can be retried.', 400);
        }

        // Set status to pending for retry
        $webhookEvent->update([
            'status' => 'pending',
        ]);

        // Re-dispatch background processing job
        ProcessWebhookJob::dispatch($webhookEvent);

        return $this->success($webhookEvent, 'Webhook event scheduled for retry.');
    }
}
