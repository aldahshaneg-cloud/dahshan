/* 🔒💰 تقفيلة الوردية: إخلاء طرف بالعهدة + تحكم المشرف في العمولة.
 *
 * ═══ الطلب (صاحب النظام 2026-09-01 — ليلة أول يوم لايف) ═══
 * «عند عمل تقفيل للطيار في نهاية الوردية، في نفس التقفيلة إثبات إن
 * الطيار أخلى طرف بالعهدة اللي معاه وعودة العهدة إلى الخزنة. وإمكانية
 * عمل العمولة من قبل المشرف في التقفيلة: مبلغ ثابت أو نسبة في المية
 * لكل الأوردرات أو لكل أوردر على حدة (أوردر سفر له عمولة خاصة)».
 *
 * ═══ ① إخلاء الطرف ═══
 * قبل كده التقفيلة كانت بتحوّل أي فرق تحصيل **لعهدة** وتقفل عادي —
 * يعني الطيار يمشي والفلوس عليه ومافيش حاجة بتجبر حد يقفّلها. دلوقتي:
 *   • التقفيلة بتستقبل `custodyReturn: {amount, cashStoreId}` — ردّ
 *     عهدة جوه نفس المعاملة: عهدة الطيار بتنقص، والفلوس بتدخل الخزنة،
 *     والحركة بتتسجّل في سجل العهدة بنوع `return` (نفس عقد custodyCreate).
 *   • بعد الردّ: لو فضلت عهدة > صفر، **الوردية مابتتقفلش** لمشرف الفرع
 *     — رسالة بتقول الباقي كام. الإدارة بس تقدر تعدّي بعلم صريح
 *     (`allowCustodyCarry: true`) والباقي بيتسجّل على الوردية.
 *   • الإثبات بيتخزن على الوردية نفسها: `custody_returned` (اللي رجع)
 *     و`custody_carried` (اللي فضل — صفر = أخلى طرف).
 *
 * ═══ ② العمولة في التقفيلة ═══
 * مش نظام جديد — نفس سجل تعديلات العمولة الموجود (اللي التقفيلة
 * الشهرية بتقرا منه أصلًا عبر orderCommission). التقفيلة بتستقبل:
 *     commission: {mode, value?, perOrder?, reason?}
 *       mode: keep    = سيب حساب الطيار الافتراضي (مافيش كتابة)
 *             percent = نسبة % من سعر توصيل كل أوردر متسلّم في الوردية
 *             fixed   = مبلغ ثابت لكل أوردر متسلّم
 *             custom  = perOrder: [{orderId, amount}] — أوردر السفر بعمولته
 * وبتتكتب صفوف override لكل أوردر (upsert على قيد uq_pca_order) —
 * فالشهرية والتقارير بيشوفوها من غير أي معادلة جديدة.
 *
 * ═══ ليه جوه نفس المعاملة ═══
 * فلوس: لو العمولة اتكتبت والقفل فشل (أو العكس) يبقى نص تقفيلة. كله
 * بينجح مع بعض أو بيرجع مع بعض.
 *
 * 🔒 الحارس: ops/test_shift_closeout.php
 */
const fs = require('fs');
const F = 'app/Http/Controllers/Api/BoardController.php';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const L = (...x) => x.join('\n');
const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};

if (s.includes('applyShiftCommission')) { console.log('🔴 موجود قبل كده'); process.exit(1); }

