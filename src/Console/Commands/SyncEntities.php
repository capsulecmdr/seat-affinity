<?php

namespace CapsuleCmdr\Affinity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sync alliances, corporations, and characters from SeAT into affinity_entity.
 *
 * Usage examples:
 *  php artisan affinity:entities:sync --types=all --dry-run
 *  php artisan affinity:entities:sync --types=alliance,corporation --chunk=1000
 */
class SyncEntities extends Command
{
    protected $signature = 'affinity:entities:sync
        {--types=all : Comma-separated list: alliance,corporation,character, or all}
        {--chunk=1000 : Rows per upsert batch}
        {--dry-run : Show actions without writing}';

    protected $description = 'Insert any SeAT-known alliances, corporations, and characters missing from affinity_entity.';

    /**
     * Map of entity types to their SeAT Eloquent model and id/name selects.
     * Adjust model class names here if your SeAT version differs.
     */
    protected const SOURCES = [
        'alliance' => [
            'model' => \Seat\Eveapi\Models\Alliance\Alliance::class,
            'id'    => 'alliance_id',
            'name'  => 'name',
        ],
        'corporation' => [
            'model' => \Seat\Eveapi\Models\Corporation\CorporationInfo::class,
            'id'    => 'corporation_id',
            'name'  => 'name',
        ],
        'character' => [
            'model' => \Seat\Eveapi\Models\Character\CharacterInfo::class,
            'id'    => 'character_id',
            'name'  => 'name',
        ],
    ];

    public function handle(): int
    {
        $dry   = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');

        $types = $this->parseTypes((string) $this->option('types'));
        if (empty($types)) {
            $this->warn('No valid types resolved. Use --types=all or a list like --types=alliance,corporation');
            return self::SUCCESS;
        }

        $totalInserted = 0;

        foreach ($types as $type) {
            [$inserted, $scanned] = $this->syncType($type, $chunk, $dry);
            $this->info(sprintf(
                '[%s] scanned: %d, %s: %d',
                $type,
                $scanned,
                $dry ? 'would-insert' : 'inserted',
                $inserted
            ));
            $totalInserted += $inserted;
        }

        $this->info($dry
            ? "DRY-RUN complete. Would insert total: {$totalInserted}"
            : "Sync complete. Inserted total: {$totalInserted}"
        );

        return self::SUCCESS;
    }

    /**
     * @return array{0:int inserted, 1:int scanned}
     */
    protected function syncType(string $type, int $chunk, bool $dry): array
    {
        if (! isset(self::SOURCES[$type])) {
            $this->warn("Unknown type '{$type}', skipping.");
            return [0, 0];
        }

        $meta = self::SOURCES[$type];
        $model = $meta['model'];
        $idCol = $meta['id'];
        $nameCol = $meta['name'];

        if (! class_exists($model)) {
            $this->warn("Model {$model} not found for type {$type}, skipping.");
            return [0, 0];
        }

        $this->line("Syncing {$type}s from {$model} …");

        // Pull already present ids for this type to avoid dup work.
        $existing = DB::table('affinity_entity')
            ->where('entity_type', $type)
            ->pluck('entity_id')
            ->flip(); // value->key map for O(1) checks

        $now = now('UTC')->toDateTimeString();
        $inserted = 0;
        $scanned = 0;

        /** @var Builder $query */
        $query = $model::query()->select([$idCol, $nameCol]);

        // Stream via chunkById on the id column to keep memory usage low.
        $query->orderBy($idCol)->chunkById(5000, function ($rows) use (
            $type, $idCol, $nameCol, $existing, $now, $chunk, $dry, &$inserted, &$scanned
        ) {
            $payload = [];

            foreach ($rows as $row) {
                $scanned++;
                $id = (int) $row->{$idCol};
                if (isset($existing[$id])) {
                    continue; // already present
                }

                $payload[] = [
                    'entity_type' => $type,
                    'entity_id'   => $id,
                    'name'        => $row->{$nameCol},
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];

                // Flush in user-defined chunk sizes
                if (count($payload) >= $chunk) {
                    $inserted += $this->flush($payload, $dry);
                    $payload = [];
                }
            }

            // Flush remainder of this chunk
            if (! empty($payload)) {
                $inserted += $this->flush($payload, $dry);
            }
        }, $idCol);

        return [$inserted, $scanned];
    }

    /**
     * Perform upsert (or preview in dry-run).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    protected function flush(array $rows, bool $dry): int
    {
        if ($dry) {
            foreach (array_slice($rows, 0, min(5, count($rows))) as $r) {
                $this->line(sprintf(
                    "- [DRY] %s %d \"%s\"",
                    $r['entity_type'],
                    $r['entity_id'],
                    $r['name'] ?? ''
                ));
            }
            if (count($rows) > 5) {
                $this->line('  … and ' . (count($rows) - 5) . ' more in this batch');
            }
            return count($rows);
        }

        DB::table('affinity_entity')->upsert(
            $rows,
            ['entity_type', 'entity_id'],
            ['name', 'updated_at']
        );

        return count($rows);
    }

    /**
     * Parse --types option.
     * Accepts: "all" or comma-separated of known keys.
     *
     * @return string[]
     */
    protected function parseTypes(string $raw): array
    {
        $raw = trim(strtolower($raw));
        if ($raw === 'all' || $raw === '') {
            return array_keys(self::SOURCES);
        }

        $items = array_filter(array_map('trim', explode(',', $raw)));
        $valid = array_keys(self::SOURCES);
        return array_values(array_intersect($items, $valid));
    }
}
