<?php

namespace App\Services;

use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeEmbedding;
use Illuminate\Support\Collection;

class KnowledgeSearchService
{
    protected OpenAIService $openai;

    public function __construct(OpenAIService $openai)
    {
        $this->openai = $openai;
    }

    /**
     * Search relevant articles for an organization using semantic cosine similarity.
     * Falls back to a SQL keyword search if no embeddings exist yet.
     *
     * @return Collection<int, KnowledgeBaseArticle>
     */
    public function search(int $organizationId, string $queryText, int $limit = 3, float $threshold = -1.0): Collection
    {
        if (trim($queryText) === '') {
            return collect();
        }

        // Fetch all article embeddings in this organization scoped to published articles
        $embeddings = KnowledgeEmbedding::query()
            ->where('organization_id', $organizationId)
            ->whereHas('article', function ($q) {
                $q->where('status', 'published');
            })
            ->with('article')
            ->get();

        // Fallback: If no embeddings are found in the DB, run a text-based LIKE match
        if ($embeddings->isEmpty()) {
            return $this->textSearchFallback($organizationId, $queryText, $limit);
        }

        // Generate query embedding
        $queryVector = $this->openai->generateEmbedding($queryText);

        $results = collect();

        foreach ($embeddings as $kbEmbed) {
            $articleVector = $kbEmbed->embedding;
            if (!is_array($articleVector) || empty($articleVector)) {
                continue;
            }

            $similarity = $this->computeCosineSimilarity($queryVector, $articleVector);

            if ($similarity >= $threshold) {
                $results->push([
                    'article' => $kbEmbed->article,
                    'similarity' => $similarity,
                ]);
            }
        }

        // Sort by similarity descending, take the limit, and extract the article models
        return $results->sortByDesc('similarity')
            ->take($limit)
            ->pluck('article')
            ->filter()
            ->values();
    }

    /**
     * Compute cosine similarity between two float vectors.
     * similarity = (A . B) / (||A|| * ||B||)
     */
    protected function computeCosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;
        $count = min(count($vec1), count($vec2));

        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] * $vec1[$i];
            $norm2 += $vec2[$i] * $vec2[$i];
        }

        if ($norm1 === 0.0 || $norm2 === 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($norm1) * sqrt($norm2));
    }

    /**
     * Fallback standard database search using LIKE operations.
     */
    protected function textSearchFallback(int $organizationId, string $queryText, int $limit): Collection
    {
        $searchTerm = trim($queryText);
        
        // Split search terms into words for a slightly smarter matching system
        $words = array_filter(explode(' ', $searchTerm), fn($w) => strlen($w) > 2);
        
        $query = KnowledgeBaseArticle::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'published');

        if (!empty($words)) {
            $query->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('title', 'like', "%{$word}%")
                      ->orWhere('content', 'like', "%{$word}%");
                }
            });
        } else {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', "%{$searchTerm}%")
                  ->orWhere('content', 'like', "%{$searchTerm}%");
            });
        }

        return $query->limit($limit)->get();
    }
}
