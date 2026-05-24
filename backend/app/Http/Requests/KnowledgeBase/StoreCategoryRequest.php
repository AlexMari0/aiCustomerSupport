<?php

namespace App\Http\Requests\KnowledgeBase;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
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

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('knowledge_base_categories')->where('organization_id', $organizationId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Custom messages for validation.
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug must only contain lowercase letters, numbers, and hyphens, and cannot start or end with a hyphen.',
            'slug.unique' => 'This category slug is already in use for this organization.',
        ];
    }
}
