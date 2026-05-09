<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Cliente;

class ClienteUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('EDITAR_CLIENTES');
    }

    public function rules(): array
    {
        $restaurante = app('restaurante_activo');
        return [
            'nombre' => 'sometimes|string|max:255',
            'apellido' => 'nullable|string|max:255',
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('clientes')->where(function ($query) use ($restaurante) {
                    return $query->where('restaurante_id', $restaurante->id);
                })->ignore($this->route('cliente')?)
            ],
            'telefono' => 'nullable|string|max:20',
            'direccion' => 'nullable|string',
            'notas' => 'nullable|string',
            'activo' => 'sometimes|boolean',
        ];
    }
}
