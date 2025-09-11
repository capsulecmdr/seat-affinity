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
            'alert_threshold_contact'       => ['Contact Alert Threshold', 1, 2, 3, 4, 5, '1=Trusted,2=Verified,3=Unverified,4=Untrusted,5=Flagged', 'trust'],
            'alert_threshold_contract'      => ['Contract Alert Threshold', 1, 2, 3, 4, 5, '1=Trusted,2=Verified,3=Unverified,4=Untrusted,5=Flagged', 'trust'],
            'alert_threshold_corporation'   => ['Corporation Alert Threshold', 1, 2, 3, 4, 5, '1=Trusted,2=Verified,3=Unverified,4=Untrusted,5=Flagged', 'trust'],
            'alert_threshold_mail'          => ['Mail Alert Threshold', 1, 2, 3, 4, 5, '1=Trusted,2=Verified,3=Unverified,4=Untrusted,5=Flagged', 'trust'],
            'alert_threshold_wallet'        => ['Wallet Alert Threshold', 1, 2, 3, 4, 5, '1=Trusted,2=Verified,3=Unverified,4=Untrusted,5=Flagged', 'trust'],
            'alert_threshold_kmail_attacker'=> ['Killmail Attacker Alert Threshold', 1, 2, 3, 4, 5, '1=Trusted,2=Verified,3=Unverified,4=Untrusted,5=Flagged', 'trust'],
            'alert_threshold_kmail_blue'    => ['Killmail Blue Alert Threshold', 1, 2, 3, 4, 5, '1=Trusted,2=Verified,3=Unverified,4=Untrusted,5=Flagged', 'trust'],

            // NOTE: You listed 1…3 but then described 1…6 behaviors.
            // Using 1…6 to match the behavior list.
            'alert_corp_change'             => ['Corp Change Alert Mode', 1, 2, 3, 4, 5, 6, '1=Off, 2=All, 3=Verfied -, 4=Unverified -, 5=Untrusted -, 6=Flagged only', 'corp_change'],
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