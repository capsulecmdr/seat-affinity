{{-- resources/views/affinity/entity_manager.blade.php --}}
@extends('web::layouts.app')

@section('title', 'Affinity Entity Manager')

@section('content')
@php
  $trustMap = [
    1 => ['label' => 'Trusted',    'class' => 'btn-primary'],      // blue
    2 => ['label' => 'Verified',   'class' => 'btn-info'],         // light blue
    3 => ['label' => 'Unverified', 'class' => 'btn-secondary'],    // grey
    4 => ['label' => 'Untrusted',  'class' => 'btn-warning'],      // yellow/orange
    5 => ['label' => 'Flagged',    'class' => 'btn-danger'],       // red
  ];

  $selectedTrustId   = $selected->trust_id   ?? 3;
  $selectedTrustData = $trustMap[$selectedTrustId];
  $seat_base = config('app.url');
  // Build the correct SeAT URL for an entity
  $makeSeatUrl = function ($type, $eve_id) use ($seat_base) {
      $type = strtolower((string)$type);
      if ($type === 'character')    return "{$seat_base}/characters/{$eve_id}/sheet";
      if ($type === 'corporation')  return "{$seat_base}/corporations/{$eve_id}/summary";
      if ($type === 'alliance')     return "{$seat_base}/alliances/{$eve_id}/summary";
      return $seat_base;
  };
@endphp

