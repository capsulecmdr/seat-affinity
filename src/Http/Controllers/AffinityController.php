<?php

namespace CapsuleCmdr\Affinity\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use CapsuleCmdr\Affinity\Models\AffinitySetting;


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
            'alert_threshold_kmail_attacker'=> ['Killmail Attacker Alert Threshold', 1, 5, 3, '1=Trusted … 5=Flagged', 'trust','Triggers alerts when character is a victim of killmail where aggressors meet or exceed the selected trust level.'],
            'alert_threshold_kmail_blue'    => ['Killmail Blue Alert Threshold', 1, 5, 3, '1=Trusted … 5=Flagged', 'trust','Triggers alerts when character is an attacker where the victim meet or undermatch the selected trust level.'],

            // NOTE: You listed 1…3 but then described 1…6 behaviors.
            // Using 1…6 to match the behavior list.
            'alert_corp_change'             => ['Corp Change Alert Mode', 1, 6, 3, '1=Off, 2=gte1, 3=gte2, 4=gte3, 5=gte4, 6=gte5', 'corp_change','Triggers alerts when a corporation change meet or exceed the selected trust level.'],
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
}