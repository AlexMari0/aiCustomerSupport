<?php

namespace App\Jobs;

use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeEmbedding;
use App\Services\OpenAIService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateKnowledgeEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected KnowledgeBaseArticle $article;

    /**
     * Create a new job instance.
     */
    public function __construct(KnowledgeBaseArticle $article)
    {
        $this->article = $article;
    }

    /**
     * Execute the job.
     */
    public function handle(OpenAIService $openai): void
    {
        // Concatenate title and content to embed the full context
        $textToEmbed = "Title: " . $this->article->title . "\n\nContent: " . $this->article->content;

        // Generate embedding via OpenAIService
        $embedding = $openai->generateEmbedding($textToEmbed);

        // Update or create the embedding record scoped to the organization and article
        KnowledgeEmbedding::query()->updateOrCreate(
            [
                'organization_id' => $this->article->organization_id,
                'article_id' => $this->article->id,
            ],
            [
                'embedding' => $embedding,
            ]
        );
    }
}
