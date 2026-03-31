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
        Schema::create('egreso_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('egreso_id');
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('accion'); // creado, editado, eliminado
            $table->json('datos_anteriores')->nullable();
            $table->json('datos_nuevos')->nullable();
            $table->timestamp('fecha');
            $table->timestamps();

            $table->foreign('egreso_id')->references('id')->on('egresos')->onDelete('cascade');
            $table->foreign('usuario_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('egreso_logs');
    }
};
