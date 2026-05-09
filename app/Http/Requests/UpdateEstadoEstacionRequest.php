<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEstadoEstacionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'estacion' => 'required|string|in:cocina,barra,postres',
            'estado'   => 'required|string|in:PENDIENTE,EN_PREPARACION,LISTO',
        ];
    }
}
