/* 📊 تفاصيل العهدة والأذونات في تقرير تقفيلة الوردية.
 *
 * ═══ الطلب (صاحب النظام 2026-09-01) ═══
 * «تفاصيل العهدة مع الطيار يجب أن تظهر في التقفيلة — استلم عهدة كام
 * وسلّم كام» + «والإذن بعد ما تعمله عايزه يظهر في تقفيلة الطيار».
 *
 * ═══ التصميم ═══
 * نقطة نهاية قراءة واحدة `GET /api/shifts/{id}/closeout-details` بتجمع
 * من السجلات الموجودة (مافيش عمود جديد):
 *   • حركات العهدة في نافذة الوردية (سجل custody_transactions):
 *     استلم (give) · ردّ (return — بما فيها ردّ التقفيلة) · فرق تسوية
 *     الأوردرات (order_pending/extra) — مع الإثبات المخزّن على الوردية
 *     (custody_returned/carried).
 *   • الأذونات المتقاطعة مع الوردية (pilot_leave_requests) بأوقاتها
 *     ومين وافق ومين أنهى.
 *   • عمولات التقفيلة المكتوبة لأوردرات الوردية.
 * والتقريرين (الفرع والإدارة) بيحقنوا القسمين بعد فتح المودال — التقرير
 * الأساسي بيظهر فورًا والتفاصيل بتوصل وراه، فمافيش بطء محسوس.
 *
 * 🔒 الحارس: ops/test_shift_report_details.php
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

const CTL = 'app/Http/Controllers/Api/BoardController.php';
const RT = 'routes/api.php';

if (load(CTL).includes('shiftCloseoutDetails')) { console.log('🔴 موجود قبل كده'); process.exit(1); }

/* ═══ ① نقطة النهاية — قبل applyShiftCommission ═══ */
one(CTL,
  L(
    '    /**',
    '     * 💰 عمولة تقفيلة الوردية — طلب صاحب النظام 2026-09-01:'
  ),
  L(
    '    /**',
    '     * GET /api/shifts/{id}/closeout-details — تفاصيل تقفيلة الوردية.',
    '     *',
    '     * طلب صاحب النظام 2026-09-01: «تفاصيل العهدة يجب أن تظهر في',
    '     * التقفيلة — استلم كام وسلّم كام» و«الإذن يظهر في تقفيلة الطيار».',
    '     * قراءة خالصة من السجلات الموجودة — مافيش كتابة ولا عمود جديد.',
    '     */',
    '    public function shiftCloseoutDetails(Request $request, string $id): JsonResponse',
    '    {',
    '        $actor   = $request->actorOrFail();',
    '        $shiftId = $this->intId($id);',
    '',
    "        $row = DB::select('SELECT * FROM shifts WHERE id = ?', [$shiftId])[0] ?? null;",
    '        if (! $row) {',
    "            throw ApiException::notFound('الوردية غير موجودة');",
    '        }',
    '        $shift = (array) $row;',
    "        if ($actor->role === 'branch' && (int) $shift['branch_id'] !== (int) ($actor->branchId ?? -1)) {",
    "            throw ApiException::forbidden('الوردية دي مش في فرعك');",
    '        }',
    '',
    "        $pilotId = (int) $shift['pilot_id'];",
    "        $from    = (string) $shift['started_at'];",
    "        $to      = $shift['ended_at'] !== null ? (string) $shift['ended_at'] : WireTime::nowDb();",
    '',
    '        /* حركات العهدة في نافذة الوردية — الأنواع الأربعة من السجل */',
    '        $rows = array_map(fn ($r) => (array) $r, DB::select(',
    "            'SELECT ct.type, ct.amount, ct.reason, ct.created_by, ct.created_at, cs.name AS store_name",
    '               FROM custody_transactions ct',
    '               LEFT JOIN cash_stores cs ON cs.id = ct.store_id',
    '              WHERE ct.pilot_id = ? AND ct.created_at BETWEEN ? AND ?',
    "              ORDER BY ct.id',",
    '            [$pilotId, $from, $to]',
    '        ));',
    '        $sum = fn (string $t): float => round(array_sum(array_map(',
    "            fn ($r) => $r['type'] === $t ? (float) $r['amount'] : 0.0, $rows)), 2);",
    '',
    '        /* الأذونات المتقاطعة مع الوردية — بتاعة الطيار في المدة دي */',
    '        $leaves = array_map(fn ($r) => (array) $r, DB::select(',
    "            \"SELECT type, reason, status, requested_at, responded_at, responded_by,",
    '                    ended_at, ended_by, forced_by',
    '               FROM pilot_leave_requests',
    "              WHERE pilot_id = ? AND status IN ('approved', 'ended')",
    '                AND requested_at <= ? AND (ended_at IS NULL OR ended_at >= ?)',
    '              ORDER BY id"',
    '            , [$pilotId, $to, $from]',
    '        ));',
    '',
    '        /* عمولات التقفيلة المكتوبة لأوردرات الوردية دي */',
    '        $comm = array_map(fn ($r) => (array) $r, DB::select(',
    "            'SELECT a.amount, a.reason, o.order_num",
    '               FROM pilot_commission_adjustments a',
    '               JOIN orders o ON o.id = a.order_id',
    "              WHERE o.shift_id = ? ORDER BY a.id',",
    '            [$shiftId]',
    '        ));',
    '',
    '        return ApiResponse::out([',
    "            'ok'      => true,",
    "            'custody' => [",
    '                /* استلم = تسليم عهدة له · ردّ = رجّع للخزنة (بما فيها',
    '                   ردّ التقفيلة) · التسوية = فرق تحصيل الأوردرات */',
    "                'given'         => \$sum('give'),",
    "                'returned'      => \$sum('return'),",
    "                'settledOn'     => \$sum('order_pending'),",
    "                'settledOff'    => \$sum('order_extra'),",
    "                'closeReturned' => round((float) (\$shift['custody_returned'] ?? 0), 2),",
    "                'closeCarried'  => round((float) (\$shift['custody_carried'] ?? 0), 2),",
    "                'rows'          => array_map(fn (\$r): array => [",
    "                    'type'      => \$r['type'],",
    "                    'amount'    => round((float) \$r['amount'], 2),",
    "                    'reason'    => \$r['reason'],",
    "                    'storeName' => \$r['store_name'],",
    "                    'by'        => \$r['created_by'],",
    "                    'at'        => WireTime::toWire(\$r['created_at']),",
    '                ], $rows),',
    '            ],',
    "            'leaves' => array_map(fn (\$r): array => [",
    "                'type'        => Vocab::LEAVE_TYPE_WIRE[\$r['type']] ?? \$r['type'],",
    "                'reason'      => \$r['reason'],",
    "                'status'      => \$r['status'],",
    "                'forced'      => \$r['forced_by'] !== null,",
    "                'approvedBy'  => \$r['responded_by'],",
    "                'from'        => WireTime::toWire(\$r['responded_at'] ?? \$r['requested_at']),",
    "                'to'          => WireTime::toWire(\$r['ended_at']),",
    "                'endedBy'     => \$r['ended_by'],",
    '            ], $leaves),',
    "            'commissions' => [",
    "                'count' => count(\$comm),",
    "                'total' => round(array_sum(array_map(fn (\$r) => (float) \$r['amount'], \$comm)), 2),",
    "                'rows'  => array_map(fn (\$r): array => [",
    "                    'orderNum' => \$r['order_num'],",
    "                    'amount'   => round((float) \$r['amount'], 2),",
    "                    'reason'   => \$r['reason'],",
    '                ], $comm),',
    '            ],',
    '        ]);',
    '    }',
    '',
    '    /**',
    '     * 💰 عمولة تقفيلة الوردية — طلب صاحب النظام 2026-09-01:'
  ),
  '① نقطة النهاية'
);

