/* 🖨️ العهدة والأذونات في التقرير **المطبوع** (الفرع + الإدارة).
 *
 * ═══ الطلب (صاحب النظام 2026-09-01 مساءً) ═══
 * «التقرير الذي يُطبع يجب أن يظهر فيه مسألة العهدة وأيضًا الإذن وتفاصيله —
 * وليس شرطًا أن يكون إذنًا، ممكن أن يكون عقابًا — لأنه يتم عمله PDF
 * ويُرسل إلى الإدارة».
 *
 * الطباعة بتبني HTML مستقل عن المودال، فالحقن بتاع الشاشة ماكانش بيوصلها.
 * هنا: الطبع بقى بيستنى التفاصيل (من كاش المودال لو اتحملت، أو بنداء
 * واحد لو لأ) وبيطبع قسمين: العهدة (استلم/اتسوّى/سلّم + إثبات الإخلاء)
 * والأذونات — والإيقاف الإجباري من الإدارة/الفرع (العقاب) معلّم صراحةً.
 * لو النداء وقع، التقرير بيتطبع بسطر «التفاصيل غير متاحة» بدل ما الطبع
 * كله يقف.
 *
 * 🔒 الحارس: ops/test_shift_report_details.php (قسم الطباعة)
 */
const fs = require('fs');
let bad = 0;
const L = (...x) => x.join('\n');
const D = String.fromCharCode(36);
const B = String.fromCharCode(96);
const BUF = {};
const load = f => (BUF[f] !== undefined ? BUF[f] : (BUF[f] = fs.readFileSync(f, 'utf8')));
const one = (file, old, neu, label) => {
  const s = load(file);
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + file + ' — ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  BUF[file] = s.replace(old, neu);
  console.log('  ✓ ' + file + ' — ' + label);
};

if (load('public/branch.html').includes('_closeoutPrintSections')) { console.log('🔴 موجود قبل كده'); process.exit(1); }

const HELPERS = L(
  '    /* كاش تفاصيل التقفيلة — المودال بيملاه، والطبع بياخد منه أو بيجيب */',
  '    async function _closeoutDetailsFor(shiftId) {',
  '      const c = window._lastCloseoutDetails;',
  '      if (c && String(c.shiftId) === String(shiftId)) return c.data;',
  '      const d = await api.get(' + B + '/api/shifts/' + D + '{encodeURIComponent(shiftId)}/closeout-details' + B + ');',
  '      window._lastCloseoutDetails = { shiftId: String(shiftId), data: d };',
  '      return d;',
  '    }',
  '',
  '    /* قسما العهدة والأذونات بصيغة الطباعة (نفس كلاسات row/table بتاعة',
  '       صفحة الطبع). الإيقاف الإجباري (العقاب) معلّم صراحةً — التقرير',
  '       بيتبعت PDF للإدارة ولازم يبان فيه. */',
  '    function _closeoutPrintSections(d) {',
  '      if (!d) return ' + B + '<h2>🔒 العهدة والأذونات</h2><div class="row"><span>التفاصيل غير متاحة وقت الطباعة</span><span>—</span></div>' + B + ';',
  '      const c = d.custody || {};',
  '      const money = v => (Number(v) || 0).toFixed(2);',
  '      const typeLabel = { give: "استلم عهدة", "return": "ردّ للخزنة",',
  '        order_pending: "فرق تحصيل (عليه)", order_extra: "فرق تحصيل (له)" };',
  '      const lvLabel = { rest: "استراحة", dayoff: "عطلة / انصراف", incident: "حادث" };',
  '      const t = v => v ? new Date(v).toLocaleTimeString("ar-EG", { hour: "numeric", minute: "2-digit" }) : "—";',
  '',
  '      const custodyRows = (c.rows || []).map((r, i) => ' + B + '<tr>',
  '        <td>' + D + '{i + 1}</td><td>' + D + '{ esc(typeLabel[r.type] || r.type) }</td>',
  '        <td>' + D + '{ esc(money(r.amount)) } ج.م</td>',
  '        <td>' + D + '{ esc(r.storeName || "—") }</td>',
  '        <td>' + D + '{ esc(r.reason || "—") }</td>',
  '      </tr>' + B + ').join("");',
  '',
  '      const leaveRows = (d.leaves || []).map((lv, i) => {',
  '        const mins = lv.from && lv.to ? Math.max(0, Math.round((Date.parse(lv.to) - Date.parse(lv.from)) / 60000)) + " د" : "—";',
  '        return ' + B + '<tr>',
  '          <td>' + D + '{i + 1}</td>',
  '          <td>' + D + '{ esc(lvLabel[lv.type] || lv.type) }' + D + '{ lv.forced ? " — <b>إيقاف من الإدارة/الفرع</b>" : "" }</td>',
  '          <td>' + D + '{ esc(t(lv.from)) } → ' + D + '{ esc(lv.to ? t(lv.to) : "ساري") }</td>',
  '          <td>' + D + '{ esc(mins) }</td>',
  '          <td>' + D + '{ esc(lv.reason || "—") }</td>',
  '          <td>' + D + '{ esc(lv.approvedBy || "—") }</td>',
  '        </tr>' + B + ';',
  '      }).join("");',
  '',
  '      return ' + B + '',
  '        <h2>🔒 العهدة — إثبات إخلاء الطرف</h2>',
  '        <div class="row"><span>استلم عهدة خلال الوردية</span><span>' + D + '{ esc(money(c.given)) } ج.م</span></div>',
  '        <div class="row"><span>اتسوّى عليه من الأوردرات</span><span>' + D + '{ esc(money(c.settledOn)) } ج.م</span></div>',
  '        <div class="row"><span>سلّم (رجّع للخزنة)</span><span>' + D + '{ esc(money(c.returned)) } ج.م</span></div>',
  '        <div class="row"><span>منها عند التقفيلة</span><span>' + D + '{ esc(money(c.closeReturned)) } ج.م</span></div>',
  '        <div class="row" style="font-weight:800"><span>الباقي عليه بعد القفل</span><span>' + D + '{ Number(c.closeCarried) > 0 ? esc(money(c.closeCarried)) + " ج.م (بموافقة الإدارة)" : "صفر — أخلى طرف ✓" }</span></div>',
  '        ' + D + '{ custodyRows ? ' + B + '<table><thead><tr><th>#</th><th>الحركة</th><th>المبلغ</th><th>الخزنة</th><th>السبب</th></tr></thead><tbody>' + D + '{custodyRows}</tbody></table>' + B + ' : "" }',
  '        ' + D + '{ leaveRows ? ' + B + '',
  '        <h2>🕐 الأذونات والإيقافات خلال الوردية</h2>',
  '        <table><thead><tr><th>#</th><th>النوع</th><th>من → إلى</th><th>المدة</th><th>السبب</th><th>وافق/أوقف</th></tr></thead><tbody>' + D + '{leaveRows}</tbody></table>' + B + ' : "" }' + B + ';',
  '    }');

