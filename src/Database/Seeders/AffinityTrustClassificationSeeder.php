<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use CapsuleCmdr\SeatAffinity\Models\AffinityTrustClassification;

class AffinityTrustClassificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $classifications = [
            'Trusted',
            'Verified',
            'Unverified',
            'Untrusted',
            'Flagged',
        ];

        foreach ($classifications as $title) {
            AffinityTrustClassification::firstOrCreate(
                ['title' => $title],
                ['title' => $title]
            );
        }
    }
}