/* ═══ ① جوه معاملة shiftEnd: بعد التسوية وقبل قفل الوردية ═══ */
one(
  L(
    '                    // 2) بنود التقفيلة على الوردية (لو اتبعتت) + القفل',
    '                    $settle = fn ($v, $cur) => in_array($v, [\'daily\', \'monthly\'], true) ? $v : $cur;'
  ),
  L(
    '                    /* 1.5) 🔒 إخلاء الطرف بالعهدة — طلب صاحب النظام:',
    '                       التقفيلة نفسها لازم تثبت إن العهدة رجعت الخزنة.',
    '                       بنقرا الرصيد **بعد** التسوية لأن فرق التحصيل لسه',
    '                       مضيفلها. */',
    '                    $custodyIn = $request->input(\'custodyReturn\');',
    '                    $retAmount = is_array($custodyIn) ? round((float) ($custodyIn[\'amount\'] ?? 0), 2) : 0.0;',
    '                    $retStore  = is_array($custodyIn) && ! empty($custodyIn[\'cashStoreId\'])',
    '                        ? $this->intId($custodyIn[\'cashStoreId\']) : null;',
    '                    if ($retAmount < 0) {',
    '                        throw new ApiException(\'مبلغ ردّ العهدة مينفعش يكون بالسالب\');',
    '                    }',
    '',
    '                    $balNow = round((float) (DB::select(',
    '                        \'SELECT custody_balance FROM pilots WHERE id = ?\',',
    '                        [(int) $pilot[\'id\']]',
    '                    )[0]->custody_balance ?? 0), 2);',
    '',
    '                    if ($retAmount > 0) {',
    '                        if ($retAmount > $balNow + 0.005) {',
    '                            throw new ApiException(\'ردّ العهدة (\' . $retAmount . \' ج.م) أكبر من اللي على الطيار (\' . $balNow . \' ج.م)\');',
    '                        }',
    '                        if (! $retStore) {',
    '                            throw new ApiException(\'اختر الخزنة اللي هترجع لها العهدة\');',
    '                        }',
    '                        /* نفس عقد ردّ العهدة اليدوي (custodyCreate type=return):',
    '                           الرصيد بينقص + صف في سجل العهدة + الفلوس تدخل الخزنة */',
    '                        DB::update(\'UPDATE pilots SET custody_balance = custody_balance - ? WHERE id = ?\',',
    '                            [$retAmount, (int) $pilot[\'id\']]);',
    '                        DB::insert(',
    '                            \'INSERT INTO custody_transactions (pilot_id, type, amount, reason, store_id, branch_id, created_by, created_at)',
    '                             VALUES (?,?,?,?,?,?,?,?)\',',
    '                            [(int) $pilot[\'id\'], \'return\', $retAmount,',
    '                             \'ردّ عهدة عند تقفيل الوردية — إخلاء طرف\',',
    '                             $retStore, $branchId, $actor->username, $now]',
    '                        );',
    '                        $this->applyCashTxn($retStore, \'in\', $retAmount,',
    '                            \'ردّ عهدة عند تقفيل الوردية: \' . $pilot[\'name\'],',
    '                            (int) $pilot[\'id\'], $branchId, $actor->username, $now);',
    '                        $balNow = round($balNow - $retAmount, 2);',
    '                    }',
    '',
    '                    /* 🔴 القاعدة: الوردية مابتتقفلش وعلى الطيار عهدة.',
    '                       الاستثناء الوحيد للإدارة وبعلم صريح — قرار إداري',
    '                       بيتسجّل على الوردية (custody_carried) عشان يبان',
    '                       في التقرير إن ده مش إخلاء طرف كامل. */',
    '                    if ($balNow > 0.005',
    '                        && ! ($actor->role === \'admin\' && $request->boolean(\'allowCustodyCarry\'))) {',
    '                        throw new ApiException(',
    '                            \'مينفعش تتقفل الوردية والطيار عليه عهدة \' . number_format($balNow, 2)',
    '                            . \' ج.م — سجّل ردّها للخزنة في خانة «ردّ العهدة»\'',
    '                            . ($actor->role === \'admin\' ? \' أو فعّل «قفل مع ترحيل العهدة»\' : \' أو كلّم الإدارة\')',
    '                        );',
    '                    }',
    '',
    '                    /* 1.6) 💰 عمولة التقفيلة — بتتكتب صفوف override في',
    '                       سجل تعديلات العمولة، فالتقفيلة الشهرية بتشوفها',
    '                       من نفس المسار الموجود من غير أي معادلة جديدة. */',
    '                    $this->applyShiftCommission($request, $shiftId, $pilot, $branchId, $actor, $now);',
    '',
    '                    // 2) بنود التقفيلة على الوردية (لو اتبعتت) + القفل',
    '                    $settle = fn ($v, $cur) => in_array($v, [\'daily\', \'monthly\'], true) ? $v : $cur;'
  ),
  '① إخلاء الطرف جوه المعاملة'
);

