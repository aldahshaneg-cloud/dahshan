@extends('v4.layouts.app')
@section('title', $pageDef['label'])
@section('content')
<div class="card" style="text-align:center;padding:3rem 1.5rem">
  <div style="font-size:2.6rem;color:var(--muted);margin-bottom:.6rem"><i class="fas {{ $pageDef['icon'] }}"></i></div>
  <h2>«{{ $pageDef['label'] }}» لسه بتتنقل للإصدار الجديد</h2>
  <p class="muted" style="max-width:520px;margin:.4rem auto 1.4rem">الشاشة دي شغالة زي ما هي في التطبيق القديم — افتحها من هناك لحد ما تتنقل. دخولك واحد في الاتنين.</p>
  <a class="btn primary" href="{{ url($v4Def['legacy']) }}"><i class="fas fa-up-right-from-square"></i> افتحها في التطبيق القديم</a>
</div>
@endsection
