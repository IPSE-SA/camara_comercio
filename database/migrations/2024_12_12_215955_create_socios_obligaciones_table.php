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
        Schema::create('socios_obligaciones', function (Blueprint $table) {
            $table->id();
            $table->integer('id_socio');
            $table->integer('id_entidad');
            $table->integer('id_obligacion');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_presentacion')->nullable();
            $table->integer('estado');
            $table->timestamps();

            // Restricción única para evitar duplicados
            $table->unique(['id_socio', 'id_entidad', 'id_obligacion'], 'unique_socio_entidad_obligacion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('socios_obligaciones');
    }
};
