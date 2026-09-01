<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('storage_key')->unique();
            $table->string('original_filename');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 128);
            $table->string('scan_status')->default('QUARANTINE')->index();
            $table->timestampsTz();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('actor_id')->nullable();
            $table->string('action')->index();
            $table->string('target_type');
            $table->string('target_id');
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->string('technical_source')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('attachments');
    }
};
