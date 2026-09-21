<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="app-base" content="{{ url('/') }}">
<meta name="v4-user" content="{{ json_encode(['id' => $actor->userId, 'username' => $actor->username, 'name' => $actor->name, 'role' => $actor->role, 'branchId' => $actor->branchId], JSON_UNESCAPED_UNICODE) }}">
<title>@yield('title', $pageDef['label'] ?? '') — الدهشان · {{ $v4Def['label'] }}</title>
<link rel="icon" href="{{ asset('favicon.ico') }}">
{{-- قبل الـCSS وقبل أول رسم: الوضع الداكن وحجم الخط المحفوظين على الجهاز — من غير وميض أبيض --}}
<script>(function(){try{var t=localStorage.getItem('dahshan.v4.theme'),f=localStorage.getItem('dahshan.v4.font'),h=document.documentElement;if(t==='dark')h.setAttribute('data-theme','dark');if(f&&f!=='m')h.setAttribute('data-font',f);}catch(e){}})();</script>
<link rel="stylesheet" href="{{ asset('v4/css/cairo.css') }}">
<link rel="stylesheet" href="{{ asset('v4/css/fontawesome.css') }}">
<link rel="stylesheet" href="{{ asset('v4/css/app.css') }}?v={{ @filemtime(public_path('v4/css/app.css')) ?: 0 }}">
@stack('head')
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand"><i class="fas {{ $v4Def['icon'] }}"></i><span>الدهشان<small>{{ $v4Def['label'] }} · الإصدار الجديد</small></span></div>
    <nav>
      @foreach($v4Def['groups'] as $gKey => $gLabel)
        @php $items = collect($v4Def['pages'])->filter(fn ($pg) => ($pg['group'] ?? 'main') === $gKey); @endphp
        @if($items->isNotEmpty())
          @if($gLabel)<div class="nav-group">{{ $gLabel }}</div>@endif
          @foreach($items as $key => $pg)
            @php
              $isList = isset(config('v4.order_lists')[$key]);
              $href = ! $pg['ready'] ? route("v4.$v4App.soon", ['page' => $key])
                    : ($isList ? route("v4.$v4App.orders", ['list' => $key]) : route("v4.$v4App.$key"));
            @endphp
            <a href="{{ $href }}" class="{{ $pageKey === $key ? 'active' : '' }}" data-nav="{{ $key }}">
              <i class="fas {{ $pg['icon'] }}"></i> {{ $pg['label'] }}
              @if(! $pg['ready'])<span class="badge gray" style="margin-inline-start:auto;font-size:.65rem">قريبًا</span>
              @elseif(($navCounts[$key] ?? 0) > 0)<span class="cnt" data-nav-count="{{ $key }}">{{ $navCounts[$key] }}</span>
              @elseif(array_key_exists($key, $navCounts))<span class="cnt" data-nav-count="{{ $key }}" hidden>0</span>
              @endif
            </a>
          @endforeach
        @endif
      @endforeach
    </nav>
    <a class="old-link" href="{{ url($v4Def['legacy']) }}"><i class="fas fa-clock-rotate-left"></i> التطبيق القديم</a>
    <div class="user">
      <span class="me">{{ $actor->name !== '' ? $actor->name : $actor->username }}</span>
      <span>{{ \App\Support\Vocab::ROLE_AR[$actor->role] ?? $actor->role }}</span>
      <div class="ux-row">
        <button type="button" class="btn sm ghost" data-ux-theme-toggle title="الوضع الداكن / الفاتح"><i class="fas fa-moon"></i></button>
        <button type="button" class="btn sm ghost" data-ux-font-cycle title="حجم الخط"><i class="fas fa-text-height"></i></button>
        <button type="button" class="btn sm ghost" data-v4-logout><i class="fas fa-right-from-bracket"></i> خروج</button>
      </div>
    </div>
  </aside>
  <div class="side-backdrop"></div>

  <div class="main">
    <div class="topbar">
      <button class="burger" type="button" aria-label="القائمة"><i class="fas fa-bars"></i></button>
      <h1>@yield('title', $pageDef['label'] ?? '')</h1>
      <div class="top-tools no-print">
        <span class="chip off" id="v4-live" hidden></span>
        @if($pageKey !== 'new')
          <a class="btn primary sm" href="{{ route("v4.$v4App.new") }}"><i class="fas fa-circle-plus"></i> <span class="t">طلب جديد</span></a>
        @endif
        <div class="small muted top-date">{{ now('Africa/Cairo')->locale('ar')->translatedFormat('l j F') }}</div>
      </div>
    </div>
    <div class="content">
      @yield('content')
    </div>
  </div>
</div>

{{-- تفاصيل الأوردر — نافذة واحدة مشتركة لكل الشاشات (orders-common.js) --}}
<div class="modal" id="m-order"><div class="box wide"><div class="head"><h2 id="m-order-title">تفاصيل الطلب</h2><button class="close" data-close="m-order" aria-label="إغلاق">✕</button></div><div id="m-order-body"></div></div></div>

<script src="{{ asset('assets/js/vendor/pusher.min.js') }}?v=20260908"></script>
<script src="{{ asset('assets/js/realtime.js') }}?v={{ @filemtime(public_path('assets/js/realtime.js')) ?: 0 }}"></script>
<script src="{{ asset('v4/js/core.js') }}?v={{ @filemtime(public_path('v4/js/core.js')) ?: 0 }}"></script>
<script src="{{ asset('v4/js/orders-common.js') }}?v={{ @filemtime(public_path('v4/js/orders-common.js')) ?: 0 }}"></script>
@stack('scripts')
</body>
</html>
