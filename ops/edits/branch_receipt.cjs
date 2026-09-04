/* بديل مؤقت لطيار من غير تطبيق (معاه أيفون) — تطبيق الفرع، 2026-08-31.
 *
 * ═══ القصة ═══
 * الإطلاق 2026-09-02 وتطبيق الطيار أندرويد بس. قرار صاحب النظام: الطيار
 * اللي معاه أيفون ياخد بيانات الأوردر بطريقتين بدل التطبيق:
 *   ١) رسالة واتساب جاهزة من الفرع فيها كل تفاصيل الأوردر.
 *   ٢) ريسيت مطبوع من الفرع يمشي بيه.
 *
 * ═══ اللي بيتضاف ═══
 *   • `_orderWhatsAppText(o)`            — نص الرسالة (مشترك).
 *   • `window.sendOrderWhatsAppToPilot`  — wa.me على phone1 بتاع طيار الأوردر.
 *   • `window.printOrderReceipt`         — نافذة طباعة بمقاس ريسيت.
 *   • زرارين في صف «جاري التوصيل» (فيه طيار مسند) + زرار ريسيت في صف
 *     «قيد التنفيذ» (لسه مافيش طيار → واتساب مالوش معنى هناك).
 *
 * ═══ على مين بنيت ═══
 * نفس أنماط الملف الموجودة حرفيًا:
 *   • `_toWhatsAppNumber` + `wa.me` (زي sendShiftReportWhatsApp).
 *   • `window.open("") + document.write + window.print()` (زي printShiftReport).
 *   • البحث عن الأوردر في `_ordersData` ثم `_allOrdersData` (زي viewOrderDetails
 *     — عشان أوردر جاي مع طيار دعم من فرع تاني).
 *   • تسمية «عهدة» زي عمود الجدول بالظبط — مش بنفسّر معنى الفلوس.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/branch.html');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');
const ORIGINAL = s;

/* ══ ١) الدوال الجديدة — بتتحط بعد sendShiftReportWhatsApp على طول ══ */