/* ═══ ② تسجيل الإثبات على صف الوردية ═══
   ②أ (الأعمدة في الاستعلام) اتطبقت يدوي بعد ما مرساتها وقعت في أول
   تشغيلة — المرساة الحرفية للسترنج المتهرّب جوه PHP اتلخبطت. الفحص هنا
   بيتأكد إنها موجودة فعلًا قبل ما نكمل، لأن ②ب من غيرها بتكسر العدّ. */
if (!s.includes('custody_returned = ?, custody_carried = ?,')) {
  console.log('  🔴 ②أ مش موجودة — طبّقها الأول'); process.exit(1);
}
console.log('  ✓ ②أ (متطبقة قبل كده)');

one(
  L(
    "                            \$settle(\$request->input('advanceSettle'), \$shift['advance_settle']),",
    '                            $now,',
    '                            $actor->username,',
    '                            $shiftId,'
  ),
  L(
    "                            \$settle(\$request->input('advanceSettle'), \$shift['advance_settle']),",
    '                            $retAmount,',
    '                            $balNow,',
    '                            $now,',
    '                            $actor->username,',
    '                            $shiftId,'
  ),
  '②ب قيم الإثبات'
);

/* ═══ ③ الرد بيرجّع تفاصيل الإخلاء للتقرير ═══ */
one(
  L(
    "                    return [\$deliveredCount, \$undeliveredCount, \$settledCount];",
    '                }',
    '            );'
  ),
  L(
    "                    return [\$deliveredCount, \$undeliveredCount, \$settledCount, \$retAmount, \$balNow];",
    '                }',
    '            );'
  ),
  '③أ إرجاع الأرقام'
);

one(
  "            [\$deliveredCount, \$undeliveredCount, \$settledCount] = DB::transaction(",
  "            [\$deliveredCount, \$undeliveredCount, \$settledCount, \$custodyReturned, \$custodyCarried] = DB::transaction(",
  '③ب استقبالها'
);

one(
  L(
    "        return ApiResponse::out([",
    "            'ok' => true,",
    "            'deliveredCount'   => \$deliveredCount,",
    "            'undeliveredCount' => \$undeliveredCount,",
    "            'settledCount'     => \$settledCount,",
    '        ]);'
  ),
  L(
    "        return ApiResponse::out([",
    "            'ok' => true,",
    "            'deliveredCount'   => \$deliveredCount,",
    "            'undeliveredCount' => \$undeliveredCount,",
    "            'settledCount'     => \$settledCount,",
    "            /* إثبات إخلاء الطرف — التقرير بيطبعه */",
    "            'custodyReturned'  => \$custodyReturned,",
    "            'custodyCarried'   => \$custodyCarried,",
    '        ]);'
  ),
  '③ج في الرد'
);

