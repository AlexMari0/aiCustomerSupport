<?php

namespace App\Http\Requests\Tickets;

use App\Support\TicketMessageSenderTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketMessageRequest extends FormRequest
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
            'sender_type' => ['required', 'string', Rule::in(TicketMessageSenderTypes::all())],
            'body' => ['required', 'string', 'min:1'],
        ];
    }
}
