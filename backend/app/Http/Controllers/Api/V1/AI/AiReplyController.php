<?php

namespace App\Http\Controllers\Api\V1\AI;

use App\Http\Controllers\Api\ApiController;
use App\Models\AiSuggestion;
use App\Models\Organization;
use App\Models\Ticket;
use App\Services\KnowledgeSearchService;
use App\Services\OpenAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiReplyController extends ApiController
{
    protected OpenAIService $openai;
    protected KnowledgeSearchService $searchService;

    public function __construct(OpenAIService $openai, KnowledgeSearchService $searchService)
    {
        $this->openai = $openai;
        $this->searchService = $searchService;
    }

    /**
     * Generate an AI suggested reply grounded in the Knowledge Base.
     */
    public function suggest(Request $request, Organization $organization, Ticket $ticket): JsonResponse
    {
        // 1. Scoping Check: Ensure ticket belongs to organization
        if ((int) $ticket->organization_id !== (int) $organization->id) {
            return $this->error('Ticket not found in this organization.', JsonResponse::HTTP_NOT_FOUND);
        }

        // 2. Fetch all messages in ascending order to construct conversation context
        $ticket->load(['messages' => fn ($q) => $q->orderBy('created_at')]);
        
        if ($ticket->messages->isEmpty()) {
            return $this->error('Cannot generate suggestions for a ticket with no messages.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $conversationContext = '';
        foreach ($ticket->messages as $msg) {
            $sender = $msg->sender_type;
            $body = $msg->body;
            $conversationContext .= "{$sender}: {$body}\n";
        }

        // 3. Search KB articles matching the latest customer message or ticket subject
        $latestMessage = $ticket->messages->last()?->body ?? $ticket->subject;
        $matchedArticles = $this->searchService->search($organization->id, $latestMessage, 3);

        // 4. Construct grounded article context
        $articleContext = '';
        foreach ($matchedArticles as $art) {
            $articleContext .= "Article: {$art->title}\nContent: {$art->content}\n---\n";
        }

        // 5. Generate AI suggestion
        $suggestedReply = $this->openai->generateSuggestedReply($conversationContext, $articleContext);

        // 6. Record in historical suggestion table
        $prompt = "Conversation History:\n{$conversationContext}\n\nArticles Context:\n{$articleContext}";
        $suggestion = AiSuggestion::query()->create([
            'organization_id' => $organization->id,
            'ticket_id' => $ticket->id,
            'suggested_reply' => $suggestedReply,
            'prompt' => $prompt,
            'metadata' => [
                'referenced_article_ids' => $matchedArticles->pluck('id')->toArray(),
            ],
        ]);

        // 7. Get suggestion history for this ticket
        $history = AiSuggestion::query()
            ->where('ticket_id', $ticket->id)
            ->latest()
            ->get()
            ->map(function (AiSuggestion $s) {
                return [
                    'id' => $s->id,
                    'suggested_reply' => $s->suggested_reply,
                    'created_at' => $s->created_at,
                ];
            })
            ->values();

        return $this->success([
            'suggested_reply' => $suggestedReply,
            'referenced_articles' => $matchedArticles->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'slug' => $a->slug,
            ])->values(),
            'history' => $history,
        ], 'AI suggested reply generated.');
    }
}
