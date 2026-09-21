@extends('v4.layouts.app')
@section('title', 'الطيارين')
@section('content')
<div class="stats" id="pl-stats">
  <div class="stat green"><div class="ic"><i class="fas fa-hourglass-half"></i></div><div><div class="v num" id="pl-waiting">—</div><div class="l">في الانتظار</div></div></div>
  <div class="stat blue"><div class="ic"><i class="fas fa-motorcycle"></i></div><div><div class="v num" id="pl-delivering">—</div><div class="l">في التوصيل</div></div></div>
  <div class="stat yellow"><div class="ic"><i class="fas fa-mug-hot"></i></div><div><div class="v num" id="pl-leave">—</div><div class="l">في إذن / راحة</div></div></div>
  <div class="stat purple"><div class="ic"><i class="fas fa-power-off"></i></div><div><div class="v num" id="pl-off">—</div><div class="l">خارج الوردية</div></div></div>
</div>
<div class="card tight">
  <div class="row">
    <div style="flex:2;min-width:200px"><label>بحث</label><input id="pl-q" type="search" placeholder="اسم الطيار…"></div>
    <div><label>الفرع</label><select id="pl-branch"><option value="">كل الفروع</option></select></div>
    <div><label>الحالة</label><select id="pl-status"><option value="">الكل</option><option value="waiting">في الانتظار</option><option value="delivering">في التوصيل</option><option value="on_leave">في إذن</option><option value="off">خارج الوردية</option></select></div>
  </div>
</div>
<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>الطيار</th><th>الفرع</th><th>الحالة</th><th>أوردرات معاه</th><th>الدور</th><th>آخر موقع</th></tr></thead>
    <tbody id="pl-body"><tr><td colspan="7" class="empty">جاري التحميل…</td></tr></tbody>
  </table></div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('v4/js/callcenter/pilots.js') }}?v={{ @filemtime(public_path('v4/js/callcenter/pilots.js')) ?: 0 }}"></script>
@endpush
