<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->string('integration');
            $table->string('scope')->default('default');
            $table->string('status')->default('idle')->index();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->timestamp('last_cursor_at')->nullable();
            $table->timestamp('last_full_sync_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['integration', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_states');
    }
};