<?php

namespace CapsuleCmdr\Affinity\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use CapsuleCmdr\Affinity\Models\AffinitySetting;
use CapsuleCmdr\Affinity\Models\AffinityEntity;
use CapsuleCmdr\Affinity\Models\AffinityTrustRelationship;
use Illuminate\Support\Facades\DB;
use Seat\Web\Models\User;
use CapsuleCmdr\Affinity\Services\AffiliationCrawler;
use Symfony\Component\HttpFoundation\Response;


class AffinityController extends Controller
{

    public function about()
    {
        $user = Auth::user();

        return view('affinity::about', compact('user'));
    }

    /**
     * Central definition so Blade + validation share the same source of truth.
     */
    private function settingDefinitions(): array
    {
        return [
            // key => [label, min, max, default, help, map (optional)]
            'alert_threshold_contact'       => ['Contact Alert Threshold', 1, 5, 3, '1=Trusted … 5=Flagged', 'trust','Triggers alerts when contacts of other parties meet or exceed the selected trust level.'],
            'alert_threshold_contract'      => ['Contract Alert Threshold', 1, 5, 3, '1=Trusted … 5=Flagged', 'trust','Triggers alerts when contracts contain other parties that meet or exceed the selected trust level.'],
            'alert_threshold_corporation'   => ['Corporation Alert Threshold', 1, 5, 3, '1=Trusted … 5=Flagged', 'trust','Triggers alerts when a character transfers to a corporation that meets or exceeds the selected trust level.'],
            'alert_threshold_mail'          => ['Mail Alert Threshold', 1, 5, 3, '1=Trusted … 5=Flagged', 'trust','Triggers alerts when mail has correspondants that meet or exceed the selected trust level.'],
            'alert_threshold_wallet'        => ['Wallet Alert Threshold', 1, 5, 3, '1=Trusted … 5=Flagged', 'trust','Triggers alerts when wallet transactions have other parties that meet or exceed the selected trust level.'],
            'alert_threshold_kmail_attacker'=> ['Killmail Attacker Alert Threshold', 1, 5, 3, '1=Trusted … 5=Flagged', 'trust','Triggers alerts when character is a victim of killmail where aggressors meets or exceeds the selected trust level.'],
            'alert_threshold_kmail_blue'    => ['Killmail Blue Alert Threshold', 1, 5, 3, '1=Trusted … 5=Flagged', 'trust','Triggers alerts when character is an attacker where the victim meets or undermatchs the selected trust level.'],

            // NOTE: You listed 1…3 but then described 1…6 behaviors.
            // Using 1…6 to match the behavior list.
            'alert_corp_change'             => ['Corp Change Alert Mode', 1, 6, 3, '1=Off, 2=gte1, 3=gte2, 4=gte3, 5=gte4, 6=gte5', 'corp_change','Triggers alerts when a corporation change meets or exceeds the selected trust level.'],
        ];
    }

    public function settings()
    {
        $defs = $this->settingDefinitions();

        // Fetch any existing settings; fall back to defaults
        $stored = AffinitySetting::whereIn('key', array_keys($defs))
            ->get()
            ->keyBy('key');

        $settings = [];
        foreach ($defs as $key => [$label,$min,$max,$default,$help]) {
            $settings[$key] = [
                'label'   => $label,
                'min'     => $min,
                'max'     => $max,
                'value'   => (int) ($stored[$key]->value ?? $default),
                'help'    => $help,
                'map'     => $defs[$key][5] ?? null,
                'description' => $defs[$key][6] ?? null,
            ];
        }

        return view('affinity::settings.index', compact('settings'));
    }

    public function updateSettings(Request $request)
    {
        $defs = $this->settingDefinitions();

        // Build validation rules dynamically
        $rules = [];
        foreach ($defs as $key => [$label,$min,$max]) {
            $rules["settings.$key"] = "required|integer|min:$min|max:$max";
        }

        $data = $request->validate($rules);

        foreach ($data['settings'] as $key => $val) {
            AffinitySetting::updateOrCreate(
                ['key' => $key],
                ['value' => (string) $val]
            );
        }

        return redirect()
            ->route('affinity.settings')
            ->with('status', 'Affinity settings updated.');
    }

    public function entityManager(Request $request)
    {
        $entities = AffinityEntity::query()
            ->leftJoin('affinity_trust_relationship as atr', 'atr.affinity_entity_id', '=', 'affinity_entity.id')
            ->leftJoin('affinity_trust_classification as atc', 'atc.id', '=', 'atr.affinity_trust_class_id')
            ->select([
                'affinity_entity.id',
                'affinity_entity.type',          // 'character' | 'corporation' | 'alliance'
                'affinity_entity.name',
                'affinity_entity.eve_id',
                DB::raw('atr.affinity_trust_class_id as trust_id'),
                DB::raw('atc.title as trust_title'),
            ])
            ->orderBy('affinity_entity.name')
            ->get();

        $selected = null;
        if ($request->filled('selected')) {
            $selected = AffinityEntity::query()
                ->leftJoin('affinity_trust_relationship as atr', 'atr.affinity_entity_id', '=', 'affinity_entity.id')
                ->leftJoin('affinity_trust_classification as atc', 'atc.id', '=', 'atr.affinity_trust_class_id')
                ->where('affinity_entity.id', (int) $request->integer('selected'))
                ->select([
                    'affinity_entity.id',
                    'affinity_entity.type',
                    'affinity_entity.name',
                    'affinity_entity.eve_id',
                    DB::raw('atr.affinity_trust_class_id as trust_id'),
                    DB::raw('atc.title as trust_title'),
                ])
                ->first();
        }

        // Base SeAT URL (configurable)
        $seat_base = config('affinity.seat_base_url', 'https://anvil.capsulecmdr.com');

        return view('affinity::affinity.entity_manager', compact('entities', 'selected', 'seat_base'));
    }

    public function updateTrust(Request $request)
    {
        $validated = $request->validate([
            'entity_id' => ['required', 'integer', 'exists:affinity_entity,id'],
            'trust_id'  => ['required', 'integer', 'exists:affinity_trust_classification,id'],
        ]);

        AffinityTrustRelationship::updateOrCreate(
            ['affinity_entity_id' => (int) $validated['entity_id']],
            ['affinity_trust_class_id' => (int) $validated['trust_id']]
        );

        return redirect()
            ->route('affinity.entities.index', ['selected' => (int) $validated['entity_id']])
            ->with('status', 'Trust relationship updated.');
    }

    public function trustManager($char_id)
    {
        $tokenRow = DB::table('refresh_tokens')->where('character_id', $char_id)->first();
        if (!$tokenRow || !$tokenRow->user_id) {
            return response('invalid target provided', Response::HTTP_BAD_REQUEST);
        }

        $ownerUserId = (int) $tokenRow->user_id;
        $user = User::with('characters')->find($ownerUserId);

        if (!$user || $user->characters->isEmpty()) {
            return response('owner has no linked characters', Response::HTTP_BAD_REQUEST);
        }

        /** @var AffiliationCrawler $crawler */
        $crawler = app(AffiliationCrawler::class);
        $dossier = $crawler->buildDossierForUser($user)->toArray();

        return view('affinity::affinity.trustmanager',['dossier' => $dossier,'char_id' => $char_id,]);

        // return response()->json($dossier->toArray(), Response::HTTP_OK);
    }
}