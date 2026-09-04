/* 📊 حقن تفاصيل العهدة والأذونات في تقرير التقفيلة (الفرع + الإدارة).
 *
 * التقرير الأساسي بيظهر فورًا زي ما هو، وبعد فتح المودال بنجيب
 * `/api/shifts/{id}/closeout-details` ونحقن تلات كروت في مكان محجوز:
 *   🔒 العهدة: استلم كام (give) · اتسوّى عليه كام (فرق الأوردرات) ·
 *      سلّم كام (return) · وإثبات التقفيلة (رجّع X والباقي Y).
 *   🕐 الأذونات: كل إذن اتاخد جوه الوردية — نوعه ومدته ومين وافق.
 *   💰 عمولات التقفيلة لو المشرف كتب منها.
 * لو النداء وقع، المكان المحجوز بيقول «تعذّر التحميل» — التقرير نفسه
 * مش بيتأثر.
 *
 * نفس الكود بالحرف في الملفين — الأسماء العامة (esc/fmtShiftDT/api)
 * موجودة في الاتنين بنفس العقد.
 *
 * 🔒 الحارس: ops/test_shift_report_details.php (قسم الواجهة)
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

const LEAVE_LABEL = L(
  '    const _crLeaveLabel = { rest: "استراحة", dayoff: "عطلة / انصراف", incident: "حادث" };',
  '    const _crCustodyLabel = {',
  '      give: "استلم عهدة", "return": "ردّ للخزنة",',
  '      order_pending: "فرق تحصيل (عليه)", order_extra: "فرق تحصيل (له)",',
  '    };',
  '    /**',
  '     * بيجيب تفاصيل التقفيلة وبيحقنها في التقرير المفتوح.',
  '     * التقرير الأساسي مش مستنيها — لو النداء وقع بيبان سطر خطأ مكانها.',
  '     */',
  '    async function _loadShiftCloseoutDetails(shiftId) {',
  '      const slot = document.getElementById("_srCloseoutDetails");',
  '      if (!slot) return;',
  '      let d;',
  '      try { d = await api.get(' + B + '/api/shifts/' + D + '{encodeURIComponent(shiftId)}/closeout-details' + B + '); }',
  '      catch (e) {',
  '        slot.innerHTML = ' + B + '<div style="font-size:.78rem;color:var(--muted);padding:6px 0">تعذّر تحميل تفاصيل العهدة والأذونات: ' + D + '{ esc(e.message || "") }</div>' + B + ';',
  '        return;',
  '      }',
  '      const c = d.custody || {};',
  '      const money = v => (Number(v) || 0).toFixed(2);',
  '      const custodyRows = (c.rows || []).map(r => ' + B + '',
  '        <div style="display:flex;justify-content:space-between;gap:8px;font-size:.76rem;padding:4px 0;border-bottom:1px dashed var(--border)">',
  '          <span>' + D + '{ esc(_crCustodyLabel[r.type] || r.type) }' + D + '{ r.storeName ? " ← " + esc(r.storeName) : "" }' + D + '{ r.reason ? ' + B + '<span style="color:var(--muted)"> — ' + D + '{ esc(r.reason) }</span>' + B + ' : "" }</span>',
  '          <b style="white-space:nowrap">' + D + '{ esc(money(r.amount)) } ج</b>',
  '        </div>' + B + ').join("");',
  '',
  '      const leaves = (d.leaves || []).map(lv => {',
  '        const fromT = lv.from ? new Date(lv.from).toLocaleTimeString("ar-EG", { hour: "numeric", minute: "2-digit" }) : "—";',
  '        const toT = lv.to ? new Date(lv.to).toLocaleTimeString("ar-EG", { hour: "numeric", minute: "2-digit" }) : "لسه ساري";',
  '        const mins = lv.from && lv.to ? Math.max(0, Math.round((Date.parse(lv.to) - Date.parse(lv.from)) / 60000)) : null;',
  '        return ' + B + '<div style="display:flex;justify-content:space-between;gap:8px;font-size:.76rem;padding:4px 0;border-bottom:1px dashed var(--border)">',
  '          <span>' + D + '{ esc(_crLeaveLabel[lv.type] || lv.type) }' + D + '{ lv.forced ? " (إيقاف من الإدارة)" : "" }' + D + '{ lv.reason ? ' + B + '<span style="color:var(--muted)"> — ' + D + '{ esc(lv.reason) }</span>' + B + ' : "" }',
  '            <span style="color:var(--muted)"> · وافق: ' + D + '{ esc(lv.approvedBy || "—") }</span></span>',
  '          <b style="white-space:nowrap">' + D + '{ esc(fromT) } → ' + D + '{ esc(toT) }' + D + '{ mins !== null ? " (" + mins + " د)" : "" }</b>',
  '        </div>' + B + ';',
  '      }).join("");',
  '',
  '      const comm = d.commissions || {};',
  '      slot.innerHTML = ' + B + '',
  '        <div class="od-card" style="margin-top:14px;border:2px solid var(--red)">',
  '          <div class="od-card-title">🔒 العهدة خلال الوردية — إثبات إخلاء الطرف</div>',
  '          <div class="od-info-block"><span class="od-info-label">استلم عهدة</span><span class="od-info-val">' + D + '{ esc(money(c.given)) } ج.م</span></div>',
  '          <div class="od-info-block" style="margin-top:6px"><span class="od-info-label">اتسوّى عليه من الأوردرات</span><span class="od-info-val" style="color:var(--orange)">' + D + '{ esc(money(c.settledOn)) } ج.م</span></div>',
  '          <div class="od-info-block" style="margin-top:6px"><span class="od-info-label">سلّم (رجّع للخزنة)</span><span class="od-info-val" style="color:var(--green);font-weight:800">' + D + '{ esc(money(c.returned)) } ج.م</span></div>',
  '          <div class="od-info-block" style="margin-top:6px"><span class="od-info-label">منها عند التقفيلة</span><span class="od-info-val">' + D + '{ esc(money(c.closeReturned)) } ج.م</span></div>',
  '          <div class="od-info-block" style="margin-top:6px"><span class="od-info-label">الباقي عليه بعد القفل</span><span class="od-info-val" style="color:' + D + '{ Number(c.closeCarried) > 0 ? "var(--red)" : "var(--green)" };font-weight:800">' + D + '{ Number(c.closeCarried) > 0 ? esc(money(c.closeCarried)) + " ج.م (بموافقة الإدارة)" : "صفر — أخلى طرف ✓" }</span></div>',
  '          ' + D + '{ custodyRows ? ' + B + '<div style="margin-top:10px;padding-top:8px;border-top:1px solid var(--border)"><div style="font-size:.74rem;color:var(--muted);margin-bottom:4px">حركات النافذة دي:</div>' + D + '{custodyRows}</div>' + B + ' : "" }',
  '        </div>',
  '        ' + D + '{ leaves ? ' + B + '',
  '        <div class="od-card" style="margin-top:14px">',
  '          <div class="od-card-title">🕐 أذونات خلال الوردية</div>',
  '          ' + D + '{leaves}',
  '        </div>' + B + ' : "" }',
  '        ' + D + '{ (comm.count || 0) > 0 ? ' + B + '',
  '        <div class="od-card" style="margin-top:14px">',
  '          <div class="od-card-title">💰 عمولات مكتوبة من التقفيلة (' + D + '{ esc(comm.count) })</div>',
  '          ' + D + '{ (comm.rows || []).map(r => ' + B + '<div style="display:flex;justify-content:space-between;font-size:.76rem;padding:3px 0"><span>' + D + '{ esc(r.orderNum || "—") }' + D + '{ r.reason ? ' + B + '<span style="color:var(--muted)"> — ' + D + '{ esc(r.reason) }</span>' + B + ' : "" }</span><b>' + D + '{ esc(money(r.amount)) } ج</b></div>' + B + ').join("") }',
  '          <div style="display:flex;justify-content:space-between;font-size:.8rem;font-weight:800;padding-top:6px;border-top:1px solid var(--border)"><span>الإجمالي</span><span>' + D + '{ esc(money(comm.total)) } ج.م</span></div>',
  '        </div>' + B + ' : "" }' + B + ';',
  '    }'
);

