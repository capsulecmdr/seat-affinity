<?php

namespace CapsuleCmdr\Affinity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use GuzzleHttp\Client;

// SeAT contact models
use Seat\Eveapi\Models\Contacts\CharacterContact;
use Seat\Eveapi\Models\Contacts\CorporationContact;
use Seat\Eveapi\Models\Contacts\AllianceContact;

// SeAT info tables
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\Alliances\Alliance;

class HydrateContactEntities extends Command
{
    protected $signature = 'affinity:hydrate:entities
        {--queue=default : Queue name to dispatch jobs to}
        {--chunk=500 : Batch size for dispatching/ESI}
        {--limit=0 : Only process the first N contact IDs (0 = no limit)}
        {--sync : Run jobs synchronously (no queue)}
        {--sleep=0 : Sleep seconds between ESI chunks (float ok)}
        {--dry-run : Don’t enqueue/run jobs; just print what would happen}';

    protected $description = 'Hydrate Character/Corporation/Alliance infos for all contact targets across SeAT contact tables.';

    public function handle(): int
    {
        $queue  = (string) $this->option('queue');
        $chunk  = max(1, (int) $this->option('chunk'));
        $limit  = max(0, (int) $this->option('limit'));
        $sync   = (bool) $this->option('sync');
        $sleep  = (float) $this->option('sleep');
        $dry    = (bool) $this->option('dry-run');

        // 1) Gather all target IDs from all three contact tables
        $allContactIds = collect()
            ->merge(CharacterContact::pluck('contact_id'))
            ->merge(CorporationContact::pluck('contact_id'))
            ->merge(AllianceContact::pluck('contact_id'))
            ->unique()
            ->values();

        if ($limit > 0) {
            $allContactIds = $allContactIds->take($limit)->values();
        }

        if ($allContactIds->isEmpty()) {
            $this->info('No contact IDs found.');
            return self::SUCCESS;
        }

        $this->line('Total unique contact IDs: ' . $allContactIds->count());

        // 2) Resolve type via ESI /universe/names for anything not already known
        $resolved = $this->resolveUniverseNames($allContactIds, $chunk, $sleep);
        // $resolved: [id => ['name' => '...', 'category' => 'character|corporation|alliance|...']]

        // Partition by category
        $charIds = $this->idsByCategory($resolved, 'character');
        $corpIds = $this->idsByCategory($resolved, 'corporation');
        $alliIds = $this->idsByCategory($resolved, 'alliance');

        $this->line("Resolved by category: characters={$charIds->count()}, corporations={$corpIds->count()}, alliances={$alliIds->count()}");

        // 3) Compute "missing" sets (not present in SeAT info tables)
        $missingChars = $charIds->diff(CharacterInfo::pluck('character_id'))->values();
        $missingCorps = $corpIds->diff(CorporationInfo::pluck('corporation_id'))->values();
        $missingAllis = $alliIds->diff(Alliance::pluck('alliance_id'))->values();

        $this->warn("Missing — chars: {$missingChars->count()}, corps: {$missingCorps->count()}, alliances: {$missingAllis->count()}");

        if ($dry) {
            $this->line('Dry-run: no jobs dispatched.');
            return self::SUCCESS;
        }

        // 4) Pick the best available job classes for each entity type
        $charJob  = $this->pickJobClass([
            \Seat\Eveapi\Jobs\Character\CharacterInfo::class,
            \Seat\Eveapi\Jobs\Character\Info::class,
            \Seat\Eveapi\Jobs\PublicData\CharacterPublic::class,
        ]);

        $corpJob  = $this->pickJobClass([
            \Seat\Eveapi\Jobs\Corporation\CorporationInfo::class,
            \Seat\Eveapi\Jobs\Corporation\Info::class,
            \Seat\Eveapi\Jobs\PublicData\CorporationPublic::class,
        ]);

        $alliJob  = $this->pickJobClass([
            \Seat\Eveapi\Jobs\Alliances\AllianceInfo::class,
            \Seat\Eveapi\Jobs\Alliances\Info::class,
            \Seat\Eveapi\Jobs\PublicData\AlliancePublic::class,
        ]);

        // 5) Dispatch or run sync in chunks; if no job class, upsert minimal stubs
        $totalDispatched = 0;

        $totalDispatched += $this->processMissing($missingChars, $charJob, $queue, $chunk, $sync, function ($id) use ($resolved) {
            // Fallback stub for CharacterInfo
            CharacterInfo::updateOrCreate(
                ['character_id' => $id],
                ['name' => $resolved[$id]['name'] ?? null]
            );
        });

        $totalDispatched += $this->processMissing($missingCorps, $corpJob, $queue, $chunk, $sync, function ($id) use ($resolved) {
            CorporationInfo::updateOrCreate(
                ['corporation_id' => $id],
                ['name' => $resolved[$id]['name'] ?? null]
            );
        });

        $totalDispatched += $this->processMissing($missingAllis, $alliJob, $queue, $chunk, $sync, function ($id) use ($resolved) {
            Alliance::updateOrCreate(
                ['alliance_id' => $id],
                ['name' => $resolved[$id]['name'] ?? null]
            );
        });

        $this->info("Finished. Dispatched/ran {$totalDispatched} jobs" . ($sync ? ' (sync)' : '') . '.');
        return self::SUCCESS;
    }

