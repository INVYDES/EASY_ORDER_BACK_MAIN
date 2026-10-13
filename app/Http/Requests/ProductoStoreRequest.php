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

    /**
     * El frontend manda los tamaños en `tamanos` (el controller acepta ambas
     * llaves), así que se normalizan aquí antes de validar. Además, si el precio
     * sólo viene en los tamaños se usa el más barato como precio base, porque la
     * columna `productos.precio` no acepta NULL.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('tamanos_personalizados') && $this->has('tamanos')) {
            $this->merge(['tamanos_personalizados' => $this->input('tamanos')]);
        }

        if ((float) ($this->input('precio') ?? 0) <= 0) {
            $menorPrecio = $this->menorPrecioDeTamanos();
            if ($menorPrecio !== null) {
                $this->merge(['precio' => $menorPrecio]);
            }
        }
    }

    /**
     * Tamaños enviados, ya decodificados (el frontend los manda como JSON cuando
     * el producto se guarda con imagen / FormData).
     */
    private function tamanosDecodificados(): array
    {
        $raw = $this->input('tamanos_personalizados') ?? $this->input('tamanos');
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * Precio más barato definido en los tamaños (null si ninguno tiene precio).
     */
    private function menorPrecioDeTamanos(): ?float
    {
        $precios = [];
        foreach ($this->tamanosDecodificados() as $tamano) {
            if (is_array($tamano) && isset($tamano['precio']) && is_numeric($tamano['precio']) && (float) $tamano['precio'] > 0) {
                $precios[] = (float) $tamano['precio'];
            }
        }

        return $precios ? min($precios) : null;
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $tienePrecioDirecto = (float) ($this->input('precio') ?? 0) > 0;
            $tienePreciosEnTamanos = false;

            foreach ($this->tamanosDecodificados() as $tamano) {
                if (is_array($tamano) && !empty($tamano['precio']) && (float) $tamano['precio'] > 0) {
                    $tienePreciosEnTamanos = true;
                    break;
                }
            }

            if (!$tienePrecioDirecto && !$tienePreciosEnTamanos) {
                $validator->errors()->add('precio', 'Debe proporcionar un precio base o precios en los tamaños personalizados');
            }
        });
    }
}