const FNS = `
    /* ── بيانات الأوردر للطيار اللي من غير تطبيق (أيفون) ────────────────
       بديل مؤقت لحد ما نسخة iOS تتبني: الفرع بيبعت الأوردر واتساب أو
       بيطبعه ريسيت. النص واحد في الاتنين عشان مايحصلش اختلاف.
       «عهدة» هنا بنفس معنى عمود «عهدة» في الجدول (orderPrice جوه الطرد،
       وstorePrepaid مجموعهم) — من غير أي تفسير إضافي. 2026-08-31 ────── */
    function _orderWhatsAppText(o) {
      const ds = o.deliveries || [];
      const L = [
        \`🛵 أوردر — الدهشان\`,
        \`📦 رقم الأوردر: \${o.orderNum || o.id || "—"}\`,
      ];
      if (o.branchName) L.push(\`🏢 الفرع: \${o.branchName}\`);
      L.push(\`\`, \`⬆️ الاستلام من: \${o.senderName || "—"}\`);
      if (o.senderPhone)   L.push(\`📞 \${o.senderPhone}\`);
      if (o.senderPhone2)  L.push(\`📞 \${o.senderPhone2}\`);
      if (o.senderAddress) L.push(\`📍 \${o.senderAddress}\`);
      L.push(\`\`, \`⬇️ التسليم (\${ds.length}):\`);
      ds.forEach((d, i) => {
        L.push(\`\${i + 1}) \${d.receiverName || "—"}\`);
        if (d.receiverPhone)  L.push(\`   📞 \${d.receiverPhone}\`);
        if (d.receiverPhone2) L.push(\`   📞 \${d.receiverPhone2}\`);
        L.push(\`   📍 \${d.zoneName || "—"}\${d.address ? " — " + d.address : ""}\`);
        L.push(\`   🚚 التوصيل: \${Number(d.zonePrice) || 0} ج.م\`);
        if (Number(d.orderPrice) > 0) L.push(\`   💵 عهدة: \${Number(d.orderPrice)} ج.م\`);
      });
      L.push(\`\`, \`💰 إجمالي التوصيل: \${Number(o.totalDeliveryPrice) || 0} ج.م\`);
      if (Number(o.storePrepaid) > 0)
        L.push(\`💵 إجمالي العهدة: \${Number(o.storePrepaid)} ج.م\${o.storePrepaidNote ? " — " + o.storePrepaidNote : ""}\`);
      if (o.notes) L.push(\`📝 ملاحظات: \${o.notes}\`);
      return L.join("\\n");
    }

    /* نفس أسلوب البحث بتاع viewOrderDetails: فرعنا الأول وبعدين الكل —
       عشان أوردر واصل مع طيار دعم من فرع تاني */
    function _findOrderAnywhere(orderId) {
      return (window._ordersData || []).find(x => x.id === orderId)
          || (window._allOrdersData || []).find(x => x.id === orderId);
    }

    window.sendOrderWhatsAppToPilot = function(orderId) {
      const o = _findOrderAnywhere(orderId);
      if (!o) { showToast("تعذّر العثور على الأوردر", "error"); return; }
      const pilot = (window._allPilotsData || window._pilotsData || []).find(p => p.id === o.pilotId);
      const waNumber = _toWhatsAppNumber(pilot && pilot.phone1);
      if (!waNumber) { showToast("لا يوجد رقم هاتف مسجل لطيار هذا الأوردر", "error"); return; }
      window.open(\`https://wa.me/\${waNumber}?text=\${encodeURIComponent(_orderWhatsAppText(o))}\`, "_blank");
    };

    window.printOrderReceipt = function(orderId) {
      const o = _findOrderAnywhere(orderId);
      if (!o) { showToast("تعذّر العثور على الأوردر", "error"); return; }
      const pilot = (window._allPilotsData || window._pilotsData || []).find(p => p.id === o.pilotId);
      const ds = o.deliveries || [];
      const row = (lbl, val) => \`<div class="row"><span>\${lbl}</span><b>\${val}</b></div>\`;
      const html = \`
        <html dir="rtl" lang="ar"><head><meta charset="UTF-8"><title>ريسيت \${ esc(o.orderNum || "") }</title>
        <style>
          /* عرض ٨٠مم لطابعات الريسيت — وبيطبع كويس على A4 برضه */
          body{font-family:Tahoma,Arial,sans-serif;color:#000;margin:0 auto;padding:8px;max-width:300px;font-size:13px}
          h1{font-size:16px;text-align:center;margin:0 0 2px}
          .sub{text-align:center;font-size:11px;color:#444;margin-bottom:6px}
          .num{text-align:center;font-size:15px;font-weight:800;border:2px solid #000;border-radius:6px;padding:4px;margin-bottom:6px}
          .sec{border-top:1px dashed #000;margin-top:6px;padding-top:5px}
          .sec h2{font-size:12px;margin:0 0 3px}
          .row{display:flex;justify-content:space-between;gap:8px;padding:1px 0}
          .d{border:1px solid #aaa;border-radius:6px;padding:4px 6px;margin-top:4px}
          .total{border-top:2px solid #000;margin-top:6px;padding-top:5px;font-size:14px}
          .notes{background:#eee;border-radius:6px;padding:5px;margin-top:6px;white-space:pre-wrap}
          .foot{text-align:center;font-size:10px;color:#555;margin-top:8px}
        </style></head><body>
        <h1>🛵 الدهشان</h1>
        <div class="sub">\${ esc(o.branchName || "") }</div>
        <div class="num">\${ esc(o.orderNum || o.id || "—") }</div>
        \${pilot ? row("الطيار", esc(pilot.name || "—")) : (o.pilotName ? row("الطيار", esc(o.pilotName)) : "")}
        \${row("التاريخ", esc(o.createdAt ? new Date(o.createdAt).toLocaleString("ar-EG", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" }) : "—"))}
        <div class="sec"><h2>⬆️ الاستلام من</h2>
          \${row("الاسم", esc(o.senderName || "—"))}
          \${o.senderPhone ? row("📞", \`<span dir="ltr">\${ esc(o.senderPhone) }</span>\`) : ""}
          \${o.senderPhone2 ? row("📞", \`<span dir="ltr">\${ esc(o.senderPhone2) }</span>\`) : ""}
          \${o.senderAddress ? \`<div>📍 \${ esc(o.senderAddress) }</div>\` : ""}
        </div>
        <div class="sec"><h2>⬇️ التسليم (\${ds.length})</h2>
          \${ds.map((d, i) => \`<div class="d">
            \${row((i + 1) + ") " + esc(d.receiverName || "—"), "")}
            \${d.receiverPhone ? row("📞", \`<span dir="ltr">\${ esc(d.receiverPhone) }</span>\`) : ""}
            \${d.receiverPhone2 ? row("📞", \`<span dir="ltr">\${ esc(d.receiverPhone2) }</span>\`) : ""}
            <div>📍 \${ esc(d.zoneName || "—") }\${ esc(d.address ? " — " + d.address : "") }</div>
            \${row("🚚 التوصيل", esc(Number(d.zonePrice) || 0) + " ج.م")}
            \${Number(d.orderPrice) > 0 ? row("💵 عهدة", esc(Number(d.orderPrice)) + " ج.م") : ""}
          </div>\`).join("")}
        </div>
        <div class="total">
          \${row("💰 إجمالي التوصيل", esc(Number(o.totalDeliveryPrice) || 0) + " ج.م")}
          \${Number(o.storePrepaid) > 0 ? row("💵 إجمالي العهدة", esc(Number(o.storePrepaid)) + " ج.م") : ""}
        </div>
        \${o.notes ? \`<div class="notes">📝 \${ esc(o.notes) }</div>\` : ""}
        <div class="foot">اتطبع من الفرع — \${new Date().toLocaleString("ar-EG", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" })}</div>
        <script>window.onload = () => { window.print(); };<\\/script>
        </body></html>\`;
      const w = window.open("", "_blank");
      if (!w) { showToast("يرجى السماح بالنوافذ المنبثقة لطباعة الريسيت", "error"); return; }
      w.document.write(html);
      w.document.close();
    };
`;