for (const F of ['public/branch.html', 'public/tiar.html']) {
  /* ① المكان المحجوز — بعد كارت وقت الوردية مباشرة */
  one(F,
    L(
      '          <div class="od-card" style="margin-top:14px">',
      '            <div class="od-card-title">📦 عدد الطلبات خلال الوردية</div>'
    ),
    L(
      '          <!-- 📊 تفاصيل العهدة والأذونات — بتتحقن بعد فتح المودال -->',
      '          <div id="_srCloseoutDetails"><div style="font-size:.76rem;color:var(--muted);padding:4px 0">جاري تحميل تفاصيل العهدة والأذونات…</div></div>',
      '          <div class="od-card" style="margin-top:14px">',
      '            <div class="od-card-title">📦 عدد الطلبات خلال الوردية</div>'
    ),
    '① المكان المحجوز'
  );

  /* ② النداء بعد الفتح + الدوال */
  one(F,
    L(
      '        </div>`;',
      '      document.body.appendChild(box);',
      '    };',
      '',
      '    /* ── تطبيق وحفظ بونص/خصم إضافي على وردية الطيار ────────────────────'
    ),
    L(
      '        </div>`;',
      '      document.body.appendChild(box);',
      '      /* التفاصيل بتتحمّل بعد الفتح — التقرير مش مستنيها */',
      '      _loadShiftCloseoutDetails(shift.id);',
      '    };',
      '',
      LEAVE_LABEL,
      '',
      '    /* ── تطبيق وحفظ بونص/خصم إضافي على وردية الطيار ────────────────────'
    ),
    '② النداء والدوال'
  );
}

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش ولا ملف'); process.exit(1); }
Object.keys(BUF).forEach(f => fs.writeFileSync(f, BUF[f]));
console.log('\n✅ التقريرين اتحدثوا');
