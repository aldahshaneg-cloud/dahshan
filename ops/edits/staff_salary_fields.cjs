/* 💵 حقول رواتب الموظفين والطيارين على السلك.
 *
 * ═══ ليه ═══
 * تقفيلة الموظفين الجديدة بتحسب من `users.hour_rate/monthly_salary/
 * paid_leave_days` (اتضافوا للمخطط النهاردة)، وتقفيلة الطيارين بتحسب من
 * أعمدة زيّهم على `pilots` — بس **مافيش أي نقطة نهاية بتكتبهم**:
 * `usersUpdate` مايعرفهمش، و`pilotsUpdate` بيقبل العمولة والراتب بس من
 * غير سعر الساعة وأيام الإجازة. النتيجة الفعلية على الإنتاج: ١٤ من ١٥
 * طيار بسعر ساعة صفر والتقفيلة هتطلع أصفار الليلة — لأن مافيش شاشة تدخل
 * منها الأرقام أصلًا.
 *
 * ═══ السلك ═══
 * الحقول الجديدة بتتسجّل في INTENTIONAL_FIELDS عشان بوابة wire:verify
 * تشيلها قبل مقارنة الأصل — زي homeBranchId وarchivedAt بالظبط.
 *
 * 🔒 الحارس: ops/test_staff_closeout.php (قسم الحقول)
 */
const fs = require('fs');
let bad = 0;
const L = (...x) => x.join('\n');
const BUF = {};
const load = f => (BUF[f] !== undefined ? BUF[f] : (BUF[f] = fs.readFileSync(f, 'utf8')));
const one = (file, old, neu, label) => {
  const s = load(file);
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  BUF[file] = s.replace(old, neu);
  console.log('  ✓ ' + label);
};

const ENT = 'app/Http/Controllers/Api/EntitiesController.php';
const CW = 'app/Wire/CoreWire.php';
const VP = 'app/Console/Commands/VerifyWireParity.php';

if (load(ENT).includes("'hourRate' => 'hour_rate'")) { console.log('🔴 موجود قبل كده'); process.exit(1); }

/* ═══ ① usersUpdate: تلات حقول رواتب ═══ */
one(ENT,
  L(
    "        foreach (['pilotId' => 'pilot_id', 'senderId' => 'sender_id'] as $wire => $col) {",
    '            if (array_key_exists($wire, $b)) {',
    '                $fields[] = "$col = ?";',
    '                $vals[] = $b[$wire] !== null ? $this->intId($b[$wire]) : null;',
    '            }',
    '        }'
  ),
  L(
    "        foreach (['pilotId' => 'pilot_id', 'senderId' => 'sender_id'] as $wire => $col) {",
    '            if (array_key_exists($wire, $b)) {',
    '                $fields[] = "$col = ?";',
    '                $vals[] = $b[$wire] !== null ? $this->intId($b[$wire]) : null;',
    '            }',
    '        }',
    '        /* 💵 رواتب الموظف — تقفيلة الموظفين بتحسب منهم. فلوس: بتتخزّن',
    '           كما هي بلا حد أدنى/أقصى، زي عمولة الطيار بالحرف. */',
    "        foreach (['hourRate' => 'hour_rate', 'monthlySalary' => 'monthly_salary'] as $wire => $col) {",
    '            if (array_key_exists($wire, $b)) {',
    '                $fields[] = "$col = ?";',
    '                $vals[] = round((float) $b[$wire], 2);',
    '            }',
    '        }',
    "        if (array_key_exists('paidLeaveDays', $b)) {",
    "            $fields[] = 'paid_leave_days = ?';",
    "            $vals[] = max(0, (int) $b['paidLeaveDays']);",
    '        }'
  ),
  '① usersUpdate'
);

/* ═══ ② usersCreate: نفس الحقول في الإدخال ═══ */
one(ENT,
  L(
    "                    'INSERT INTO users (username, password_hash, role, name, branch_id, pilot_id, sender_id,",
    '                                        shop_name, shop_phone, shop_phone2, shop_address, created_at)',
    "                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',"
  ),
  L(
    "                    'INSERT INTO users (username, password_hash, role, name, branch_id, pilot_id, sender_id,",
    '                                        shop_name, shop_phone, shop_phone2, shop_address,',
    '                                        hour_rate, monthly_salary, paid_leave_days, created_at)',
    "                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',"
  ),
  '②أ usersCreate الاستعلام'
);

one(ENT,
  L(
    "                        trim((string) ($b['shopAddress'] ?? '')) ?: null,",
    '                        WireTime::nowDb(),',
    '                    ]',
    '                );'
  ),
  L(
    "                        trim((string) ($b['shopAddress'] ?? '')) ?: null,",
    "                        round((float) ($b['hourRate'] ?? 0), 2),",
    "                        round((float) ($b['monthlySalary'] ?? 0), 2),",
    "                        max(0, (int) ($b['paidLeaveDays'] ?? 0)),",
    '                        WireTime::nowDb(),',
    '                    ]',
    '                );'
  ),
  '②ب usersCreate القيم'
);