<div class="container-fluid">

  <div class="row">
    {{-- LEFT: Selected Entity Details --}}
    <div class="col-lg-4">
      <div class="card mb-3 sticky-top" style="top: 1rem;">
        <div class="card-header">
          <strong>Selected Entity Details</strong>
        </div>
        <div class="card-body">
          <dl class="mb-3" id="detail-fields">
            <dt class="text-muted mb-1">id:</dt>
            <dd id="d-id">{{ $selected->id ?? '—' }}</dd>

            <dt class="text-muted mb-1">type:</dt>
            <dd id="d-type">{{ $selected->type ?? '—' }}</dd>

            <dt class="text-muted mb-1">name:</dt>
            <dd id="d-name">{{ $selected->name ?? '—' }}</dd>

            <dt class="text-muted mb-1">eve_id:</dt>
            <dd id="d-eveid">{{ $selected->eve_id ?? '—' }}</dd>
          </dl>

          <div class="d-flex justify-content-center mb-3">
            <img id="d-avatar"
                 src="{{ $selected->avatar_url ?? 'https://images.evetech.net/characters/123/portrait?size=128' }}"
                 alt="Avatar"
                 class="rounded-circle"
                 style="width:140px;height:140px;object-fit:cover;">
          </div>

          <div class="text-center mb-3">
            @if(!empty($selected))
                <a id="d-seat-link" href="{{ $makeSeatUrl($selected->type ?? '', $selected->eve_id ?? '') }}" target="_blank" rel="noopener">View SeAT Record</a>
            @else
                <a id="d-seat-link" href="javascript:void(0)" class="disabled text-muted">View SeAT Record</a>
            @endif
            </div>

          {{-- Trust adjust form --}}
          <form id="trust-form" method="POST" action="{{ route('affinity.entities.updateTrust') }}">
            @csrf
            <input type="hidden" name="entity_id" id="f-entity-id" value="{{ $selected->id ?? '' }}">
            <div class="d-grid gap-2 mb-2">
            <button class="btn {{ $selectedTrustData['class'] }} btn-block" 
                    type="button" id="trust-pill">
                {{ $selectedTrustData['label'] }}
            </button>
            </div>

            <div class="px-2">
              <input type="range"
                     class="form-range w-100"
                     min="1" max="5" step="1"
                     name="trust_id"
                     id="trust-range"
                     value="{{ $selectedTrustId }}">
              <div class="text-center small text-muted mt-1">adjust trust relationship</div>
            </div>
          </form>
        </div>
      </div>
    </div>

    {{-- RIGHT: Observed Entities --}}
    <div class="col-lg-8">
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <strong>Observed Entities</strong>

            <div class="d-flex align-items-center flex-wrap gap-3">
                {{-- Type filters --}}
                <div class="d-flex align-items-center gap-3">
                <label class="mb-0 me-3">
                    <input type="checkbox" class="me-1 type-filter" data-type="alliance" checked>
                    Alliances
                </label>
                <label class="mb-0 me-3">
                    <input type="checkbox" class="me-1 type-filter" data-type="corporation" checked>
                    Corporations
                </label>
                <label class="mb-0 me-3">
                    <input type="checkbox" class="me-1 type-filter" data-type="character" checked>
                    Characters
                </label>
                </div>

                {{-- Trust filters --}}
                <div class="d-flex align-items-center gap-2 ms-3">
                <label class="mb-0 me-2 small text-muted">Trust:</label>
                <label class="mb-0 me-2">
                    <input type="checkbox" class="me-1 trust-filter" data-trust="1" checked>
                    Trusted
                </label>
                <label class="mb-0 me-2">
                    <input type="checkbox" class="me-1 trust-filter" data-trust="2" checked>
                    Verified
                </label>
                <label class="mb-0 me-2">
                    <input type="checkbox" class="me-1 trust-filter" data-trust="3" checked>
                    Unverified
                </label>
                <label class="mb-0 me-2">
                    <input type="checkbox" class="me-1 trust-filter" data-trust="4" checked>
                    Untrusted
                </label>
                <label class="mb-0 me-2">
                    <input type="checkbox" class="me-1 trust-filter" data-trust="5" checked>
                    Flagged
                </label>
                </div>

                <input id="search-box" type="search" class="form-control form-control-sm"
                    placeholder="search (min 3 chars)" style="width:220px;">
            </div>
            </div>


        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-striped mb-0" id="entities-table">
              <thead class="table-light">
                <tr>
                  <th style="width:48px;">Id</th>
                  <th style="width:64px;">Avatar</th>
                  <th>Type</th>
                  <th>Name</th>
                  <th>Eve ID</th>
                  <th>Trust Level</th>
                  <th style="width:48px;"></th>
                </tr>
              </thead>
              <tbody>
              @forelse($entities as $e)
                @php
                    switch(strtolower($e->type)){
                        case "alliance":
                            $e->avatar_url = "https://images.evetech.net/alliances/". $e->eve_id ."/logo?size=128";
                        break;
                        case "corporation":
                            $e->avatar_url = "https://images.evetech.net/corporations/". $e->eve_id ."/logo?size=128";
                        break;
                        case "character":
                            $e->avatar_url = "https://images.evetech.net/characters/". $e->eve_id ."/portrait?size=128";
                        break;
                        default: '';

                    };

                    $tid = (int) ($e->trust_id ?? 3);
                    $t   = $trustMap[$tid] ?? $trustMap[3];
                @endphp
                <tr class="entity-row"
                    data-type="{{ strtolower($e->type) }}"
                    data-name="{{ strtolower($e->name) }}"
                    data-id="{{ $e->id }}"
                    data-eveid="{{ $e->eve_id }}"
                    data-avatar="{{ $e->avatar_url }}"
                    data-trustid="{{ $e->trust_id ?? 3 }}"
                    data-trusttitle="{{ $e->trust_title ?? 'Unverified' }}"
                    data-seaturl="{{ $makeSeatUrl($e->type, $e->eve_id) }}">
                  <td>{{ $e->id }}</td>
                  <td>
                    <img src="{{ $e->avatar_url }}" alt="avatar" class="rounded-circle" style="width:36px;height:36px;object-fit:cover;">
                  </td>
                  <td class="text-capitalize">{{ $e->type }}</td>
                  <td>{{ $e->name }}</td>
                  <td>{{ $e->eve_id }}</td>
                  <td><span class="badge {{ str_replace('btn-', 'bg-', $t['class']) }}">
                        {{ $t['label'] }}
                    </span></td>
                  <td class="text-end">
                    <input type="radio" name="row-select" class="form-check-input row-picker">
                  </td>
                </tr>
              @empty
                <tr><td colspan="7" class="text-center text-muted p-4">No entities to display.</td></tr>
              @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- Inline script keeps it self-contained; feel free to move into your asset pipeline --}}
