<?php 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affinity_entity', function (Blueprint $table) {
            // unique pair (entity_type + entity_id)
            $table->unique(['type', 'eve_id'], 'affinity_eve_type_id_unique');

            // index for faster lookups by type + name
            $table->index(['type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('affinity_entity', function (Blueprint $table) {
            $table->dropUnique('affinity_type_id_unique');
            $table->dropIndex(['type', 'name']);
        });
    }
};
