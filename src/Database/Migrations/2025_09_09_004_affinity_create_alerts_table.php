<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('affinity_alert', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->string('status', 32)->default('new')->index(); // new | acknowledged | closed
            $table->unsignedInteger('acknowledged_by_id')->nullable()->index();
            $table->timestamp('acknowledge_date')->nullable();

            // optional link to an entity this alert is about
            $table->foreignId('associated_entity_id')
                  ->nullable()
                  ->constrained('affinity_entity')
                  ->nullOnDelete();

            $table->timestamps();

            // FK to SeAT users table
            $table->foreign('acknowledged_by_id')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affinity_alert');
    }
};
