<?php

namespace App\Http\Requests\KnowledgeBase;

use App\Models\Organization;
use App\Models\KnowledgeBaseArticle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateArticleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $organization = $this->route('organization');
        $organizationId = $organization instanceof Organization ? $organization->id : (int) $organization;

        $article = $this->route('article');
        $articleId = $article instanceof KnowledgeBaseArticle ? $article->id : (int) $article;

        return [
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('knowledge_base_categories', 'id')->where('organization_id', $organizationId),
            ],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('knowledge_base_articles')
                    ->where('organization_id', $organizationId)
                    ->ignore($articleId),
            ],
            'content' => ['sometimes', 'required', 'string', 'min:1'],
            'status' => ['sometimes', 'required', 'string', Rule::in(['draft', 'published'])],
        ];
    }

    /**
     * Custom messages for validation.
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug must only contain lowercase letters, numbers, and hyphens, and cannot start or end with a hyphen.',
            'slug.unique' => 'This article slug is already in use for this organization.',
            'category_id.exists' => 'The selected category is invalid or does not belong to this organization.',
        ];
    }
}
