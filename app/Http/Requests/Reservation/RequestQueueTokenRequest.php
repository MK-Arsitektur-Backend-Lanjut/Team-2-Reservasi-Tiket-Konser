<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Foundation\Http\FormRequest;

class RequestQueueTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'venue_id.required' => 'venue_id wajib diisi.',
            'venue_id.integer'  => 'venue_id harus berupa angka.',
            'venue_id.exists'   => 'Venue tidak ditemukan.',
        ];
    }
}
