<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IngredienteSeeder extends Seeder
{
    public function run(): void
    {
        $ingredientes = [
            ['id' => 1, 'restaurante_id' => 1, 'nombre' => 'Carne de Res', 'unidad' => 'kg', 'costo_unitario' => 120.00, 'stock_actual' => 10.000, 'stock_minimo' => 2.000, 'proveedor' => 'Carnes del Norte', 'activo' => 1],
            ['id' => 2, 'restaurante_id' => 1, 'nombre' => 'Pan para Hamburguesa', 'unidad' => 'pieza', 'costo_unitario' => 5.00, 'stock_actual' => 50, 'stock_minimo' => 10, 'proveedor' => 'Panadería La Espiga', 'activo' => 1],
            ['id' => 3, 'restaurante_id' => 1, 'nombre' => 'Queso Manzori', 'unidad' => 'kg', 'costo_unitario' => 180.00, 'stock_actual' => 3.000, 'stock_minimo' => 1.000, 'proveedor' => 'Lácteos San José', 'activo' => 1],
            ['id' => 4, 'restaurante_id' => 1, 'nombre' => 'Lechuga', 'unidad' => 'kg', 'costo_unitario' => 25.00, 'stock_actual' => 5.000, 'stock_minimo' => 1.000, 'proveedor' => 'Verduras Frescas', 'activo' => 1],
            ['id' => 5, 'restaurante_id' => 1, 'nombre' => 'Tomate', 'unidad' => 'kg', 'costo_unitario' => 30.00, 'stock_actual' => 8.000, 'stock_minimo' => 2.000, 'proveedor' => 'Verduras Frescas', 'activo' => 1],
            ['id' => 6, 'restaurante_id' => 1, 'nombre' => 'Harina para Pizza', 'unidad' => 'kg', 'costo_unitario' => 35.00, 'stock_actual' => 15.000, 'stock_minimo' => 5.000, 'proveedor' => 'Molinos del Valle', 'activo' => 1],
            ['id' => 7, 'restaurante_id' => 1, 'nombre' => 'Salsa de Tomate', 'unidad' => 'L', 'costo_unitario' => 45.00, 'stock_actual' => 6.000, 'stock_minimo' => 2.000, 'proveedor' => 'Salsas Mexicanas', 'activo' => 1],
            ['id' => 8, 'restaurante_id' => 1, 'nombre' => 'Albahaca Fresca', 'unidad' => 'kg', 'costo_unitario' => 200.00, 'stock_actual' => 0.500, 'stock_minimo' => 0.200, 'proveedor' => 'Hierbas Aromáticas', 'activo' => 1],
            ['id' => 9, 'restaurante_id' => 1, 'nombre' => 'Pollo', 'unidad' => 'kg', 'costo_unitario' => 85.00, 'stock_actual' => 8.000, 'stock_minimo' => 2.000, 'proveedor' => 'Avícolas del Sur', 'activo' => 1],
            ['id' => 10, 'restaurante_id' => 1, 'nombre' => 'Aderezo César', 'unidad' => 'L', 'costo_unitario' => 120.00, 'stock_actual' => 3.000, 'stock_minimo' => 1.000, 'proveedor' => 'Salsas Mexicanas', 'activo' => 1],
            ['id' => 11, 'restaurante_id' => 1, 'nombre' => 'Crutones', 'unidad' => 'kg', 'costo_unitario' => 60.00, 'stock_actual' => 2.000, 'stock_minimo' => 0.500, 'proveedor' => 'Panadería La Espiga', 'activo' => 1],
            ['id' => 12, 'restaurante_id' => 1, 'nombre' => 'Chocolate para Pastel', 'unidad' => 'kg', 'costo_unitario' => 150.00, 'stock_actual' => 4.000, 'stock_minimo' => 1.000, 'proveedor' => 'Dulcería El Cacao', 'activo' => 1],
            ['id' => 13, 'restaurante_id' => 1, 'nombre' => 'Huevo', 'unidad' => 'pieza', 'costo_unitario' => 2.50, 'stock_actual' => 100, 'stock_minimo' => 24, 'proveedor' => 'Huevos San Juan', 'activo' => 1],
            ['id' => 14, 'restaurante_id' => 1, 'nombre' => 'Leche', 'unidad' => 'L', 'costo_unitario' => 22.00, 'stock_actual' => 10.000, 'stock_minimo' => 3.000, 'proveedor' => 'Lácteos San José', 'activo' => 1],
            ['id' => 15, 'restaurante_id' => 1, 'nombre' => 'Caramelo', 'unidad' => 'L', 'costo_unitario' => 40.00, 'stock_actual' => 2.000, 'stock_minimo' => 0.500, 'proveedor' => 'Dulcería El Cacao', 'activo' => 1],
        ];

        foreach ($ingredientes as $ingrediente) {
            DB::table('ingredientes')->updateOrInsert(
                ['id' => $ingrediente['id']],
                [
                    'restaurante_id' => $ingrediente['restaurante_id'],
                    'nombre' => $ingrediente['nombre'],
                    'unidad' => $ingrediente['unidad'],
                    'costo_unitario' => $ingrediente['costo_unitario'],
                    'stock_actual' => $ingrediente['stock_actual'],
                    'stock_minimo' => $ingrediente['stock_minimo'],
                    'proveedor' => $ingrediente['proveedor'],
                    'activo' => $ingrediente['activo'],
                    'deleted_at' => null,
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
        }
    }
}
