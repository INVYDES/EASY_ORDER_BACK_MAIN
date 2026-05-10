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
            'precio' => 'required|numeric|min:0|max:999999.99',
            'categoria_id' => 'nullable|exists:categorias,id',
            'stock' => 'nullable|numeric|min:0',
            'stock_minimo' => 'nullable|numeric|min:0',
            'activo' => 'nullable|boolean',
            'imagen' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'imagen_url' => 'nullable|url|max:500',
            'ingredientes' => 'nullable|array',
        ];
    }
}
