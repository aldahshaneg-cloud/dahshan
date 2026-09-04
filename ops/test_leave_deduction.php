<?php

/**
 * ⏱ حارس: ساعة الإذن بتتخصم من أجر الطيار **مرة واحدة بس**.
 *
 * ═══ الباج اللي اتلقى (2026-09-01 مساءً) ═══
 * سؤال صاحب النظام «الإذن بيخصم من وقت الوردية؟» كشف خصمًا مزدوجًا:
 * autoMatrix كانت بتخصم الاستئذان من الساعات، وبعدها dayRow بتخصمه
 * تاني — وردية ٦ ساعات وإذن ساعة كانت بتطلع ٤ بدل ٥. الخصم بقى في
 * dayRow بس. الحارس بيزرع وردية وإذن حقيقيين وبيقرا الرقم من نقطة
 * النهاية نفسها.
 *
 * التشغيل: php ops/test_leave_deduction.php
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
use Illuminate\Support\Facades\DB;

$admin = DB::select("SELECT id, username, role, branch_id FROM users WHERE role='admin' LIMIT 1")[0];
$sup = DB::select("SELECT id, username, branch_id FROM users WHERE role='branch' AND branch_id IS NOT NULL LIMIT 1")[0];
$pilot = DB::select('SELECT id, name FROM pilots WHERE archived_at IS NULL AND assigned_branch_id = ? LIMIT 1', [$sup->branch_id])[0];

DB::beginTransaction();
try {
    /* وردية ٦ ساعات النهارده (بيوم الطيارين التجاري) + إذن ساعة جواها */
    $in  = gmdate('Y-m-d H:i:s', strtotime('today 08:00 UTC'));   // 11ص قاهرة
    $out = gmdate('Y-m-d H:i:s', strtotime('today 14:00 UTC'));   // 5م قاهرة
    DB::insert('INSERT INTO shifts (pilot_id, branch_id, status, started_at, ended_at, created_at)
                VALUES (?,?,?,?,?,NOW())', [$pilot->id, $sup->branch_id, 'ended', $in, $out]);
    DB::insert("INSERT INTO pilot_leave_requests (pilot_id, branch_id, type, status, requested_at, responded_at, responded_by, ended_at, created_at)
                VALUES (?,?,?,'ended',?,?,?,?,NOW())",
        [$pilot->id, $sup->branch_id, 'rest',
         gmdate('Y-m-d H:i:s', strtotime('today 10:00 UTC')),
         gmdate('Y-m-d H:i:s', strtotime('today 10:00 UTC')), 'اختبار',
         gmdate('Y-m-d H:i:s', strtotime('today 11:00 UTC'))]);

    $req = Illuminate\Http\Request::create('/api/pilot-accounting/month?month=' . date('Y-m'), 'GET');
    $req->headers->set('Accept', 'application/json');
    $s = app('session')->driver(); $s->start();
    $s->put(['user_id'=>(int)$admin->id,'username'=>$admin->username,'role'=>'admin','branch_id'=>null,'name'=>$admin->username]);
    $req->setLaravelSession($s);
    $j = json_decode($kernel->handle($req)->getContent(), true);

    /* خانة اليوم في المصفوفة = **اليوم التجاري** للوردية المزروعة، مش
       اليوم الميلادي الحالي — الفرق بيبان بعد نص الليل (الساعة 00:30
       قاهرة يوم 3، الوردية المزروعة 11ص يوم 2 بتقع في خانة يوم 2). */
    $today = (int) substr(App\Support\BizDay::key(strtotime('today 08:00 UTC')), 8, 2);
    $found = false;
    foreach ($j['pilots'] as $p) {
        if ($p['pilotId'] == $pilot->id) {
            $found = true;
            $d = $p['days'][$today - 1];
            echo "الطيار: {$pilot->name} — يوم {$today}\n";
            echo "حضور {$d['in']} → انصراف {$d['out']} · استئذان: "
                . json_encode($d['perms'], JSON_UNESCAPED_UNICODE) . "\n";
            /* 🔴 الفحص الحاسم: 5 بالظبط.
               4 = الخصم المزدوج رجع (autoMatrix بتخصم وdayRow بتخصم تاني).
               6 = الخصم اتشال خالص من dayRow. الاتنين بيدخلوا أجر غلط. */
            $ok = abs((float) $d['hours'] - 5.0) < 0.01
                && count($d['perms']) === 1
                && $d['perms'][0]['out'] === '13:00' && $d['perms'][0]['in'] === '14:00';
            echo ($ok ? '  ✓' : '  ✗') . " وردية 6 س وإذن ساعة → {$d['hours']} س (المفروض 5)\n";
            DB::rollBack();
            echo $ok ? "✅ عدّى — الإذن بيتخصم مرة واحدة بس\n" : "🔴 وقع\n";
            exit($ok ? 0 : 1);
        }
    }
    DB::rollBack();
    echo $found ? '' : "🔴 الطيار مش في الرد\n";
    exit(1);
} catch (Throwable $e) { DB::rollBack(); echo '🔴 ' . $e->getMessage() . "\n"; exit(1); }