/* ═══ ③ pilotsUpdate: سعر الساعة وأيام الإجازة ═══ */
one(ENT,
  L(
    '        // 🔴 فلوس: القيمة بتتخزّن كما هي بلا حد أدنى/أقصى — زي الأصل بالحرف',
    "        foreach (['commissionValue' => 'commission_value', 'monthlySalary' => 'monthly_salary'] as $wire => $col) {"
  ),
  L(
    '        // 🔴 فلوس: القيمة بتتخزّن كما هي بلا حد أدنى/أقصى — زي الأصل بالحرف',
    '        // سعر الساعة اتضاف 2026-09-01: التقفيلة بتحسب بيه ومكانش له أي',
    '        // نقطة كتابة — ١٤ من ١٥ طيار على الإنتاج بسعر صفر عشان كده.',
    "        foreach (['commissionValue' => 'commission_value', 'monthlySalary' => 'monthly_salary',",
    "                  'hourRate' => 'hour_rate'] as $wire => $col) {"
  ),
  '③أ pilotsUpdate فلوس'
);

one(ENT,
  L(
    "        if (array_key_exists('requiredDailyHours', $b)) {",
    "            $fields[] = 'required_daily_hours = ?';",
    "            $vals[] = $b['requiredDailyHours'] !== null ? (float) $b['requiredDailyHours'] : null;",
    '        }'
  ),
  L(
    "        if (array_key_exists('requiredDailyHours', $b)) {",
    "            $fields[] = 'required_daily_hours = ?';",
    "            $vals[] = $b['requiredDailyHours'] !== null ? (float) $b['requiredDailyHours'] : null;",
    '        }',
    "        if (array_key_exists('paidLeaveDays', $b)) {",
    "            $fields[] = 'paid_leave_days = ?';",
    "            $vals[] = max(0, (int) $b['paidLeaveDays']);",
    '        }'
  ),
  '③ب pilotsUpdate الإجازة'
);

/* ═══ ④ السلك: المستخدم ═══ */
one(CW,
  L(
    "            'blocked'     => (bool) ($r['blocked'] ?? 0),",
    "            'protected'   => (bool) ($r['protected'] ?? 0),",
    "            'createdAt'   => WireTime::toWire($r['created_at'] ?? null),",
    '        ];',
    '    }'
  ),
  L(
    "            'blocked'     => (bool) ($r['blocked'] ?? 0),",
    "            'protected'   => (bool) ($r['protected'] ?? 0),",
    '            /* 💵 رواتب تقفيلة الموظفين — مسجّلين INTENTIONAL في بوابة السلك */',
    "            'hourRate'      => round((float) ($r['hour_rate'] ?? 0), 2),",
    "            'monthlySalary' => round((float) ($r['monthly_salary'] ?? 0), 2),",
    "            'paidLeaveDays' => (int) ($r['paid_leave_days'] ?? 0),",
    "            'createdAt'   => WireTime::toWire($r['created_at'] ?? null),",
    '        ];',
    '    }'
  ),
  '④ سلك المستخدم'
);

/* ═══ ⑤ السلك: الطيار — سعر الساعة وأيام الإجازة للقايمة ═══ */
one(CW,
  L(
    "            'commissionType'  => $r['commission_type'] ?: 'percent',",
    "            'commissionValue' => (float) ($r['commission_value'] ?? 0),"
  ),
  L(
    "            'commissionType'  => $r['commission_type'] ?: 'percent',",
    "            'commissionValue' => (float) ($r['commission_value'] ?? 0),",
    '            /* 💵 شاشة أسعار الطيارين في برنامج التقفيل بتقرا منهم */',
    "            'hourRate'      => round((float) ($r['hour_rate'] ?? 0), 2),",
    "            'paidLeaveDays' => (int) ($r['paid_leave_days'] ?? 0),"
  ),
  '⑤ سلك الطيار'
);

/* ═══ ⑥ تسجيلهم في بوابة السلك ═══ */
one(VP,
  L(
    "        'store_contacts' => ["
  ),
  L(
    "        'users' => [",
    "            'hourRate'      => 'سعر ساعة الموظف — اتضاف 2026-09-01 مع تقفيلة الموظفين (طلب صاحب النظام: «زي ما الطيارين لهم تقفيلة ضيف كل الموظفين»). الأصل ماكانش فيه رواتب موظفين خالص.',",
    "            'monthlySalary' => 'الراتب الشهري للموظف — بيتقسم على أيام الشغل في التقفيلة، نفس معادلة دمشق',",
    "            'paidLeaveDays' => 'أيام الإجازة المدفوعة شهريًا للموظف',",
    '        ],',
    "        'store_contacts' => ["
  ),
  '⑥أ بوابة السلك — users'
);

one(VP,
  L(
    "            'archivedBy' => 'اسم المستخدم اللي أرشف الطيار — لقطة زي باقي أعمدة الـactor في النظام، عشان السجل يفضل مقروء حتى لو الحساب اتغيّر',",
    '        ],'
  ),
  L(
    "            'archivedBy' => 'اسم المستخدم اللي أرشف الطيار — لقطة زي باقي أعمدة الـactor في النظام، عشان السجل يفضل مقروء حتى لو الحساب اتغيّر',",
    "            'hourRate'      => 'سعر ساعة الطيار — العمود موجود من زمان بس ماكانش على السلك ولا له نقطة كتابة، فـ١٤ من ١٥ طيار بصفر. اتضاف 2026-09-01 مع شاشة الأسعار في برنامج التقفيل',",
    "            'paidLeaveDays' => 'أيام الإجازة المدفوعة شهريًا — نفس السبب',",
    '        ],'
  ),
  '⑥ب بوابة السلك — pilots'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش ولا ملف'); process.exit(1); }
Object.keys(BUF).forEach(f => fs.writeFileSync(f, BUF[f]));
console.log('\n✅ حقول الرواتب — ' + Object.keys(BUF).length + ' ملفات');
