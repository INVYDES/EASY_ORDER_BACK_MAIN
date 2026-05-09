<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VentasReporteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // any authenticated user can request a report
    }

    public function rules(): array
    {
        return [
            'fecha_inicio' => 'sometimes|date',
            'fecha_fin' => 'sometimes|date|after_or_equal:fecha_inicio',
            'grupo' => 'sometimes|in:dia,semana,mes',
        ];
    }
}
