<?php

namespace CapsuleCmdr\Affinity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

// SeAT EVEAPI Models
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterPortrait;
use Seat\Eveapi\Models\Character\CharacterCorporationHistory;
use Seat\Eveapi\Models\Character\CharacterAffiliation;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\Alliances\Alliance;

class AddUnlinkedEntity extends Command
{
    protected $signature = 'affinity:character:add
                            {identifier : Character ID (number) or exact Character Name}
                            {--no-fetch : Do not call ESI; insert only what is provided}';

    protected $description = 'Add or update an unlinked character in SeAT using public ESI data.';

    public function handle(): int
    {
        $identifier = trim($this->argument('identifier'));
        $noFetch    = (bool) $this->option('no-fetch');

        Log::info('AFFINITY01: AddUnlinkedCharacter invoked', [
            'identifier' => $identifier,
            'noFetch'    => $noFetch,
        ]);

        // Numeric -> treat as ID
        if (ctype_digit($identifier)) {
            $characterId = (int) $identifier;

            if (!$noFetch) {
                $info = $this->fetchCharacterPublicBundle($characterId);
                if ($info) {
                    $this->persistPublicBundle($characterId, $info);
                    $this->info("✅ Added/updated unlinked character: {$info['name']} ({$characterId})");
                    return self::SUCCESS;
                }
            }

            // Stub only (name unknown)
            CharacterInfo::updateOrCreate(['character_id' => $characterId], []);
            $this->warn("⚠️ Inserted stub with ID only (no ESI data).");
            return self::SUCCESS;
        }

        // Name -> must fetch
        if ($noFetch) {
            $this->error('❌ When using a name, ESI lookup is required. Remove --no-fetch or use a numeric ID.');
            return self::INVALID;
        }

        $resolvedId = $this->resolveCharacterIdByName($identifier);
        if (!$resolvedId) {
            $this->error("❌ Could not resolve character name '{$identifier}' via ESI /universe/ids/.");
            return self::FAILURE;
        }

        $info = $this->fetchCharacterPublicBundle($resolvedId);
        if (!$info) {
            $this->error("❌ Failed to fetch public bundle for character ID {$resolvedId}.");
            return self::FAILURE;
        }

        $this->persistPublicBundle($resolvedId, $info);
        $this->info("✅ Added/updated unlinked character: {$info['name']} ({$resolvedId})");

        return self::SUCCESS;
    }

