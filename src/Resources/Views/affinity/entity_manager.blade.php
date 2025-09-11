{{-- resources/views/affinity/entity_manager.blade.php --}}
@extends('web::layouts.app')

@section('title', 'Affinity Entity Manager')

@push('css')
<style>
  .btn-group .btn { margin-right: .25rem; }
  .btn-group .btn:last-child { margin-right: 0; }
  @media (max-width: 992px) {
    .card-header .d-flex.gap-2 > div { margin-bottom: .25rem; }
  }
</style>
@endpush

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
        <div class="card-header">
            <div class="d-flex flex-wrap align-items-center gap-2">

                {{-- Group: Type --}}
                <div class="d-flex align-items-center me-3">
                <span class="small text-muted me-2">Type:</span>
                <div class="btn-group btn-group-sm" role="group" aria-label="Type filters">
                    <label class="btn btn-outline-secondary active">
                    <input type="checkbox" class="d-none type-chip" data-type="alliance" checked>
                    Alliance
                    </label>
                    <label class="btn btn-outline-secondary active">
                    <input type="checkbox" class="d-none type-chip" data-type="corporation" checked>
                    Corporation
                    </label>
                    <label class="btn btn-outline-secondary active">
                    <input type="checkbox" class="d-none type-chip" data-type="character" checked>
                    Character
                    </label>
                </div>
                </div>

                {{-- Group: Trust --}}
                <div class="d-flex align-items-center">
                <span class="small text-muted me-2">Trust:</span>
                <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Trust filters">
                    <label class="btn btn-outline-primary active">
                    <input type="checkbox" class="d-none trust-chip" data-trust="1" checked> Trusted
                    </label>
                    <label class="btn btn-outline-info active">
                    <input type="checkbox" class="d-none trust-chip" data-trust="2" checked> Verified
                    </label>
                    <label class="btn btn-outline-secondary active">
                    <input type="checkbox" class="d-none trust-chip" data-trust="3" checked> Unverified
                    </label>
                    <label class="btn btn-outline-warning active">
                    <input type="checkbox" class="d-none trust-chip" data-trust="4" checked> Untrusted
                    </label>
                    <label class="btn btn-outline-danger active">
                    <input type="checkbox" class="d-none trust-chip" data-trust="5" checked> Flagged
                    </label>
                </div>
                </div>

                {{-- Search on the far right --}}
                <div class="ml-auto" style="min-width:220px;">
                <input id="search-box" type="search" class="form-control form-control-sm"
                        placeholder="search (min 3 chars)">
                </div>
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

