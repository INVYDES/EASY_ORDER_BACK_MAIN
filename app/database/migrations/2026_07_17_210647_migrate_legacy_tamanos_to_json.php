<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $productos = DB::table('productos')
            ->where('tiene_tamanos', true)
            ->where(function ($q) {
                $q->whereNull('tamanos_personalizados')
                  ->orWhere('tamanos_personalizados', '[]')
                  ->orWhere('tamanos_personalizados', '{}')
                  ->orWhere('tamanos_personalizados', '');
            })
            ->get();

        foreach ($productos as $p) {
            $tamanos = [];

            $precioPeq = (float) ($p->precio_pequeno ?? $p->precio ?? 0);
            $stockPeq  = (int) ($p->stock_pequeno ?? $p->stock ?? 0);
            if ($precioPeq > 0 || $stockPeq > 0) {
                $tamanos[] = [
                    'key'    => 'pequeno',
                    'nombre' => 'Pequeño',
                    'precio' => $precioPeq,
                    'stock'  => $stockPeq,
                ];
            }

            $precioMed = (float) ($p->precio_mediano ?? 0);
            $stockMed  = (int) ($p->stock_mediano ?? 0);
            if ($precioMed > 0 || $stockMed > 0) {
                $tamanos[] = [
                    'key'    => 'mediano',
                    'nombre' => 'Mediano',
                    'precio' => $precioMed,
                    'stock'  => $stockMed,
                ];
            }

            $precioGra = (float) ($p->precio_grande ?? 0);
            $stockGra  = (int) ($p->stock_grande ?? 0);
            if ($precioGra > 0 || $stockGra > 0) {
                $tamanos[] = [
                    'key'    => 'grande',
                    'nombre' => 'Grande',
                    'precio' => $precioGra,
                    'stock'  => $stockGra,
                ];
            }

            if (!empty($tamanos)) {
                DB::table('productos')
                    ->where('id', $p->id)
                    ->update(['tamanos_personalizados' => json_encode($tamanos)]);
            }
        }
    }

    public function down(): void
    {
    }
};