/* ═══ ④ دالة العمولة — قبل settlePilotMoney ═══ */
one(
  L(
    '    private function settlePilotMoney(',
    '        array $pilot,'
  ),
  L(
    '    /**',
    '     * 💰 عمولة تقفيلة الوردية — طلب صاحب النظام 2026-09-01:',
    '     * «المشرف يعمل العمولة في التقفيلة: ثابت أو نسبة لكل الأوردرات',
    '     * أو لكل أوردر على حدة (أوردر سفر له عمولة خاصة)».',
    '     *',
    '     * بتتكتب صفوف `override` في سجل تعديلات العمولة الموجود — نفس',
    '     * اللي التقفيلة الشهرية بتقرا منه (orderCommission). upsert على',
    '     * قيد `uq_pca_order` عشان إعادة التقفيلة ماتعملش صفين لأوردر.',
    '     * `effective_date` من `delivered_at` زي commissionAdjustmentSave',
    '     * بالحرف — عشان التعديل يقع في شهر التسليم مهما اتكتب إمتى.',
    '     *',
    '     * `mode=keep` (أو مافيش commission خالص) = مافيش أي كتابة —',
    '     * حساب الطيار الافتراضي شغال زي ما هو.',
    '     */',
    '    private function applyShiftCommission(',
    '        Request $request,',
    '        int $shiftId,',
    '        array $pilot,',
    '        ?int $branchId,',
    '        Actor $actor,',
    '        string $now,',
    '    ): void {',
    '        $c = $request->input(\'commission\');',
    '        if (! is_array($c)) {',
    '            return;',
    '        }',
    '        $mode = (string) ($c[\'mode\'] ?? \'keep\');',
    '        if ($mode === \'keep\') {',
    '            return;',
    '        }',
    '        if (! in_array($mode, [\'percent\', \'fixed\', \'custom\'], true)) {',
    '            throw new ApiException(\'نوع العمولة لازم يكون percent أو fixed أو custom\');',
    '        }',
    '',
    '        $value = round((float) ($c[\'value\'] ?? 0), 2);',
    '        if (in_array($mode, [\'percent\', \'fixed\'], true) && $value < 0) {',
    '            throw new ApiException(\'قيمة العمولة مينفعش تكون بالسالب\');',
    '        }',
    '        if ($mode === \'percent\' && $value > 100) {',
    '            throw new ApiException(\'النسبة مينفعش تعدّي 100%\');',
    '        }',
    '',
    '        $per = [];',
    '        if ($mode === \'custom\') {',
    '            foreach ((array) ($c[\'perOrder\'] ?? []) as $row) {',
    '                $oid = (int) ($row[\'orderId\'] ?? 0);',
    '                $amt = round((float) ($row[\'amount\'] ?? -1), 2);',
    '                if ($oid > 0 && $amt >= 0) {',
    '                    $per[$oid] = $amt;',
    '                }',
    '            }',
    '            if (! $per) {',
    '                return;   // تحديد يدوي من غير ولا أوردر = مافيش حاجة تتكتب',
    '            }',
    '        }',
    '',
    '        $reason = trim((string) ($c[\'reason\'] ?? \'\'));',
    '        if ($reason === \'\') {',
    '            $reason = $mode === \'percent\' ? (\'عمولة تقفيلة الوردية — نسبة \' . $value . \'%\')',
    '                : ($mode === \'fixed\' ? (\'عمولة تقفيلة الوردية — \' . $value . \' ج.م للأوردر\')',
    '                : \'عمولة تقفيلة الوردية — تحديد يدوي\');',
    '        }',
    '        $reason = mb_substr($reason, 0, 190);',
    '',
    '        /* أوردرات الوردية دي المتسلّمة — بعد ما التسوية خلّصت حالاتها.',
    '           بقفل، لأننا هنكتب فلوس بناءً على أسعارها. */',
    '        $orders = array_map(fn ($r) => (array) $r, DB::select(',
    '            "SELECT id, total_delivery_price, delivered_at, created_at',
    '               FROM orders WHERE shift_id = ? AND status = \'delivered\' FOR UPDATE",',
    '            [$shiftId]',
    '        ));',
    '',
    '        foreach ($orders as $o) {',
    '            $oid = (int) $o[\'id\'];',
    '            if ($mode === \'percent\') {',
    '                $amt = round(((float) $o[\'total_delivery_price\']) * $value / 100, 2);',
    '            } elseif ($mode === \'fixed\') {',
    '                $amt = $value;',
    '            } else {',
    '                if (! array_key_exists($oid, $per)) {',
    '                    continue;   // المشرف ماحددش للأوردر ده — بيفضل على حساب الطيار',
    '                }',
    '                $amt = $per[$oid];',
    '            }',
    '',
    '            $effective = substr((string) ($o[\'delivered_at\'] ?: $o[\'created_at\']), 0, 10);',
    '            DB::insert(',
    '                \'INSERT INTO pilot_commission_adjustments',
    '                   (pilot_id, order_id, kind, amount, reason, effective_date, branch_id, created_by, created_at)',
    '                 VALUES (?,?,?,?,?,?,?,?,?)',
    '                 ON DUPLICATE KEY UPDATE pilot_id = VALUES(pilot_id), amount = VALUES(amount),',
    '                     reason = VALUES(reason), effective_date = VALUES(effective_date),',
    '                     branch_id = VALUES(branch_id), created_by = VALUES(created_by), updated_at = VALUES(created_at)\',',
    '                [(int) $pilot[\'id\'], $oid, \'override\', $amt, $reason, $effective,',
    '                 $branchId, $actor->username, $now]',
    '            );',
    '        }',
    '    }',
    '',
    '    private function settlePilotMoney(',
    '        array $pilot,'
  ),
  '④ دالة العمولة'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ إخلاء الطرف والعمولة اتحطوا في تقفيلة الوردية');
