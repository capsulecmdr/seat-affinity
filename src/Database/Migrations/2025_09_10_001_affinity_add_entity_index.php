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
            $table->unique(['entity_type', 'entity_id'], 'affinity_entity_type_id_unique');

            // index for faster lookups by type + name
            $table->index(['entity_type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('affinity_entity', function (Blueprint $table) {
            $table->dropUnique('affinity_entity_type_id_unique');
            $table->dropIndex(['entity_type', 'name']);
        });
    }
};