@push('javascript')
<script>
(function(){
  const trustMap = {
    1: {label:'Trusted',    class:'btn-primary'},
    2: {label:'Verified',   class:'btn-info'},
    3: {label:'Unverified', class:'btn-secondary'},
    4: {label:'Untrusted',  class:'btn-warning'},
    5: {label:'Flagged',    class:'btn-danger'}
  };

  const rows        = Array.from(document.querySelectorAll('.entity-row'));
  const chkType     = Array.from(document.querySelectorAll('.type-filter'));
  const chkTrust    = Array.from(document.querySelectorAll('.trust-filter'));
  const searchBox   = document.getElementById('search-box');

  const dId     = document.getElementById('d-id');
  const dType   = document.getElementById('d-type');
  const dName   = document.getElementById('d-name');
  const dEveId  = document.getElementById('d-eveid');
  const dAvatar = document.getElementById('d-avatar');
  const dSeat   = document.getElementById('d-seat-link');

  const trustForm   = document.getElementById('trust-form');
  const trustRange  = document.getElementById('trust-range');
  const trustPill   = document.getElementById('trust-pill');
  const fEntityId   = document.getElementById('f-entity-id');

  function activeTypes(){
    return new Set(chkType.filter(c=>c.checked).map(c=>c.dataset.type));
  }
  function activeTrusts(){
    return new Set(chkTrust.filter(c=>c.checked).map(c=>parseInt(c.dataset.trust,10)));
  }

  function applyFilters(){
    const enabledTypes  = activeTypes();
    const enabledTrusts = activeTrusts();
    const q = (searchBox.value || '').trim().toLowerCase();
    const useSearch = q.length >= 3;

    rows.forEach(tr=>{
      const type   = tr.dataset.type;                      // 'character' | 'corporation' | 'alliance'
      const name   = tr.dataset.name;                      // lowercased
      const trust  = parseInt(tr.dataset.trustid || '3', 10);

      const typeOk   = enabledTypes.has(type);
      const trustOk  = enabledTrusts.has(trust);
      const searchOk = !useSearch || name.includes(q);

      tr.style.display = (typeOk && trustOk && searchOk) ? '' : 'none';
    });
  }

  chkType.forEach(c => c.addEventListener('change', applyFilters));
  chkTrust.forEach(c => c.addEventListener('change', applyFilters));
  searchBox.addEventListener('input', applyFilters);

  function clearRowSelections(){
    document.querySelectorAll('.row-picker').forEach(r => r.checked = false);
    rows.forEach(r => r.classList.remove('table-active'));
  }

  function updateTrustPill(val){
    const data = trustMap[val] || trustMap[3];
    trustPill.textContent = data.label;
    trustPill.className = 'btn btn-block ' + data.class;
  }

  function selectRow(tr){
    clearRowSelections();
    tr.classList.add('table-active');
    const radio = tr.querySelector('.row-picker');
    if(radio) radio.checked = true;

    // Bind details panel
    dId.textContent    = tr.dataset.id;
    dType.textContent  = tr.dataset.type;
    dName.textContent  = tr.querySelector('td:nth-child(4)').textContent; // Name cell
    dEveId.textContent = tr.dataset.eveid;
    dAvatar.src        = tr.dataset.avatar || 'https://images.evetech.net/characters/123/portrait?size=128';
    dSeat.classList.remove('disabled','text-muted');
    dSeat.href         = tr.dataset.seaturl;

    // Bind trust form
    fEntityId.value    = tr.dataset.id;
    const tid          = parseInt(tr.dataset.trustid || '3', 10);
    trustRange.value   = tid;
    updateTrustPill(tid);
  }

  rows.forEach(tr=>{
    tr.addEventListener('click', (e)=>{
      if(!e.target.classList.contains('row-picker')){
        selectRow(tr);
      }
    });
    const radio = tr.querySelector('.row-picker');
    if(radio){
      radio.addEventListener('change', ()=>selectRow(tr));
    }
  });

  // Update pill dynamically & auto-submit on change
  trustRange?.addEventListener('input', ()=>{
    updateTrustPill(parseInt(trustRange.value,10));
  });

  trustRange?.addEventListener('change', ()=>{
    if(fEntityId.value){
      trustForm.submit();
    }
  });

  // Initial filter pass
  applyFilters();
})();
</script>
@endpush

@endsection
