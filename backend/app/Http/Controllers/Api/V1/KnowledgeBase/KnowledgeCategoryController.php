<?php

namespace App\Http\Controllers\Api\V1\KnowledgeBase;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\KnowledgeBase\StoreCategoryRequest;
use App\Http\Requests\KnowledgeBase\UpdateCategoryRequest;
use App\Models\KnowledgeBaseCategory;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeCategoryController extends ApiController
{
    /**
     * Display a listing of the categories.
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $categories = KnowledgeBaseCategory::query()
            ->where('organization_id', $organization->id)
            ->withCount('articles')
            ->orderBy('name')
            ->get()
            ->map(function (KnowledgeBaseCategory $category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'articles_count' => $category->articles_count,
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                ];
            })
            ->values();

        return $this->success($categories, 'Knowledge base categories retrieved.');
    }

    /**
     * Store a newly created category.
     */
    public function store(StoreCategoryRequest $request, Organization $organization): JsonResponse
    {
        $category = KnowledgeBaseCategory::query()->create([
            'organization_id' => $organization->id,
            'name' => $request->string('name')->value(),
            'slug' => $request->string('slug')->value(),
            'description' => $request->string('description')->value() ?: null,
            'created_by' => $request->user()->id,
        ]);

        return $this->success([
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'created_at' => $category->created_at,
        ], 'Category created successfully.', JsonResponse::HTTP_CREATED);
    }

    /**
     * Update the specified category.
     */
    public function update(
        UpdateCategoryRequest $request,
        Organization $organization,
        KnowledgeBaseCategory $category
    ): JsonResponse {
        $scopedCategory = $this->resolveScopedCategory($organization, $category);

        $scopedCategory->update($request->validated());

        return $this->success([
            'id' => $scopedCategory->id,
            'name' => $scopedCategory->name,
            'slug' => $scopedCategory->slug,
            'description' => $scopedCategory->description,
            'updated_at' => $scopedCategory->updated_at,
        ], 'Category updated successfully.');
    }

    /**
     * Remove the specified category.
     */
    public function destroy(
        Request $request,
        Organization $organization,
        KnowledgeBaseCategory $category
    ): JsonResponse {
        $scopedCategory = $this->resolveScopedCategory($organization, $category);

        $scopedCategory->delete();

        return $this->success(null, 'Category deleted successfully.');
    }

    /**
     * Resolve and verify category is scoped to the organization.
     */
    private function resolveScopedCategory(Organization $organization, KnowledgeBaseCategory $category): KnowledgeBaseCategory
    {
        if ((int) $category->organization_id !== (int) $organization->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Category not found in this organization.',
                'errors' => [],
            ], JsonResponse::HTTP_NOT_FOUND));
        }

        return $category;
    }
}
