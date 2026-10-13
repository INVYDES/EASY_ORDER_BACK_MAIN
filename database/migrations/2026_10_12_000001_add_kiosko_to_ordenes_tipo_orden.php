<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Amplía `ordenes.tipo_orden` con el valor `kiosko`.
 *
 * Las órdenes creadas en el kiosko (pantalla de autoservicio) se marcan con este
 * tipo para distinguirlas de las de mesero (`local`) y que no entren al flujo de
 * mesero cuando el restaurante tiene `servicio_rapido` activo.
 *
 * Es aditivo: las órdenes existentes no cambian y el default sigue siendo `local`.
 * Aplicar ANTES de desplegar el kiosko, si no el alta de órdenes del kiosko falla.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE ordenes MODIFY tipo_orden ENUM('local','pickup','delivery','kiosko') NOT NULL DEFAULT 'local'");
    }

    public function down(): void
    {
        // Solo seguro si ninguna orden usa 'kiosko'.
        DB::statement("UPDATE ordenes SET tipo_orden = 'local' WHERE tipo_orden = 'kiosko'");
        DB::statement("ALTER TABLE ordenes MODIFY tipo_orden ENUM('local','pickup','delivery') NOT NULL DEFAULT 'local'");
    }
};
