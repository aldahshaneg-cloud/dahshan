@extends('v4.layouts.app')
@section('title', 'طلب شحن جديد')
@push('head')
<link rel="stylesheet" href="{{ asset('v4/css/new-order.css') }}?v={{ @filemtime(public_path('v4/css/new-order.css')) ?: 0 }}">
@endpush
@section('content')
<form id="f-order" autocomplete="off" onsubmit="return false">
  {{-- ① جهة الاستلام --}}
  <div class="card">
    <h2><i class="fas fa-store"></i> جهة الاستلام</h2>
    <div class="grid c2">
      <div class="field dd-wrap">
        <label>عميل الاستلام <span class="req">*</span></label>
        <input id="s-search" placeholder="اكتب الاسم أو رقم الهاتف…" autofocus>
        <div class="dd-list" id="s-dd" hidden></div>
        <div class="small muted" id="s-hint" style="margin-top:.3rem">اكتب حرفين على الأقل — أو سجّل عميل جديد من الزرار.</div>
      </div>
      <div class="field dd-wrap">
        <label>منطقة الاستلام <span class="req">*</span> <span class="muted">— منها بيتحدّد الفرع</span></label>
        <input id="s-zone" placeholder="ابحث باسم المنطقة…">
        <div class="dd-list" id="s-zone-dd" hidden></div>
        <div class="small" id="s-branch" style="margin-top:.3rem"><span class="muted">💡 اختار منطقة الاستلام والفرع هيتحدّد لوحده</span></div>
      </div>
    </div>
    <div class="row">
      <div><label>الهاتف</label><input id="s-phone" class="ltr" readonly></div>
      <div><label>هاتف 2</label><input id="s-phone2" class="ltr" readonly></div>
      <div style="flex:2"><label>العنوان</label><input id="s-addr" readonly></div>
      <div style="flex:0 0 auto;min-width:0"><button type="button" class="btn" id="s-new"><i class="fas fa-user-plus"></i> عميل جديد</button></div>
      <div style="flex:0 0 auto;min-width:0"><button type="button" class="btn ghost" id="s-clear" hidden><i class="fas fa-xmark"></i> تغيير</button></div>
    </div>
  </div>

  {{-- ② الطرود --}}
  <div class="card">
    <div class="page-head" style="margin-bottom:.8rem">
      <h2 style="margin:0"><i class="fas fa-box"></i> الطرود / الوجهات <span class="muted small">— كل طرد بيتسجّل أوردر مستقل برقمه</span></h2>
      <button type="button" class="btn green" id="p-add"><i class="fas fa-plus"></i> إضافة وجهة</button>
    </div>
    <div id="parcels"></div>
  </div>

  {{-- ③ الإجمالي والحفظ --}}
  <div class="card">
    <div class="grid c3">
      <div class="field"><label>ملاحظات الطلب</label><textarea id="o-notes" rows="2"></textarea></div>
      <div class="field"><label>ملاحظة العهدة (للمحل)</label><textarea id="o-prepaid-note" rows="2"></textarea></div>
      <div class="field"><label>قيمة البضاعة (اختياري)</label><input id="o-goods" type="number" min="0" step="0.01" class="ltr"></div>
    </div>
    <div class="totals-bar">
      <div><span class="muted">عدد الطرود</span> <b id="t-count">0</b></div>
      <div><span class="muted">إجمالي التوصيل</span> <b class="green" id="t-delivery">0 ج.م</b></div>
      <div><span class="muted">إجمالي العهدة</span> <b class="yellow" id="t-prepaid">0 ج.م</b></div>
      <button type="button" class="btn primary lg" id="o-save"><i class="fas fa-floppy-disk"></i> حفظ الطلب</button>
    </div>
  </div>
</form>

{{-- فورم تسجيل عميل جديد — عائم ومستقل فوق فورم الأوردر، ومابيتقفلش بالضغط برّه (طلب صاحب النظام 2026-09-20) --}}
<div class="modal top" id="m-contact"><div class="box">
  <div class="head"><h2 id="mc-title">تسجيل عميل جديد</h2><button class="close" data-close="m-contact" aria-label="إغلاق">✕</button></div>
  <div class="field"><label>الاسم <span class="req">*</span></label><input id="mc-name" autofocus></div>
  <div class="row"><div><label>رقم الهاتف 1 <span class="req">*</span></label><input id="mc-phone" class="ltr" inputmode="tel"></div><div><label>رقم الهاتف 2</label><input id="mc-phone2" class="ltr" inputmode="tel"></div></div>
  <div class="field" style="margin-top:.8rem"><label>العنوان <span class="req">*</span></label><textarea id="mc-addr" rows="2"></textarea></div>
  <div class="foot"><button type="button" class="btn green" id="mc-save"><i class="fas fa-check"></i> حفظ العميل</button><button type="button" class="btn" data-close="m-contact">إلغاء</button></div>
</div></div>

{{-- إجبار إرسال رسالة الواتساب للمستلم بعد التسجيل (قرار صاحب النظام 2026-09-01) --}}
<div class="modal top" id="m-wa" data-locked><div class="box"><div class="head"><h2><i class="fab fa-whatsapp green"></i> رسالة الواتساب للمستلم</h2></div><div id="m-wa-body"></div></div></div>

<template id="tpl-parcel">
  <div class="parcel" data-parcel>
    <div class="parcel-head"><b><i class="fas fa-box-open"></i> طرد <span data-no></span></b><button type="button" class="btn sm ghost red" data-remove><i class="fas fa-trash"></i> حذف</button></div>
    <div class="grid c3">
      <div class="field dd-wrap"><label>اسم المستلم <span class="req">*</span></label><input data-f="name" placeholder="اسم أو رقم — هيظهر لو متسجّل"><div class="dd-list" data-dd="recv" hidden></div></div>
      <div class="field"><label>الهاتف</label><input data-f="phone" class="ltr" inputmode="tel"></div>
      <div class="field"><label>هاتف 2</label><input data-f="phone2" class="ltr" inputmode="tel"></div>
      <div class="field dd-wrap"><label>منطقة التسليم <span class="req">*</span></label><input data-f="zone" placeholder="اختار منطقة الاستلام الأول" disabled><div class="dd-list" data-dd="zone" hidden></div></div>
      <div class="field" style="grid-column:span 2"><label>العنوان بالتفصيل</label><input data-f="addr"></div>
      <div class="field"><label>سعر التوصيل</label><input data-f="price" class="ltr" readonly value="0"></div>
      <div class="field"><label>عهدة الطرد <span class="muted">(يدفعها الطيار للمحل)</span></label><input data-f="prepaid" type="number" min="0" step="0.01" class="ltr" value="0"></div>
      <div class="field"><label>لوكيشن (اختياري)</label><input data-f="loc" class="ltr" placeholder="31.0187, 31.2283 أو لينك خرائط جوجل"></div>
      <div class="field" style="grid-column:1/-1"><label>ملاحظة الطرد</label><input data-f="note"></div>
    </div>
  </div>
</template>
@endsection
@push('scripts')
<script src="{{ asset('assets/js/geoloc.js') }}?v=20260903"></script>
<script src="{{ asset('v4/js/wa-notify.js') }}?v={{ @filemtime(public_path('v4/js/wa-notify.js')) ?: 0 }}"></script>
<script src="{{ asset('v4/js/callcenter/new-order.js') }}?v={{ @filemtime(public_path('v4/js/callcenter/new-order.js')) ?: 0 }}"></script>
@endpush
