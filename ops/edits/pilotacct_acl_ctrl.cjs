/* 🔐 صلاحيات «تقفيل الطيارين» — الفرض على السيرفر.
 *
 * ═══ ليه الفرض هنا مش في الواجهة ═══
 * إخفاء عمود في HTML مش صلاحية — الرقم بيفضل في الشبكة وأي حد بيفتح
 * أدوات المتصفح بيشوفه. القص هنا معناه إن العمود الممنوع **مابيخرجش
 * من السيرفر أصلًا**.
 *
 * ═══ نقط الخروج ═══
 * الصفوف بتخرج من مكانين بس: `month` (الشيت والكشف والتقفيلة) و
 * `deferredList` (السلف). `entrySave` و`permsSave` و`lock/unlock`
 * بيرجّعوا `{ok}` بس. فالقص في المكانين دول = تغطية كاملة، مش زي
 * دمشق اللي فيها `entriesSave` بترجّع الصف كامل بلا قص.
 *
 * ═══ الافتراضي «افتح» ═══
 * النظام لايف. مشرفي الفروع والمحاسب شغالين على البرنامج دلوقتي من
 * غير أي صف صلاحيات. لو الافتراضي كان «اقفل» كانت الرفعة دي هتقفل
 * عليهم البرنامج في نص يوم شغل. الصف لما الأدمن يحفظه هو اللي بيضيّق.
 *
 * ═══ الكتابة ═══
 * `act.edit` لازمة، **وكمان** العمود نفسه. حد شايف «سلف» ومش شايف
 * «خصومات» مايقدرش يكتب في خصومات — الشرطين مع بعض مش واحد.
 *
 * 🔒 الحارس: ops/test_pilotacct_acl.php
 */
const fs = require('fs');
const F = 'app/Http/Controllers/Api/PilotAccountingController.php';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const L = (...x) => x.join('\n');
const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};

if (s.includes('function aclOf')) { console.log('🔴 موجود قبل كده'); process.exit(1); }

/* ═══ ① محمّل الصلاحيات + الحرّاس — بيتحطوا قبل pilotInScope ═══ */
one(
  L(
    '    private function pilotInScope(Actor $actor, int $pilotId): array',
    '    {'
  ),
  L(
    '    /* ═══════════════════════════════════════════════════════════',
    '       🔐 الصلاحيات',
    '    ═══════════════════════════════════════════════════════════ */',
    '',
    '    /**',
    '     * صلاحيات الشخص ده: `{keys, branches, full}`.',
    '     *',
    '     * الأدمن بياخد كل حاجة دايمًا ومابيتقريش له صف — قرار زي دمشق:',
    '     * صاحب النظام مايقدرش يقفل على نفسه بالغلط.',
    '     *',
    '     * 🔴 اللي مالوش صف بياخد **كل حاجة** كمان. مش سهو: البرنامج',
    '     * شغال لايف من غير صفوف، والمنع الافتراضي كان هيقفله على',
    '     * المحاسب ومشرفي الفروع في نص يوم شغل. الصف = تضييق.',
    '     */',
    '    private function aclOf(Actor $actor): array',
    '    {',
    "        if ($actor->role === 'admin') {",
    "            return ['keys' => W::fullPermKeys(), 'branches' => [], 'full' => true];",
    '        }',
    '',
    '        $uid = $actor->userId;',
    '        $row = $uid !== null',
    "            ? (DB::select('SELECT perm_keys, branches FROM pilot_acct_perms WHERE user_id = ? LIMIT 1', [$uid])[0] ?? null)",
    '            : null;',
    '',
    '        if (! $row) {',
    "            return ['keys' => W::fullPermKeys(), 'branches' => [], 'full' => true];",
    '        }',
    '',
    '        $row  = (array) $row;',
    '        $keys = [];',
    "        $d    = json_decode((string) ($row['perm_keys'] ?? ''), true);",
    '        if (is_array($d)) {',
    '            foreach ($d as $k => $v) {',
    '                if ($v === true) {',
    '                    $keys[(string) $k] = true;',
    '                }',
    '            }',
    '        }',
    '',
    '        $branches = [];',
    "        foreach (explode(',', (string) ($row['branches'] ?? '')) as $b) {",
    '            $b = (int) trim($b);',
    '            if ($b > 0) {',
    '                $branches[] = $b;',
    '            }',
    '        }',
    '',
    "        return ['keys' => $keys, 'branches' => array_values(array_unique($branches)), 'full' => false];",
    '    }',
    '',
    '    /** المقارنة `=== true` مقصودة — المفتاح الغايب أو أي قيمة تانية = ممنوع */',
    '    private function can(array $acl, string $key): bool',
    '    {',
    "        return ($acl['keys'][$key] ?? null) === true;",
    '    }',
    '',
    '    /** بيرمي 403 برسالة بتقول المفتاح الناقص إيه بالعربي */',
    '    private function need(array $acl, string $key, string $what): void',
    '    {',
    '        if (! $this->can($acl, $key)) {',
    "            throw ApiException::forbidden('مالكش صلاحية ' . $what . ' — كلّم الإدارة');",
    '        }',
    '    }',
    '',
    '    private function pilotInScope(Actor $actor, int $pilotId): array',
    '    {'
  ),
  '① محمّل الصلاحيات والحرّاس'
);

