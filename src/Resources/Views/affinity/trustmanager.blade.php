{{-- resources/views/affinity/dossier.blade.php --}}
@extends('web::layouts.app')

@section('title', 'Affinity — Point-in-Time Dossier')

@push('css')
<style>
  .meta-badge { font-size: .75rem; }
  .table-sticky th { position: sticky; top: 0; background: #fff; z-index: 2; }
  .small-muted { font-size: .85rem; color: #6c757d; }
  .nowrap { white-space: nowrap; }
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  @media (max-width: 992px) {
    .grid-2 { grid-template-columns: 1fr; }
  }
</style>
@endpush

@section('content')
@php
  // Normalize inputs
  $owner       = $dossier['owner']        ?? ['user_id'=>null,'name'=>'Unknown'];
  $nodes       = collect($dossier['nodes'] ?? []);
  $characters  = collect($dossier['characters'] ?? []);
  $corporations= collect($dossier['corporations'] ?? []);
  $alliances   = collect($dossier['alliances'] ?? []);
  $edges       = collect($dossier['edges'] ?? []);
  $issues      = collect($dossier['issues'] ?? []);
  $generatedAt = $dossier['generated_at'] ?? null;

  // Edge kinds present
  $kinds = $edges->pluck('kind')->unique()->values()->all();
@endphp

<div class="container-fluid">

  {{-- Header --}}
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h4 class="mb-0">Point-in-Time Affiliation Dossier</h4>
      <div class="small-muted">
        Owner: <strong>{{ $owner['name'] }}</strong> (User ID: {{ $owner['user_id'] }}) ·
        Built: <span class="nowrap">{{ $generatedAt }}</span>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a href="{{ route('affinity.dossier', ['char_id'=>$char_id, 'format'=>'json']) }}"
         class="btn btn-sm btn-outline-secondary">
        Export JSON
      </a>
      <button class="btn btn-sm btn-primary" id="copyJsonBtn">Copy JSON</button>
    </div>
  </div>

  {{-- Top summary cards --}}
  <div class="row mb-3">
    <div class="col-md-3">
      <div class="card h-100">
        <div class="card-header"><strong>Summary</strong></div>
        <div class="card-body">
          <div>Characters: <strong>{{ $characters->count() }}</strong></div>
          <div>Corporations: <strong>{{ $corporations->count() }}</strong></div>
          <div>Alliances: <strong>{{ $alliances->count() }}</strong></div>
          <div>Edges: <strong>{{ $edges->count() }}</strong></div>
        </div>
      </div>
    </div>
    <div class="col-md-9">
      <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Scope Issues & Errors</strong>
          <span class="badge bg-secondary">{{ $issues->count() }}</span>
        </div>
        <div class="card-body" style="max-height: 200px; overflow:auto;">
          @if($issues->isEmpty())
            <em class="text-muted">No issues recorded.</em>
          @else
            <ul class="mb-0">
              @foreach($issues as $i)
                <li class="small">
                  <strong>{{ ucfirst($i['type']) }}</strong>
                  in <code>{{ $i['category'] }}</code>
                  (char: {{ $i['character_id'] ?? 'n/a' }}) —
                  <span class="text-muted">{{ $i['message'] }}</span>
                </li>
              @endforeach
            </ul>
          @endif
        </div>
      </div>
    </div>
  </div>

  {{-- Nodes --}}
  <div class="grid-2 mb-3">
    {{-- Characters --}}
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Characters</strong>
        <span class="badge bg-secondary">{{ $characters->count() }}</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 360px; overflow:auto;">
          <table class="table table-sm table-hover mb-0 table-sticky">
            <thead>
              <tr>
                <th class="nowrap">ID</th>
                <th>Name</th>
                <th>Corp</th>
                <th>Alliance</th>
                <th class="nowrap">Sec</th>
                <th>Affinity / Trust</th>
              </tr>
            </thead>
            <tbody>
            @foreach($characters as $c)
              @php
                $m = $c['meta'] ?? [];
                $corp = $m['corporation'] ?? null; // {id,name,ticker}
                $ally = $m['alliance']    ?? null; // {id,name,ticker}
                $aff  = $m['affinity']    ?? ['affinity_entity_id'=>null,'trust'=>null];
                $trust= $aff['trust'] ?? null;
              @endphp
              <tr>
                <td class="nowrap">{{ $c['id'] }}</td>
                <td>
                  <div class="fw-semibold">{{ $c['name'] }}</div>
                  <div class="small-muted">Birthday: {{ $m['birthday'] ?? '—' }}</div>
                </td>
                <td>
                  @if($corp)
                    <div>{{ $corp['name'] }}</div>
                    <div class="small-muted">{{ $corp['ticker'] ?? '' }} · {{ $corp['id'] ?? '' }}</div>
                  @else
                    <span class="text-muted">—</span>
                  @endif
                </td>
                <td>
                  @if($ally)
                    <div>{{ $ally['name'] }}</div>
                    <div class="small-muted">{{ $ally['ticker'] ?? '' }} · {{ $ally['id'] ?? '' }}</div>
                  @else
                    <span class="text-muted">—</span>
                  @endif
                </td>
                <td class="nowrap">{{ $m['security_status'] ?? '—' }}</td>
                <td>
                  @if($aff['affinity_entity_id'])
                    <div class="small">
                      Entity: <strong>#{{ $aff['affinity_entity_id'] }}</strong>
                    </div>
                    @if($trust)
                      <span class="badge bg-info meta-badge">
                        {{ $trust['classification'] ?? '—' }}
                      </span>
                      <div class="small-muted">
                        Updated: {{ $trust['updated_at'] ?? '—' }}
                      </div>
                    @else
                      <span class="text-muted small">No trust set</span>
                    @endif
                  @else
                    <span class="text-muted small">Unmapped</span>
                  @endif
                </td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </div>

    {{-- Corporations --}}
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Corporations</strong>
        <span class="badge bg-secondary">{{ $corporations->count() }}</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 360px; overflow:auto;">
          <table class="table table-sm table-hover mb-0 table-sticky">
            <thead>
              <tr>
                <th class="nowrap">ID</th>
                <th>Name</th>
                <th>Ticker</th>
                <th>Alliance</th>
                <th>Members</th>
                <th>Affinity / Trust</th>
              </tr>
            </thead>
            <tbody>
            @foreach($corporations as $co)
              @php
                $m = $co['meta'] ?? [];
                $ally = $m['alliance'] ?? null; // {id,name,ticker}
                $aff  = $m['affinity'] ?? ['affinity_entity_id'=>null,'trust'=>null];
                $trust= $aff['trust'] ?? null;
              @endphp
              <tr>
                <td class="nowrap">{{ $co['id'] }}</td>
                <td>{{ $co['name'] }}</td>
                <td class="nowrap">{{ $m['ticker'] ?? '—' }}</td>
                <td>
                  @if($ally)
                    <div>{{ $ally['name'] }}</div>
                    <div class="small-muted">{{ $ally['ticker'] ?? '' }} · {{ $ally['id'] ?? '' }}</div>
                  @else
                    <span class="text-muted">—</span>
                  @endif
                </td>
                <td class="nowrap">{{ $m['member_count'] ?? '—' }}</td>
                <td>
                  @if($aff['affinity_entity_id'])
                    <div class="small">Entity: <strong>#{{ $aff['affinity_entity_id'] }}</strong></div>
                    @if($trust)
                      <span class="badge bg-info meta-badge">{{ $trust['classification'] ?? '—' }}</span>
                    @else
                      <span class="text-muted small">No trust set</span>
                    @endif
                  @else
                    <span class="text-muted small">Unmapped</span>
                  @endif
                </td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </div>

    {{-- Alliances --}}
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Alliances</strong>
        <span class="badge bg-secondary">{{ $alliances->count() }}</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 360px; overflow:auto;">
          <table class="table table-sm table-hover mb-0 table-sticky">
            <thead>
              <tr>
                <th class="nowrap">ID</th>
                <th>Name</th>
                <th>Ticker</th>
                <th>Affinity / Trust</th>
              </tr>
            </thead>
            <tbody>
            @foreach($alliances as $al)
              @php
                $m = $al['meta'] ?? [];
                $aff  = $m['affinity'] ?? ['affinity_entity_id'=>null,'trust'=>null];
                $trust= $aff['trust'] ?? null;
              @endphp
              <tr>
                <td class="nowrap">{{ $al['id'] }}</td>
                <td>{{ $al['name'] }}</td>
                <td class="nowrap">{{ $m['ticker'] ?? '—' }}</td>
                <td>
                  @if($aff['affinity_entity_id'])
                    <div class="small">Entity: <strong>#{{ $aff['affinity_entity_id'] }}</strong></div>
                    @if($trust)
                      <span class="badge bg-info meta-badge">{{ $trust['classification'] ?? '—' }}</span>
                    @else
                      <span class="text-muted small">No trust set</span>
                    @endif
                  @else
                    <span class="text-muted small">Unmapped</span>
                  @endif
                </td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </div>

    {{-- Dossier JSON (collapsible) --}}
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Raw Dossier JSON</strong>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle="collapse" data-target="#rawJson">
          Toggle
        </button>
      </div>
      <div class="collapse" id="rawJson">
        <div class="card-body">
          <pre id="rawJsonPre" class="small" style="max-height:360px;overflow:auto;">{{ json_encode($dossier, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
      </div>
    </div>
  </div>

  {{-- Edges / Timeline --}}
  <div class="card mb-4">
    <div class="card-header">
      <strong>Timeline / Edges</strong>
      <div class="small-muted">Filter by kind and search text.</div>
    </div>
    <div class="card-body">

      <div class="row g-2 align-items-end mb-3">
        <div class="col-md-3">
          <label class="form-label">Kind</label>
          <select class="form-control form-control-sm" id="edgeKind">
            <option value="">(All kinds)</option>
            @foreach($kinds as $k)
              <option value="{{ $k }}">{{ $k }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">From (UTC)</label>
          <input type="datetime-local" class="form-control form-control-sm" id="fromDate">
        </div>
        <div class="col-md-3">
          <label class="form-label">To (UTC)</label>
          <input type="datetime-local" class="form-control form-control-sm" id="toDate">
        </div>
        <div class="col-md-3">
          <label class="form-label">Search</label>
          <input type="text" class="form-control form-control-sm" id="edgeSearch" placeholder="entity name, id, etc.">
        </div>
      </div>

      <div class="table-responsive" style="max-height: 500px; overflow:auto;">
        <table class="table table-sm table-hover mb-0 table-sticky" id="edgesTable">
          <thead>
            <tr>
              <th class="nowrap">At</th>
              <th class="nowrap">Kind</th>
              <th>Source</th>
              <th>Destination</th>
              <th>Meta</th>
            </tr>
          </thead>
          <tbody>
          @foreach($edges as $e)
            @php
              $src = $e['src'] ?? ['type'=>'?','id'=>0,'name'=>'?'];
              $dst = $e['dst'] ?? ['type'=>'?','id'=>0,'name'=>'?'];
              $meta = $e['meta'] ?? [];
              $metaStr = $meta ? json_encode($meta) : '';
              $rowText = strtolower(($e['kind'] ?? '') . ' ' . ($src['name'] ?? '') . ' ' . ($dst['name'] ?? '') . ' ' . $metaStr);
            @endphp
            <tr data-kind="{{ $e['kind'] ?? '' }}"
                data-at="{{ $e['at'] ?? '' }}"
                data-text="{{ $rowText }}">
              <td class="nowrap">{{ $e['at'] ?? '—' }}</td>
              <td class="nowrap"><span class="badge bg-light text-dark">{{ $e['kind'] ?? '' }}</span></td>
              <td>
                <div class="fw-semibold">{{ $src['name'] }}</div>
                <div class="small-muted">{{ $src['type'] ?? '' }} · {{ $src['id'] ?? '' }}</div>
              </td>
              <td>
                <div class="fw-semibold">{{ $dst['name'] }}</div>
                <div class="small-muted">{{ $dst['type'] ?? '' }} · {{ $dst['id'] ?? '' }}</div>
              </td>
              <td class="small"><code>{{ $metaStr }}</code></td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>

    </div>
  </div>

</div>
@endsection

@push('javascript')
<script>
(function() {
  const $table = document.getElementById('edgesTable').getElementsByTagName('tbody')[0];
  const $kind  = document.getElementById('edgeKind');
  const $from  = document.getElementById('fromDate');
  const $to    = document.getElementById('toDate');
  const $q     = document.getElementById('edgeSearch');
  const $copy  = document.getElementById('copyJsonBtn');

  function visible(row) {
    const kind = $kind.value;
    const at   = row.dataset.at || '';
    const text = row.dataset.text || '';

    // kind filter
    if (kind && row.dataset.kind !== kind) return false;

    // date filter
    if ($from.value) {
      const a = at ? new Date(at) : null;
      if (!a || a < new Date($from.value)) return false;
    }
    if ($to.value) {
      const a = at ? new Date(at) : null;
      if (!a || a > new Date($to.value)) return false;
    }

    // text search
    const q = ($q.value || '').trim().toLowerCase();
    if (q && !text.includes(q)) return false;

    return true;
  }

  function applyFilters() {
    Array.from($table.rows).forEach(row => {
      row.style.display = visible(row) ? '' : 'none';
    });
  }

  [$kind, $from, $to, $q].forEach(el => el.addEventListener('input', applyFilters));

  // Copy JSON
  if ($copy) {
    $copy.addEventListener('click', function() {
      const pre = document.getElementById('rawJsonPre');
      if (!pre) return;
      const text = pre.innerText || pre.textContent || '';
      navigator.clipboard.writeText(text).then(() => {
        $copy.classList.remove('btn-primary');
        $copy.classList.add('btn-success');
        $copy.textContent = 'Copied!';
        setTimeout(() => {
          $copy.classList.remove('btn-success');
          $copy.classList.add('btn-primary');
          $copy.textContent = 'Copy JSON';
        }, 1200);
      });
    });
  }
})();
</script>
@endpush
