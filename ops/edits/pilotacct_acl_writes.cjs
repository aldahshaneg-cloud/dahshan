/* 🔐 حرّاس الكتابة + شاشة الصلاحيات (السيرفر).
 *
 * الجزء ده بيكمّل `pilotacct_acl_ctrl.cjs`: القراءة اتقصّت هناك، وهنا
 * الكتابة بتتقفل ونقط نهاية الشاشة بتتعمل.
 *
 * ═══ قاعدة الكتابة ═══
 * `act.edit` **مش كافية** لوحدها. اللي شايف «سلف» بس مايكتبش في
 * «خصومات» — فالشرط شرطين: الفعل + العمود.
 *
 * ═══ ليه الاستئذان مربوط بعمودين ═══
 * `permsSave` بتكتب فترات الاستئذان، واللي بيتعرض منها عمودين في
 * الشاشة (`col.bout` خروج و`col.bin` رجوع). الكتابة بتتسمح لو أي
 * واحد منهم مفتوح — نفس منطق القصّ في `filterDay` بالظبط، عشان
 * مايحصلش إن حد يشوف العمود ومايقدرش يكتب فيه (أو العكس).
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

if (s.includes('function aclList')) { console.log('🔴 موجود قبل كده'); process.exit(1); }

/* ═══ ① entrySave — الفعل + العمود ═══
   المرساة بتشمل «خانة مش معروفة» عشان `pilotInScope($actor, $pid)`
   لوحدها موجودة في `permsSave` كمان — والسطر ده في entrySave بس. */
one(
  L(
    "            throw new ApiException('خانة مش معروفة');",
    '        }',
    '        if ($day < 1 || $day > W::daysInMonth($ym)) {',
    "            throw new ApiException('اليوم مش في الشهر ده');",
    '        }',
    "        $pilot = $this->pilotInScope($actor, $pid);"
  ),
  L(
    "            throw new ApiException('خانة مش معروفة');",
    '        }',
    '        if ($day < 1 || $day > W::daysInMonth($ym)) {',
    "            throw new ApiException('اليوم مش في الشهر ده');",
    '        }',
    '',
    '        /* 🔐 شرطين مش واحد: الفعل، وبعدين العمود نفسه. اللي شايف',
    "           «سلف» بس مايقدرش يكتب في «خصومات». اسم الخانة هو نفسه",
    "           اللي بعد `col.` في المفتاح — الجدولين اتكتبوا مع بعض. */",
    '        $acl = $this->aclOf($actor);',
    "        $this->need($acl, 'act.edit', 'تعديل الخانات');",
    "        $this->need($acl, 'col.' . $field, 'الكتابة في الخانة دي');",
    '',
    "        $pilot = $this->pilotInScope($actor, $pid);"
  ),
  '① entrySave'
);

/* ═══ ② permsSave (فترات الاستئذان) ═══ */
one(
  L(
    '    public function permsSave(Request $request): JsonResponse',
    '    {',
    '        $actor = $request->actorOrFail();'
  ),
  L(
    '    public function permsSave(Request $request): JsonResponse',
    '    {',
    '        $actor = $request->actorOrFail();',
    '',
    '        /* 🔐 الاستئذان بيتعرض في عمودين (خروج ورجوع). الكتابة مسموحة',
    "           لو أي واحد فيهم مفتوح — نفس شرط القصّ في `filterDay`، عشان",
    '           مايحصلش إن حد يشوف العمود ومايقدرش يكتب فيه. */',
    '        $acl = $this->aclOf($actor);',
    "        $this->need($acl, 'act.edit', 'تعديل الخانات');",
    "        if (! $this->can($acl, 'col.bout') && ! $this->can($acl, 'col.bin')) {",
    "            throw ApiException::forbidden('مالكش صلاحية على خانات الاستئذان — كلّم الإدارة');",
    '        }'
  ),
  '② permsSave (الاستئذان)'
);

/* ═══ ③ deferredList — شاشة السلف ═══ */
one(
  L(
    '    public function deferredList(Request $request): JsonResponse',
    '    {',
    '        $actor = $request->actorOrFail();',
    "        $ym    = $this->monthArg((string) $request->query('month', ''));"
  ),
  L(
    '    public function deferredList(Request $request): JsonResponse',
    '    {',
    '        $actor = $request->actorOrFail();',
    "        $this->need($this->aclOf($actor), 'page.deferred', 'على شاشة السلف المؤجلة');",
    "        $ym    = $this->monthArg((string) $request->query('month', ''));"
  ),
  '③ deferredList'
);

/* ═══ ④ الكتابة على السلف — تلات نقط ═══ */
one(
  L(
    '    public function deferredSave(Request $request): JsonResponse',
    '    {',
    '        $request->actorOrFail();'
  ),
  L(
    '    public function deferredSave(Request $request): JsonResponse',
    '    {',
    "        $this->need($this->aclOf($request->actorOrFail()), 'act.deferred', 'على السلف المؤجلة');"
  ),
  '④أ deferredSave'
);

