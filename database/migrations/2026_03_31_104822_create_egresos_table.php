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
        Schema::create('egresos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->string('banco_metodo_pago');
            $table->string('categoria');
            $table->decimal('precio', 12, 2);
            $table->date('fecha');
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->timestamps();

            $table->foreign('usuario_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('egresos');
    }
};
