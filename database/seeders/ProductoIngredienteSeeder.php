<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductoIngredienteSeeder extends Seeder
{
    public function run(): void
    {
        $productoIngredientes = [
            // Pastel de Chocolate (id=8): Chocolate, Huevo, Leche, Caramelo
            ['producto_id' => 8, 'ingrediente_id' => 12, 'cantidad' => 188.000],
            ['producto_id' => 8, 'ingrediente_id' => 13, 'cantidad' => 116.000],
            ['producto_id' => 8, 'ingrediente_id' => 14, 'cantidad' => 145.000],
            ['producto_id' => 8, 'ingrediente_id' => 15, 'cantidad' => 78.000],

            // Flan Napolitano (id=8): Huevo, Leche, Caramelo, Queso
            ['producto_id' => 8, 'ingrediente_id' => 13, 'cantidad' => 200.000],
            ['producto_id' => 8, 'ingrediente_id' => 14, 'cantidad' => 300.000],
            ['producto_id' => 8, 'ingrediente_id' => 15, 'cantidad' => 100.000],
            ['producto_id' => 8, 'ingrediente_id' => 3, 'cantidad' => 50.000],

            // Pizza Margarita (id=5): Harina, Salsa, Queso, Albahaca
            ['producto_id' => 5, 'ingrediente_id' => 6, 'cantidad' => 250.000],
            ['producto_id' => 5, 'ingrediente_id' => 7, 'cantidad' => 100.000],
            ['producto_id' => 5, 'ingrediente_id' => 3, 'cantidad' => 150.000],
            ['producto_id' => 5, 'ingrediente_id' => 8, 'cantidad' => 10.000],

            // Hamburguesa Clásica (id=4): Carne, Pan, Queso, Lechuga, Tomate
            ['producto_id' => 4, 'ingrediente_id' => 1, 'cantidad' => 180.000],
            ['producto_id' => 4, 'ingrediente_id' => 2, 'cantidad' => 1.000],
            ['producto_id' => 4, 'ingrediente_id' => 3, 'cantidad' => 50.000],
            ['producto_id' => 4, 'ingrediente_id' => 4, 'cantidad' => 30.000],
            ['producto_id' => 4, 'ingrediente_id' => 5, 'cantidad' => 40.000],

            // Ensalada César (id=6): Pollo, Lechuga, Aderezo, Crutones
            ['producto_id' => 6, 'ingrediente_id' => 9, 'cantidad' => 120.000],
            ['producto_id' => 6, 'ingrediente_id' => 4, 'cantidad' => 80.000],
            ['producto_id' => 6, 'ingrediente_id' => 10, 'cantidad' => 50.000],
            ['producto_id' => 6, 'ingrediente_id' => 11, 'cantidad' => 25.000],
        ];

        foreach ($productoIngredientes as $productoIngrediente) {
            DB::table('producto_ingrediente')->updateOrInsert(
                ['producto_id' => $productoIngrediente['producto_id'], 'ingrediente_id' => $productoIngrediente['ingrediente_id']],
                [
                    'cantidad' => $productoIngrediente['cantidad'],
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
        }
    }
}
