<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LicenciaSeeder extends Seeder
{
    public function run(): void
    {
        $licencias = [
            [
                'id' => 1,
                'nombre' => 'Básica Mensual',
                'tipo' => 'MENSUAL',
                'max_restaurantes' => 1,
                'max_usuarios' => 5,
                'precio' => 299.00,
                'activo' => 1
            ],
            [
                'id' => 2,
                'nombre' => 'Premium Mensual',
                'tipo' => 'MENSUAL',
                'max_restaurantes' => 2,
                'max_usuarios' => 10,
                'precio' => 499.00,
                'activo' => 1
            ],
            [
                'id' => 3,
                'nombre' => 'Básica Anual',
                'tipo' => 'ANUAL',
                'max_restaurantes' => 1,
                'max_usuarios' => 5,
                'precio' => 2999.00,
                'activo' => 1
            ],
            [
                'id' => 4,
                'nombre' => 'Premium Anual',
                'tipo' => 'ANUAL',
                'max_restaurantes' => 2,
                'max_usuarios' => 10,
                'precio' => 4999.00,
                'activo' => 1
            ],
        ];

        foreach ($licencias as $licencia) {
            DB::table('licencias')->updateOrInsert(
                ['id' => $licencia['id']],
                $licencia
            );
        }
    }
}