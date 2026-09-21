@extends('v4.layouts.app')
@section('title', 'الرئيسية')
@section('content')
{{-- الأرقام بتترسم من السيرفر (CallcenterStats) — وhome.js بيحدّثها من /v4/callcenter/stats مع كل حدث بث --}}
<div class="stats" id="home-stats">
  <div class="stat blue"><div class="ic"><i class="fas fa-calendar-day"></i></div><div><div class="v num" data-stat="todayTotal">{{ number_format($stats['todayTotal']) }}</div><div class="l">طلبات يوم العمل</div></div></div>
  <div class="stat yellow"><div class="ic"><i class="fas fa-hourglass-half"></i></div><div><div class="v num" data-stat="pending">{{ number_format($stats['pending']) }}</div><div class="l">قيد التنفيذ</div></div></div>
  <div class="stat purple"><div class="ic"><i class="fas fa-motorcycle"></i></div><div><div class="v num" data-stat="delivering">{{ number_format($stats['delivering']) }}</div><div class="l">قيد التوصيل</div></div></div>
  <div class="stat green"><div class="ic"><i class="fas fa-circle-check"></i></div><div><div class="v num" data-stat="deliveredToday">{{ number_format($stats['deliveredToday']) }}</div><div class="l">اتسلّم النهاردة</div></div></div>
</div>
<div class="stats">
  <div class="stat red"><div class="ic"><i class="fas fa-rotate-left"></i></div><div><div class="v num" data-stat="undeliveredToday">{{ number_format($stats['undeliveredToday']) }}</div><div class="l">لم يتم التوصيل النهاردة</div></div></div>
  <div class="stat blue"><div class="ic"><i class="fas fa-people-group"></i></div><div><div class="v num"><span data-stat="pilotsWaiting">{{ $stats['pilotsWaiting'] }}</span> <span class="muted small">/ <span data-stat="pilotsTotal">{{ $stats['pilotsTotal'] }}</span></span></div><div class="l">طيارين في الانتظار / الإجمالي</div></div></div>
  <div class="stat purple"><div class="ic"><i class="fas fa-route"></i></div><div><div class="v num" data-stat="pilotsDelivering">{{ number_format($stats['pilotsDelivering']) }}</div><div class="l">طيارين بيوصّلوا دلوقتي</div></div></div>
  <div class="stat green"><div class="ic"><i class="fas fa-sack-dollar"></i></div><div><div class="v num" data-stat="revenueToday" data-money="1">{{ number_format($stats['revenueToday'], 2) }} ج.م</div><div class="l">تحصيل التوصيل النهاردة</div></div></div>
</div>

<div class="card">
  <div class="page-head" style="margin-bottom:.6rem">
    <h2 style="margin:0"><i class="fas fa-building"></i> الفروع — يوم العمل <span class="muted small ltr">{{ $stats['day'] }}</span></h2>
    <span class="muted small">يوم العمل من ٩ صباحًا لـ٩ صباحًا</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>الفرع</th><th>قيد التنفيذ</th><th>قيد التوصيل</th><th>اتسلّم النهاردة</th><th>إجمالي اليوم</th><th>تحصيل التوصيل</th><th>طيارين (انتظار / الكل)</th></tr></thead>
      <tbody id="home-branches">
        @forelse($branches as $i => $b)
          <tr>
            <td class="muted">{{ $i + 1 }}</td>
            <td class="bold">{{ $b['name'] }} @if($b['paused'])<span class="badge red">موقوف</span>@endif</td>
            <td><span class="badge {{ $b['pending'] ? 'yellow' : 'gray' }}">{{ $b['pending'] }}</span></td>
            <td><span class="badge {{ $b['delivering'] ? 'blue' : 'gray' }}">{{ $b['delivering'] }}</span></td>
            <td class="green bold">{{ $b['deliveredToday'] }}</td>
            <td>{{ $b['todayTotal'] }}</td>
            <td class="num">{{ number_format($b['revenueToday'], 2) }}</td>
            <td>{{ $b['pilotsWaiting'] }} / {{ $b['pilots'] }}</td>
          </tr>
        @empty
          <tr><td colspan="8" class="empty">مفيش فروع</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="page-head" style="margin-bottom:.6rem">
    <h2 style="margin:0"><i class="fas fa-bolt"></i> آخر الطلبات</h2>
    <a class="btn sm" href="{{ route('v4.callcenter.orders', ['list' => 'active']) }}">كل النشطة</a>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>رقم الطلب</th><th>الفرع</th><th>استلام</th><th>تسليم / المنطقة</th><th>التوصيل</th><th>الحالة</th><th>الطيار</th><th>الوقت</th></tr></thead>
    <tbody id="home-latest"><tr><td colspan="8" class="empty">جاري التحميل…</td></tr></tbody>
  </table></div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('v4/js/callcenter/home.js') }}?v={{ @filemtime(public_path('v4/js/callcenter/home.js')) ?: 0 }}"></script>
@endpush
