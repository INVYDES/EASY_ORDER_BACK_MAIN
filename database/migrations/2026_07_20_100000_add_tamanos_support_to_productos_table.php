<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Crear tabla producto_tamanos
        if (!Schema::hasTable('producto_tamanos')) {
            Schema::create('producto_tamanos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('restaurante_id')->index();
                $table->unsignedBigInteger('producto_id');
                $table->string('nombre', 100);
                $table->decimal('precio', 10, 2)->default(0);
                $table->decimal('costo', 10, 4)->default(0);
                $table->decimal('stock', 10, 2)->default(0);
                $table->decimal('stock_minimo', 10, 2)->default(5);
                $table->timestamps();

                $table->foreign('producto_id')
                      ->references('id')
                      ->on('productos')
                      ->onDelete('cascade');
            });
        }

        // 2. Agregar tamano_id a ingredientes_de_productos
        if (Schema::hasTable('ingredientes_de_productos')) {
            Schema::table('ingredientes_de_productos', function (Blueprint $table) {
                if (!Schema::hasColumn('ingredientes_de_productos', 'tamano_id')) {
                    $table->unsignedBigInteger('tamano_id')->nullable()->after('producto_id');
                    $table->foreign('tamano_id')
                          ->references('id')
                          ->on('producto_tamanos')
                          ->onDelete('cascade');
                }
            });
        }

        // 3. Agregar tamano_id y tamano_nombre a orden_detalles
        if (Schema::hasTable('orden_detalles')) {
            Schema::table('orden_detalles', function (Blueprint $table) {
                if (!Schema::hasColumn('orden_detalles', 'tamano_id')) {
                    $table->unsignedBigInteger('tamano_id')->nullable()->after('producto_id');
                }
                if (!Schema::hasColumn('orden_detalles', 'tamano_nombre')) {
                    $table->string('tamano_nombre', 100)->nullable()->after('tamano_id');
                }
            });
        }

        // 4. Agregar tamano_id a paquete_producto
        if (Schema::hasTable('paquete_producto')) {
            Schema::table('paquete_producto', function (Blueprint $table) {
                if (!Schema::hasColumn('paquete_producto', 'tamano_id')) {
                    $table->unsignedBigInteger('tamano_id')->nullable()->after('producto_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('orden_detalles')) {
            Schema::table('orden_detalles', function (Blueprint $table) {
                if (Schema::hasColumn('orden_detalles', 'tamano_nombre')) {
                    $table->dropColumn('tamano_nombre');
                }
                if (Schema::hasColumn('orden_detalles', 'tamano_id')) {
                    $table->dropColumn('tamano_id');
                }
            });
        }

        if (Schema::hasTable('ingredientes_de_productos')) {
            Schema::table('ingredientes_de_productos', function (Blueprint $table) {
                if (Schema::hasColumn('ingredientes_de_productos', 'tamano_id')) {
                    $table->dropForeign(['tamano_id']);
                    $table->dropColumn('tamano_id');
                }
            });
        }

        Schema::dropIfExists('producto_tamanos');
    }
};
