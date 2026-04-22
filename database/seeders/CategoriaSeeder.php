<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoriaSeeder extends Seeder
{
    public function run(): void
    {
        $categorias = [
            [
                'restaurante_id' => 1,
                'nombre' => 'Bebidas',
                'descripcion' => 'Bebidas frías y calientes',
                'color' => '#3B82F6',
                'activo' => 1
            ],
            [
                'restaurante_id' => 1,
                'nombre' => 'Comidas',
                'descripcion' => 'Platillos principales',
                'color' => '#10B981',
                'activo' => 1
            ],
            [
                'restaurante_id' => 1,
                'nombre' => 'Postres',
                'descripcion' => 'Dulces y postres',
                'color' => '#F59E0B',
                'activo' => 1
            ],
        ];

        foreach ($categorias as $categoria) {
            DB::table('categorias')->insert([
                'restaurante_id' => $categoria['restaurante_id'],
                'nombre' => $categoria['nombre'],
                'descripcion' => $categoria['descripcion'],
                'color' => $categoria['color'],
                'activo' => $categoria['activo'],
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }
}