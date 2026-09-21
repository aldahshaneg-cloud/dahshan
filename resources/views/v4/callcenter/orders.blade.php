@extends('v4.layouts.app')
@section('title', $listDef['title'])
@section('content')
<div class="tabs">
  @foreach(config('v4.order_lists') as $k => $d)
    <a href="{{ route('v4.callcenter.orders', ['list' => $k]) }}" class="{{ $k === $list ? 'active' : '' }}">{{ $d['title'] }}</a>
  @endforeach
</div>

<div class="card tight">
  <form class="row" id="f-filter" autocomplete="off" onsubmit="return false">
    <div style="flex:2;min-width:220px"><label>بحث</label><input id="flt-q" type="search" placeholder="رقم الطلب · اسم أو تليفون المرسل / المستلم"></div>
    <div><label>الفرع</label><select id="flt-branch"><option value="">كل الفروع</option></select></div>
    <div><label>من <span class="muted" title="يوم العمل من ٩ صباحًا لـ٩ صباحًا اليوم التالي">(يوم العمل)</span></label><input id="flt-from" type="date"></div>
    <div><label>إلى</label><input id="flt-to" type="date"></div>
    <div style="flex:0 0 auto;min-width:0"><button type="button" class="btn" id="flt-today">النهاردة</button></div>
    <div style="flex:0 0 auto;min-width:0"><button type="button" class="btn ghost" id="flt-clear"><i class="fas fa-eraser"></i> مسح</button></div>
  </form>
</div>

<div class="card">
  <div class="page-head" style="margin-bottom:.6rem">
    <div><span class="bold" id="ol-total">—</span> <span class="muted small" id="ol-sum"></span></div>
    <div class="muted small" id="ol-updated"></div>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>رقم الطلب</th><th>الفرع</th><th>استلام</th><th>تسليم / المنطقة</th><th>التوصيل</th><th>عهدة</th><th>الحالة</th><th>الطيار</th><th>الوقت</th></tr></thead>
    <tbody id="ol-body"><tr><td colspan="9" class="empty">جاري التحميل…</td></tr></tbody>
  </table></div>
  <div class="pager" id="ol-pager"></div>
</div>
@endsection
@push('scripts')
<script>window.V4_LIST = {!! json_encode(['list' => $list, 'url' => route('v4.callcenter.ordersData')], JSON_UNESCAPED_SLASHES) !!};</script>
<script src="{{ asset('v4/js/callcenter/orders.js') }}?v={{ @filemtime(public_path('v4/js/callcenter/orders.js')) ?: 0 }}"></script>
@endpush