    protected function idsByCategory(array $resolved, string $cat): Collection
    {
        return collect($resolved)
            ->filter(fn ($r) => ($r['category'] ?? null) === $cat)
            ->keys()
            ->map(fn ($k) => (int) $k)
            ->values();
    }

    protected function pickJobClass(array $candidates): ?string
    {
        foreach ($candidates as $cls) {
            if (class_exists($cls)) return $cls;
        }
        return null;
    }

    protected function processMissing(Collection $ids, ?string $jobClass, string $queue, int $chunk, bool $sync, callable $fallbackUpsert): int
    {
        if ($ids->isEmpty()) return 0;

        $dispatched = 0;

        if ($jobClass) {
            foreach ($ids->chunk($chunk) as $batch) {
                foreach ($batch as $id) {
                    $dispatched++;
                    if ($sync) {
                        // Prefer dispatchSync if available
                        if (method_exists($jobClass, 'dispatchSync')) {
                            $jobClass::dispatchSync($id);
                        } else {
                            Bus::dispatchSync(new $jobClass($id));
                        }
                    } else {
                        dispatch((new $jobClass($id))->onQueue($queue));
                    }
                }
            }
        } else {
            // No known job -> upsert minimal stubs (keeps your type detection from skipping)
            foreach ($ids->chunk($chunk) as $batch) {
                foreach ($batch as $id) {
                    $fallbackUpsert($id);
                    $dispatched++;
                }
            }
        }

        return $dispatched;
    }

    /**
     * Resolve names/categories for a set of IDs via ESI /universe/names.
     * Returns [id => ['name' => ..., 'category' => ...]]
     */
    protected function resolveUniverseNames(Collection $ids, int $chunk, float $sleep): array
    {
        $http = new Client(['base_uri' => 'https://esi.evetech.net/latest/']);
        $out  = [];

        foreach ($ids->chunk(min(900, $chunk)) as $batch) {
            $resp = $http->post('universe/names/', ['json' => $batch->values()->all()]);
            $rows = json_decode((string) $resp->getBody(), true) ?? [];
            foreach ($rows as $r) {
                if (! isset($r['id'])) continue;
                $out[(int) $r['id']] = [
                    'name'     => $r['name'] ?? null,
                    'category' => $r['category'] ?? null,
                ];
            }
            if ($sleep > 0) usleep((int) ($sleep * 1_000_000));
        }

        return $out;
    }
}
