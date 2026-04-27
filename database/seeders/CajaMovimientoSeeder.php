<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CajaMovimientoSeeder extends Seeder
{
    public function run(): void
    {
        $movimientos = [
            [
                'caja_id' => 1,
                'usuario_id' => 1,
                'tipo' => 'apertura',
                'monto' => 1000.00,
                'descripcion' => 'Apertura de caja - Turno matutino',
                'referencia' => 'AP-001',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ];

        foreach ($movimientos as $movimiento) {
            DB::table('caja_movimientos')->updateOrInsert(
                ['caja_id' => $movimiento['caja_id'], 'usuario_id' => $movimiento['usuario_id'], 'tipo' => $movimiento['tipo']],
                $movimiento
            );
        }
    }
}
