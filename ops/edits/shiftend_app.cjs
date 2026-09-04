/* ⚠️ متنفّذ 2026-08-31 — سكربت لمرة واحدة على D:/dahshaneg/aldahshan،
   **اتنفّذ خلاص**. اتنقل هنا عشان سكربتات التعديل كلها في مكان واحد.
   بيشتغل من مجلد التطبيق مش من هنا. */
/* تطبيق الطيار — إزالة كل طريق بينهي الوردية.
 *
 * ═══ القرار (صاحب النظام 2026-08-30) ═══
 * «امنع الطيار إنه يخرج من الوردية — اللي يخرجه هو الفرع أو الإدارة».
 *
 * ═══ الأبواب اللي كانت مفتوحة ═══
 * ① زر «إنهاء الوردية» في القائمة الجانبية → _endShift()
 * ② **تسجيل الخروج** → كان بينده ShiftService.endShift كمان. ده كان
 *    الباب المخفي: الطيار يسجّل خروج فتتقفل ورديته من غير ما يقصد.
 *
 * ═══ اللي بيفضل ═══
 * endShiftLocalOnly() — دي مزامنة مش إنهاء: لما الفرع يقفل الوردية من
 * اللوحة، التطبيق بيكتشف ده وينضّف حالته المحلية بس. لازم تفضل.
 *
 * ═══ الخروج وهو في وردية ═══
 * بقى بينضّف الحالة المحلية بـendShiftLocalOnly() من غير ما يبلّغ السيرفر.
 * التنضيف ده مش تفصيلة: Session.clear() بتمسح اسم المستخدم بس، ومفاتيح
 * الوردية (shift_active وإخواتها) بتفضل في SharedPreferences — فلو طيار
 * تاني دخل على نفس التليفون كان هيلاقي نفسه في وردية الأول. والوردية على
 * السيرفر بتفضل مفتوحة، ولما يرجع يدخل _adoptRemoteShiftIfAny بتتبنّاها
 * وترجّعه لشاشة الطلبات على طول.
 *
 * ⚠️ ملاحظة تحريرية: BS و BT تحت مكتوبين بـfromCharCode بدل ما يتكتبوا
 * حرفيًا. السبب إن السكربت ده بيعدّي على أكتر من طبقة اقتباس قبل ما يوصل
 * القرص، وأول محاولة الباك-سلاش اتاكل فيها فاتكتب سطر حقيقي جوه نص Dart
 * وكسر الملف. الطريقة دي مامتأثرةش بأي طبقة هروب.
 */
const fs = require('fs');
const BS = String.fromCharCode(92);   // \
const BT = String.fromCharCode(96);   // `
const NL = BS + 'n';                  // هروب السطر زي ما هو مكتوب في Dart

let bad = 0;

/* main.dart بـCRLF — بنوحّد على LF جوه ونرجّع الأصلي عند الكتابة، عشان
   مانخلطش سطور LF جوه ملف CRLF. */
const edit = (file, old, neu, label) => {
  const raw  = fs.readFileSync(file, 'utf8');
  const crlf = raw.includes('\r\n');
  const s    = crlf ? raw.split('\r\n').join('\n') : raw;
  const n    = s.split(old).length - 1;
  if (n !== 1) { console.log(`  🔴 ${label}: اتلقت ${n} مرة`); bad++; return; }
  const out = s.replace(old, neu);
  fs.writeFileSync(file, crlf ? out.split('\n').join('\r\n') : out);
  console.log('  ✓ ' + label);
};

const M = 'lib/main.dart';

/* ───────── ① الخروج مابقاش بينهي الوردية ───────── */
edit(M,
`    /* هنا مابنمنعش الخروج لو الإقفال فشل — ممكن يكون في مكان مفيش فيه نت
       خالص وهيفضل حبيس. بس بنقوله بصراحة إن الوردية لسه مفتوحة عند
       الإدارة عشان يبلّغ الفرع بدل ما يكتشفوا في التقفيلة. */
    try {
      await ShiftService.endShift(widget.pilotId);
    } catch (_) {
      if (!mounted) return;
      final goOn = await _confirm(context, 'الوردية ما اتقفلتش',
          'مفيش نت، فالوردية لسه مفتوحة عند الإدارة والفرع.${NL}'
          'تخرج برضه؟ لو خرجت هتحتاج تبلّغ الفرع يقفلها.',
          K.orange, 'اخرج برضه');
      if (!goOn) return;
    }`,
`    /* الخروج **مابيقفلش الوردية** — الإنهاء بقى للفرع والإدارة بس.
       بننضّف الحالة المحلية بس عشان لو طيار تاني دخل على نفس التليفون
       مايلاقيش نفسه في وردية الأول (${BT}Session.clear()${BT} بتمسح اسم
       المستخدم بس، ومفاتيح الوردية مش منها). الوردية على السيرفر بتفضل
       مفتوحة، ولما يرجع يدخل ${BT}_adoptRemoteShiftIfAny${BT} بتتبنّاها
       وترجّعه لشاشة الطلبات. */
    await ShiftService.endShiftLocalOnly();`,
'تسجيل الخروج مابقاش ينهي الوردية');