const EDITS = [
  {
    label: 'الدوال التلاتة بعد sendShiftReportWhatsApp',
    old: '      window.open(`https://wa.me/${waNumber}?text=${text}`, "_blank");\n    };\n',
    neu: '      window.open(`https://wa.me/${waNumber}?text=${text}`, "_blank");\n    };\n' + FNS,
  },
  {
    /* صف «جاري التوصيل»: فيه طيار → واتساب + ريسيت.
       المرساة لازم تمسك جدول «جاري التوصيل» بس — «تفاصيل + transfer-btn»
       لوحدها بتطابق كمان جدول «لم يتم التوصيل» (retryDeliveryAssign)،
       فبنخصّصها بذيل transferOrderPilot الفريد. */
    label: 'زرارين في صف جاري التوصيل',
    old: `              <button class="view-btn" onclick="viewOrderDetails('\${ escJs(o.id) }')">🔍 تفاصيل</button>
              <button class="transfer-btn" style="background:rgba(249,115,22,.15);color:var(--orange);border:1px solid var(--orange)" onclick="transferOrderPilot(`,
    neu: `              <button class="view-btn" onclick="viewOrderDetails('\${ escJs(o.id) }')">🔍 تفاصيل</button>
              <button class="view-btn" style="background:rgba(37,211,102,.15);color:#25D366;border:1px solid #25D366" onclick="sendOrderWhatsAppToPilot('\${ escJs(o.id) }')">💬 واتساب للطيار</button>
              <button class="view-btn" onclick="printOrderReceipt('\${ escJs(o.id) }')">🖨️ ريسيت</button>
              <button class="transfer-btn" style="background:rgba(249,115,22,.15);color:var(--orange);border:1px solid var(--orange)" onclick="transferOrderPilot(`,
  },
  {
    /* صف «قيد التنفيذ»: لسه مافيش طيار → ريسيت بس */
    label: 'زرار ريسيت في صف قيد التنفيذ',
    old: `              <button class="view-btn" onclick="viewOrderDetails('\${ escJs(o.id) }')">🔍 تفاصيل</button>
              \${o.status === "مؤجل"`,
    neu: `              <button class="view-btn" onclick="viewOrderDetails('\${ escJs(o.id) }')">🔍 تفاصيل</button>
              <button class="view-btn" onclick="printOrderReceipt('\${ escJs(o.id) }')">🖨️ ريسيت</button>
              \${o.status === "مؤجل"`,
  },
];

