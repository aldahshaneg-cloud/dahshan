<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="app-base" content="{{ url('/') }}">
<title>تسجيل الدخول — الدهشان</title>
<script>(function(){try{if(localStorage.getItem('dahshan.v4.theme')==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<link rel="stylesheet" href="{{ asset('v4/css/cairo.css') }}">
<link rel="stylesheet" href="{{ asset('v4/css/fontawesome.css') }}">
<link rel="stylesheet" href="{{ asset('v4/css/app.css') }}?v={{ @filemtime(public_path('v4/css/app.css')) ?: 0 }}">
</head>
<body>
<div class="login-wrap">
  <form class="login-card" id="f-login" autocomplete="on">
    <div class="logo"><i class="fas fa-motorcycle"></i><h1>الدهشان</h1><div class="muted small">الإصدار الجديد</div></div>
    @if(!empty($noApp))<div class="alert warn">حسابك ({{ $noApp }}) لسه مالوش شاشة في الإصدار الجديد — <a href="{{ url('home.html') }}">افتح التطبيق القديم</a></div>@endif
    <div class="alert err" id="login-err" hidden></div>
    <div class="field"><label for="u">اسم المستخدم</label><input id="u" name="username" autocomplete="username" required autofocus></div>
    <div class="field"><label for="p">كلمة المرور</label><input id="p" name="password" type="password" autocomplete="current-password" required></div>
    <button class="btn primary block lg" id="login-btn" type="submit"><i class="fas fa-right-to-bracket"></i> دخول</button>
    <p class="muted small" style="text-align:center;margin:1rem 0 0">نفس حسابك في النظام — الدخول هنا بيدخّلك على التطبيق القديم كمان.</p>
  </form>
</div>
<script>
(function () {
  var base = document.querySelector('meta[name="app-base"]').content.replace(/\/$/, '');
  var next = {!! json_encode($next) !!};
  var f = document.getElementById('f-login'), err = document.getElementById('login-err'), btn = document.getElementById('login-btn');
  f.addEventListener('submit', function (e) {
    e.preventDefault(); err.hidden = true; btn.disabled = true;
    /* الدخول على مسار النظام نفسه — نفس حدّ المحاولات والحظر، والجلسة واحدة للقديم والجديد */
    fetch(base + '/api/login', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ username: f.username.value.trim(), password: f.password.value }) })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok && j.ok !== false, j: j }; }); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.j.error || 'تعذّر الدخول');
        /* السيرفر هو اللي بيقرر الصفحة الرئيسية حسب الدور (AuthController::homeFor) */
        window.location.href = next ? base + next : base + '/v4/login';
      })
      .catch(function (e2) { err.textContent = e2.message; err.hidden = false; btn.disabled = false; });
  });
})();
</script>
</body>
</html>
