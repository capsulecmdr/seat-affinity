<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('affinity_entity', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);     // 'character','corporation','alliance', etc.
            $table->string('name');
            $table->unsignedBigInteger('eve_id')->index(); // backing EVE ID
            $table->timestamps();

            // helpful uniqueness to prevent dupes per type
            $table->unique(['type', 'eve_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affinity_entity');
    }
};
