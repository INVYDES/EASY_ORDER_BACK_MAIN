<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrdenDetalleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'cantidad' => 'required|integer|min:1|max:100',
            'notas'    => 'nullable|string|max:255',
        ];
    }
}
