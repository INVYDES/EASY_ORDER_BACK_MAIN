<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGastoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'concepto'   => 'required|string|max:200',
            'categoria'  => 'required|in:renta,nomina,servicios,insumos,empaque,comisiones,marketing,mantenimiento,software,general',
            'monto'      => 'required|numeric|min:0.01',
            'fecha'      => 'required|date',
            'notas'      => 'nullable|string|max:500',
        ];
    }
}
