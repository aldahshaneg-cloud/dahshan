<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>غير مسموح — الدهشان</title>
<link rel="stylesheet" href="{{ asset('v4/css/cairo.css') }}">
<link rel="stylesheet" href="{{ asset('v4/css/fontawesome.css') }}">
<link rel="stylesheet" href="{{ asset('v4/css/app.css') }}">
</head>
<body>
<div class="login-wrap">
  <div class="login-card" style="text-align:center">
    <div class="logo"><i class="fas fa-lock"></i><h1>الشاشة دي مش من صلاحيتك</h1></div>
    <p class="muted">«{{ $app }}» متاح لأدوار معيّنة بس، وحسابك ({{ $actor->username }}) دوره «{{ \App\Support\Vocab::ROLE_AR[$actor->role] ?? $actor->role }}».</p>
    <a class="btn primary block" href="{{ url('home.html') }}"><i class="fas fa-house"></i> بوابة النظام</a>
  </div>
</div>
</body>
</html>
