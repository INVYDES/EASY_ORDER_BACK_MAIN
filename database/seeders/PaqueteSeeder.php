<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaqueteSeeder extends Seeder
{
    public function run(): void
    {
        $paquetes = [
            ['id' => 1, 'restaurante_id' => 1, 'propietario_id' => 1, 'nombre' => 'Combo Hamburguesa', 'descripcion' => 'Hamburguesa completa con bebida', 'precio' => 95.00, 'imagen' => null, 'activo' => 1],
            ['id' => 2, 'restaurante_id' => 1, 'propietario_id' => 1, 'nombre' => 'Combo Pizza Familiar', 'descripcion' => 'Pizza mediana + 2 bebidas', 'precio' => 180.00, 'imagen' => null, 'activo' => 1],
            ['id' => 3, 'restaurante_id' => 1, 'propietario_id' => 1, 'nombre' => 'Combo Cena', 'descripcion' => 'Ensalada + Plato fuerte + Postre + Bebida', 'precio' => 150.00, 'imagen' => null, 'activo' => 1],
        ];

        foreach ($paquetes as $paquete) {
            DB::table('paquetes')->updateOrInsert(
                ['id' => $paquete['id']],
                [
                    'restaurante_id' => $paquete['restaurante_id'],
                    'propietario_id' => $paquete['propietario_id'],
                    'nombre' => $paquete['nombre'],
                    'descripcion' => $paquete['descripcion'],
                    'precio' => $paquete['precio'],
                    'imagen' => $paquete['imagen'],
                    'activo' => $paquete['activo'],
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
        }
    }
}
