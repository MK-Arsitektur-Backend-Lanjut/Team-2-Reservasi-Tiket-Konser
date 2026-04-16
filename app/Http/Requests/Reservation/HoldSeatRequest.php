<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Foundation\Http\FormRequest;

class HoldSeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'venue_id'    => ['required', 'integer', 'exists:venues,id'],
            'seat_id'     => ['required', 'integer', 'exists:seats,id'],
            'queue_token' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'venue_id.required'    => 'venue_id wajib diisi.',
            'venue_id.integer'     => 'venue_id harus berupa angka.',
            'venue_id.exists'      => 'Venue tidak ditemukan.',
            'seat_id.required'     => 'seat_id wajib diisi.',
            'seat_id.integer'      => 'seat_id harus berupa angka.',
            'seat_id.exists'       => 'Kursi tidak ditemukan.',
            'queue_token.required' => 'queue_token wajib diisi.',
            'queue_token.string'   => 'queue_token harus berupa string.',
        ];
    }
}
