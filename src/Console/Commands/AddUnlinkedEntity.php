<?php

namespace Capsulecmdr\Affinity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Character\CharacterInfo;

class AddUnlinkedEntity extends Command
{
    protected $signature = 'seat:affinity:character:add
                            {identifier : Character ID (number) or exact Character Name}
                            {--no-fetch : Do not call ESI; insert only what is provided}';

    protected $description = 'Add an unlinked character to SeAT (by ID or Name), optionally fetching basics from public ESI.';

    public function handle(): int
    {
        $identifier = trim($this->argument('identifier'));
        $noFetch    = (bool) $this->option('no-fetch');

        Log::info('AFFINITY01: AddUnlinkedCharacter invoked', ['identifier' => $identifier, 'noFetch' => $noFetch]);

        // Determine character_id and name
        $characterId = null;
        $characterName = null;

        if (ctype_digit($identifier)) {
            $characterId = (int) $identifier;

            if (!$noFetch) {
                $info = $this->fetchCharacterBasics($characterId);
                if ($info) {
                    $characterName = $info['name'] ?? null;
                    $corpId = $info['corporation_id'] ?? null;
                    $allyId = $info['alliance_id'] ?? null;
                    $this->upsertCharacter($characterId, $characterName, $corpId, $allyId);
                    $this->info("✅ Added/updated unlinked character: {$characterName} ({$characterId})");
                    return self::SUCCESS;
                }
            }

            // No fetch path (or fetch failed)
            $this->upsertCharacter($characterId, $characterName);
            $this->warn("⚠️ Inserted stub with ID only (no ESI data). You can re-run without --no-fetch later.");
            return self::SUCCESS;
        }

        // Treat as a name: resolve to ID unless --no-fetch
        if ($noFetch) {
            $this->error('❌ When using a name, ESI lookup is required. Remove --no-fetch or provide a numeric character_id.');
            return self::INVALID;
        }

        $resolvedId = $this->resolveCharacterIdByName($identifier);
        if (!$resolvedId) {
            $this->error("❌ Could not resolve character name '{$identifier}' via ESI /universe/ids/.");
            return self::FAILURE;
        }

        $info = $this->fetchCharacterBasics($resolvedId);
        $characterName = $info['name'] ?? $identifier;
        $corpId = $info['corporation_id'] ?? null;
        $allyId = $info['alliance_id'] ?? null;

        $this->upsertCharacter($resolvedId, $characterName, $corpId, $allyId);
        $this->info("✅ Added/updated unlinked character: {$characterName} ({$resolvedId})");
        return self::SUCCESS;
    }

    /**
     * Upsert into CharacterInfo + characters (unlinked stub).
     */
    protected function upsertCharacter(int $characterId, ?string $name = null, ?int $corpId = null, ?int $allyId = null): void
    {
        CharacterInfo::updateOrCreate(
            ['character_id' => $characterId],
            array_filter([
                'name'           => $name,
                'corporation_id' => $corpId,
                'alliance_id'    => $allyId,
                'updated_at'     => now(),
                'created_at'     => now(), // harmless on update
            ], fn($v) => !is_null($v))
        );

        // DB::table('characters')->updateOrInsert(
        //     ['character_id' => $characterId],
        //     [
        //         'owner_hash' => null,
        //         'user_id'    => null,
        //         'updated_at' => now(),
        //         'created_at' => now(),
        //     ]
        // );

        Log::info('AFFINITY02: Upserted unlinked character', [
            'character_id' => $characterId,
            'name' => $name,
            'corporation_id' => $corpId,
            'alliance_id' => $allyId
        ]);
    }

    /**
     * Resolve an exact character name to ID via ESI /universe/ids/.
     */
    protected function resolveCharacterIdByName(string $name): ?int
    {
        try {
            $resp = Http::withHeaders([
                'Accept' => 'application/json',
                'X-User-Agent' => 'SeAT-Affinity-AddUnlinkedCharacter'
            ])->post('https://esi.evetech.net/latest/universe/ids/', [
                'names' => [$name],
            ]);

            if (!$resp->ok()) {
                Log::warning('AFFINITY03: /universe/ids/ non-OK', ['status' => $resp->status(), 'body' => $resp->body()]);
                return null;
            }

            $json = $resp->json();
            $chars = $json['characters'] ?? [];
            foreach ($chars as $c) {
                if (isset($c['name'], $c['id']) && strcasecmp($c['name'], $name) === 0) {
                    return (int) $c['id'];
                }
            }
            Log::warning('AFFINITY04: Name not found in /universe/ids/', ['name' => $name, 'json' => $json]);
            return null;
        } catch (\Throwable $e) {
            Log::error('AFFINITY05: Exception resolving name', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Fetch public character basics via ESI GET /characters/{character_id}.
     */
    protected function fetchCharacterBasics(int $characterId): ?array
    {
        try {
            $resp = Http::withHeaders([
                'Accept' => 'application/json',
                'X-User-Agent' => 'SeAT-Affinity-AddUnlinkedCharacter'
            ])->get("https://esi.evetech.net/latest/characters/{$characterId}/");

            if (!$resp->ok()) {
                Log::warning('AFFINITY06: /characters/{id} non-OK', ['id' => $characterId, 'status' => $resp->status(), 'body' => $resp->body()]);
                return null;
            }

            $data = $resp->json();
            // Expected keys: name, corporation_id, alliance_id (optional)
            return [
                'name' => $data['name'] ?? null,
                'corporation_id' => $data['corporation_id'] ?? null,
                'alliance_id' => $data['alliance_id'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('AFFINITY07: Exception fetching /characters/{id}', ['id' => $characterId, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
