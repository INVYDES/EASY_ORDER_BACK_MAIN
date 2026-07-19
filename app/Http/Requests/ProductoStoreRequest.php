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
            'categoria_id' => 'nullable|exists:categorias,id',
            'stock' => 'nullable|numeric|min:0',
            'stock_minimo' => 'nullable|numeric|min:0',
            'minutos_produccion' => 'nullable|numeric|min:0|max:1440',
            'tiene_tamanos' => 'nullable|boolean',
            'activo' => 'nullable|boolean',
            'imagen' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'imagen_url' => 'nullable|url|max:500',
            'ingredientes' => 'nullable|array',
            'ingredientes.*.componente_type' => 'nullable|in:ingrediente,insumo_preparado',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $this->all();
            $tienePrecioDirecto = !empty($data['precio']) && (float) $data['precio'] > 0;
            $tienePreciosEnTamanos = false;
            $tamanosRaw = $data['tamanos_personalizados'] ?? [];
            if (is_string($tamanosRaw)) $tamanosRaw = json_decode($tamanosRaw, true) ?? [];
            if (is_array($tamanosRaw)) {
                foreach ($tamanosRaw as $t) {
                    if (!empty($t['precio']) && (float) $t['precio'] > 0) {
                        $tienePreciosEnTamanos = true;
                        break;
                    }
                }
            }
            if (!$tienePrecioDirecto && !$tienePreciosEnTamanos) {
                $validator->errors()->add('precio', 'Debe proporcionar un precio base o precios en los tamaños personalizados');
            }
        });
    }
}
