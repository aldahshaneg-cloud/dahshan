# ═══════════════════════════════════════════════════════════════════════════
# parity.ps1 — بوابة القبول للترحيل
#
# بيشغّل النظامين جنب بعض على نفس قاعدة البيانات وبيقارن الرد **حرف بحرف**:
#     النظام الحالي (PHP اليدوي)  ←→  لارافل
#
# القاعدة الحاكمة في الترحيل: **صفر تغيير في العقد**. أي مسار اتنقل لازم
# يرجّع نفس الـJSON بنفس المفاتيح وبنفس الترتيب وبنفس الترميز. الفاحص ده
# هو الدليل — مش القراءة ولا الثقة.
#
# التشغيل: powershell -ExecutionPolicy Bypass -File tests\parity.ps1
# ═══════════════════════════════════════════════════════════════════════════

$ErrorActionPreference = 'Continue'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$NewRoot = Split-Path $PSScriptRoot -Parent            # مشروع لارافل
$OldRoot = Join-Path (Split-Path $NewRoot -Parent) 'aldahshan'
$Php     = 'C:\xampp\php\php.exe'
$OldPort = 8301
$NewPort = 8302
$OldBase = "http://127.0.0.1:$OldPort"
$NewBase = "http://127.0.0.1:$NewPort"

if (-not (Test-Path (Join-Path $OldRoot 'public\index.php'))) {
    Write-Host "[FAIL] مالقيتش النظام الحالي في $OldRoot"; exit 1
}

# بيانات الدخول — من نفس ملف النظام الحالي (مستثنى من git)
$creds = Join-Path $OldRoot 'tests\creds.local.ps1'
if (Test-Path $creds) { . $creds }
$U = if ($env:ALDAHSHAN_ADMIN_USER) { $env:ALDAHSHAN_ADMIN_USER } else { 'admin' }
$P = $env:ALDAHSHAN_ADMIN_PASS
if (-not $P) { Write-Host '[FAIL] مفيش كلمة مرور اختبار — اعمل aldahshan\tests\creds.local.ps1'; exit 1 }

