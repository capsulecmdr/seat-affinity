<?php

namespace CapsuleCmdr\Affinity\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Seat\Web\Models\User;
use CapsuleCmdr\Affinity\Support\EsiClient;
use CapsuleCmdr\Affinity\Support\PointInTimeAffiliationMap;

class AffiliationCrawler
{
    /** Cache: id => ['type','id','name','meta'=>[]] */
    protected array $nameCache = [];

    /** Build dossier for all characters owned by the given user. */
    public function buildDossierForUser(User $user): PointInTimeAffiliationMap
    {
        $map = new PointInTimeAffiliationMap(
            name: $user->name ?? ('User#' . $user->id),
            owner_user_id: $user->id
        );

        foreach ($user->characters as $char) {
            $characterId = (int) $char->character_id;
            $esi = EsiClient::forCharacter($characterId);

            // Seed character node (enriched + affinity)
            $map->addCharacterNode($this->enrichedEntity('character', $characterId, $esi));

            $this->crawlEmployment($esi, $characterId, $map);
            $this->crawlContacts($esi, $characterId, $map);
            $this->crawlContracts($esi, $characterId, $map);
            $this->crawlMail($esi, $characterId, $map);
            $this->crawlKillmails($esi, $characterId, $map);
            $this->crawlWallet($esi, $characterId, $map);
        }

        $map->finalize();
        return $map;
    }

    /* =========================
     * Crawlers
     * ========================= */

