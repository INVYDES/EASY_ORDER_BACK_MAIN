<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrdenDetalleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'producto_id' => 'required|exists:productos,id',
            'cantidad'    => 'required|numeric|min:0.1|max:100',
            'comensal'    => 'nullable|string|max:100',
            'comensal_id' => 'nullable|integer',
        ];
    }
}