    /**
     * Resolve character name → ID using /universe/ids/
     */
    protected function resolveCharacterIdByName(string $name): ?int
    {
        try {
            $resp = Http::withHeaders([
                'Accept'       => 'application/json',
                'X-User-Agent' => 'SeAT-Affinity-AddUnlinkedCharacter',
            ])->post('https://esi.evetech.net/latest/universe/ids/', [
                'names' => [$name],
            ]);

            if (!$resp->ok()) {
                Log::warning('AFFINITY03: /universe/ids/ non-OK', [
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
                ]);
                return null;
            }

            $json = $resp->json();
            foreach ($json['characters'] ?? [] as $c) {
                if (isset($c['name'], $c['id']) && strcasecmp($c['name'], $name) === 0) {
                    return (int) $c['id'];
                }
            }

            Log::warning('AFFINITY04: Name not found in /universe/ids/', [
                'name' => $name,
                'json' => $json,
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('AFFINITY05: Exception resolving name', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Fetch all public information about a character (Character, Corp, Alliance, Portrait, Corp History).
     */
    protected function fetchCharacterPublicBundle(int $characterId): ?array
    {
        try {
            $headers = [
                'Accept'       => 'application/json',
                'X-User-Agent' => 'SeAT-Affinity-AddUnlinkedCharacter',
            ];

            // 1) Character
            $charResp = Http::withHeaders($headers)
                ->get("https://esi.evetech.net/latest/characters/{$characterId}/");
            if (!$charResp->ok()) return null;
            $char = $charResp->json();

            // 2) Corporation
            $corp = [];
            if (!empty($char['corporation_id'])) {
                $corpResp = Http::withHeaders($headers)
                    ->get("https://esi.evetech.net/latest/corporations/{$char['corporation_id']}/");
                if ($corpResp->ok()) $corp = $corpResp->json();
            }

            // 3) Alliance
            $ally = [];
            if (!empty($char['alliance_id'])) {
                $allyResp = Http::withHeaders($headers)
                    ->get("https://esi.evetech.net/latest/alliances/{$char['alliance_id']}/");
                if ($allyResp->ok()) $ally = $allyResp->json();
            }

            // 4) Portraits
            $portraitResp = Http::withHeaders($headers)
                ->get("https://esi.evetech.net/latest/characters/{$characterId}/portrait/");
            $portrait = $portraitResp->ok() ? $portraitResp->json() : [];

            // 5) Corporation history
            $historyResp = Http::withHeaders($headers)
                ->get("https://esi.evetech.net/latest/characters/{$characterId}/corporationhistory/");
            $history = $historyResp->ok() ? $historyResp->json() : [];

            return [
                'character_id'       => $characterId,
                'name'               => $char['name'] ?? null,
                'security_status'    => $char['security_status'] ?? null,
                'birthday'           => $char['birthday'] ?? null,
                'corporation_id'     => $char['corporation_id'] ?? null,
                'corporation_name'   => $corp['name'] ?? null,
                'corporation_ticker' => $corp['ticker'] ?? null,
                'alliance_id'        => $char['alliance_id'] ?? null,
                'alliance_name'      => $ally['name'] ?? null,
                'alliance_ticker'    => $ally['ticker'] ?? null,
                'faction_id'         => $char['faction_id'] ?? null,
                'portrait'           => $portrait,
                'corp_history'       => $history,
            ];
        } catch (\Throwable $e) {
            Log::error('AFFINITY07: Exception fetching character basics', [
                'id'    => $characterId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Persist public data strictly via SeAT EVEAPI models.
     */
    protected function persistPublicBundle(int $characterId, array $data): void
    {
        // --- CharacterInfo (do NOT set corporation/alliance here) ---
        CharacterInfo::updateOrCreate(
            ['character_id' => $characterId],
            array_filter([
                'name'            => $data['name'] ?? null,
                'security_status' => $data['security_status'] ?? null,
                'birthday'        => !empty($data['birthday']) ? Carbon::parse($data['birthday']) : null,
            ], fn($v) => !is_null($v))
        );

        // --- CharacterAffiliation (corp/alliance/faction linkage) ---
        // Upsert on character_id; set current public affiliations
        CharacterAffiliation::updateOrCreate(
            ['character_id' => $characterId],
            array_filter([
                'corporation_id' => $data['corporation_id'] ?? null,
                'alliance_id'    => $data['alliance_id'] ?? null,
                'faction_id'     => $data['faction_id'] ?? null,
            ], fn($v) => !is_null($v))
        );

        // --- CorporationInfo (if present) ---
        if (!empty($data['corporation_id'])) {
            CorporationInfo::updateOrCreate(
                ['corporation_id' => (int)$data['corporation_id']],
                array_filter([
                    'name'        => $data['corporation_name'] ?? null,
                    'ticker'      => $data['corporation_ticker'] ?? null,
                    // mirror alliance on corp if present
                    'alliance_id' => $data['alliance_id'] ?? null,
                ], fn($v) => !is_null($v))
            );
        }

        // --- Alliance (if present) ---
        if (!empty($data['alliance_id'])) {
            Alliance::updateOrCreate(
                ['alliance_id' => (int)$data['alliance_id']],
                array_filter([
                    'name'   => $data['alliance_name'] ?? null,
                    'ticker' => $data['alliance_ticker'] ?? null,
                ], fn($v) => !is_null($v))
            );
        }

        // --- Portraits ---
        if (!empty($data['portrait']) && is_array($data['portrait'])) {
            $p = $data['portrait'];
            CharacterPortrait::updateOrCreate(
                ['character_id' => $characterId],
                array_filter([
                    'px64x64'     => $p['px64x64'] ?? null,
                    'px128x128'   => $p['px128x128'] ?? null,
                    'px256x256'   => $p['px256x256'] ?? null,
                    'px512x512'   => $p['px512x512'] ?? null,
                    'px1024x1024' => $p['px1024x1024'] ?? null,
                ], fn($v) => !is_null($v))
            );
        }

        // --- Corporation History ---
        foreach (($data['corp_history'] ?? []) as $h) {
            if (empty($h['corporation_id']) || empty($h['start_date'])) {
                continue;
            }

            $recordId = $h['record_id'] ?? null;
            $where = $recordId
                ? ['character_id' => $characterId, 'record_id' => (int)$recordId]
                : [
                    'character_id'   => $characterId,
                    'corporation_id' => (int)$h['corporation_id'],
                    'start_date'     => Carbon::parse($h['start_date']),
                ];

            CharacterCorporationHistory::updateOrCreate(
                $where,
                array_filter([
                    'corporation_id' => (int)$h['corporation_id'],
                    'start_date'     => Carbon::parse($h['start_date']),
                    'is_deleted'     => $h['is_deleted'] ?? 0,
                    'record_id'      => $recordId ? (int)$recordId : null,
                ], fn($v) => !is_null($v))
            );
        }

        Log::info('AFFINITY02: Upserted public character via SeAT models', [
            'character_id'   => $characterId,
            'name'           => $data['name'] ?? null,
            'corporation_id' => $data['corporation_id'] ?? null,
            'alliance_id'    => $data['alliance_id'] ?? null,
        ]);
    }
}
