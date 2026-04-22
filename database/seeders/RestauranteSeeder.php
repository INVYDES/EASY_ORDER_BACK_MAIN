<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RestauranteSeeder extends Seeder
{
    public function run(): void
    {
        // Restaurante Centro
        DB::table('restaurantes')->updateOrInsert(
            ['id' => 1],
            [
                'id' => 1,
                'propietario_id' => 1,
                'nombre' => 'Restaurante Centro',
                'calle' => 'Av Principal 123',
                'ciudad' => 'CDMX',
                'estado' => 'CDMX',
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Asignar usuarios al restaurante
        for ($userId = 1; $userId <= 5; $userId++) {
            DB::table('restaurante_user')->updateOrInsert(
                ['user_id' => $userId, 'restaurante_id' => 1],
                [
                    'user_id' => $userId,
                    'restaurante_id' => 1,
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
        }

        // Actualizar restaurante_activo para todos los usuarios
        for ($userId = 1; $userId <= 5; $userId++) {
            DB::table('users')
                ->where('id', $userId)
                ->update(['restaurante_activo' => 1]);
        }

        // Restaurante Norte
        DB::table('restaurantes')->updateOrInsert(
            ['id' => 2],
            [
                'id' => 2,
                'propietario_id' => 1,
                'nombre' => 'Restaurante Norte',
                'calle' => 'Calle Norte 456',
                'ciudad' => 'CDMX',
                'estado' => 'CDMX',
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Asignar usuarios al restaurante Norte (solo propietario y admin)
        DB::table('restaurante_user')->updateOrInsert(
            ['user_id' => 1, 'restaurante_id' => 2],
            [
                'user_id' => 1,
                'restaurante_id' => 2,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
        
        DB::table('restaurante_user')->updateOrInsert(
            ['user_id' => 2, 'restaurante_id' => 2],
            [
                'user_id' => 2,
                'restaurante_id' => 2,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
    }
}