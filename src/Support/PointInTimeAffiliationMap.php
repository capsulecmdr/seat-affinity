<?php

namespace CapsuleCmdr\Affinity\Support;

class PointInTimeAffiliationMap
{
    public string $name;
    public int $owner_user_id;

    /** @var array<string,array{id:int,type:string,name:string,meta?:array}> key = "type:id" */
    public array $nodes = [];
    /** @var array<int,array{kind:string,src:array,dst:array,at:?string,end:?string,meta:array}> */
    public array $edges = [];

    public array $characters   = [];
    public array $corporations = [];
    public array $alliances    = [];

    public array $issues = [];

    public function __construct(string $name, int $owner_user_id)
    {
        $this->name = $name;
        $this->owner_user_id = $owner_user_id;
    }

    public function addCharacterNode(array $entity): void
    {
        $this->addNode($entity);
        $this->characters[$entity['id']] = $entity;
    }

    public function addCorporationNode(array $entity): void
    {
        $this->addNode($entity);
        $this->corporations[$entity['id']] = $entity;
    }

    public function addAllianceNode(array $entity): void
    {
        $this->addNode($entity);
        $this->alliances[$entity['id']] = $entity;
    }

    public function addNode(array $entity): void
    {
        $this->upsertNode($entity);
    }

    /** Merge metadata if node already exists */
    public function upsertNode(array $entity): void
    {
        $key = $this->key($entity);
        $prev = $this->nodes[$key] ?? null;
        $this->nodes[$key] = $prev ? array_replace_recursive($prev, $entity) : $entity;
    }

    public function addEdge(string $kind, array $edge): void
    {
        $this->edges[] = [
            'kind' => $kind,
            'src'  => $edge['src'],
            'dst'  => $edge['dst'],
            'at'   => $edge['at'] ?? null,
            'end'  => $edge['end'] ?? null,
            'meta' => $edge['meta'] ?? [],
        ];
    }

    public function addError(string $category, int $characterId, string $message): void
    {
        $this->issues[] = [
            'category'     => $category,
            'character_id' => $characterId,
            'type'         => 'error',
            'message'      => $message,
        ];
    }

    public function addMissingScopeOrError(string $category, int $characterId, \Throwable $e): void
    {
        $msg = $e->getMessage();
        $type = (str_contains(strtolower($msg), '403') || str_contains(strtolower($msg), 'insufficient'))
            ? 'missing_scope'
            : 'error';

        $this->issues[] = [
            'category'     => $category,
            'character_id' => $characterId,
            'type'         => $type,
            'message'      => $msg,
        ];
    }

    public function finalize(): void
    {
        usort($this->edges, fn($a, $b) => strcmp($a['at'] ?? '', $b['at'] ?? ''));
    }

    public function toArray(): array
    {
        return [
            'owner'        => ['user_id' => $this->owner_user_id, 'name' => $this->name],
            'nodes'        => array_values($this->nodes),
            'edges'        => $this->edges,
            'characters'   => array_values($this->characters),
            'corporations' => array_values($this->corporations),
            'alliances'    => array_values($this->alliances),
            'issues'       => $this->issues,
            'generated_at' => now('UTC')->toIso8601String(),
        ];
    }

    protected function key(array $e): string
    {
        return "{$e['type']}:{$e['id']}";
    }
}
