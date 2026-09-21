@extends('v4.layouts.app')
@section('title', $pageDef['label'])
@section('contentClass', 'embed')
@section('content')
{{-- الشاشة دي بكود التطبيق القديم نفسه (قرار صاحب النظام 2026-09-21: «أمور لا أريد تغييرها») — الغلاف بس هو الجديد --}}
<iframe id="v4-embed" title="{{ $pageDef['label'] }}" data-page="{{ $embedPage }}" data-new="{{ $embedNew ? '1' : '' }}"
        data-src="{{ url($v4Def['legacy']) }}" data-role="{{ \App\Support\Vocab::ROLE_AR[$actor->role] ?? $actor->role }}"></iframe>
@endsection
@push('scripts')
<script src="{{ asset('v4/js/callcenter/embed.js') }}?v={{ @filemtime(public_path('v4/js/callcenter/embed.js')) ?: 0 }}"></script>
@endpush
