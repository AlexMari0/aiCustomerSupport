<?php

namespace App\Http\Requests\Tickets;

use App\Support\TicketPriorities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['required_without:customer_id', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_source_channel' => ['nullable', 'string', 'max:80'],
            'customer_tags' => ['nullable', 'array'],
            'customer_tags.*' => ['string', 'max:80'],
            'subject' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'string', Rule::in(TicketPriorities::all())],
            'source_channel' => ['nullable', 'string', 'max:80'],
            'message' => ['required', 'string', 'min:1'],
        ];
    }
}
