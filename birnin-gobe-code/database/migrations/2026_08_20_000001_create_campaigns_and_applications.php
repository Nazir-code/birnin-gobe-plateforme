<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status')->default('DRAFT');
            $table->string('timezone')->default('Africa/Niamey');
            $table->timestampTz('opens_at')->nullable();
            $table->timestampTz('closes_at')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();
        });

        Schema::create('applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id');
            $table->string('status')->default('DRAFT')->index();
            $table->string('submission_number')->nullable()->unique();
            $table->unsignedSmallInteger('completion_percent')->default(0);
            $table->timestampTz('submitted_at')->nullable();
            $table->jsonb('submitted_snapshot')->nullable();
            $table->timestampsTz();
            $table->unique(['campaign_id', 'candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
        Schema::dropIfExists('campaigns');
    }
};
