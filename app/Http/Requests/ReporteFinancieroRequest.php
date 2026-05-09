<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReporteFinancieroRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'fecha_inicio'      => 'sometimes|date',
            'fecha_fin'         => 'sometimes|date|after_or_equal:fecha_inicio',
            'utilidad_objetivo' => 'sometimes|numeric|min:0',
        ];
    }
}
