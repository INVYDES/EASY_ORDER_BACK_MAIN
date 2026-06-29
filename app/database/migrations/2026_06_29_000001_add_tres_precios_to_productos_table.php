<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->decimal('precio_pequeno', 10, 2)->nullable()->after('precio');
            $table->decimal('precio_mediano', 10, 2)->nullable()->after('precio_pequeno');
            $table->decimal('precio_grande', 10, 2)->nullable()->after('precio_mediano');
        });

        DB::statement('UPDATE productos SET precio_pequeno = precio WHERE precio_pequeno IS NULL');
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['precio_pequeno', 'precio_mediano', 'precio_grande']);
        });
    }
};
