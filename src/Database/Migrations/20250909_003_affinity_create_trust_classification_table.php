<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('affinity_trust_classification', function (Blueprint $table) {
            $table->id();
            $table->string('title')->unique(); // e.g., Trusted, Neutral, Hostile, Watchlist, etc.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affinity_trust_classification');
    }
};
