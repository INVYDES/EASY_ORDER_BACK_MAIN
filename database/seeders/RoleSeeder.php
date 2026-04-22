<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['nombre' => 'PROPIETARIO', 'descripcion' => 'Control total del sistema'],
            ['nombre' => 'ADMIN', 'descripcion' => 'Administrador del restaurante'],
            ['nombre' => 'MESERO', 'descripcion' => 'Gestiona ordenes'],
            ['nombre' => 'COCINA', 'descripcion' => 'Gestiona preparacion'],
            ['nombre' => 'CAJA', 'descripcion' => 'Gestiona pagos'],
            ['nombre' => 'BARRA', 'descripcion' => 'Gestiona bebidas'],
            ['nombre' => 'MENU', 'descripcion' => 'Vista de menú / kiosko de pedidos'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['nombre' => $role['nombre']],
                [
                    'nombre' => $role['nombre'],
                    'descripcion' => $role['descripcion'],
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
        }
    }
}