/* ═══ ② نطاق الفروع في pilotInScope — الصلاحية بتقص زي الدور ═══ */
one(
  L(
    "        if ($actor->role === 'branch' && (int) ($p['assigned_branch_id'] ?? 0) !== (int) ($actor->branchId ?? -1)) {",
    "            throw ApiException::forbidden('الطيار ده مش في فرعك');",
    '        }',
    '',
    '        return $p;'
  ),
  L(
    "        if ($actor->role === 'branch' && (int) ($p['assigned_branch_id'] ?? 0) !== (int) ($actor->branchId ?? -1)) {",
    "            throw ApiException::forbidden('الطيار ده مش في فرعك');",
    '        }',
    '',
    '        /* 🔐 قصّ الفروع من الصلاحيات. مشرف الفرع مقفول بدوره فوق،',
    '           إنما المحاسب ممكن يتحدّدله فروع بعينها من شاشة الصلاحيات.',
    '           القايمة الفاضية = كل الفروع. */',
    '        $acl = $this->aclOf($actor);',
    "        if ($acl['branches'] && ! in_array((int) ($p['assigned_branch_id'] ?? 0), $acl['branches'], true)) {",
    "            throw ApiException::forbidden('الطيار ده في فرع مش مسموحلك بيه');",
    '        }',
    '',
    '        return $p;'
  ),
  '② نطاق الفروع في pilotInScope'
);

/* ═══ ③ month: قصّ الفروع + قصّ الأعمدة + إرسال الصلاحيات للواجهة ═══ */
one(
  L(
    "        if ($actor->role === 'branch') {",
    '            $branchId = (int) ($actor->branchId ?? 0);   // مشرف الفرع مقفول على فرعه',
    '        }'
  ),
  L(
    "        if ($actor->role === 'branch') {",
    '            $branchId = (int) ($actor->branchId ?? 0);   // مشرف الفرع مقفول على فرعه',
    '        }',
    '',
    '        /* 🔐 صلاحيات الشخص. بتتقرا مرة واحدة هنا وبتتمرّر على كل صف. */',
    '        $acl = $this->aclOf($actor);',
    '',
    '        /* قصّ الفروع المسموحة. لو طلب فرع مش في قايمته يترفض صراحةً —',
    '           مش يترد فاضي، عشان يعرف إن ده منع مش «مافيش بيانات». */',
    "        if ($acl['branches']) {",
    "            if ($branchId !== null && ! in_array($branchId, $acl['branches'], true)) {",
    "                throw ApiException::forbidden('الفرع ده مش مسموحلك بيه');",
    '            }',
    '        }'
  ),
  '③أ قصّ الفروع في month'
);

one(
  L(
    '        if ($branchId !== null) {',
    "            $where[] = 'p.assigned_branch_id = ?';",
    '            $args[]  = $branchId;',
    '        }'
  ),
  L(
    '        if ($branchId !== null) {',
    "            $where[] = 'p.assigned_branch_id = ?';",
    '            $args[]  = $branchId;',
    "        } elseif ($acl['branches']) {",
    '            /* مافيش فرع مطلوب بس الصلاحية محدودة — بنقصّ على فروعه',
    '               بدل ما يشوف الشركة كلها. */',
    "            $where[] = 'p.assigned_branch_id IN (' . implode(',', array_fill(0, count($acl['branches']), '?')) . ')';",
    "            $args    = array_merge($args, $acl['branches']);",
    '        }'
  ),
  '③ب فلترة الاستعلام بالفروع المسموحة'
);

one(
  L(
    '            $days = [];',
    '            for ($d = 1; $d <= $nd; $d++) {',
    "                $e     = $entries[$pid][$d] ?? [];",
    "                $perms = $e['perms'] ?? ($auto[$pid][$d]['perms'] ?? []);",
    '                $days[] = W::dayRow($d, $auto[$pid][$d] ?? [], $e, $perms);',
    '            }'
  ),
  L(
    '            $days = [];',
    '            for ($d = 1; $d <= $nd; $d++) {',
    "                $e     = $entries[$pid][$d] ?? [];",
    "                $perms = $e['perms'] ?? ($auto[$pid][$d]['perms'] ?? []);",
    '                $days[] = W::dayRow($d, $auto[$pid][$d] ?? [], $e, $perms);',
    '            }'
  ),
  '③ج (مرساة الصفوف — بلا تغيير)'
);

/* القصّ نفسه بيتعمل على `$days` و`$totals` قبل ما يتحطوا في الرد */
one(
  L(
    '            ]);',
    '',
    '            $out[] = [',
    "                'pilotId'    => $pid,"
  ),
  L(
    '            ]);',
    '',
    '            /* 🔐 القصّ. `$acl[\'full\']` معناها إن مافيش صف صلاحيات',
    '               فمافيش داعي نلف على كل يوم في الشهر لكل طيار. */',
    "            if (! $acl['full']) {",
    "                $days   = array_map(fn (array $r): array => W::filterDay($r, $acl['keys']), $days);",
    "                $totals = W::filterTotals($totals, $acl['keys']);",
    '            }',
    '',
    '            $out[] = [',
    "                'pilotId'    => $pid,"
  ),
  '③د قصّ الأعمدة والإجماليات'
);

one(
  L(
    "            'pilots'      => $out,",
    "            'deferred'    => $this->deferredWire($deferred, $ym),",
    '        ]);'
  ),
  L(
    "            'pilots'      => $out,",
    '            /* 🔐 السلف المؤجلة شاشة لوحدها — لو مقفولة مابتخرجش أصلًا */',
    "            'deferred'    => $this->can($acl, 'page.deferred') ? $this->deferredWire($deferred, $ym) : [],",
    '            /* الواجهة بتبني شاشتها من دي — مصدر واحد للمفاتيح */',
    "            'acl'         => ['keys' => (object) $acl['keys'], 'branches' => $acl['branches'],",
    "                              'full' => $acl['full'], 'isAdmin' => $actor->role === 'admin'],",
    '        ]);'
  ),
  '③هـ إرسال الصلاحيات للواجهة'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ الفرض اتحط في month');