one(
  L(
    '    public function deferredDelete(Request $request, string $id): JsonResponse',
    '    {'
  ),
  L(
    '    public function deferredDelete(Request $request, string $id): JsonResponse',
    '    {',
    "        $this->need($this->aclOf($request->actorOrFail()), 'act.deferred', 'على السلف المؤجلة');"
  ),
  '④ب deferredDelete'
);

one(
  L(
    '    public function deferredPayment(Request $request, string $id): JsonResponse',
    '    {'
  ),
  L(
    '    public function deferredPayment(Request $request, string $id): JsonResponse',
    '    {',
    "        $this->need($this->aclOf($request->actorOrFail()), 'act.deferred', 'على السلف المؤجلة');"
  ),
  '④ج deferredPayment'
);

/* ═══ ⑤ قفل الشهر وفتحه ═══ */
one(
  L(
    '    public function lockMonth(Request $request): JsonResponse',
    '    {',
    '        $actor = $request->actorOrFail();'
  ),
  L(
    '    public function lockMonth(Request $request): JsonResponse',
    '    {',
    '        $actor = $request->actorOrFail();',
    "        $this->need($this->aclOf($actor), 'act.lock', 'على قفل الشهر');"
  ),
  '⑤أ lockMonth'
);

one(
  L(
    '    public function unlockMonth(Request $request): JsonResponse',
    '    {',
    '        $request->actorOrFail();'
  ),
  L(
    '    public function unlockMonth(Request $request): JsonResponse',
    '    {',
    "        $this->need($this->aclOf($request->actorOrFail()), 'act.lock', 'على فتح الشهر');"
  ),
  '⑤ب unlockMonth'
);

/* ═══ ⑥ الإعدادات ═══ */
one(
  L(
    '    public function settingsSave(Request $request): JsonResponse',
    '    {',
    '        $request->actorOrFail();'
  ),
  L(
    '    public function settingsSave(Request $request): JsonResponse',
    '    {',
    "        $this->need($this->aclOf($request->actorOrFail()), 'act.settings', 'على إعدادات البرنامج');"
  ),
  '⑥ settingsSave'
);

