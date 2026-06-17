<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PropietarioSeeder extends Seeder
{
    public function run(): void
    {
        // Propietario de ejemplo
        DB::table('propietarios')->updateOrInsert(
            ['id' => 1],
            [
                'id' => 1,
                'nombre' => 'Juan',
                'apellido' => 'Perez',
                'correo' => 'juan@empresa.com',
                'telefono' => '5551234567',
                'rfc' => 'XAXX010101000',
                'regimen_fiscal' => '601',
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Asignar licencia al propietario
        DB::table('propietario_licencia')->updateOrInsert(
            ['propietario_id' => 1, 'licencia_id' => 1],
            [
                'propietario_id' => 1,
                'licencia_id' => 1,
                'fecha_inicio' => now(),
                'fecha_expiracion' => now()->addMonth(),
                'estado' => 'ACTIVA',
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
    }
}