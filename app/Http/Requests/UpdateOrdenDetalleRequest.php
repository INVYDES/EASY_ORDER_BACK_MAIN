<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrdenDetalleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'cantidad' => 'required|numeric|min:0.1|max:100',
            'comensal' => 'nullable|string|max:100',
            'comensal_id' => 'nullable|integer',
            'notas'    => 'nullable|string|max:255',
        ];
    }
}
