<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Producto;

class ProductoStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('CREAR_PRODUCTOS');
    }

    public function rules(): array
    {
        return [
            'nombre' => 'required|string|max:150',
            'descripcion' => 'nullable|string|max:1000',
            'precio' => 'nullable|numeric|min:0|max:999999.99',
            'precio_pequeno' => 'nullable|numeric|min:0|max:999999.99',
            'precio_mediano' => 'nullable|numeric|min:0|max:999999.99',
            'precio_grande' => 'nullable|numeric|min:0|max:999999.99',
            'categoria_id' => 'nullable|exists:categorias,id',
            'stock' => 'nullable|numeric|min:0',
            'stock_minimo' => 'nullable|numeric|min:0',
            'minutos_produccion' => 'nullable|numeric|min:0|max:1440',
            'activo' => 'nullable|boolean',
            'imagen' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'imagen_url' => 'nullable|url|max:500',
            'ingredientes' => 'nullable|array',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $this->all();
            if (empty($data['precio']) && empty($data['precio_pequeno']) && empty($data['precio_mediano']) && empty($data['precio_grande'])) {
                $validator->errors()->add('precio', 'Debe proporcionar al menos un precio (precio, precio_pequeno, precio_mediano o precio_grande)');
            }
        });
    }
}
