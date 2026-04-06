<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bsale_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('external_id')->unique();
            $table->unsignedBigInteger('document_number')->nullable()->index();
            $table->string('serial_number')->nullable()->index();
            $table->timestamp('generation_date')->nullable()->index();
            $table->timestamp('emission_date')->nullable()->index();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->integer('state')->nullable()->index();
            $table->integer('commercial_state')->nullable();
            $table->integer('cancellation_status')->nullable();
            $table->string('client_code')->nullable()->index();
            $table->string('client_name')->nullable();
            $table->string('client_email')->nullable();
            $table->string('client_phone')->nullable();
            $table->unsignedBigInteger('office_id')->nullable()->index();
            $table->string('office_name')->nullable();
            $table->unsignedBigInteger('user_id_external')->nullable();
            $table->string('user_name')->nullable();
            $table->unsignedBigInteger('document_type_id')->nullable();
            $table->string('document_type_name')->nullable();
            $table->string('fingerprint', 64)->nullable()->index();
            $table->timestamp('synced_at')->nullable()->index();
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bsale_documents');
    }
};