/* ══ فحص قبل أي كتابة ══ */
let bad = 0;
for (const e of EDITS) {
  const n = s.split(e.old).length - 1;
  if (n !== 1) { bad++; console.log('✗ «' + e.label + '»: متوقّع ١ لقى ' + n); }
}
/* الأسماء الجديدة مايكونوش موجودين أصلاً (إعادة تشغيل بالغلط = مضاعفة) */
for (const n of ['_orderWhatsAppText', 'sendOrderWhatsAppToPilot', 'printOrderReceipt', '_findOrderAnywhere']) {
  if (s.includes(n)) { bad++; console.log('✗ «' + n + '» موجود قبل كده — السكريبت اتشغّل مرتين؟'); }
}
if (bad) { console.log('\n⛔ مافيش بايت اتكتب.'); process.exit(1); }

for (const e of EDITS) { s = s.split(e.old).join(e.neu); console.log('  ✓ ' + e.label); }

/* ══ فحوص بعدية — قبل الكتابة ══ */
const after = [];

/* كل كتل الجافاسكربت لسه سليمة */
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, broken = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'brcpt-' + process.pid + '-' + i + '.mjs');
    try { fs.writeFileSync(tmp, m[2]); execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' }); }
    catch (e2) { broken++; console.log('  ✗ كتلة ' + i + ': ' + ((e2.stderr || '').toString().match(/SyntaxError:.*/) || ['?'])[0]); }
    finally { try { fs.unlinkSync(tmp); } catch (e3) {} }
  }
  console.log((broken ? '  ✗' : '  ✓') + ' كل كتل الجافاسكربت سليمة (' + i + ' كتلة)');
  if (broken) after.push('كتل مكسورة');
}

/* الزرار الجديد مانضافش في أي جدول تاني بالغلط.
   `name(` بيمسك النداءات بس — التعريف شكله `name = function(orderId)`. */
{
  const waDef = (s.match(/window\.sendOrderWhatsAppToPilot\s*=/g) || []).length;
  const prDef = (s.match(/window\.printOrderReceipt\s*=/g) || []).length;
  const wa = (s.match(/onclick="sendOrderWhatsAppToPilot\(/g) || []).length;
  const pr = (s.match(/onclick="printOrderReceipt\(/g) || []).length;
  console.log((waDef === 1 && wa === 1 ? '  ✓' : '  ✗') + ' واتساب للطيار: تعريف واحد + زرار واحد (' + waDef + '+' + wa + ')');
  console.log((prDef === 1 && pr === 2 ? '  ✓' : '  ✗') + ' ريسيت: تعريف واحد + زرارين (' + prDef + '+' + pr + ')');
  if (waDef !== 1 || wa !== 1 || prDef !== 1 || pr !== 2) after.push('عدد الأزرار/التعريفات غلط');
}

if (after.length) { console.log('\n⛔ فحوص بعدية وقعت — مافيش بايت اتكتب.'); process.exit(1); }
if (process.env.DRY) { console.log('\n🟦 DRY — مافيش بايت اتكتب.'); process.exit(0); }

fs.writeFileSync(FILE, WAS_CRLF ? s.replace(/\n/g, '\r\n') : s);
console.log('\n✓ اتكتب ' + path.basename(FILE) + (WAS_CRLF ? '  (CRLF)' : '  (LF)'));
console.log('  ' + ORIGINAL.split('\n').length + ' سطر → ' + s.split('\n').length + ' سطر');