    /** Employment ⇒ Corporations + Alliances (via corp info) */
    protected function crawlEmployment($esi, int $characterId, PointInTimeAffiliationMap $map): void
    {
        try {
            $resp = $esi->get("/characters/{$characterId}/corporationhistory");
            $items = Arr::get($resp, 'data', []);
            foreach ($items as $row) {
                $corpId = (int) ($row['corporation_id'] ?? 0);
                if (!$corpId) continue;

                $start = $row['start_date'] ?? null;
                $end   = $row['end_date'] ?? null;

                $corp = $this->enrichedEntity('corporation', $corpId, $esi);
                $map->addCorporationNode($corp);

                $map->addEdge('employment', [
                    'src' => $this->enrichedEntity('character', $characterId, $esi),
                    'dst' => $corp,
                    'at'  => $start,
                    'end' => $end,
                ]);

                // If corp has alliance in enrichment, record the relationship window
                if (!empty($corp['meta']['alliance']['id'])) {
                    $ally = $this->enrichedEntity('alliance', (int) $corp['meta']['alliance']['id'], $esi);
                    $map->addAllianceNode($ally);
                    $map->addEdge('corp_alliance_membership', [
                        'src' => $corp,
                        'dst' => $ally,
                        'at'  => $start,
                        'end' => $end,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $map->addError('employment', $characterId, $e->getMessage());
        }
    }

    /** Contacts ⇒ Character/Corp/Alliance edges */
    protected function crawlContacts($esi, int $characterId, PointInTimeAffiliationMap $map): void
    {
        try {
            $resp  = $esi->get("/characters/{$characterId}/contacts");
            $items = Arr::get($resp, 'data', []);
            foreach ($items as $c) {
                $type = strtolower($c['contact_type'] ?? '');
                $id   = (int) ($c['contact_id'] ?? 0);
                if (!$id || !$type) continue;

                $entity = match ($type) {
                    'character'   => $this->enrichedEntity('character', $id, $esi),
                    'corporation' => $this->enrichedEntity('corporation', $id, $esi),
                    'alliance'    => $this->enrichedEntity('alliance', $id, $esi),
                    default       => null,
                };
                if (!$entity) continue;

                $map->addNode($entity);
                $map->addEdge('contact', [
                    'src'  => $this->enrichedEntity('character', $characterId, $esi),
                    'dst'  => $entity,
                    'at'   => null,
                    'meta' => [
                        'standing'  => $c['standing'] ?? null,
                        'watched'   => $c['is_watched'] ?? null,
                        'label_ids' => $c['label_ids'] ?? [],
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            $map->addMissingScopeOrError('contacts', $characterId, $e);
        }
    }

    /** Contracts ⇒ relations to issuer/acceptor (mapped to entities when possible) */
    protected function crawlContracts($esi, int $characterId, PointInTimeAffiliationMap $map): void
    {
        try {
            $resp  = $esi->get("/characters/{$characterId}/contracts");
            $items = Arr::get($resp, 'data', []);
            foreach ($items as $ct) {
                $issuer = (int) ($ct['issuer_id'] ?? 0);
                $accept = (int) ($ct['acceptor_id'] ?? 0);
                $issued = $ct['date_issued'] ?? null;

                $issuerEnt = $issuer ? $this->guessEntityForId($issuer, $esi) : null;
                $acceptEnt = $accept ? $this->guessEntityForId($accept, $esi) : null;

                foreach ([$issuerEnt, $acceptEnt] as $party) {
                    if ($party) $map->addNode($party);
                }

                if ($issuerEnt) {
                    $map->addEdge('contract', [
                        'src'  => $this->enrichedEntity('character', $characterId, $esi),
                        'dst'  => $issuerEnt,
                        'at'   => $issued,
                        'meta' => [
                            'contract_id' => $ct['contract_id'] ?? null,
                            'status'      => $ct['status'] ?? null,
                            'type'        => $ct['type'] ?? null,
                            'price'       => $ct['price'] ?? null,
                            'reward'      => $ct['reward'] ?? null,
                            'assignee_id' => $ct['assignee_id'] ?? null,
                            'acceptor'    => $acceptEnt,
                        ],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $map->addMissingScopeOrError('contracts', $characterId, $e);
        }
    }

    /** Mail ⇒ from + recipients relationships */
    protected function crawlMail($esi, int $characterId, PointInTimeAffiliationMap $map): void
    {
        try {
            $resp  = $esi->get("/characters/{$characterId}/mail/");
            $items = Arr::get($resp, 'data', []);
            foreach ($items as $m) {
                $from = (int) ($m['from'] ?? 0);
                $date = $m['timestamp'] ?? null;

                if ($from) {
                    $src = $this->guessEntityForId($from, $esi) ?? $this->enrichedEntity('character', $from, $esi);
                    $map->addNode($src);
                    $map->addEdge('mail_from', [
                        'src'  => $src,
                        'dst'  => $this->enrichedEntity('character', $characterId, $esi),
                        'at'   => $date,
                        'meta' => ['subject' => $m['subject'] ?? null],
                    ]);
                }

                foreach ($m['recipients'] ?? [] as $r) {
                    $rtype = strtolower($r['recipient_type'] ?? '');
                    $rid   = (int) ($r['recipient_id'] ?? 0);
                    if (!$rid || !$rtype) continue;

                    $dst = match ($rtype) {
                        'character'   => $this->enrichedEntity('character', $rid, $esi),
                        'corporation' => $this->enrichedEntity('corporation', $rid, $esi),
                        'alliance'    => $this->enrichedEntity('alliance', $rid, $esi),
                        default       => null
                    };
                    if (!$dst) continue;

                    $map->addNode($dst);
                    $map->addEdge('mail_to', [
                        'src'  => $this->enrichedEntity('character', $characterId, $esi),
                        'dst'  => $dst,
                        'at'   => $date,
                        'meta' => ['subject' => $m['subject'] ?? null],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $map->addMissingScopeOrError('mail', $characterId, $e);
        }
    }

    /** Killmails ⇒ attackers → victim edges (entities enriched) */
    protected function crawlKillmails($esi, int $characterId, PointInTimeAffiliationMap $map): void
    {
        try {
            $resp  = $esi->get("/characters/{$characterId}/killmails/recent/");
            $items = Arr::get($resp, 'data', []);
            foreach ($items as $km) {
                $killmailId = (int) $km['killmail_id'];
                $hash       = $km['killmail_hash'];

                $full = $this->safeGet($esi, "/killmails/{$killmailId}/{$hash}/");
                if (!$full) continue;

                $ts     = Arr::get($full, 'data.killmail_time');
                $victim = Arr::get($full, 'data.victim', []);

                $v = $this->victimEntity($victim, $esi);
                if ($v) $map->addNode($v);

                foreach (Arr::get($full, 'data.attackers', []) as $a) {
                    $att = $this->attackerEntity($a, $esi);
                    if (!$att) continue;
                    $map->addNode($att);

                    $map->addEdge('killmail', [
                        'src'  => $att,
                        'dst'  => $v ?: ['type' => 'unknown', 'id' => 0, 'name' => 'Unknown'],
                        'at'   => $ts,
                        'meta' => [
                            'killmail_id'  => $killmailId,
                            'final_blow'   => (bool) ($a['final_blow'] ?? false),
                            'ship_type_id' => $a['ship_type_id'] ?? null
                        ],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $map->addMissingScopeOrError('killmails', $characterId, $e);
        }
    }

    /** Wallet ⇒ journal + transactions parties (mapped/enriched) */
    protected function crawlWallet($esi, int $characterId, PointInTimeAffiliationMap $map): void
    {
        // Journal
        try {
            $resp  = $esi->get("/characters/{$characterId}/wallet/journal/");
            $items = Arr::get($resp, 'data', []);
            foreach ($items as $j) {
                $contextId = (int) ($j['context_id'] ?? 0);
                $ts        = $j['date'] ?? null;
                if ($contextId) {
                    $party = $this->guessEntityForId($contextId, $esi);
                    if ($party) {
                        $map->addNode($party);
                        $map->addEdge('wallet_journal', [
                            'src'  => $this->enrichedEntity('character', $characterId, $esi),
                            'dst'  => $party,
                            'at'   => $ts,
                            'meta' => [
                                'amount'   => $j['amount'] ?? null,
                                'balance'  => $j['balance'] ?? null,
                                'ref_type' => $j['ref_type'] ?? null,
                                'reason'   => $j['reason'] ?? null,
                            ],
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            $map->addMissingScopeOrError('wallet_journal', $characterId, $e);
        }

        // Transactions
        try {
            $resp  = $esi->get("/characters/{$characterId}/wallet/transactions/");
            $items = Arr::get($resp, 'data', []);
            foreach ($items as $t) {
                $clientId = (int) ($t['client_id'] ?? 0);
                $ts       = $t['date'] ?? null;
                if ($clientId) {
                    $party = $this->guessEntityForId($clientId, $esi);
                    if ($party) {
                        $map->addNode($party);
                        $map->addEdge('wallet_transaction', [
                            'src'  => $this->enrichedEntity('character', $characterId, $esi),
                            'dst'  => $party,
                            'at'   => $ts,
                            'meta' => [
                                'is_buy'    => $t['is_buy'] ?? null,
                                'quantity'  => $t['quantity'] ?? null,
                                'unit_price'=> $t['unit_price'] ?? null,
                                'type_id'   => $t['type_id'] ?? null,
                            ],
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            $map->addMissingScopeOrError('wallet_transactions', $characterId, $e);
        }
    }

    /* =========================
     * Entity helpers + enrichment
     * ========================= */

    protected function victimEntity(array $v, $esiOrNull = null): ?array
    {
        if (!empty($v['character_id'])) {
            return $this->enrichedEntity('character', (int) $v['character_id'], $esiOrNull);
        }
        if (!empty($v['corporation_id'])) {
            return $this->enrichedEntity('corporation', (int) $v['corporation_id'], $esiOrNull);
        }
        if (!empty($v['alliance_id'])) {
            return $this->enrichedEntity('alliance', (int) $v['alliance_id'], $esiOrNull);
        }
        return null;
    }

    protected function attackerEntity(array $a, $esiOrNull = null): ?array
    {
        if (!empty($a['character_id'])) {
            return $this->enrichedEntity('character', (int) $a['character_id'], $esiOrNull);
        }
        if (!empty($a['corporation_id'])) {
            return $this->enrichedEntity('corporation', (int) $a['corporation_id'], $esiOrNull);
        }
        if (!empty($a['alliance_id'])) {
            return $this->enrichedEntity('alliance', (int) $a['alliance_id'], $esiOrNull);
        }
        return null;
    }

    /** Enriched entity + Affinity attachment */
    protected function enrichedEntity(string $type, int $id, $esiOrNull = null): array
    {
        $base = $this->entity($type, $id);

        $enriched = match ($type) {
            'character'   => $this->enrichCharacter($base, $esiOrNull),
            'corporation' => $this->enrichCorporation($base, $esiOrNull),
            'alliance'    => $this->enrichAlliance($base, $esiOrNull),
            default       => $base,
        };

        return $this->attachAffinity($enriched);
    }

    protected function enrichCharacter(array $e, $esiOrNull = null): array
    {
        $id = $e['id'];
        try {
            $esi = $esiOrNull ?: EsiClient::anonymous();
            $info = $this->safeGet($esi, "/characters/{$id}");
            if ($info && isset($info['data'])) {
                $d = $info['data'];
                $e['name'] = $d['name'] ?? $e['name'];
                $e['meta']['birthday']        = $d['birthday']        ?? null;
                $e['meta']['race_id']         = $d['race_id']         ?? null;
                $e['meta']['security_status'] = $d['security_status'] ?? null;

                if (!empty($d['corporation_id'])) {
                    $corp = $this->enrichCorporation($this->entity('corporation', (int)$d['corporation_id']), $esi);
                    $e['meta']['corporation'] = [
                        'id'     => $corp['id'],
                        'name'   => $corp['name'],
                        'ticker' => $corp['meta']['ticker'] ?? null,
                    ];

                    if (!empty($corp['meta']['alliance']['id'])) {
                        $e['meta']['alliance'] = $corp['meta']['alliance'];
                    }
                }
            }
        } catch (\Throwable) { /* ignore */ }

        return $e;
    }

    protected function enrichCorporation(array $e, $esiOrNull = null): array
    {
        $id = $e['id'];
        try {
            $esi = $esiOrNull ?: EsiClient::anonymous();
            $info = $this->safeGet($esi, "/corporations/{$id}");
            if ($info && isset($info['data'])) {
                $d = $info['data'];
                $e['name'] = $d['name'] ?? $e['name'];
                $e['meta']['ticker']       = $d['ticker']       ?? null;
                $e['meta']['member_count'] = $d['member_count'] ?? null;

                if (!empty($d['alliance_id'])) {
                    $ally = $this->enrichAlliance($this->entity('alliance', (int)$d['alliance_id']), $esi);
                    $e['meta']['alliance'] = [
                        'id'     => $ally['id'],
                        'name'   => $ally['name'],
                        'ticker' => $ally['meta']['ticker'] ?? null,
                    ];
                }
            }
        } catch (\Throwable) { /* ignore */ }

        return $e;
    }

    protected function enrichAlliance(array $e, $esiOrNull = null): array
    {
        $id = $e['id'];
        try {
            $esi = $esiOrNull ?: EsiClient::anonymous();
            $info = $this->safeGet($esi, "/alliances/{$id}");
            if ($info && isset($info['data'])) {
                $d = $info['data'];
                $e['name'] = $d['name'] ?? $e['name'];
                $e['meta']['ticker'] = $d['ticker'] ?? null;
            }
        } catch (\Throwable) { /* ignore */ }

        return $e;
    }

    /** Attach Affinity entity + latest trust classification (if exists) */
    protected function attachAffinity(array $e): array
    {
        $affId = $this->findAffinityEntityId($e['type'], (int) $e['id']);

        // OPTIONAL: auto-create if missing
        if (!$affId) {
            $affId = $this->ensureAffinityEntity($e);
        }

        if ($affId) {
            $trust = DB::table('affinity_trust_relationship as r')
                ->leftJoin('affinity_trust_classification as c', 'c.id', '=', 'r.affinity_trust_class_id')
                ->where('r.affinity_entity_id', $affId)
                ->orderByDesc('r.updated_at')
                ->orderByDesc('r.created_at')
                ->first([
                    'r.id as relationship_id',
                    'r.affinity_trust_class_id',
                    'c.title as classification',
                    'r.created_at',
                    'r.updated_at',
                ]);

            $e['meta']['affinity'] = [
                'affinity_entity_id' => $affId,
                'trust' => $trust ? [
                    'relationship_id' => $trust->relationship_id,
                    'class_id'        => $trust->affinity_trust_class_id,
                    'classification'  => $trust->classification,
                    'updated_at'      => (string) $trust->updated_at,
                    'created_at'      => (string) $trust->created_at,
                ] : null,
            ];

            // Keep Affinity name fresh with our latest ESI name (optional)
            try {
                DB::table('affinity_entity')
                ->where('id', $affId)
                ->update(['name' => $e['name'], 'updated_at' => now('UTC')]);
            } catch (\Throwable) { /* ignore */ }

        } else {
            // Shouldn't happen if ensureAffinityEntity used; keep null to signal unmapped case
            $e['meta']['affinity'] = [
                'affinity_entity_id' => null,
                'trust' => null,
            ];
        }

        // Cache enriched+affinity node
        $this->nameCache[$e['id']] = $e;
        return $e;
    }


    /** Locate Affinity Entity row id for (type, EVE id) across a few common shapes. */
    protected function findAffinityEntityId(string $type, int $eveId): ?int
    {
        // normalize type to your canonical values
        $type = strtolower($type); // 'character' | 'corporation' | 'alliance'

        $id = DB::table('affinity_entity')
            ->where('type', $type)
            ->where('eve_id', $eveId)
            ->value('id');

        return $id ? (int) $id : null;
    }

    protected function ensureAffinityEntity(array $node): int
    {
        // $node: ['type' => ..., 'id' => eve_id, 'name' => ..., 'meta' => ...]
        $type  = strtolower($node['type']);
        $eveId = (int) $node['id'];
        $name  = (string) ($node['name'] ?? (string)$eveId);

        // Try fast path
        $existing = DB::table('affinity_entity')
            ->where('type', $type)
            ->where('eve_id', $eveId)
            ->value('id');

        if ($existing) return (int) $existing;

        // Upsert (race-safe if you added the unique index)
        try {
            return (int) DB::table('affinity_entity')->insertGetId([
                'type'       => $type,
                'eve_id'     => $eveId,
                'name'       => $name,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        } catch (\Throwable) {
            // Another process might have inserted; fetch again
            return (int) DB::table('affinity_entity')
                ->where('type', $type)
                ->where('eve_id', $eveId)
                ->value('id');
        }
    }

    /** Try to guess entity type from local names or /universe/names, then enrich. */
    protected function guessEntityForId(int $id, $esiOrNull = null): ?array
    {
        if (isset($this->nameCache[$id]) &&
            in_array($this->nameCache[$id]['type'] ?? '', ['character', 'corporation', 'alliance'], true)) {
            return $this->nameCache[$id];
        }

        $row = DB::table('universe_names')->where('entity_id', $id)->first();
        if ($row) {
            $cat = strtolower($row->category ?? 'unknown');
            if (in_array($cat, ['character', 'corporation', 'alliance'], true)) {
                return $this->enrichedEntity($cat, $id, $esiOrNull);
            }
        }

        $named = $this->esiNames([$id])[0] ?? null;
        if ($named) {
            $cat = strtolower($named['category'] ?? 'unknown');
            if (in_array($cat, ['character', 'corporation', 'alliance'], true)) {
                $this->nameCache[$id] = [
                    'type' => $cat,
                    'id'   => $id,
                    'name' => $named['name'] ?? (string) $id,
                ];
                return $this->enrichedEntity($cat, $id, $esiOrNull);
            }
        }

        return null;
    }

    /** Canonical entity (with name) using local names first, then /universe/names. */
    protected function entity(string $type, int $id): array
    {
        if (isset($this->nameCache[$id])) {
            $cached = $this->nameCache[$id];
            $cached['type'] = $cached['type'] === 'unknown' ? $type : ($cached['type'] ?? $type);
            return $cached;
        }

        $name = DB::table('universe_names')->where('entity_id', $id)->value('name');
        if (!$name) {
            $named = $this->esiNames([$id])[0] ?? null;
            $name  = $named['name'] ?? (string) $id;
        }

        $node = [
            'type' => $type,
            'id'   => $id,
            'name' => $name,
            'meta' => [],
        ];

        $this->nameCache[$id] = $node;
        return $node;
    }

    /** Batch /universe/names helper. */
    protected function esiNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($v) => (int)$v > 0)));
        if (empty($ids)) return [];
        try {
            $esi = EsiClient::anonymous();
            $resp = $esi->post('/universe/names', ['ids' => $ids]);
            return $resp['data'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Safe GET wrapper (returns null on failure). */
    protected function safeGet($esi, string $path): ?array
    {
        try {
            return $esi->get($path);
        } catch (\Throwable) {
            return null;
        }
    }
}
