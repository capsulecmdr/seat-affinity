<?php

namespace CapsuleCmdr\Affinity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShowEntityContacts extends Command
{
    protected $signature = 'affinity:entity:contacts
        {type : character|corporation|alliance}
        {name : Name to search}
        {--exact : Exact match on name}
        {--limit=200 : Max contacts to display}
        {--sync : Create/update affinity_entity rows and trust relationships based on standings}';

    protected $description = 'Resolve an entity by type+name and list its EVE contacts. With --sync, mirrors into Affinity entities and trust relationships.';

    /** Cache for classification title -> id */
    protected array $classificationIds = [];

    public function handle(): int
    {
        $type   = strtolower($this->argument('type'));
        $name   = (string) $this->argument('name');
        $exact  = (bool) $this->option('exact');
        $limit  = max(1, (int) $this->option('limit'));
        $doSync = (bool) $this->option('sync');

        $meta = $this->entityMeta($type);
        if (! $meta) {
            $this->error("Unsupported type '{$type}'. Use: character|corporation|alliance");
            return self::INVALID;
        }
        if (! class_exists($meta['model'])) {
            $this->error("Model not found for {$type}: {$meta['model']}");
            return self::INVALID;
        }

        // 1) Resolve owner
        $q = $meta['model']::query()->select([$meta['id'], $meta['name']]);
        $exact ? $q->where($meta['name'], $name) : $q->where($meta['name'], 'like', "%{$name}%");
        $row = $q->orderBy($meta['name'])->first();

        if (! $row) {
            $this->warn("No {$type} found for '{$name}'" . ($exact ? ' (exact)' : ''));
            return self::SUCCESS;
        }

        $ownerId   = (int) $row->{$meta['id']};
        $ownerName = (string) $row->{$meta['name']};
        $this->info("Resolved {$type}: {$ownerName} [{$ownerId}]");

        // 2) Load contacts
        $contactModel = $this->contactModel($type);
        if (! class_exists($contactModel)) {
            $this->warn("Contact model not available for {$type}: {$contactModel}");
            return self::SUCCESS;
        }

        $contacts = $contactModel::query()
            ->where($meta['id'], $ownerId) // owner column: character_id / corporation_id / alliance_id
            ->select(['contact_id', 'standing']) // known-safe columns
            ->orderBy('contact_id')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'contact_id' => (int) $r->contact_id,
                'standing'   => $r->standing, // float
            ]);

        if ($contacts->isEmpty()) {
            $this->line('No contacts found.');
            return self::SUCCESS;
        }

        // 3) Enrich names + detect type of each contact (needed for syncing)
        $enriched = $this->attachTypesAndNames($contacts);

        // Show table output regardless
        $this->table(
            ['#', 'contact_id', 'type', 'name', 'standing', $doSync ? 'classification' : null],
            $enriched->values()->map(function ($c, $i) use ($doSync) {
                $row = [
                    $i + 1,
                    $c['contact_id'],
                    $c['entity_type'] ?? null,
                    $c['name'] ?? null,
                    $c['standing'],
                ];
                if ($doSync) {
                    $row[] = $this->classificationTitleForStanding($c['standing']);
                }
                return $row;
            })->all()
        );

        if (! $doSync) {
            return self::SUCCESS;
        }

        // 4) SYNC: mirror owner + contacts into affinity tables with trust classification from standing
        $this->info('Syncing into affinity_entity and affinity_trust_relationships…');

        DB::transaction(function () use ($type, $ownerId, $ownerName, $enriched) {

            // 4a) Ensure owner has an affinity_entity
            $ownerAffinityId = $this->getOrCreateAffinityEntityId($type, $ownerId, $ownerName);

            // 4b) Preload classification ids
            $this->primeClassificationIds([
                'Trusted', 'Verified', 'Unverified', 'Untrusted', 'Flagged',
            ]);

            // 4c) For each contact: ensure entity, then upsert trust relationship
            foreach ($enriched as $c) {
                $contactType = $c['entity_type'] ?? null;
                $contactId   = $c['contact_id'];
                $contactName = $c['name'] ?? null;

                if (! $contactType) {
                    // Couldn’t resolve type from SeAT models; skip safely.
                    $this->warn("  - Skipping contact {$contactId} (unknown type; not found in Character/Corp/Alliance tables)");
                    continue;
                }

                $classificationTitle = $this->classificationTitleForStanding($c['standing']);
                $classificationId    = $this->classificationIdByTitle($classificationTitle);
                if (! $classificationId) {
                    $this->warn("  - Skipping contact {$contactId} ({$contactType}): classification '{$classificationTitle}' not found");
                    continue;
                }

                $targetAffinityId = $this->getOrCreateAffinityEntityId($contactType, $contactId, $contactName);

                // Upsert relationship owner -> contact
                $this->upsertTrustRelationship($ownerAffinityId, $targetAffinityId, (int) $classificationId);
            }
        });

        $this->info('Sync complete.');
        return self::SUCCESS;
    }

    /**
     * Map CLI entity type to SeAT owner model and id/name columns.
     */
    protected function entityMeta(string $type): ?array
    {
        return match ($type) {
            'character' => [
                'model' => \Seat\Eveapi\Models\Character\CharacterInfo::class,
                'id'    => 'character_id',
                'name'  => 'name',
            ],
            'corporation' => [
                'model' => \Seat\Eveapi\Models\Corporation\CorporationInfo::class,
                'id'    => 'corporation_id',
                'name'  => 'name',
            ],
            'alliance' => [
                'model' => \Seat\Eveapi\Models\Alliances\Alliance::class,
                'id'    => 'alliance_id',
                'name'  => 'name',
            ],
            default => null,
        };
    }

    /**
     * Contact models (Contacts namespace).
     */
    protected function contactModel(string $type): string
    {
        return match ($type) {
            'character'   => \Seat\Eveapi\Models\Contacts\CharacterContact::class,
            'corporation' => \Seat\Eveapi\Models\Contacts\CorporationContact::class,
            'alliance'    => \Seat\Eveapi\Models\Contacts\AllianceContact::class,
            default       => '',
        };
    }

    /**
     * Resolve contact names AND deduce entity_type by probing SeAT info tables.
     * Adds keys: entity_type (character|corporation|alliance), name
     */
    protected function attachTypesAndNames(Collection $contacts): Collection
    {
        $ids = $contacts->pluck('contact_id')->unique()->values()->all();

        $names  = [];
        $types  = []; // id -> type

        // Characters
        if (class_exists(\Seat\Eveapi\Models\Character\CharacterInfo::class)) {
            $rows = \Seat\Eveapi\Models\Character\CharacterInfo::query()
                ->whereIn('character_id', $ids)
                ->select(['character_id as id', 'name'])
                ->get();
            foreach ($rows as $r) {
                $names[$r->id] = $r->name;
                $types[$r->id] = 'character';
            }
        }

        // Corporations
        if (class_exists(\Seat\Eveapi\Models\Corporation\CorporationInfo::class)) {
            $rows = \Seat\Eveapi\Models\Corporation\CorporationInfo::query()
                ->whereIn('corporation_id', $ids)
                ->select(['corporation_id as id', 'name'])
                ->get();
            foreach ($rows as $r) {
                $names[$r->id] = $r->name;
                $types[$r->id] = 'corporation';
            }
        }

        // Alliances
        if (class_exists(\Seat\Eveapi\Models\Alliances\Alliance::class)) {
            $rows = \Seat\Eveapi\Models\Alliances\Alliance::query()
                ->whereIn('alliance_id', $ids)
                ->select(['alliance_id as id', 'name'])
                ->get();
            foreach ($rows as $r) {
                $names[$r->id] = $r->name;
                $types[$r->id] = 'alliance';
            }
        }

        return $contacts->map(function ($c) use ($names, $types) {
            $c['name']        = $names[$c['contact_id']] ?? null;
            $c['entity_type'] = $types[$c['contact_id']] ?? null;
            return $c;
        });
    }

    /**
     * Standing → Classification title mapping.
     */
    protected function classificationTitleForStanding(?float $standing): string
    {
        if ($standing === null) {
            return 'Unverified';
        }
        if ($standing >= 5.1 && $standing <= 10.0) {
            return 'Trusted';
        }
        if ($standing >= 0.1 && $standing <= 5.0) {
            return 'Verified';
        }
        if ($standing == 0.0) {
            return 'Unverified';
        }
        if ($standing >= -5.0 && $standing <= -0.1) {
            return 'Untrusted';
        }
        // -10 to -5.1
        return 'Flagged';
    }

    /**
     * Ensure classification IDs are cached.
     */
    protected function primeClassificationIds(array $titles): void
    {
        $missing = array_diff($titles, array_keys($this->classificationIds));
        if (empty($missing)) return;

        // Adjust table/model if your app uses a model class
        $rows = DB::table('affinity_trust_classifications')
            ->whereIn('title', $missing)
            ->select(['id','title'])
            ->get();

        foreach ($rows as $r) {
            $this->classificationIds[$r->title] = (int) $r->id;
        }
    }

    protected function classificationIdByTitle(string $title): ?int
    {
        if (! isset($this->classificationIds[$title])) {
            $this->primeClassificationIds([$title]);
        }
        return $this->classificationIds[$title] ?? null;
    }

    /**
     * Get or create an affinity_entity row and return its PK id.
     * Assumes unique constraint on (entity_type, entity_id).
     */
    protected function getOrCreateAffinityEntityId(string $entityType, int $entityId, ?string $name): int
    {
        // Try fast path: is there already one?
        $existing = DB::table('affinity_entity')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->select('id')
            ->first();

        if ($existing) return (int) $existing->id;

        $now = now('UTC')->toDateTimeString();

        // Upsert-like behavior with duplicate-key ignore
        DB::table('affinity_entity')->insert([
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'name'        => $name,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        // Re-fetch id (covers race)
        $row = DB::table('affinity_entity')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->select('id')
            ->first();

        return (int) $row->id;
    }

    /**
     * Upsert trust relationship owner -> contact with new classification.
     * Adjust table/column names here if your schema differs.
     */
    protected function upsertTrustRelationship(int $sourceId, int $targetId, int $classificationId): void
    {
        $now = now('UTC')->toDateTimeString();

        $existing = DB::table('affinity_trust_relationships')
            ->where('source_entity_id', $sourceId)
            ->where('target_entity_id', $targetId)
            ->first();

        if (! $existing) {
            DB::table('affinity_trust_relationships')->insert([
                'source_entity_id' => $sourceId,
                'target_entity_id' => $targetId,
                'classification_id'=> $classificationId,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
            $this->line("  + Added relationship {$sourceId} -> {$targetId} ({$classificationId})");
            return;
        }

        if ((int) $existing->classification_id !== $classificationId) {
            DB::table('affinity_trust_relationships')
                ->where('id', $existing->id)
                ->update([
                    'classification_id' => $classificationId,
                    'updated_at'        => $now,
                ]);
            $this->line("  ~ Updated relationship {$sourceId} -> {$targetId} to classification {$classificationId}");
        } else {
            // No change needed
            // $this->line("  = Relationship {$sourceId} -> {$targetId} already up-to-date");
        }
    }
}
