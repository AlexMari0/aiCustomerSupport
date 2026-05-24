<?php

namespace App\Http\Controllers\Api\V1\KnowledgeBase;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\KnowledgeBase\StoreArticleRequest;
use App\Http\Requests\KnowledgeBase\UpdateArticleRequest;
use App\Models\KnowledgeBaseArticle;
use App\Models\Organization;
use App\Jobs\GenerateKnowledgeEmbeddingJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeArticleController extends ApiController
{
    /**
     * Display a listing of the articles.
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $query = KnowledgeBaseArticle::query()
            ->where('organization_id', $organization->id)
            ->with(['category:id,name,slug']);

        // Filter by category_id
        $categoryId = $request->query('category_id');
        if (is_numeric($categoryId)) {
            $query->where('category_id', (int) $categoryId);
        }

        // Filter by status (draft, published)
        $status = $request->query('status');
        if (is_string($status) && in_array($status, ['draft', 'published'], true)) {
            $query->where('status', $status);
        }

        // Search in title, content, or category name
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $searchTerm = trim($search);
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', "%{$searchTerm}%")
                    ->orWhere('content', 'like', "%{$searchTerm}%")
                    ->orWhereHas('category', function ($catQ) use ($searchTerm) {
                        $catQ->where('name', 'like', "%{$searchTerm}%");
                    });
            });
        }

        $articles = $query
            ->latest('updated_at')
            ->get()
            ->map(function (KnowledgeBaseArticle $article) {
                return [
                    'id' => $article->id,
                    'title' => $article->title,
                    'slug' => $article->slug,
                    'content' => $article->content,
                    'status' => $article->status,
                    'category' => $article->category ? [
                        'id' => $article->category->id,
                        'name' => $article->category->name,
                        'slug' => $article->category->slug,
                    ] : null,
                    'created_at' => $article->created_at,
                    'updated_at' => $article->updated_at,
                ];
            })
            ->values();

        return $this->success($articles, 'Knowledge base articles retrieved.');
    }

    /**
     * Display the specified article.
     */
    public function show(
        Request $request,
        Organization $organization,
        KnowledgeBaseArticle $article
    ): JsonResponse {
        $scopedArticle = $this->resolveScopedArticle($organization, $article);
        $scopedArticle->load(['category:id,name,slug', 'creator:id,name,email', 'updater:id,name,email']);

        return $this->success([
            'id' => $scopedArticle->id,
            'title' => $scopedArticle->title,
            'slug' => $scopedArticle->slug,
            'content' => $scopedArticle->content,
            'status' => $scopedArticle->status,
            'category' => $scopedArticle->category ? [
                'id' => $scopedArticle->category->id,
                'name' => $scopedArticle->category->name,
                'slug' => $scopedArticle->category->slug,
            ] : null,
            'creator' => $scopedArticle->creator ? [
                'id' => $scopedArticle->creator->id,
                'name' => $scopedArticle->creator->name,
            ] : null,
            'updater' => $scopedArticle->updater ? [
                'id' => $scopedArticle->updater->id,
                'name' => $scopedArticle->updater->name,
            ] : null,
            'created_at' => $scopedArticle->created_at,
            'updated_at' => $scopedArticle->updated_at,
        ], 'Article detail retrieved.');
    }

    /**
     * Store a newly created article.
     */
    public function store(StoreArticleRequest $request, Organization $organization): JsonResponse
    {
        $article = KnowledgeBaseArticle::query()->create([
            'organization_id' => $organization->id,
            'category_id' => $request->input('category_id'),
            'title' => $request->string('title')->value(),
            'slug' => $request->string('slug')->value(),
            'content' => $request->string('content')->value(),
            'status' => $request->string('status')->value() ?: 'draft',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        GenerateKnowledgeEmbeddingJob::dispatch($article);

        app(\App\Services\AuditLogger::class)->log(
            organizationId: $organization->id,
            userId: $request->user()->id,
            event: 'knowledge_article_updated',
            targetType: 'KnowledgeBaseArticle',
            targetId: $article->id,
            metadata: [
                'action' => 'created',
                'title' => $article->title,
                'status' => $article->status,
            ]
        );

        return $this->success([
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'status' => $article->status,
            'created_at' => $article->created_at,
        ], 'Article created successfully.', JsonResponse::HTTP_CREATED);
    }

    /**
     * Update the specified article.
     */
    public function update(
        UpdateArticleRequest $request,
        Organization $organization,
        KnowledgeBaseArticle $article
    ): JsonResponse {
        $scopedArticle = $this->resolveScopedArticle($organization, $article);

        $data = $request->validated();
        $data['updated_by'] = $request->user()->id;

        $scopedArticle->update($data);

        GenerateKnowledgeEmbeddingJob::dispatch($scopedArticle);

        app(\App\Services\AuditLogger::class)->log(
            organizationId: $organization->id,
            userId: $request->user()->id,
            event: 'knowledge_article_updated',
            targetType: 'KnowledgeBaseArticle',
            targetId: $scopedArticle->id,
            metadata: [
                'action' => 'updated',
                'title' => $scopedArticle->title,
                'status' => $scopedArticle->status,
            ]
        );

        return $this->success([
            'id' => $scopedArticle->id,
            'title' => $scopedArticle->title,
            'slug' => $scopedArticle->slug,
            'status' => $scopedArticle->status,
            'updated_at' => $scopedArticle->updated_at,
        ], 'Article updated successfully.');
    }

    /**
     * Remove the specified article from storage.
     */
    public function destroy(
        Request $request,
        Organization $organization,
        KnowledgeBaseArticle $article
    ): JsonResponse {
        $scopedArticle = $this->resolveScopedArticle($organization, $article);

        app(\App\Services\AuditLogger::class)->log(
            organizationId: $organization->id,
            userId: $request->user()->id,
            event: 'knowledge_article_updated',
            targetType: 'KnowledgeBaseArticle',
            targetId: $scopedArticle->id,
            metadata: [
                'action' => 'deleted',
                'title' => $scopedArticle->title,
            ]
        );

        $scopedArticle->delete();

        return $this->success(null, 'Article deleted successfully.');
    }

    /**
     * Resolve and verify article is scoped to the organization.
     */
    private function resolveScopedArticle(Organization $organization, KnowledgeBaseArticle $article): KnowledgeBaseArticle
    {
        if ((int) $article->organization_id !== (int) $organization->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Article not found in this organization.',
                'errors' => [],
            ], JsonResponse::HTTP_NOT_FOUND));
        }

        return $article;
    }
}
