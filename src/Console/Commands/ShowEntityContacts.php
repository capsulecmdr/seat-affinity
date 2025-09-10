<?php

namespace CapsuleCmdr\Affinity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use CapsuleCmdr\Affinity\Models\AffinityEntity;
use CapsuleCmdr\Affinity\Models\AffinityTrustClassification;
use CapsuleCmdr\Affinity\Models\AffinityTrustRelationship;

class ShowEntityContacts extends Command
{
    protected $signature = 'affinity:entity:contacts
        {type : character|corporation|alliance}
        {name : Name to search}
        {--exact : Exact match on name}
        {--limit=200 : Max contacts to display}
        {--sync : Create/update affinity_entity and trust relationship (classification) for each contact}';

    protected $description = 'Resolve an entity by type+name and list its EVE contacts. With --sync, ensures each contact has an Affinity entity and an up-to-date trust classification.';

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
        if (! $meta || ! class_exists($meta['model'])) {
            $this->error("Unsupported or missing model for type '{$type}'.");
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

        // 2) Load contacts from SeAT
        $contactModel = $this->contactModel($type);
        if (! class_exists($contactModel)) {
            $this->warn("Contact model not available for {$type}: {$contactModel}");
            return self::SUCCESS;
        }

        $contactsQuery = $contactModel::query()
            ->where($meta['id'], $ownerId)
            ->select(['contact_id', 'standing'])
            ->orderBy('contact_id');

        // If syncing, fetch ALL to keep Affinity in sync; otherwise only what we show
        $contacts = ($doSync ? $contactsQuery->get() : $contactsQuery->limit($limit)->get())
            ->map(fn ($r) => [
                'contact_id' => (int) $r->contact_id,
                'standing'   => $r->standing,
            ]);

        if ($contacts->isEmpty()) {
            $this->line('No contacts found.');
            return self::SUCCESS;
        }

        // 3) Enrich with name + resolved type
        $enriched = $this->attachTypesAndNames($contacts);

        // 4) Print table (limit applies to display only)
        $headings = ['#', 'contact_id', 'type', 'name', 'standing'];
        if ($doSync) $headings[] = 'classification';

        $this->table(
            $headings,
            $enriched->take($limit)->values()->map(function ($c, $i) use ($doSync) {
                $row = [
                    $i + 1,
                    $c['contact_id'],
                    $c['contact_type'] ?? null,
                    $c['name'] ?? null,
                    $c['standing'],
                ];
                if ($doSync) {
                    $row[] = $this->classificationTitleForStanding($c['standing']);
                }
                return $row;
            })->all()
        );

        if (! $doSync) return self::SUCCESS;

        // 5) SYNC: for each contact, ensure AffinityEntity exists and its single trust classification matches standing
        $this->info('Syncing affinity_entity + affinity_trust_relationship (classification per contact entity)…');

        DB::transaction(function () use ($enriched) {
            // Preload classification ids from YOUR table name
            $this->primeClassificationIds(['Trusted','Verified','Unverified','Untrusted','Flagged']);

            foreach ($enriched as $c) {
                $contactType = $c['type'] ?? null;
                $contactId   = $c['contact_id'];
                $contactName = $c['name'] ?? null;

                $dbug = implode("-",$c);

                if (! $contactType) {
                    $this->warn("  - Skipping contact {$contactId} (unknown type; not in Character/Corp/Alliance tables) ({$enriched})");
                    continue;
                }

                $classificationTitle = $this->classificationTitleForStanding($c['standing']);
                $classificationId    = $this->classificationIdByTitle($classificationTitle);
                if (! $classificationId) {
                    $this->warn("  - Skipping contact {$contactId}: classification '{$classificationTitle}' not found");
                    continue;
                }

                // Ensure an AffinityEntity exists for the contact (uses your columns: type/eve_id/name)
                $entity = AffinityEntity::firstOrCreate(
                    ['type' => $contactType, 'eve_id' => $contactId],
                    ['name' => $contactName]
                );

                // Upsert the single trust relationship row for that entity
                $rel = AffinityTrustRelationship::where('affinity_entity_id', $entity->id)->first();

                if (! $rel) {
                    AffinityTrustRelationship::create([
                        'affinity_entity_id'             => $entity->id,
                        'affinity_trust_class_id' => $classificationId,
                    ]);
                    $this->line("  + Set {$contactType} {$contactId} → '{$classificationTitle}'");
                } elseif ((int) $rel->affinity_trust_classification_id !== (int) $classificationId) {
                    $rel->update(['affinity_trust_class_id' => $classificationId]);
                    $this->line("  ~ Updated {$contactType} {$contactId} → '{$classificationTitle}'");
                } // else already correct; no output
            }
        });

        $this->info('Sync complete.');
        return self::SUCCESS;
    }

    /**
     * Map CLI entity type to SeAT model and id/name columns.
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
     * Contact models (Contacts namespace you specified).
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
     * Resolve contact names AND deduce entity type by probing SeAT info tables.
     * Adds keys: type (character|corporation|alliance), name
     */
    protected function attachTypesAndNames(Collection $contacts): Collection
    {
        $ids = $contacts->pluck('contact_id')->unique()->values()->all();

        $names = [];
        $types = [];

        if (class_exists(\Seat\Eveapi\Models\Character\CharacterInfo::class)) {
            \Seat\Eveapi\Models\Character\CharacterInfo::query()
                ->whereIn('character_id', $ids)
                ->select(['character_id as id', 'name'])
                ->get()
                ->each(function ($r) use (&$names, &$types) {
                    $names[$r->id] = $r->name;
                    $types[$r->id] = 'character';
                });
        }

        if (class_exists(\Seat\Eveapi\Models\Corporation\CorporationInfo::class)) {
            \Seat\Eveapi\Models\Corporation\CorporationInfo::query()
                ->whereIn('corporation_id', $ids)
                ->select(['corporation_id as id', 'name'])
                ->get()
                ->each(function ($r) use (&$names, &$types) {
                    $names[$r->id] = $r->name;
                    $types[$r->id] = 'corporation';
                });
        }

        if (class_exists(\Seat\Eveapi\Models\Alliances\Alliance::class)) {
            \Seat\Eveapi\Models\Alliances\Alliance::query()
                ->whereIn('alliance_id', $ids)
                ->select(['alliance_id as id', 'name'])
                ->get()
                ->each(function ($r) use (&$names, &$types) {
                    $names[$r->id] = $r->name;
                    $types[$r->id] = 'alliance';
                });
        }

        return $contacts->map(function ($c) use ($names, $types) {
            $c['name'] = $names[$c['contact_id']] ?? null;
            $c['type'] = $types[$c['contact_id']] ?? null; // <- consistent key
            return $c;
        });
    }

    /**
     * Standing → Classification title mapping.
     */
    protected function classificationTitleForStanding(?float $standing): string
    {
        if ($standing === null) return 'Unverified';
        if ($standing >= 5.1 && $standing <= 10.0) return 'Trusted';
        if ($standing >= 0.1 && $standing <= 5.0)  return 'Verified';
        if ($standing == 0.0)                      return 'Unverified';
        if ($standing >= -5.0 && $standing <= -0.1) return 'Untrusted';
        return 'Flagged'; // -10 to -5.1
    }

    /**
     * Load classification IDs from your table (affinity_trust_classification).
     */
    protected function primeClassificationIds(array $titles): void
    {
        $missing = array_diff($titles, array_keys($this->classificationIds));
        if (empty($missing)) return;

        $rows = AffinityTrustClassification::query()
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
}
