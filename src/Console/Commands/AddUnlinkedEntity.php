<?php

namespace CapsuleCmdr\Affinity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

// SeAT EVEAPI Models
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterCorporationHistory;
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

        $characterId   = null;
        $characterName = null;

        // Identifier is numeric → treat as ID
        if (ctype_digit($identifier)) {
            $characterId = (int) $identifier;

            if (!$noFetch) {
                $info = $this->fetchCharacterBasics($characterId);
                if ($info) {
                    $characterName = $info['name'] ?? null;
                    $corpId        = $info['corporation_id'] ?? null;
                    $allyId        = $info['alliance_id'] ?? null;

                    $this->upsertCharacter($characterId, $characterName, $corpId, $allyId, $info);
                    $this->info("✅ Added/updated unlinked character: {$characterName} ({$characterId})");
                    return self::SUCCESS;
                }
            }

            $this->upsertCharacter($characterId, $characterName);
            $this->warn("⚠️ Inserted stub with ID only (no ESI data).");
            return self::SUCCESS;
        }

        // Identifier is name → must fetch
        if ($noFetch) {
            $this->error('❌ When using a name, ESI lookup is required. Remove --no-fetch or use a numeric ID.');
            return self::INVALID;
        }

        $resolvedId = $this->resolveCharacterIdByName($identifier);
        if (!$resolvedId) {
            $this->error("❌ Could not resolve character name '{$identifier}' via ESI /universe/ids/.");
            return self::FAILURE;
        }

        $info = $this->fetchCharacterBasics($resolvedId);
        $characterName = $info['name'] ?? $identifier;
        $corpId        = $info['corporation_id'] ?? null;
        $allyId        = $info['alliance_id'] ?? null;

        $this->upsertCharacter($resolvedId, $characterName, $corpId, $allyId, $info);
        $this->info("✅ Added/updated unlinked character: {$characterName} ({$resolvedId})");

        return self::SUCCESS;
    }

    /**
     * Resolve character name → ID using /universe/ids/
     */
    protected function resolveCharacterIdByName(string $name): ?int
    {
        try {
            $resp = Http::withHeaders([
                'Accept'        => 'application/json',
                'X-User-Agent'  => 'SeAT-Affinity-AddUnlinkedCharacter',
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
            $chars = $json['characters'] ?? [];
            foreach ($chars as $c) {
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
     * Fetch all public information about a character (Character, Corp, Alliance, Portrait, Corp History)
     */
    protected function fetchCharacterBasics(int $characterId): ?array
    {
        try {
            $headers = [
                'Accept'       => 'application/json',
                'X-User-Agent' => 'SeAT-Affinity-AddUnlinkedCharacter',
            ];

            // 1️⃣ Character
            $charResp = Http::withHeaders($headers)
                ->get("https://esi.evetech.net/latest/characters/{$characterId}/");
            if (!$charResp->ok()) return null;
            $char = $charResp->json();

            // 2️⃣ Corporation
            $corp = [];
            if (!empty($char['corporation_id'])) {
                $corpResp = Http::withHeaders($headers)
                    ->get("https://esi.evetech.net/latest/corporations/{$char['corporation_id']}/");
                if ($corpResp->ok()) $corp = $corpResp->json();
            }

            // 3️⃣ Alliance
            $ally = [];
            if (!empty($char['alliance_id'])) {
                $allyResp = Http::withHeaders($headers)
                    ->get("https://esi.evetech.net/latest/alliances/{$char['alliance_id']}/");
                if ($allyResp->ok()) $ally = $allyResp->json();
            }

            // // 4️⃣ Portraits
            // $portraitResp = Http::withHeaders($headers)
            //     ->get("https://esi.evetech.net/latest/characters/{$characterId}/portrait/");
            // $portrait = $portraitResp->ok() ? $portraitResp->json() : [];

            // 5️⃣ Corporation history
            $historyResp = Http::withHeaders($headers)
                ->get("https://esi.evetech.net/latest/characters/{$characterId}/corporationhistory/");
            $history = $historyResp->ok() ? $historyResp->json() : [];

            return [
                'name'              => $char['name'] ?? null,
                'corporation_id'    => $char['corporation_id'] ?? null,
                'corporation_name'  => $corp['name'] ?? null,
                'corporation_ticker'=> $corp['ticker'] ?? null,
                'alliance_id'       => $char['alliance_id'] ?? null,
                'alliance_name'     => $ally['name'] ?? null,
                'alliance_ticker'   => $ally['ticker'] ?? null,
                'security_status'   => $char['security_status'] ?? null,
                'birthday'          => $char['birthday'] ?? null,
                'corp_history'      => $history,
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
    protected function upsertCharacter(
        int $characterId,
        ?string $name = null,
        ?int $corpId = null,
        ?int $allyId = null,
        ?array $enriched = null
    ): void {
        // --- CharacterInfo ---
        $charPayload = array_filter([
            'name'            => $name ?? ($enriched['name'] ?? null),
            'corporation_id'  => $corpId ?? ($enriched['corporation_id'] ?? null),
            'alliance_id'     => $allyId ?? ($enriched['alliance_id'] ?? null),
            'security_status' => $enriched['security_status'] ?? null,
            'birthday'        => !empty($enriched['birthday'])
                ? Carbon::parse($enriched['birthday'])
                : null,
        ], fn($v) => !is_null($v));

        CharacterInfo::updateOrCreate(
            ['character_id' => $characterId],
            $charPayload
        );

        // --- CorporationInfo ---
        if (!empty($enriched['corporation_id'])) {
            CorporationInfo::updateOrCreate(
                ['corporation_id' => (int)$enriched['corporation_id']],
                array_filter([
                    'name'        => $enriched['corporation_name'] ?? null,
                    'ticker'      => $enriched['corporation_ticker'] ?? null,
                    'alliance_id' => $enriched['alliance_id'] ?? null,
                ], fn($v) => !is_null($v))
            );
        }

        // --- Alliance ---
        if (!empty($enriched['alliance_id'])) {
            Alliance::updateOrCreate(
                ['alliance_id' => (int)$enriched['alliance_id']],
                array_filter([
                    'name'   => $enriched['alliance_name'] ?? null,
                    'ticker' => $enriched['alliance_ticker'] ?? null,
                ], fn($v) => !is_null($v))
            );
        }

        // // --- Portraits ---
        // if (!empty($enriched['portrait']) && is_array($enriched['portrait'])) {
        //     $p = $enriched['portrait'];
        //     CharacterPortrait::updateOrCreate(
        //         ['character_id' => $characterId],
        //         array_filter([
        //             'px64x64'     => $p['px64x64'] ?? null,
        //             'px128x128'   => $p['px128x128'] ?? null,
        //             'px256x256'   => $p['px256x256'] ?? null,
        //             'px512x512'   => $p['px512x512'] ?? null,
        //             'px1024x1024' => $p['px1024x1024'] ?? null,
        //         ], fn($v) => !is_null($v))
        //     );
        // }

        // --- Corporation History ---
        if (!empty($enriched['corp_history']) && is_array($enriched['corp_history'])) {
            foreach ($enriched['corp_history'] as $h) {
                if (empty($h['corporation_id']) || empty($h['start_date'])) continue;

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
        }

        Log::info('AFFINITY02: Upserted public character via SeAT models', [
            'character_id'   => $characterId,
            'name'           => $enriched['name'] ?? null,
            'corporation_id' => $enriched['corporation_id'] ?? null,
            'alliance_id'    => $enriched['alliance_id'] ?? null,
        ]);
    }
}
