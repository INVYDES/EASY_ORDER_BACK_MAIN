<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'ABIERTA' to the enum values
        DB::statement("ALTER TABLE orden_detalles MODIFY COLUMN estado_preparacion ENUM('ABIERTA', 'PENDIENTE', 'EN_PREPARACION', 'LISTO', 'ENTREGADO') NOT NULL DEFAULT 'PENDIENTE'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back without 'ABIERTA'
        DB::statement("ALTER TABLE orden_detalles MODIFY COLUMN estado_preparacion ENUM('PENDIENTE', 'EN_PREPARACION', 'LISTO', 'ENTREGADO') NOT NULL DEFAULT 'PENDIENTE'");
    }
};
