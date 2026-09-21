@extends('v4.layouts.app')
@section('title', 'العملاء')
@section('content')
<div class="tabs" id="c-tabs">
  <button type="button" class="active" data-type="senders"><i class="fas fa-store"></i> عملاء الاستلام</button>
  <button type="button" data-type="receivers"><i class="fas fa-user"></i> عملاء التسليم</button>
</div>
<div class="card tight">
  <label>بحث بالاسم أو رقم الهاتف</label>
  <input id="c-q" type="search" placeholder="اكتب حرفين على الأقل…" autofocus>
</div>
<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>الاسم</th><th>الهاتف</th><th>هاتف 2</th><th>العنوان</th><th>آخر منطقة</th><th></th></tr></thead>
    <tbody id="c-body"><tr><td colspan="6" class="empty">اكتب في خانة البحث — الدفتر كبير فالبحث بيتم في السيرفر.</td></tr></tbody>
  </table></div>
</div>
@endsection
@push('scripts')
<script>window.V4_SEARCH_URL = {!! json_encode(route('v4.callcenter.search'), JSON_UNESCAPED_SLASHES) !!};</script>
<script src="{{ asset('v4/js/callcenter/clients.js') }}?v={{ @filemtime(public_path('v4/js/callcenter/clients.js')) ?: 0 }}"></script>
@endpush
