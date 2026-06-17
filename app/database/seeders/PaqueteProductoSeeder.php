<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaqueteProductoSeeder extends Seeder
{
    public function run(): void
    {
        $paquetesProductos = [
            // Combo Hamburguesa (id=1): Hamburguesa Clásica + Coca Cola
            ['paquete_id' => 1, 'producto_id' => 4, 'cantidad' => 1], // Hamburguesa Clásica
            ['paquete_id' => 1, 'producto_id' => 1, 'cantidad' => 1], // Coca Cola

            // Combo Pizza Familiar (id=2): Pizza Margarita + 2 Coca Colas
            ['paquete_id' => 2, 'producto_id' => 5, 'cantidad' => 1], // Pizza Margarita
            ['paquete_id' => 2, 'producto_id' => 1, 'cantidad' => 2], // 2 Coca Colas

            // Combo Cena (id=3): Ensalada César + (hamburguesa como plato fuerte) + Flan + Agua
            ['paquete_id' => 3, 'producto_id' => 6, 'cantidad' => 1], // Ensalada César
            ['paquete_id' => 3, 'producto_id' => 4, 'cantidad' => 1], // Hamburguesa Clásica
            ['paquete_id' => 3, 'producto_id' => 8, 'cantidad' => 1], // Flan Napolitano
            ['paquete_id' => 3, 'producto_id' => 2, 'cantidad' => 1], // Agua Natural
        ];

        foreach ($paquetesProductos as $paqueteProducto) {
            DB::table('paquete_producto')->updateOrInsert(
                ['paquete_id' => $paqueteProducto['paquete_id'], 'producto_id' => $paqueteProducto['producto_id']],
                [
                    'cantidad' => $paqueteProducto['cantidad'],
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
        }
    }
}