/* ═══ ⑦ شاشة الصلاحيات — قراءة وحفظ (الأدمن بس) ═══ */
one(
  L(
    '    /* ═══════════════════════════════════════════════════════════',
    '       🔐 الصلاحيات',
    '    ═══════════════════════════════════════════════════════════ */'
  ),
  L(
    '    /* ═══════════════════════════════════════════════════════════',
    '       🔐 الصلاحيات',
    '    ═══════════════════════════════════════════════════════════ */',
    '',
    '    /**',
    '     * GET /api/pilot-accounting/acl — شاشة الصلاحيات (الأدمن بس).',
    '     *',
    '     * بترجّع تعريف المجموعات كمان مش المحفوظ بس: الواجهة بتبني',
    '     * الشاشة من الرد، فمفتاح جديد في الـWire بيظهر في الشاشة من',
    '     * غير أي تعديل في الـHTML — ومافيش فرصة إن الاتنين يختلفوا.',
    '     */',
    '    public function aclList(Request $request): JsonResponse',
    '    {',
    '        $actor = $request->actorOrFail();',
    "        if ($actor->role !== 'admin') {",
    "            throw ApiException::forbidden('شاشة الصلاحيات للإدارة بس');",
    '        }',
    '',
    '        $rows = [];',
    "        foreach (DB::select('SELECT user_id, perm_keys, branches, updated_by, updated_at FROM pilot_acct_perms') as $r) {",
    '            $r    = (array) $r;',
    '            $keys = [];',
    "            $d    = json_decode((string) ($r['perm_keys'] ?? ''), true);",
    '            if (is_array($d)) {',
    '                foreach ($d as $k => $v) {',
    '                    if ($v === true) {',
    '                        $keys[(string) $k] = true;',
    '                    }',
    '                }',
    '            }',
    '            $branches = [];',
    "            foreach (explode(',', (string) ($r['branches'] ?? '')) as $b) {",
    '                $b = (int) trim($b);',
    '                if ($b > 0) {',
    '                    $branches[] = $b;',
    '                }',
    '            }',
    "            $rows[(string) (int) $r['user_id']] = [",
    "                'keys'      => (object) $keys,",
    "                'branches'  => $branches,",
    "                'updatedBy' => $r['updated_by'],",
    "                'updatedAt' => $r['updated_at'] !== null ? WireTime::toWire((string) $r['updated_at']) : null,",
    '            ];',
    '        }',
    '',
    '        /* الأدمن مابيظهرش في القايمة — عنده كل حاجة دايمًا ومافيش',
    '           معنى إن صاحب النظام يقفل على نفسه بالغلط. */',
    '        $users = DB::select(',
    "            \"SELECT u.id, u.username, u.name, u.role, u.branch_id, b.name AS branch_name",
    '               FROM users u LEFT JOIN branches b ON b.id = u.branch_id',
    "              WHERE u.blocked = 0 AND u.role NOT IN ('admin', 'pilot', 'store', 'customer')",
    '              ORDER BY u.role, u.username"',
    '        );',
    '',
    '        return ApiResponse::out([',
    "            'ok'         => true,",
    "            'users'      => array_map(fn ($u): array => [",
    "                'id'         => (int) $u->id,",
    "                'username'   => $u->username,",
    "                'name'       => $u->name,",
    "                'role'       => $u->role,",
    "                'branchId'   => $u->branch_id !== null ? (int) $u->branch_id : null,",
    "                'branchName' => $u->branch_name,",
    '            ], $users),',
    "            'perms'      => (object) $rows,",
    "            'permGroups' => W::permGroups(),",
    "            'presets'    => W::permPresets(),",
    "            'branches'   => array_map(fn ($b): array => ['id' => (int) $b->id, 'name' => $b->name],",
    "                DB::select('SELECT id, name FROM branches ORDER BY name')),",
    '        ]);',
    '    }',
    '',
    '    /**',
    '     * PUT /api/pilot-accounting/acl — {userId, keys:{...}, branches:[ids]}',
    '     *',
    '     * ⚠️ القيمة `true` بس هي اللي بتتخزّن. المفتاح المقفول **بيختفي**',
    "     * مش بيتخزّن `false` — ده اللي بيخلّي `=== true` في `can()` كافية.",
    '     *',
    '     * والمفاتيح بتتفلتر على `W::permKeys()`: مفتاح مش معروف بيتترمي',
    '     * بدل ما يتخزّن ويفضل قاعد في الجدول بلا معنى.',
    '     */',
    '    public function aclSave(Request $request): JsonResponse',
    '    {',
    '        $actor = $request->actorOrFail();',
    "        if ($actor->role !== 'admin') {",
    "            throw ApiException::forbidden('شاشة الصلاحيات للإدارة بس');",
    '        }',
    '',
    '        $b   = $request->json()->all();',
    "        $uid = (int) ($b['userId'] ?? 0);",
    '        if ($uid <= 0) {',
    "            throw new ApiException('اختار المستخدم');",
    '        }',
    '',
    "        $u = DB::select('SELECT id, role FROM users WHERE id = ? LIMIT 1', [$uid])[0] ?? null;",
    '        if (! $u) {',
    "            throw ApiException::notFound('المستخدم غير موجود');",
    '        }',
    "        if ((string) $u->role === 'admin') {",
    "            throw new ApiException('الأدمن عنده كل الصلاحيات أصلًا');",
    '        }',
    '',
    '        $known = array_flip(W::permKeys());',
    '        $keys  = [];',
    "        foreach ((array) ($b['keys'] ?? []) as $k => $v) {",
    '            $k = (string) $k;',
    "            if (isset($known[$k]) && ($v === true || $v === 1 || $v === '1')) {",
    '                $keys[$k] = true;',
    '            }',
    '        }',
    '',
    '        $branches = [];',
    "        foreach ((array) ($b['branches'] ?? []) as $x) {",
    '            $n = (int) $x;',
    '            if ($n > 0) {',
    '                $branches[] = $n;',
    '            }',
    '        }',
    '        $branches = array_values(array_unique($branches));',
    '',
    '        DB::insert(',
    "            'INSERT INTO pilot_acct_perms (user_id, perm_keys, branches, updated_by)",
    '             VALUES (?, ?, ?, ?)',
    '             ON DUPLICATE KEY UPDATE perm_keys = VALUES(perm_keys),',
    "                 branches = VALUES(branches), updated_by = VALUES(updated_by)',",
    '            [$uid, json_encode((object) $keys, JSON_UNESCAPED_UNICODE),',
    "                $branches ? implode(',', $branches) : null, $actor->username]",
    '        );',
    '',
    "        return ApiResponse::out(['ok' => true, 'userId' => $uid,",
    "                                 'keys' => (object) $keys, 'branches' => $branches]);",
    '    }',
    '',
    '    /**',
    '     * DELETE /api/pilot-accounting/acl/{userId} — يرجّع للافتراضي.',
    '     *',
    '     * مسح الصف **مش** بيقفل عليه — بيرجّعه لـ«كل حاجة» زي ما هو',
    '     * دلوقتي. الشاشة بتقول ده صراحةً عشان محدش يفتكر إن المسح قفل.',
    '     */',
    '    public function aclReset(Request $request, string $userId): JsonResponse',
    '    {',
    '        $actor = $request->actorOrFail();',
    "        if ($actor->role !== 'admin') {",
    "            throw ApiException::forbidden('شاشة الصلاحيات للإدارة بس');",
    '        }',
    "        DB::delete('DELETE FROM pilot_acct_perms WHERE user_id = ?', [(int) $userId]);",
    '',
    "        return ApiResponse::out(['ok' => true]);",
    '    }'
  ),
  '⑦ نقط نهاية شاشة الصلاحيات'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ حرّاس الكتابة ونقط الشاشة اتحطوا');
