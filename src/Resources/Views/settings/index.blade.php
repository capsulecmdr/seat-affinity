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
      @foreach ($settings as $key => $meta)
        @php
          $id = 'slider_' . $key;
          $val = (int) $meta['value'];
          $min = (int) $meta['min'];
          $max = (int) $meta['max'];
          $map = $meta['map']; // 'trust' or 'corp_change' or null
        @endphp

        <div class="col-lg-6">
          <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between">
              <strong>{{ $meta['label'] }}</strong>
              <span class="badge badge-primary">
                <span id="{{ $id }}_num">{{ $val }}:</span>
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
                oninput="AffinitySettings.updateLabel('{{ $id }}', {{ $min }}, {{ $max }}, '{{ $map }}')"
                onchange="AffinitySettings.updateLabel('{{ $id }}', {{ $min }}, {{ $max }}, '{{ $map }}')"
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

    <div class="d-flex">
      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
  </form>
</div>
@endsection

@push('javascript')
<script>
  window.AffinitySettings = {
    trustMap(num) {
      switch (Number(num)) {
        case 1: return 'Alert on Trusted -';
        case 2: return 'Alert on Verified -';
        case 3: return 'Alert on Unverified -';
        case 4: return 'Alert on Untrusted -';
        case 5: return 'Alert on Flagged';
        default: return '';
      }
    },
    corpChangeMap(num) {
      // 1..6 as per your description
      switch (Number(num)) {
        case 1: return 'Alerts Off';
        case 2: return 'Alert on all';
        case 3: return 'Alert on Verified -';
        case 4: return 'Alert on Unverified -';
        case 5: return 'Alert on Untrusted -';
        case 6: return 'Alert on Flagged';
        default: return '';
      }
    },
    updateLabel(id, min, max, mapType) {
      const el = document.getElementById(id);
      const n  = document.getElementById(id + '_num');
      const l  = document.getElementById(id + '_label');
      if (!el || !n || !l) return;
      const v = el.value;
      n.textContent = v;

      let label = '';
      if (mapType === 'trust')      label = this.trustMap(v);
      else if (mapType === 'corp_change') label = this.corpChangeMap(v);
      else label = '';

      l.textContent = label ? `(${label})` : '';
    },
    initAll() {
      document.querySelectorAll('input[type="range"][id^="slider_"]').forEach(r => {
        const mapType = r.getAttribute('oninput').includes('corp_change') ? 'corp_change'
                      : (r.getAttribute('oninput').includes('trust') ? 'trust' : null);
        this.updateLabel(r.id, r.min, r.max, mapType);
      });
    }
  };

  document.addEventListener('DOMContentLoaded', () => AffinitySettings.initAll());
</script>
@endpush
