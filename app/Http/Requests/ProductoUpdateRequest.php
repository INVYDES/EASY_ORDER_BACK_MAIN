<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Producto;

class ProductoUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('EDITAR_PRODUCTOS');
    }

    public function rules(): array
    {
        return [
            'nombre' => 'sometimes|string|max:150',
            'descripcion' => 'nullable|string|max:1000',
            'precio' => 'nullable|numeric|min:0|max:999999.99',
            'categoria_id' => 'nullable|exists:categorias,id',
            'stock' => 'nullable|numeric|min:0',
            'stock_minimo' => 'nullable|numeric|min:0',
            'minutos_produccion' => 'nullable|numeric|min:0|max:1440',
            'tiene_tamanos' => 'sometimes|boolean',
            'activo' => 'nullable|boolean',
            'imagen' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'imagen_url' => 'nullable|url|max:500',
            'ingredientes' => 'nullable|array',
            'ingredientes.*.componente_type' => 'nullable|in:ingrediente,insumo_preparado',
        ];
    }
}
