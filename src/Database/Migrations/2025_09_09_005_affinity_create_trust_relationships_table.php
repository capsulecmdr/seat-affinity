<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('affinity_trust_relationship', function (Blueprint $table) {
            $table->id();

            $table->foreignId('affinity_entity_id')
                  ->constrained('affinity_entity')
                  ->cascadeOnDelete();

            $table->foreignId('affinity_trust_class_id')
                  ->constrained('affinity_trust_classification')
                  ->restrictOnDelete();

            $table->timestamps();

            // each entity can have at most one record per classification
            $table->unique(['affinity_entity_id', 'affinity_trust_classification_id'], 'entity_classification_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affinity_trust_relationship');
    }
};