/* ═══ ② المسار ═══ */
one(RT,
  L(
    "Route::post('shifts/{id}/end', [BoardController::class, 'shiftEnd'])->middleware('role:admin,branch');"
  ),
  L(
    "Route::post('shifts/{id}/end', [BoardController::class, 'shiftEnd'])->middleware('role:admin,branch');",
    "/* 📊 تفاصيل التقفيلة (عهدة + أذونات + عمولات) — قراءة خالصة للتقرير */",
    "Route::get('shifts/{id}/closeout-details', [BoardController::class, 'shiftCloseoutDetails'])",
    "    ->middleware('role:admin,branch');"
  ),
  '② المسار'
);

/* ═══ ③ تسجيله في التغطية ═══ */
one('app/Console/Commands/RouteCoverage.php',
  "        'GET /api/pilot-accounting/staff-month' =>",
  L(
    "        'GET /api/shifts/{id}/closeout-details' =>",
    "            'تفاصيل تقفيلة الوردية للتقرير (طلب صاحب النظام 2026-09-01: تفاصيل العهدة — استلم كام وسلّم كام — والأذونات تظهر في التقفيلة) — قراءة خالصة من custody_transactions وpilot_leave_requests وعمولات التقفيلة',",
    '',
    "        'GET /api/pilot-accounting/staff-month' =>"
  ),
  '③ التغطية'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش ولا ملف'); process.exit(1); }
Object.keys(BUF).forEach(f => fs.writeFileSync(f, BUF[f]));
console.log('\n✅ نقطة التفاصيل اتعملت');