$Tmp = Join-Path $env:TEMP ('parity_' + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Path $Tmp -Force | Out-Null
$utf8 = New-Object System.Text.UTF8Encoding($false)
$body = Join-Path $Tmp 'login.json'
[System.IO.File]::WriteAllText($body, (@{ username = $U; password = $P } | ConvertTo-Json -Compress), $utf8)
$jarOld = Join-Path $Tmp 'old.txt'
$jarNew = Join-Path $Tmp 'new.txt'

$pass = 0; $fail = 0; $failed = @()

<#
 القيم المتغيّرة بطبيعتها (وقت السيرفر، التوكنات، طوابع الوقت اللحظية)
 بتتصفّر قبل المقارنة — الفرق فيها مش انحدار.
#>
function Normalize([string]$s) {
    if ($null -eq $s) { return '' }
    $s = $s -replace '"serverNow":\s*\d+', '"serverNow":0'
    $s = $s -replace '"token":\s*"[0-9a-f]{64}"', '"token":"X"'
    return $s
}

function Compare-Route([string]$label, [string]$method, [string]$path, [switch]$Auth) {
    $jo = if ($Auth) { @('-b', $jarOld) } else { @() }
    $jn = if ($Auth) { @('-b', $jarNew) } else { @() }
    $m  = if ($method -eq 'GET') { @() } else { @('-X', $method) }

    $o = & curl.exe -s @m @jo "$OldBase$path"
    $n = & curl.exe -s @m @jn "$NewBase$path"

    if ((Normalize $o) -eq (Normalize $n)) {
        $script:pass++
        Write-Host "  [مطابق]  $method $path" -ForegroundColor DarkGreen
    } else {
        $script:fail++
        $script:failed += "$method $path"
        Write-Host "  [مختلف]  $method $path" -ForegroundColor Red
        Write-Host "     قديم : $o"
        Write-Host "     جديد : $n"
    }
}

<#
 الفاحص ده بينده /api/lookup على النظامين، وكل نداء بيتسجّل في lookup_log
 وبيتحسب في حد الـ60/ساعة بتاع منظومة الثقة. من غير تنظيف، تشغيل الفاحص
 قبل tests\e2e_trust.ps1 في aldahshan بيخلّي فحص الـrate-limit بتاعه يفشل
 (اتلاحظ فعليًا: 116/117 بدل 117/117). بنسجّل آخر id قبل الشغل ونمسح
 اللي زاد بعده — تنظيف دقيق مش مسح للجدول.
#>
$Mysql = 'C:\xampp\mysql\bin\mysql.exe'
$lookupMaxBefore = (& $Mysql -u root -N -e "SELECT IFNULL(MAX(id),0) FROM aldahshan.lookup_log;") 2>$null

# ── تشغيل السيرفرين ─────────────────────────────────────────────
Write-Host 'بشغّل النظامين...' -ForegroundColor Cyan
$oldSrv = Start-Process -FilePath $Php `
    -ArgumentList '-S', "127.0.0.1:$OldPort", '-t', "$OldRoot\public", "$OldRoot\public\index.php" `
    -WindowStyle Hidden -PassThru -WorkingDirectory $OldRoot
$newSrv = Start-Process -FilePath $Php `
    -ArgumentList 'artisan', 'serve', '--host=127.0.0.1', "--port=$NewPort" `
    -WindowStyle Hidden -PassThru -WorkingDirectory $NewRoot
Start-Sleep -Seconds 5

try {
    $hOld = & curl.exe -s "$OldBase/api/health" | ConvertFrom-Json
    $hNew = & curl.exe -s "$NewBase/api/health" | ConvertFrom-Json
    if (-not $hOld.ok) { Write-Host '[FAIL] النظام القديم مش رد'; exit 1 }
    if (-not $hNew.ok) { Write-Host '[FAIL] لارافل مش رد'; exit 1 }

    Write-Host ''
    Write-Host '══ 1) قبل الدخول ══' -ForegroundColor Cyan
    Compare-Route 'health'   'GET'  '/api/health'
    Compare-Route 'not-found' 'GET' '/api/no_such_route_zz'
    Compare-Route 'me-anon'  'GET'  '/api/me'

    Write-Host ''
    Write-Host '══ 2) الدخول ══' -ForegroundColor Cyan
    $lo = & curl.exe -s -c $jarOld -X POST "$OldBase/api/login" -H 'Content-Type: application/json' -d "@$body"
    $ln = & curl.exe -s -c $jarNew -X POST "$NewBase/api/login" -H 'Content-Type: application/json' -d "@$body"
    if ((Normalize $lo) -eq (Normalize $ln)) {
        $pass++; Write-Host '  [مطابق]  POST /api/login' -ForegroundColor DarkGreen
    } else {
        $fail++; $failed += 'POST /api/login'
        Write-Host '  [مختلف]  POST /api/login' -ForegroundColor Red
        Write-Host "     قديم : $lo"; Write-Host "     جديد : $ln"
    }

    # اسم كوكي الجلسة جزء من العقد — تطبيق الطيار والواجهات بيعتمدوا عليه
    function CookieName($jar) {
        (Get-Content $jar -ErrorAction SilentlyContinue |
            Where-Object { $_ -notmatch '^#(?!HttpOnly)' -and $_.Trim() -ne '' } |
            ForEach-Object { ($_ -split "`t")[5] }) -join ','
    }
    $cOld = CookieName $jarOld; $cNew = CookieName $jarNew
    if ($cOld -eq $cNew -and $cOld -ne '') {
        $pass++; Write-Host "  [مطابق]  اسم كوكي الجلسة ($cOld)" -ForegroundColor DarkGreen
    } else {
        $fail++; $failed += 'session cookie name'
        Write-Host "  [مختلف]  كوكي الجلسة — قديم [$cOld] جديد [$cNew]" -ForegroundColor Red
    }

    Write-Host ''
    Write-Host '══ 3) بعد الدخول ══' -ForegroundColor Cyan
    Compare-Route 'me' 'GET' '/api/me' -Auth

    Write-Host ''
    Write-Host '══ 4) الكيانات — قراءة ══' -ForegroundColor Cyan
    Compare-Route 'egypt'          'GET' '/api/egypt'
    Compare-Route 'branches'       'GET' '/api/branches'        -Auth
    Compare-Route 'zones'          'GET' '/api/zones'           -Auth
    Compare-Route 'pilots'         'GET' '/api/pilots'          -Auth
    Compare-Route 'pilots?branch'  'GET' '/api/pilots?branchId=1' -Auth
    Compare-Route 'users'          'GET' '/api/users'           -Auth
    Compare-Route 'admin-emails'   'GET' '/api/admin-emails'    -Auth
    Compare-Route 'senders'        'GET' '/api/senders'         -Auth
    Compare-Route 'receivers'      'GET' '/api/receivers'       -Auth
    Compare-Route 'senders?q'      'GET' '/api/senders?q=01'    -Auth
    Compare-Route 'senders?q قصير' 'GET' '/api/senders?q=0'     -Auth
    Compare-Route 'receivers?q'    'GET' '/api/receivers?q=01'  -Auth
    Compare-Route 'senders?since'  'GET' '/api/senders?since=99999999999999' -Auth
    Compare-Route 'store-contacts' 'GET' '/api/store-contacts?store=alnour'  -Auth

    Write-Host ''
    Write-Host '══ 5) الصلاحيات على الكيانات ══' -ForegroundColor Cyan
    Compare-Route 'pilots بلا دخول'   'GET' '/api/pilots'
    Compare-Route 'users بلا دخول'    'GET' '/api/users'
    Compare-Route 'senders بلا دخول'  'GET' '/api/senders'
    Compare-Route 'store-contacts بلا محل' 'GET' '/api/store-contacts' -Auth

    Write-Host ''
    Write-Host '══ 6) الأوردرات — قراءة ══' -ForegroundColor Cyan
    Compare-Route 'orders'            'GET' '/api/orders' -Auth
    Compare-Route 'orders?limit'      'GET' '/api/orders?limit=2' -Auth
    Compare-Route 'orders?offset'     'GET' '/api/orders?limit=2&offset=1' -Auth
    Compare-Route 'orders?status ع'   'GET' '/api/orders?status=%D8%AA%D9%85%20%D8%A7%D9%84%D8%AA%D8%B3%D9%84%D9%8A%D9%85' -Auth
    Compare-Route 'orders?status كود' 'GET' '/api/orders?status=delivered' -Auth
    Compare-Route 'orders?branchId'   'GET' '/api/orders?branchId=3' -Auth
    Compare-Route 'orders?pilotId'    'GET' '/api/orders?pilotId=1' -Auth
    Compare-Route 'orders?moneySettled' 'GET' '/api/orders?moneySettled=1' -Auth
    Compare-Route 'orders?q رقم'      'GET' '/api/orders?q=CAI' -Auth
    Compare-Route 'orders?q تليفون'   'GET' '/api/orders?q=010' -Auth
    Compare-Route 'orders?from/to'    'GET' '/api/orders?from=2026-08-01&to=2026-08-31' -Auth
    Compare-Route 'orders?since جديد' 'GET' '/api/orders?since=99999999999999' -Auth
    Compare-Route 'orders?since قديم' 'GET' '/api/orders?since=1' -Auth
    Compare-Route 'orders?addedBy'    'GET' '/api/orders?addedBy=alnour' -Auth
    Compare-Route 'orders?customerId' 'GET' '/api/orders?customerId=2' -Auth
    Compare-Route 'orders بلا دخول'   'GET' '/api/orders'
    Compare-Route 'stats addedBy'     'GET' '/api/orders/stats?groupBy=addedBy' -Auth
    Compare-Route 'stats customerId'  'GET' '/api/orders/stats?groupBy=customerId' -Auth
    Compare-Route 'stats غلط'         'GET' '/api/orders/stats?groupBy=xxx' -Auth
    Compare-Route 'order موجود'       'GET' '/api/orders/89' -Auth
    Compare-Route 'order مش موجود'    'GET' '/api/orders/999999' -Auth
    Compare-Route 'order بلا دخول'    'GET' '/api/orders/89'


    Write-Host ''
    Write-Host '══ 7) المجالات المنقولة بالوكلاء ══' -ForegroundColor Cyan
    Compare-Route 'complaints'              'GET' '/api/complaints' -Auth
    Compare-Route 'complaints?status=open'   'GET' '/api/complaints?status=open' -Auth
    Compare-Route 'complaints?status=resolved' 'GET' '/api/complaints?status=resolved' -Auth
    Compare-Route 'complaints?status=all'    'GET' '/api/complaints?status=all' -Auth
    Compare-Route 'complaints?status فاضي'   'GET' '/api/complaints?status=' -Auth
    Compare-Route 'complaints?status مجهول'  'GET' '/api/complaints?status=zzz' -Auth
    Compare-Route 'complaints بلا دخول'      'GET' '/api/complaints'
    Compare-Route 'zone-requests'            'GET' '/api/zone-requests' -Auth
    Compare-Route 'zone-requests?pending'    'GET' '/api/zone-requests?status=pending' -Auth
    Compare-Route 'zone-requests?added'      'GET' '/api/zone-requests?status=added' -Auth
    Compare-Route 'zone-requests?all'        'GET' '/api/zone-requests?status=all' -Auth
    Compare-Route 'zone-requests?مجهول'      'GET' '/api/zone-requests?status=zzz' -Auth
    Compare-Route 'zone-requests بلا دخول'   'GET' '/api/zone-requests'
    Compare-Route 'settings'                 'GET' '/api/settings' -Auth
    Compare-Route 'settings بلا دخول'        'GET' '/api/settings'
    Compare-Route 'settings/site عام'        'GET' '/api/settings/site'
    Compare-Route 'settings/site بدخول'      'GET' '/api/settings/site' -Auth
    Compare-Route 'partners عام'             'GET' '/api/partners'
    Compare-Route 'partners بدخول'           'GET' '/api/partners' -Auth
    Compare-Route 'cash-stores'          'GET' '/api/cash-stores' -Auth
    Compare-Route 'cash-stores?branchId' 'GET' '/api/cash-stores?branchId=1' -Auth
    Compare-Route 'cash-stores?branchId=0' 'GET' '/api/cash-stores?branchId=0' -Auth
    Compare-Route 'cash-stores?branchId فاضي' 'GET' '/api/cash-stores?branchId=' -Auth
    Compare-Route 'cash-stores بلا دخول' 'GET' '/api/cash-stores'
    Compare-Route 'cash txns'            'GET' '/api/cash-stores/1/transactions' -Auth
    Compare-Route 'cash txns?type'       'GET' '/api/cash-stores/1/transactions?type=in' -Auth
    Compare-Route 'cash txns?from/to'    'GET' '/api/cash-stores/1/transactions?from=2026-08-01&to=2026-08-31' -Auth
    Compare-Route 'cash txns id مش رقم'  'GET' '/api/cash-stores/abc/transactions' -Auth
    Compare-Route 'cash txns id صفر'     'GET' '/api/cash-stores/0/transactions' -Auth
    Compare-Route 'cash txns id سالب'    'GET' '/api/cash-stores/-3/transactions' -Auth
    Compare-Route 'cash txns بلا دخول'   'GET' '/api/cash-stores/1/transactions'
    Compare-Route 'custody'              'GET' '/api/custody' -Auth
    Compare-Route 'custody?pilotId'      'GET' '/api/custody?pilotId=1' -Auth
    Compare-Route 'custody?from/to'      'GET' '/api/custody?from=2026-08-01&to=2026-08-31' -Auth
    Compare-Route 'custody بلا دخول'     'GET' '/api/custody'
    Compare-Route 'pilot custody'        'GET' '/api/pilots/1/custody' -Auth
    Compare-Route 'pilot custody مش موجود' 'GET' '/api/pilots/999999/custody' -Auth
    Compare-Route 'pilot custody مش رقم' 'GET' '/api/pilots/abc/custody' -Auth
    Compare-Route 'pilot custody بلا دخول' 'GET' '/api/pilots/1/custody'
    Compare-Route 'expenses'             'GET' '/api/expenses' -Auth
    Compare-Route 'expenses?from/to'     'GET' '/api/expenses?from=2026-08-01&to=2026-08-31' -Auth
    Compare-Route 'expenses?branchId'    'GET' '/api/expenses?branchId=1' -Auth
    Compare-Route 'expenses بلا دخول'    'GET' '/api/expenses'
    Compare-Route 'wallets افتراضي'      'GET' '/api/wallets' -Auth
    Compare-Route 'wallets?store'        'GET' '/api/wallets?ownerType=store' -Auth
    Compare-Route 'wallets?customer'     'GET' '/api/wallets?ownerType=customer' -Auth
    Compare-Route 'wallets?نوع غلط'      'GET' '/api/wallets?ownerType=xxx' -Auth
    Compare-Route 'wallets?نوع فاضي'     'GET' '/api/wallets?ownerType=' -Auth
    Compare-Route 'wallets بلا دخول'     'GET' '/api/wallets'
    Compare-Route 'wallet store/1'       'GET' '/api/wallets/store/1' -Auth
    Compare-Route 'wallet customer/1'    'GET' '/api/wallets/customer/1' -Auth
    Compare-Route 'wallet مش متخلقة'     'GET' '/api/wallets/customer/999999' -Auth
    Compare-Route 'wallet نوع غلط'       'GET' '/api/wallets/pilot/1' -Auth
    Compare-Route 'wallet id صفر'        'GET' '/api/wallets/store/0' -Auth
    Compare-Route 'wallet id مش رقم'     'GET' '/api/wallets/store/abc' -Auth
    Compare-Route 'wallet بلا دخول'      'GET' '/api/wallets/store/1'
    Compare-Route 'attendance يوم القاهرة' 'GET' '/api/attendance' -Auth
    Compare-Route 'attendance?day'       'GET' '/api/attendance?day=2026-08-19' -Auth
    Compare-Route 'attendance?from/to'   'GET' '/api/attendance?from=2026-08-01&to=2026-08-31' -Auth
    Compare-Route 'attendance?from بس'   'GET' '/api/attendance?from=2026-08-01' -Auth
    Compare-Route 'attendance?day يغلب from' 'GET' '/api/attendance?day=2026-08-19&from=2026-08-01' -Auth
    Compare-Route 'attendance?username'  'GET' '/api/attendance?username=admin' -Auth
    Compare-Route 'attendance يوم فاضي'  'GET' '/api/attendance?day=1990-01-01' -Auth
    Compare-Route 'attendance بلا دخول'  'GET' '/api/attendance'
    Compare-Route 'manual-employees'     'GET' '/api/manual-employees' -Auth
    Compare-Route 'manual-employees بلا دخول' 'GET' '/api/manual-employees'
    Compare-Route 'pilot/state بلا دخول'           'GET' '/api/pilot/state'
    Compare-Route 'pilot/active-orders بلا دخول'   'GET' '/api/pilot/active-orders'
    Compare-Route 'pilot/return-requests بلا دخول' 'GET' '/api/pilot/return-requests'
    Compare-Route 'pilot/leave-requests بلا دخول'  'GET' '/api/pilot/leave-requests'
    Compare-Route 'pilot/shift-requests بلا دخول'  'GET' '/api/pilot/shift-requests'
    Compare-Route 'pilot/finished-orders بلا دخول' 'GET' '/api/pilot/finished-orders'
    Compare-Route 'pilot/closeouts بلا دخول'       'GET' '/api/pilot/closeouts'
    Compare-Route 'pilot/state أدمن'           'GET' '/api/pilot/state' -Auth
    Compare-Route 'pilot/active-orders أدمن'   'GET' '/api/pilot/active-orders' -Auth
    Compare-Route 'pilot/return-requests أدمن' 'GET' '/api/pilot/return-requests' -Auth
    Compare-Route 'pilot/leave-requests أدمن'  'GET' '/api/pilot/leave-requests' -Auth
    Compare-Route 'pilot/shift-requests أدمن'  'GET' '/api/pilot/shift-requests' -Auth
    Compare-Route 'pilot/finished-orders أدمن' 'GET' '/api/pilot/finished-orders' -Auth
    Compare-Route 'pilot/closeouts أدمن'       'GET' '/api/pilot/closeouts' -Auth
    Compare-Route 'state?since'          'GET' '/api/pilot/state?since=1' -Auth
    Compare-Route 'state?since كبير'     'GET' '/api/pilot/state?since=99999999999999' -Auth
    Compare-Route 'state?since+sig'      'GET' '/api/pilot/state?since=1&sig=deadbeef1234' -Auth
    Compare-Route 'state?since فاضي'     'GET' '/api/pilot/state?since=' -Auth
    Compare-Route 'active-orders?since'  'GET' '/api/pilot/active-orders?since=1' -Auth
    Compare-Route 'active-orders?since ك' 'GET' '/api/pilot/active-orders?since=99999999999999' -Auth
    Compare-Route 'return-req?status'    'GET' '/api/pilot/return-requests?status=pending' -Auth
    Compare-Route 'return-req?status صفر' 'GET' '/api/pilot/return-requests?status=0' -Auth
    Compare-Route 'return-req?since'     'GET' '/api/pilot/return-requests?since=99999999999999' -Auth
    Compare-Route 'leave-req?status'     'GET' '/api/pilot/leave-requests?status=approved' -Auth
    Compare-Route 'leave-req?status غلط' 'GET' '/api/pilot/leave-requests?status=zzz' -Auth
    Compare-Route 'leave-req?since'      'GET' '/api/pilot/leave-requests?since=1' -Auth
    Compare-Route 'shift-req?status'     'GET' '/api/pilot/shift-requests?status=pending' -Auth
    Compare-Route 'shift-req?since'      'GET' '/api/pilot/shift-requests?since=99999999999999' -Auth
    Compare-Route 'finished?day'         'GET' '/api/pilot/finished-orders?day=2026-08-19' -Auth
    Compare-Route 'finished?day غلط'     'GET' '/api/pilot/finished-orders?day=19-08-2026' -Auth
    Compare-Route 'finished?day مستحيل'  'GET' '/api/pilot/finished-orders?day=2026-13-45' -Auth
    Compare-Route 'finished?shift'       'GET' '/api/pilot/finished-orders?shift=1' -Auth
    Compare-Route 'finished?shift غريبة' 'GET' '/api/pilot/finished-orders?shift=999999' -Auth
    Compare-Route 'finished?since'       'GET' '/api/pilot/finished-orders?since=99999999999999' -Auth
    Compare-Route 'finished?since سالب'  'GET' '/api/pilot/finished-orders?since=-5' -Auth
    Compare-Route 'finished?day+shift'   'GET' '/api/pilot/finished-orders?day=2026-08-19&shift=1' -Auth
    Compare-Route 'GET pilot/version'  'GET' '/api/pilot/version' -Auth
    Compare-Route 'GET pilot/location' 'GET' '/api/pilot/location' -Auth
    Compare-Route 'GET pilot/shift/end' 'GET' '/api/pilot/shift/end' -Auth
    Compare-Route 'board بلا فرع'      'GET' '/api/board' -Auth
    Compare-Route 'board فرع'          'GET' '/api/board?branch=3' -Auth
    Compare-Route 'board فرع مش موجود' 'GET' '/api/board?branch=999' -Auth
    Compare-Route 'board since قديم'   'GET' '/api/board?branch=3&since=1' -Auth
    Compare-Route 'board since جديد'   'GET' '/api/board?branch=3&since=99999999999999' -Auth
    Compare-Route 'board بلا دخول'     'GET' '/api/board?branch=3'
    Compare-Route 'shifts'             'GET' '/api/shifts' -Auth
    Compare-Route 'shifts?branch'      'GET' '/api/shifts?branch=3' -Auth
    Compare-Route 'shifts?pilot'       'GET' '/api/shifts?pilot=6' -Auth
    Compare-Route 'shifts?status نشط'  'GET' '/api/shifts?status=active' -Auth
    Compare-Route 'shifts?status منتهي' 'GET' '/api/shifts?status=ended' -Auth
    Compare-Route 'shifts?status غلط'  'GET' '/api/shifts?status=zzz' -Auth
    Compare-Route 'shifts?branch=0'    'GET' '/api/shifts?branch=0' -Auth
    Compare-Route 'shifts?since فاضي'  'GET' '/api/shifts?since=' -Auth
    Compare-Route 'shifts?since قديم'  'GET' '/api/shifts?since=1' -Auth
    Compare-Route 'shifts?since جديد'  'GET' '/api/shifts?since=99999999999999' -Auth
    Compare-Route 'shifts بلا دخول'    'GET' '/api/shifts'
    Compare-Route 'closeout بلا طيار'  'GET' '/api/closeouts/monthly' -Auth
    Compare-Route 'closeout بلا شهر'   'GET' '/api/closeouts/monthly?pilot=6' -Auth
    Compare-Route 'closeout طيار سالب' 'GET' '/api/closeouts/monthly?pilot=-3&month=2026-08' -Auth
    Compare-Route 'closeout طيار نص'   'GET' '/api/closeouts/monthly?pilot=abc&month=2026-08' -Auth
    Compare-Route 'closeout شهر بلا شرطة' 'GET' '/api/closeouts/monthly?pilot=6&month=202608' -Auth
    Compare-Route 'closeout شهر 13'    'GET' '/api/closeouts/monthly?pilot=6&month=2026-13' -Auth
    Compare-Route 'closeout طيار مش موجود' 'GET' '/api/closeouts/monthly?pilot=99999&month=2026-08' -Auth
    Compare-Route 'closeout شهر فاضي'  'GET' '/api/closeouts/monthly?pilot=6&month=2026-07' -Auth
    Compare-Route 'closeout شهر بورديات' 'GET' '/api/closeouts/monthly?pilot=6&month=2026-08' -Auth
    Compare-Route 'closeout بلا دخول'  'GET' '/api/closeouts/monthly?pilot=6&month=2026-08'
    Compare-Route 'join-requests'      'GET' '/api/join-requests' -Auth
    Compare-Route 'join-requests?branch' 'GET' '/api/join-requests?branch=3&status=pending' -Auth
    Compare-Route 'join-requests?since' 'GET' '/api/join-requests?since=1' -Auth
    Compare-Route 'join-requests بلا دخول' 'GET' '/api/join-requests'
    Compare-Route 'leave-requests'     'GET' '/api/leave-requests' -Auth
    Compare-Route 'leave-requests?status' 'GET' '/api/leave-requests?status=approved' -Auth
    Compare-Route 'leave-requests?branch' 'GET' '/api/leave-requests?branch=3' -Auth
    Compare-Route 'leave-requests بلا دخول' 'GET' '/api/leave-requests'
    Compare-Route 'shift-requests'     'GET' '/api/shift-requests' -Auth
    Compare-Route 'shift-requests?branch' 'GET' '/api/shift-requests?branch=3' -Auth
    Compare-Route 'shift-requests?since' 'GET' '/api/shift-requests?since=99999999999999' -Auth
    Compare-Route 'shift-requests بلا دخول' 'GET' '/api/shift-requests'
    Compare-Route 'return-requests'    'GET' '/api/return-requests' -Auth
    Compare-Route 'return-requests?status' 'GET' '/api/return-requests?status=pending' -Auth
    Compare-Route 'return-requests بلا دخول' 'GET' '/api/return-requests'
    Compare-Route 'pilot-transfers'    'GET' '/api/pilot-transfers' -Auth
    Compare-Route 'pilot-transfers?branch' 'GET' '/api/pilot-transfers?branch=3&status=pending' -Auth
    Compare-Route 'pilot-transfers?since' 'GET' '/api/pilot-transfers?since=1' -Auth
    Compare-Route 'pilot-transfers بلا دخول' 'GET' '/api/pilot-transfers'
    Compare-Route 'support-requests'   'GET' '/api/support-requests' -Auth
    Compare-Route 'support-requests?status' 'GET' '/api/support-requests?status=pending' -Auth
    Compare-Route 'support-requests?since' 'GET' '/api/support-requests?since=1' -Auth
    Compare-Route 'support-requests بلا دخول' 'GET' '/api/support-requests'
    Compare-Route 'customers'              'GET' '/api/customers' -Auth
    Compare-Route 'customers?since جديد'   'GET' '/api/customers?since=99999999999999' -Auth
    Compare-Route 'customers?since قديم'   'GET' '/api/customers?since=1' -Auth
    Compare-Route 'customers?since فاضي'   'GET' '/api/customers?since=' -Auth
    Compare-Route 'customers?since صفر'    'GET' '/api/customers?since=0' -Auth
    Compare-Route 'customers بلا دخول'     'GET' '/api/customers'
    Compare-Route 'pickup-profile بلا دخول' 'GET' '/api/store/pickup-profile'
    Compare-Route 'pickup-profile بأدمن'    'GET' '/api/store/pickup-profile' -Auth
    Compare-Route 'coverage عام'          'GET' '/api/public/coverage'
    Compare-Route 'coverage بدخول'        'GET' '/api/public/coverage' -Auth
    Compare-Route 'track موجود'           'GET' '/api/track/CAI-260818-004'
    Compare-Route 'track متعدد الطرود'    'GET' '/api/track/CAI-260809-001'
    Compare-Route 'track طرد 1'           'GET' '/api/track/CAI-260809-001-1'
    Compare-Route 'track طرد 2'           'GET' '/api/track/CAI-260809-001-2'
    Compare-Route 'track طرد مش موجود'    'GET' '/api/track/CAI-260809-001-9'
    Compare-Route 'track لاحقة غلط'       'GET' '/api/track/CAI-260809-001-2-5'
    Compare-Route 'track مش موجود'        'GET' '/api/track/CAI-000000-999'
    Compare-Route 'track رقم بايظ'        'GET' '/api/track/x'
    Compare-Route 'track فاضي (مسافة)'    'GET' '/api/track/%20'
    Compare-Route 'track بلا باراميتر'    'GET' '/api/track'
    Compare-Route 'track بدخول أدمن'      'GET' '/api/track/CAI-260809-001' -Auth
    Compare-Route 'lookup رقم'          'GET' '/api/lookup?phone=01012345678' -Auth
    Compare-Route 'lookup دولي'         'GET' '/api/lookup?phone=%2B201012345678' -Auth
    Compare-Route 'lookup بشرط'         'GET' '/api/lookup?phone=0100-123-4567' -Auth
    Compare-Route 'lookup بلا صفر'      'GET' '/api/lookup?phone=1012345678' -Auth
    Compare-Route 'lookup بلا رقم'      'GET' '/api/lookup' -Auth
    Compare-Route 'lookup رقم قصير'     'GET' '/api/lookup?phone=12345' -Auth
    Compare-Route 'lookup بلا دخول'     'GET' '/api/lookup?phone=01012345678'
    Compare-Route 'ratings رقم'         'GET' '/api/trust/01012345678/ratings' -Auth
    Compare-Route 'ratings رقم مجهول'   'GET' '/api/trust/01099999999/ratings' -Auth
    Compare-Route 'ratings رقم قصير'    'GET' '/api/trust/123/ratings' -Auth
    Compare-Route 'ratings بلا دخول'    'GET' '/api/trust/01012345678/ratings'
    Compare-Route 'lookup-log'          'GET' '/api/trust/lookup-log' -Auth
    Compare-Route 'lookup-log?limit'    'GET' '/api/trust/lookup-log?limit=5' -Auth
    Compare-Route 'lookup-log?limit=0'  'GET' '/api/trust/lookup-log?limit=0' -Auth
    Compare-Route 'lookup-log?limit كبير' 'GET' '/api/trust/lookup-log?limit=99999' -Auth
    Compare-Route 'lookup-log?actor'    'GET' '/api/trust/lookup-log?actor=admin' -Auth
    Compare-Route 'lookup-log?actor فاضي' 'GET' '/api/trust/lookup-log?actor=' -Auth
    Compare-Route 'lookup-log?phone'    'GET' '/api/trust/lookup-log?phone=%2B201012345678' -Auth
    Compare-Route 'lookup-log بلا دخول' 'GET' '/api/trust/lookup-log'

    # ── المسارات اللي اتنقلت لحد دلوقتي تتضاف هنا ──────────────
    # كل خطوة في الترحيل بتضيف سطورها هنا، والبوابة إن الكل يفضل مطابق.

} finally {
    Stop-Process -Id $oldSrv.Id -Force -ErrorAction SilentlyContinue
    Stop-Process -Id $newSrv.Id -Force -ErrorAction SilentlyContinue

    # تنظيف أثر الفاحص على lookup_log — عشان مايكسّرش اختبار الثقة بعده
    if ($lookupMaxBefore -ne $null -and $lookupMaxBefore -ne '') {
        & $Mysql -u root -e "DELETE FROM aldahshan.lookup_log WHERE id > $lookupMaxBefore;" 2>$null
        $after = & $Mysql -u root -N -e "SELECT IFNULL(MAX(id),0) FROM aldahshan.lookup_log;" 2>$null
        Write-Host ''
        Write-Host ("تنظيف lookup_log: قبل=$lookupMaxBefore بعد=$after") -ForegroundColor DarkGray
    }
    Get-Process php -ErrorAction SilentlyContinue |
        Where-Object { $_.StartTime -gt (Get-Date).AddMinutes(-5) -and $_.Id -ne $PID } |
        ForEach-Object { }   # مابنقتلش عمياني — السيرفرين اتقفلوا بالـid فوق
}

Write-Host ''
Write-Host '════════════════════════════════════════════'
Write-Host "PARITY: $pass مطابق / $fail مختلف   (إجمالي $($pass + $fail))"
if ($failed.Count) { Write-Host 'المختلف:'; $failed | ForEach-Object { Write-Host "   - $_" } }
Write-Host '════════════════════════════════════════════'
if ($fail -gt 0) { exit 1 }
