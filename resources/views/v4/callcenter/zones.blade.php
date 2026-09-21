@extends('v4.layouts.app')
@section('title', 'دليل المناطق')
@section('content')
<div class="card tight">
  <div class="row">
    <div style="flex:2;min-width:220px"><label>بحث</label><input id="z-q" type="search" placeholder="اسم المنطقة أو الفرع أو السعر…" autofocus></div>
    <div><label>الفرع</label><select id="z-branch"><option value="">كل الفروع</option></select></div>
    <div style="flex:0 0 auto;min-width:0"><span class="muted small" id="z-count"></span></div>
  </div>
</div>
<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>المنطقة</th><th>الفرع اللي بيوصّل</th><th>سعر التوصيل</th><th></th></tr></thead>
    <tbody id="z-body"><tr><td colspan="5" class="empty">جاري التحميل…</td></tr></tbody>
  </table></div>
  <div class="pager" id="z-pager"></div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('v4/js/callcenter/zones.js') }}?v={{ @filemtime(public_path('v4/js/callcenter/zones.js')) ?: 0 }}"></script>
@endpush
