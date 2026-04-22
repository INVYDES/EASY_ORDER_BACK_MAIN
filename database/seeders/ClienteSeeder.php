<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClienteSeeder extends Seeder
{
    public function run(): void
    {
        $clientes = [
            [
                'restaurante_id' => 1,
                'nombre' => 'Carlos Rodríguez',
                'email' => 'carlos@email.com',
                'telefono' => '5551112233'
            ],
            [
                'restaurante_id' => 1,
                'nombre' => 'Ana Martínez',
                'email' => 'ana@email.com',
                'telefono' => '5552223344'
            ],
            [
                'restaurante_id' => 1,
                'nombre' => 'Roberto Sánchez',
                'email' => 'roberto@email.com',
                'telefono' => '5553334455'
            ],
        ];

        foreach ($clientes as $cliente) {
            DB::table('clientes')->insert([
                'restaurante_id' => $cliente['restaurante_id'],
                'nombre' => $cliente['nombre'],
                'email' => $cliente['email'],
                'telefono' => $cliente['telefono'],
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }
}