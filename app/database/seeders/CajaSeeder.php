<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CajaSeeder extends Seeder
{
    public function run(): void
    {
        $cajas = [
            [
                'id' => 1,
                'restaurante_id' => 1,
                'usuario_apertura_id' => 1,
                'usuario_cierre_id' => null,
                'fecha_apertura' => now()->format('Y-m-d H:i:s'),
                'fecha_cierre' => null,
                'monto_inicial' => 1000.00,
                'monto_final' => null,
                'ventas_efectivo' => 0.00,
                'ventas_tarjeta' => 0.00,
                'ventas_transferencia' => 0.00,
                'total_ordenes' => 0,
                'diferencia' => null,
                'observaciones_cierre' => null,
                'estado' => 'abierta',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ];

        foreach ($cajas as $caja) {
            DB::table('cajas')->updateOrInsert(
                ['id' => $caja['id']],
                $caja
            );
        }
    }
}
