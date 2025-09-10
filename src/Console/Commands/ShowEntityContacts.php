<?php

namespace CapsuleCmdr\Affinity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ShowEntityContacts extends Command
{
    protected $signature = 'affinity:entity:contacts
        {type : character|corporation|alliance}
        {name : Name to search}
        {--exact : Exact match on name}
        {--limit=200 : Max contacts to display}';

    protected $description = 'Resolve an entity by type+name and list its EVE contacts (SeAT models only).';

    public function handle(): int
    {
        $type  = strtolower($this->argument('type'));
        $name  = (string) $this->argument('name');
        $exact = (bool) $this->option('exact');
        $limit = max(1, (int) $this->option('limit'));

        $meta = $this->entityMeta($type);
        if (! $meta) {
            $this->error("Unsupported type '{$type}'. Use: character|corporation|alliance");
            return self::INVALID;
        }
        if (! class_exists($meta['model'])) {
            $this->error("Model not found for {$type}: {$meta['model']}");
            return self::INVALID;
        }

        // Resolve owner by name
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

        // Contact model per owner type (Contacts namespace)
        $contactModel = $this->contactModel($type);
        if (! class_exists($contactModel)) {
            $this->warn("Contact model not available for {$type}: {$contactModel}");
            return self::SUCCESS;
        }

        // Fetch contacts; select only known-safe columns
        $contacts = $contactModel::query()
            ->where($meta['id'], $ownerId) // owner column matches the ID column name
            ->select(['contact_id', 'standing'])
            ->orderBy('contact_id')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'contact_id' => (int) $r->contact_id,
                'standing'   => $r->standing,
            ]);

        if ($contacts->isEmpty()) {
            $this->line('No contacts found.');
            return self::SUCCESS;
        }

        // Resolve names for contacts
        $enriched = $this->attachNames($contacts);

        $this->table(
            ['#', 'contact_id', 'name', 'standing'],
            $enriched->values()->map(fn ($c, $i) => [
                $i + 1, $c['contact_id'], $c['name'] ?? null, $c['standing'],
            ])->all()
        );

        return self::SUCCESS;
    }

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

    protected function contactModel(string $type): string
    {
        return match ($type) {
            'character'   => \Seat\Eveapi\Models\Contacts\CharacterContact::class,
            'corporation' => \Seat\Eveapi\Models\Contacts\CorporationContact::class,
            'alliance'    => \Seat\Eveapi\Models\Contacts\AllianceContact::class,
            default       => '',
        };
    }

    protected function attachNames(Collection $contacts): Collection
    {
        $ids = $contacts->pluck('contact_id')->unique()->values()->all();
        $names = [];

        if (class_exists(\Seat\Eveapi\Models\Character\CharacterInfo::class)) {
            $names += \Seat\Eveapi\Models\Character\CharacterInfo::query()
                ->whereIn('character_id', $ids)
                ->select(['character_id as id', 'name'])
                ->get()->pluck('name', 'id')->toArray();
        }

        if (class_exists(\Seat\Eveapi\Models\Corporation\CorporationInfo::class)) {
            $names += \Seat\Eveapi\Models\Corporation\CorporationInfo::query()
                ->whereIn('corporation_id', $ids)
                ->select(['corporation_id as id', 'name'])
                ->get()->pluck('name', 'id')->toArray();
        }

        if (class_exists(\Seat\Eveapi\Models\Alliances\Alliance::class)) {
            $names += \Seat\Eveapi\Models\Alliances\Alliance::query()
                ->whereIn('alliance_id', $ids)
                ->select(['alliance_id as id', 'name'])
                ->get()->pluck('name', 'id')->toArray();
        }

        return $contacts->map(function ($c) use ($names) {
            $c['name'] = $names[$c['contact_id']] ?? null;
            return $c;
        });
    }
}
