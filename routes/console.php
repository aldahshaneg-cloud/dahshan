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

/* أقل إصدار لتطبيق الطيار بعد النشر (طلب صاحب النظام 2026-09-09: «خلي 2.5.8 أقل إصدار مسموح
   بعد ما أنشر»): السكربت ساكت لحد ما `public/downloads/dahshan-pilot-latest.apk` يشاور على
   2.5.8، وبعدها بيرفع minVersion/latestVersion مرة واحدة (شوف ops/pilot_app_min_version.php).
   هنا مش في crontab عشان يبقى في الكود مع باقي الجدولة. يتشال بعد ما يتطبّق (مش ضروري — بيخرج فورًا). */
Schedule::exec('php ' . base_path('ops/pilot_app_min_version.php') . ' 2.5.8 --auto')
    ->everyFiveMinutes()
    ->appendOutputTo(storage_path('logs/minver.log'));
