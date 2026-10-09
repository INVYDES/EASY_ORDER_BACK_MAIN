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
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impresora_id')->constrained('impresoras_nube')->cascadeOnDelete();
            $table->longText('payload_base64'); // El contenido ESC/POS o StarPRNT codificado en base64
            $table->enum('status', ['pending', 'printed', 'failed'])->default('pending');
            $table->string('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