for (const F of ['public/branch.html', 'public/tiar.html']) {
  /* ① المودال بيملى الكاش */
  one(F,
    "      catch (e) {\n        slot.innerHTML = `<div style=\"font-size:.78rem;color:var(--muted);padding:6px 0\">تعذّر تحميل تفاصيل العهدة والأذونات: ${ esc(e.message || \"\") }</div>`;\n        return;\n      }",
    L(
      '      catch (e) {',
      '        slot.innerHTML = `<div style="font-size:.78rem;color:var(--muted);padding:6px 0">تعذّر تحميل تفاصيل العهدة والأذونات: ${ esc(e.message || "") }</div>`;',
      '        return;',
      '      }',
      '      /* الطبع بياخد من الكاش ده بدل نداء تاني */',
      '      window._lastCloseoutDetails = { shiftId: String(shiftId), data: d };'
    ),
    '① الكاش'
  );

  /* ② الطبع بقى async وبيستنى التفاصيل */
  one(F,
    L(
      '    window.printShiftReport = function() {',
      '      const data = window._lastShiftReportData;',
      '      if (!data) return;',
      '      const { shift, pilot } = data;'
    ),
    L(
      '    window.printShiftReport = async function() {',
      '      const data = window._lastShiftReportData;',
      '      if (!data) return;',
      '      const { shift, pilot } = data;',
      '      /* التقرير بيتبعت PDF للإدارة — العهدة والأذونات لازم يكونوا فيه.',
      '         لو التفاصيل لسه ماتحملتش في المودال بنجيبها هنا، ولو النداء',
      '         وقع بنطبع بسطر «غير متاحة» بدل ما الطبع كله يقف. */',
      '      let _cd = null;',
      '      try { _cd = await _closeoutDetailsFor(shift.id); } catch (_) {}'
    ),
    '② الطبع async'
  );

  /* ③ الأقسام في صفحة الطبع — قبل تفاصيل الطلبات */
  one(F,
    '        <h2>📋 تفاصيل الطلبات</h2>',
    L(
      '        ${_closeoutPrintSections(_cd)}',
      '        <h2>📋 تفاصيل الطلبات</h2>'
    ),
    '③ الأقسام في الطبع'
  );

  /* ④ الدوال — قبل printShiftReport */
  one(F,
    '    window.printShiftReport = async function() {',
    L(HELPERS, '', '    window.printShiftReport = async function() {'),
    '④ الدوال'
  );
}

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش ولا ملف'); process.exit(1); }
Object.keys(BUF).forEach(f => fs.writeFileSync(f, BUF[f]));
console.log('\n✅ المطبوع بقى فيه العهدة والأذونات');
