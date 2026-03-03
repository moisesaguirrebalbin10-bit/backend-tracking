<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete()->after('id');
            $table->text('error_reason')->nullable()->after('status');
            $table->string('delivery_image_path')->nullable()->after('error_reason');
            $table->timestamp('error_created_at')->nullable()->after('delivery_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeignKeyIfExists(['user_id']);
            $table->dropColumn(['user_id', 'error_reason', 'delivery_image_path', 'error_created_at']);
        });
    }
};
