@extends('web::layouts.app')

@section('title', 'Affinity - Settings')

@section('content')
<div class="container-fluid">
  @if (session('status'))
    <div class="alert alert-success mb-3">{{ session('status') }}</div>
  @endif

  <form method="POST" action="{{ route('affinity.settings.update') }}">
    @csrf

    <div class="row">
      {{-- LEFT: Explainer / Legend --}}
      <div class="col-lg-4">
        <div class="card mb-3 sticky-top" style="top: 1rem;">
          <div class="card-header">
            <strong>About Affinity Settings</strong>
          </div>
          <div class="card-body">
            <p class="mb-3">
              Configure alert thresholds for contacts, killmails, corp changes, and more.
              Values map to trust classifications so you can dial in how sensitive your alerts are.
            </p>

            <h6 class="mb-2">Trust Levels</h6>
            <ul class="list-unstyled small mb-3">
              <li><span class="badge badge-light">1</span> Trusted</li>
              <li><span class="badge badge-light">2</span> Verified</li>
              <li><span class="badge badge-light">3</span> Unverified</li>
              <li><span class="badge badge-light">4</span> Untrusted</li>
              <li><span class="badge badge-light">5</span> Flagged</li>
            </ul>

            <h6 class="mb-2">Corp Change Modes</h6>
            <ul class="list-unstyled small mb-0">
              <li><span class="badge badge-light">1</span> Alerts Off</li>
              <li><span class="badge badge-light">2</span> Alert on all</li>
              <li><span class="badge badge-light">3</span> Alert on ≥ Verified</li>
              <li><span class="badge badge-light">4</span> Alert on ≥ Unverified</li>
              <li><span class="badge badge-light">5</span> Alert on ≥ Untrusted</li>
              <li><span class="badge badge-light">6</span> Alert on Flagged only</li>
            </ul>
          </div>
          <div class="card-footer d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">Save Settings</button>
          </div>
        </div>
      </div>

      {{-- RIGHT: Form with two columns of sliders --}}
      <div class="col-lg-8">
        <div class="row">
          @foreach ($settings as $key => $meta)
            @php
              $id = 'slider_' . $key;
              $val = (int) $meta['value'];
              $min = (int) $meta['min'];
              $max = (int) $meta['max'];
              $map = $meta['map']; // 'trust' or 'corp_change' or null
            @endphp

            <div class="col-md-6">
              <div class="card mb-3 h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                  <strong>{{ $meta['label'] }}</strong>
                  <span class="badge badge-primary">
                    <span id="{{ $id }}_num">{{ $val }}</span>
                    <span id="{{ $id }}_label" class="ml-1"></span>
                  </span>
                </div>
                <div class="card-body">
                  {{-- Hidden default guarantees a value is always sent --}}
                  <input type="hidden" name="settings[{{ $key }}]" value="{{ $min }}">

                  <input
                    type="range"
                    id="{{ $id }}"
                    name="settings[{{ $key }}]"
                    class="form-control-range w-100"
                    min="{{ $min }}"
                    max="{{ $max }}"
                    step="1"
                    value="{{ $val }}"
                    data-map="{{ $map ?? '' }}"
                    oninput="AffinitySettings.updateLabel('{{ $id }}')"
                    onchange="AffinitySettings.updateLabel('{{ $id }}')"
                  >

                  <small class="form-text text-muted mt-2">
                    {{ $meta['help'] }}
                  </small>

                  @error("settings.$key")
                    <span class="text-danger small d-block mt-2">{{ $message }}</span>
                  @enderror
                </div>
              </div>
            </div>
          @endforeach
        </div>
      </div>
    </div>

    {{-- Mobile/extra: bottom save button (hidden on lg+ because sidebar has one) --}}
    <div class="d-lg-none d-flex mt-2">
      <button type="submit" class="btn btn-primary btn-block">Save Settings</button>
    </div>
  </form>
</div>
@endsection

@push('javascript')
<script>
  window.AffinitySettings = {
    trustMap(num) {
      switch (Number(num)) {
        case 1: return 'Alert on Trusted';
        case 2: return 'Alert on Verified';
        case 3: return 'Alert on Unverified';
        case 4: return 'Alert on Untrusted';
        case 5: return 'Alert on Flagged';
        default: return '';
      }
    },
    corpChangeMap(num) {
      switch (Number(num)) {
        case 1: return 'Alerts Off';
        case 2: return 'Alert on all';
        case 3: return 'Alert on ≥ Verified';
        case 4: return 'Alert on ≥ Unverified';
        case 5: return 'Alert on ≥ Untrusted';
        case 6: return 'Alert on Flagged';
        default: return '';
      }
    },
    labelFor(id, v) {
      const input = document.getElementById(id);
      const mapType = (input && input.dataset && input.dataset.map) ? input.dataset.map : null;
      if (mapType === 'trust')       return this.trustMap(v);
      if (mapType === 'corp_change') return this.corpChangeMap(v);
      return '';
    },
    updateLabel(id) {
      const el = document.getElementById(id);
      const n  = document.getElementById(id + '_num');
      const l  = document.getElementById(id + '_label');
      if (!el || !n || !l) return;
      const v = Number(el.value);
      n.textContent = v;
      const label = this.labelFor(id, v);
      l.textContent = label ? `(${label})` : '';
    },
    initAll() {
      document.querySelectorAll('input[type="range"][id^="slider_"]').forEach(r => {
        this.updateLabel(r.id);
      });
    }
  };

  document.addEventListener('DOMContentLoaded', () => AffinitySettings.initAll());
</script>
@endpush
