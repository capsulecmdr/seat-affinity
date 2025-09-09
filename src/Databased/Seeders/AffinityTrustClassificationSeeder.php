<?php

namespace CapsuleCmdr\Affinity\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AffinityTrustClassificationSeeder extends Seeder
{
    public function run(): void
    {
        $now  = now();
        $rows = collect(['Trusted','Verified','Unverified','Untrusted','Flagged'])
            ->map(fn ($t) => ['title' => $t, 'created_at' => $now, 'updated_at' => $now])
            ->all();

        DB::table('affinity_trust_classification')->upsert(
            $rows,
            ['title'],     // unique key
            ['updated_at'] // columns to update on conflict
        );
    }
}
