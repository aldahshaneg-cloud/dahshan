<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| ── الجدولة ──────────────────────────────────────────────────────────────
| المهام اللي كانت في `aldahshan/cron/` على Task Scheduler بتتجدول هنا.
| على الـVPS بيتشغّل عامل واحد (`php artisan schedule:work`) بدل مهمة
| ويندوز لكل سكربت.
*/

/* إقفال جلسات الحضور المقطوعة — كل 5 دقايق، نفس دورية الكرون القديم.
   الدورية دي هي **حد دقة** وقت الانصراف المسجّل: الجلسة ما بتتقفلش قبل
   مرور المهلة (15 د افتراضيًا) + لحد 5 دقايق زيادة لحد ما التشغيلة الجاية
   تلاقيها. مايفرقش في الحساب لأن `check_out` بياخد وقت آخر نبضة مش وقت
   التشغيل — التأخير بيأثر على «امتى بيتسجّل» مش «بكام بيتسجّل». */
Schedule::command('attendance:autoclose')->everyFiveMinutes();