/* ───────── ② الطيار يعرف إن ورديته هتفضل مفتوحة ───────── */
edit(M,
`    final ok = await _confirm(context, 'تسجيل الخروج', 'هل تريد الخروج من حسابك؟', K.red, 'خروج');`,
`    final ok = await _confirm(context, 'تسجيل الخروج',
        'هل تريد الخروج من حسابك؟${NL}'
        'ورديتك هتفضل مفتوحة — الفرع أو الإدارة هما اللي بيقفلوها.',
        K.red, 'خروج');`,
'رسالة الخروج بتوضّح إن الوردية بتفضل');

/* ───────── ③ زر «إنهاء الوردية» من القائمة ───────── */
edit(M,
`            // ── إنهاء الوردية ──
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
              child: ListTile(
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                tileColor: K.orange.withValues(alpha: .10),
                leading: Icon(Icons.stop_circle_outlined, color: K.orange, size: 20),
                title: Text('إنهاء الوردية',
                    style: TextStyle(color: K.orange, fontSize: 14, fontWeight: FontWeight.w600)),
                onTap: () { Navigator.pop(context); _endShift(); },
              ),
            ),
`,
`            /* زر «إنهاء الوردية» اتشال 2026-08-30 — الإنهاء بقى من الفرع
               أو الإدارة بس (قرار صاحب النظام). */
`,
'زر إنهاء الوردية من القائمة');

/* ───────── ④ الدالة _endShift نفسها ───────── */
edit(M,
`  /// إنهاء الوردية فقط (بدون خروج من الحساب)
  Future<void> _endShift() async {
    final ok = await _confirm(
      context,
      'إنهاء الوردية',
      'هل تريد إنهاء الوردية الحالية؟${NL}ستخرج من قائمة فرعك الحالي وتصبح متاحًا لفتح وردية في أي فرع آخر.${NL}لن تُحذف الطلبات المنجزة.',
      K.orange,
      'إنهاء الوردية',
    );
    if (!ok) return;
    /* لو الإقفال ماوصلش للسيرفر، مابنكملش. قبل كده الوردية كانت بتتقفل
       محليًا على طول وتفضل "active" عند الإدارة والفرع — فالطيار يفتكر
       إنه خلص، والإدارة شايفاه لسه شغّال، وساعات الوردية بتفضل بتعدّ. */
    try {
      await ShiftService.endShift(widget.pilotId);
    } catch (e) {
      if (!mounted) return;
      showErrorSnack(context, e,
          fallback: 'إنهاء الوردية ماوصلش للسيرفر — الوردية لسه مفتوحة، جرّب تاني');
      return;
    }
    if (!mounted) return;
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(
        builder: (_) => ShiftScreen(
          username: widget.username,
          name:     widget.name,
          pilotId:  widget.pilotId,
        ),
      ),
    );
  }

`,
``,
'الدالة _endShift');

/* ───────── ⑤ ShiftService.endShift — بقت يتيمة ───────── */
edit(M,
`  /// إنهاء الوردية الحالية — POST /api/pilot/shift/end بيقفل سجل الوردية
  /// ويحرّر الطيار بالكامل من فرعه ويزيح طابور الانتظار، كله في معاملة
  /// واحدة على السيرفر (نفس سلوك زر "إنهاء الوردية" في اللوحات بالضبط)
  static Future<void> endShift(String pilotId) async {
    await Api.endShift();
    final p = await SharedPreferences.getInstance();
    await p.setBool  (_keyActive,    false);
    await p.setString(_keyStartedAt, '');
    await p.setString(_keyShiftId,   '');
    await p.setStringList(_keyOrderIds, []);
    await p.setInt(_keyPausedSeconds, 0);
    await p.setString(_keyBreakStartedAt, '');
  }

`,
`  /* ${BT}endShift()${BT} اتشالت 2026-08-30 — الطيار مايقدرش ينهي ورديته.
     الباقي هو ${BT}endShiftLocalOnly()${BT} تحت، وهي **مزامنة** مش إنهاء:
     بتتندى لما الفرع يقفل الوردية من اللوحة. */

`,
'ShiftService.endShift');

/* ⑥ Api.endShift اتشالت في جولة سابقة (api_client.dart بـLF فماتأثرش) */

console.log(bad ? `\n🔴 ${bad} مشكلة` : '\n✅ التطبيق اتقفل');
process.exit(bad ? 1 : 0);