@push('javascript')
<script>
(function(){
  // Trust mapping (labels + Bootstrap classes)
  const trustMap = {
    1: {label:'Trusted',    class:'btn-primary'},
    2: {label:'Verified',   class:'btn-info'},
    3: {label:'Unverified', class:'btn-secondary'},
    4: {label:'Untrusted',  class:'btn-warning'},
    5: {label:'Flagged',    class:'btn-danger'}
  };

  const table      = document.getElementById('entities-table');
  const tableHead  = table.querySelector('thead');
  const tableBody  = table.querySelector('tbody');

  // We'll refresh this after sorts so other code keeps working
  let rows = Array.from(document.querySelectorAll('.entity-row'));

  // Chip selectors
  const typeChips   = Array.from(document.querySelectorAll('.type-chip'));
  const trustChips  = Array.from(document.querySelectorAll('.trust-chip'));

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

  // ---------- FILTERING ----------

  function activeTypes(){
    return new Set(typeChips.filter(c => c.checked).map(c => c.dataset.type));
  }
  function activeTrusts(){
    return new Set(trustChips.filter(c => c.checked).map(c => parseInt(c.dataset.trust,10)));
  }

  function applyFilters(){
    const enabledTypes  = activeTypes();
    const enabledTrusts = activeTrusts();
    const q = (searchBox.value || '').trim().toLowerCase();
    const useSearch = q.length >= 3;

    rows.forEach(tr=>{
      const type   = tr.dataset.type;
      const name   = tr.dataset.name;
      const trust  = parseInt(tr.dataset.trustid || '3', 10);

      const typeOk   = enabledTypes.has(type);
      const trustOk  = enabledTrusts.has(trust);
      const searchOk = !useSearch || name.includes(q);

      tr.style.display = (typeOk && trustOk && searchOk) ? '' : 'none';
    });
  }

  function wireChipToggle(chipList){
    chipList.forEach(input => {
      const label = input.closest('label.btn');

      // Initialize label state
      label.classList.toggle('active', !!input.checked);

      // Explicit toggle on label click (works even if input is hidden)
      label.addEventListener('click', (e) => {
        e.preventDefault();
        input.checked = !input.checked;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });

      // Sync on input change
      input.addEventListener('change', () => {
        label.classList.toggle('active', !!input.checked);
        applyFilters();
      });
    });
  }
  wireChipToggle(typeChips);
  wireChipToggle(trustChips);

  searchBox.addEventListener('input', applyFilters);

  // ---------- SELECTION / TRUST PILL ----------

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
    const radio = tr.querySelector('input.row-picker');
    if(radio) radio.checked = true;

    // Bind details panel
    dId.textContent    = tr.dataset.id;
    dType.textContent  = tr.dataset.type;
    dName.textContent  = tr.querySelector('td:nth-child(4)').textContent;
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

  function refreshRowBindings(){
    rows = Array.from(document.querySelectorAll('.entity-row'));
    rows.forEach(tr=>{
      // prevent stacking duplicate handlers
      tr.__bound || (tr.__bound = true, tr.addEventListener('click', (e)=>{
        if(!e.target.classList.contains('row-picker')){
          selectRow(tr);
        }
      }));
      const radio = tr.querySelector('input.row-picker');
      if(radio && !radio.__bound){
        radio.__bound = true;
        radio.addEventListener('change', ()=>selectRow(tr));
      }
    });
  }
  refreshRowBindings();

  trustRange?.addEventListener('input', ()=>{
    updateTrustPill(parseInt(trustRange.value,10));
  });

  trustRange?.addEventListener('change', ()=>{
    if(fEntityId.value){
      trustForm.submit();
    }
  });

  // ---------- SORTING ----------

  // Map header index -> sort key
  // 0: Id      (numeric)
  // 1: Avatar  (skip)
  // 2: Type    (string from dataset.type)
  // 3: Name    (string from cell text)
  // 4: Eve ID  (numeric from dataset.eveid)
  // 5: Trust   (numeric from dataset.trustid)
  // 6: (radio) (skip)
  const sortableMap = {
    0: 'id',
    2: 'type',
    3: 'name',
    4: 'eveid',
    5: 'trust'
  };

  let currentSort = { key: null, dir: 'asc' }; // dir: 'asc' | 'desc'

  // Add basic cursor + indicator via titles; (optionally add icons here)
  Array.from(tableHead.querySelectorAll('th')).forEach((th, idx) => {
    if (idx in sortableMap) {
      th.style.cursor = 'pointer';
      th.title = 'Click to sort';
      th.addEventListener('click', () => {
        const key = sortableMap[idx];
        // toggle direction if same key; otherwise asc
        currentSort.dir = (currentSort.key === key && currentSort.dir === 'asc') ? 'desc' : 'asc';
        currentSort.key = key;
        sortTable(key, currentSort.dir);
      });
    }
  });

  function compareValues(a, b, dir){
    if (a === b) return 0;
    if (a === undefined || a === null) return (dir === 'asc') ? 1 : -1;
    if (b === undefined || b === null) return (dir === 'asc') ? -1 : 1;
    if (typeof a === 'number' && typeof b === 'number'){
      return (dir === 'asc') ? (a - b) : (b - a);
    }
    // fallback string compare (case-insensitive)
    a = String(a).toLowerCase();
    b = String(b).toLowerCase();
    if (a < b) return (dir === 'asc') ? -1 : 1;
    if (a > b) return (dir === 'asc') ? 1 : -1;
    return 0;
  }

  function getSortValue(tr, key){
    switch(key){
      case 'id':    return parseInt(tr.dataset.id, 10);
      case 'eveid': return parseInt(tr.dataset.eveid, 10);
      case 'trust': return parseInt(tr.dataset.trustid || '3', 10);
      case 'type':  return tr.dataset.type || '';
      case 'name':  // use the Name cell text to respect case/spacing
        return tr.querySelector('td:nth-child(4)')?.textContent?.trim() || '';
      default: return '';
    }
  }

  function sortTable(key, dir){
    // Only sort visible rows to avoid jumping hidden entries when filters applied
    const visibleRows = rows.filter(tr => tr.style.display !== 'none');

    visibleRows.sort((a, b) => {
      const va = getSortValue(a, key);
      const vb = getSortValue(b, key);
      return compareValues(va, vb, dir);
    });

    // Re-append visible rows in sorted order; keep hidden rows in their current order (at end)
    visibleRows.forEach(tr => tableBody.appendChild(tr));
    rows.filter(tr => tr.style.display === 'none').forEach(tr => tableBody.appendChild(tr));

    // Rebind row listeners (DOM order changed)
    refreshRowBindings();
  }

  // ---------- INITIALIZE ----------

  applyFilters();

})();
</script>
@endpush




@endsection
