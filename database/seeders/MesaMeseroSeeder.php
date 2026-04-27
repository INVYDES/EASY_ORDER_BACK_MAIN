<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MesaMeseroSeeder extends Seeder
{
    public function run(): void
    {
        // Datos de ejemplo para asignación de mesas a meseros
        // user_id 3, 6, 7, 8 son meseros, restaurante_id=1, propietario_id=1, rol_id=3 (mesero)
        $mesasMeseros = [
            // Mesero 1 (user_id=3, Maria Garcia) - Mesas 1-10
            ['user_id' => 3, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 1],
            ['user_id' => 3, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 2],
            ['user_id' => 3, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 3],
            ['user_id' => 3, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 4],
            ['user_id' => 3, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 5],
            ['user_id' => 3, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 6],
            ['user_id' => 3, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 7],
            ['user_id' => 3, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 8],
            ['user_id' => 3, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 9],
            ['user_id' => 3, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 10],
            // Mesero 2 (user_id=6, Pedro Ramirez) - Mesas 11-20
            ['user_id' => 6, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 11],
            ['user_id' => 6, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 12],
            ['user_id' => 6, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 13],
            ['user_id' => 6, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 14],
            ['user_id' => 6, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 15],
            ['user_id' => 6, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 16],
            ['user_id' => 6, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 17],
            ['user_id' => 6, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 18],
            ['user_id' => 6, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 19],
            ['user_id' => 6, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 20],
            // Mesero 3 (user_id=7, Sofia Torres) - Mesas 21-30
            ['user_id' => 7, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 21],
            ['user_id' => 7, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 22],
            ['user_id' => 7, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 23],
            ['user_id' => 7, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 24],
            ['user_id' => 7, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 25],
            ['user_id' => 7, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 26],
            ['user_id' => 7, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 27],
            ['user_id' => 7, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 28],
            ['user_id' => 7, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 29],
            ['user_id' => 7, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 30],
            // Mesero 4 (user_id=8, Miguel Angel Flores) - Mesas 31-40
            ['user_id' => 8, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 31],
            ['user_id' => 8, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 32],
            ['user_id' => 8, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 33],
            ['user_id' => 8, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 34],
            ['user_id' => 8, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 35],
            ['user_id' => 8, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 36],
            ['user_id' => 8, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 37],
            ['user_id' => 8, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 38],
            ['user_id' => 8, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 39],
            ['user_id' => 8, 'restaurante_id' => 1, 'propietario_id' => 1, 'rol_id' => 3, 'numero_mesa' => 40],
        ];

        foreach ($mesasMeseros as $mesaMesero) {
            DB::table('mesas_meseros')->updateOrInsert(
                [
                    'user_id' => $mesaMesero['user_id'],
                    'restaurante_id' => $mesaMesero['restaurante_id'],
                    'numero_mesa' => $mesaMesero['numero_mesa']
                ],
                [
                    'propietario_id' => $mesaMesero['propietario_id'],
                    'rol_id' => $mesaMesero['rol_id'],
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
        }
    }
}
