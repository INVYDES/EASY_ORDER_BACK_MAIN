<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['id' => 1, 'nombre' => 'PROPIETARIO', 'descripcion' => 'Control total del sistema'],
            ['id' => 2, 'nombre' => 'ADMIN', 'descripcion' => 'Administrador del restaurante'],
            ['id' => 3, 'nombre' => 'MESERO', 'descripcion' => 'Gestiona ordenes'],
            ['id' => 4, 'nombre' => 'COCINA', 'descripcion' => 'Gestiona preparacion'],
            ['id' => 5, 'nombre' => 'CAJA', 'descripcion' => 'Gestiona pagos'],
            ['id' => 6, 'nombre' => 'CLIENTE', 'descripcion' => 'Compra'],
            ['id' => 7, 'nombre' => 'MENU', 'descripcion' => 'Vista de menú / kiosko de pedidos'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['id' => $role['id']],
                [
                    'id' => $role['id'],
                    'nombre' => $role['nombre'],
                    'descripcion' => $role['descripcion'],
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
        }
    }
}