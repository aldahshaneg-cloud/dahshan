/* تطبيق المحلات — الطرود بقت أزرار مش قايمة تحت بعضها.
 *
 * ═══ الطلب (صاحب النظام 2026-08-31) ═══
 * «فوق المكان اللي بتتكتب فيه بيانات الطرد، يبقى في زرار لطرد آخر، والطرود
 * ماتبقاش تحت بعضها — زرار مكتوب عليه 1 للطرد الأول و2 للتاني وهكذا، في
 * نفس الصف اللي فيه زرار طرد آخر، بحيث ما أسكرولش كتير لفوق بل أتنقل بين
 * الطرود من خلال الأزرار».
 *
 * ═══ القرارات (اتسألت واتجاوبت) ═══
 * ① الترقيم بيتعاد ١·٢·٣ دايمًا. الأرقام الداخلية (row-N و rName-N و
 *    _rowImages[N]) بتفضل زي ما هي — اللي بيتغيّر هو المعروض بس، فمافيش
 *    أي خطر على بيانات مكتوبة. من غير كده حذف الطرد التاني كان بيسيب
 *    زرارين مكتوب عليهم 1 و 3.
 * ② الطرد الناقص بياناته بياخد نقطة حمرا على زراره، والإرسال بينقلك عليه
 *    ويحدّد الخانة الفاضية. النقط بتظهر **بعد أول محاولة إرسال** — قبل كده
 *    الفورم لسه نضيف ومايستاهلش يبان كأنه غلطان.
 * ③ زر «إزالة» فضل جوه الطرد — الزرار فوق للتنقّل بس، فمافيش دوسة غلط
 *    بتضيّع بيانات.
 *
 * ═══ ليه الإخفاء بـdisplay:none آمن هنا ═══
 * الخريطة مش جوه الكارت — renderGeoPicker بترسم **زرار** بس، والخريطة
 * بتفتح في طبقة منفصلة (pinMapOverlay). لو كانت خريطة مضمّنة كانت هتطلع
 * بمقاس صفر وهي مخفية وتحتاج invalidateSize() عند الإظهار.
 * وباقي الحقول input/select عادية — بتحتفظ بقيمتها وهي مخفية، و
 * querySelectorAll('.parcel-card') في saveOrder بتلقطها برضه.
 *
 * 🔒 الحارس: ops/test_parcel_tabs.cjs
 */
const fs = require('fs');
const F = 'public/store.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};

const L = (...lines) => lines.join('\n');
const BT = String.fromCharCode(96);   // `
const DOL = String.fromCharCode(36);  // $
const BS = String.fromCharCode(92);   // \

/* ═══════════ ① الهيكل: صف الأزرار محل زر الإضافة المنفصل ═══════════ */
one(
  L('    <div class="sec">📦 الطرود</div>',
    '    <div id="delivContainer"></div>',
    '',
    '    <button class="add-btn" onclick="addRow()">➕ إضافة طرد آخر</button>'),
  L('    <div class="sec">📦 الطرود</div>',
    '',
    '    <!-- صف التنقّل: زر الإضافة ثابت أول الصف، وبعده زرار لكل طرد.',
    '         الطرود بقت واحد ظاهر في المرة — التنقّل من هنا مش بالسكرول. -->',
    '    <div id="parcelTabs" class="parcel-tabs"></div>',
    '',
    '    <div id="delivContainer"></div>'),
  'صف الأزرار في الهيكل');

/* ═══════════ ② الستايل ═══════════ */
one(
  L('    .parcel-card {',
    '      background: var(--card); border: 1px solid var(--border);',
    '      border-radius: var(--R); padding: 14px; margin-bottom: 11px;',
    '    }'),
  L('    /* صف التنقّل بين الطرود — بيسكرول أفقيًا لوحده لما الطرود تكتر،',
    '       عشان مايلفّش على سطرين ويزقّ الفورم لتحت. */',
    '    .parcel-tabs {',
    '      display: flex; align-items: center; gap: 7px;',
    '      overflow-x: auto; overflow-y: hidden; padding: 3px 2px 9px;',
    '      scrollbar-width: thin; -webkit-overflow-scrolling: touch;',
    '    }',
    '    .parcel-tabs::-webkit-scrollbar { height: 4px; }',
    '    .parcel-tabs::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }',
    '    /* زر الإضافة أول الصف عشان مكانه مايتغيّرش مع كل طرد بيتضاف —',
    '       لو كان في الآخر كان بيهرب من تحت الصباع مع كل دوسة. */',
    '    .ptab-add {',
    '      flex: 0 0 auto; height: 34px;',
    '      background: var(--tint); color: var(--sky);',
    '      border: 1px dashed var(--sky); border-radius: 9px;',
    '      padding: 0 13px; font-size: .8rem; font-weight: 700;',
    '      cursor: pointer; font-family: inherit; white-space: nowrap;',
    '    }',
    '    .ptab-add:active { opacity: .75; }',
    '    .ptab {',
    '      position: relative; flex: 0 0 auto;',
    '      min-width: 38px; height: 34px; padding: 0 11px;',
    '      background: var(--card); color: var(--muted);',
    '      border: 1px solid var(--border); border-radius: 9px;',
    '      font-size: .88rem; font-weight: 700; cursor: pointer;',
    '      font-family: inherit; font-variant-numeric: tabular-nums;',
    '    }',
    '    .ptab.active {',
    '      background: var(--sky); color: #fff; border-color: var(--sky);',
    '    }',
    '    /* النقطة الحمرا = الطرد ناقص بيانات مطلوبة. بتظهر بعد أول محاولة',
    '       إرسال بس — قبلها الفورم لسه نضيف. */',
    '    .ptab.bad::after {',
    '      content: ""; position: absolute; top: -3px; left: -3px;',
    '      width: 9px; height: 9px; border-radius: 50%;',
    '      background: var(--red); border: 2px solid var(--bg);',
    '    }',
    '',
    '    .parcel-card {',
    '      background: var(--card); border: 1px solid var(--border);',
    '      border-radius: var(--R); padding: 14px; margin-bottom: 11px;',
    '    }'),
  'ستايل الأزرار');

/* ═══════════ ③ زر الإزالة يبقى دايم في القالب ═══════════ */
one(
  L('          <span class="parcel-label">📦 الطرد #' + DOL + '{n}</span>',
    '          ' + DOL + '{n > 1 ? ' + BT + '<button class="rm-btn" onclick="rmRow(' + DOL + '{n})">✕ إزالة</button>' + BT + ' : ""}'),
  L('          <span class="parcel-label">📦 الطرد #' + DOL + '{n}</span>',
    '          <!-- الزرار بيتحط دايمًا، و renderParcelTabs بتخفيه لو الطرد',
    '               ده الوحيد الفاضل. قبل كده كان الشرط n > 1 وقت الإنشاء،',
    '               فلو الطرد الأول اتحذف كان بيفضل طرد واحد **بزرار حذف** —',
    '               والمحل يقدر يفضّي الشحنة بالكامل. -->',
    '          <button class="rm-btn" onclick="rmRow(' + DOL + '{n})">✕ إزالة</button>'),
  'زر الإزالة دايم في القالب');

/* ═══════════ ④ منظومة التنقّل ═══════════ */
one(
  '    window.addRow = function () {',
  L('    /* ══════════════════════════════════════════════════════',
    '       التنقّل بين الطرود',
    '       الطرود موجودة كلها في الـDOM في نفس الوقت — الظاهر واحد بس.',
    '       يعني القيم كلها محفوظة، و saveOrder بتلقط الصفوف المخفية عادي',
    '       بـ querySelectorAll زي ما هي.',
    '    ══════════════════════════════════════════════════════ */',
    '',
    '    /* الصفوف بترتيب ظهورها — الترتيب ده هو مصدر الترقيم المعروض */',
    '    function parcelRows() {',
    '      return Array.from(document.querySelectorAll("#delivContainer .parcel-card"));',
    '    }',
    '',
    '    /* الرقم المعروض للطرد ذي الرقم الداخلي n (يبدأ من 1، و0 لو مش موجود) */',
    '    window.parcelDisplayNo = function (n) {',
    '      return parcelRows().findIndex(r => r.id === "row-" + n) + 1;',
    '    };',
    '',
    '    /* نفس التلات حقول اللي saveOrder بتتحقق منهم بالظبط — لو اتغيّروا',
    '       هناك لازم يتغيّروا هنا، والحارس بيتأكد إن الاتنين متطابقين. */',
    '    function parcelIsIncomplete(n) {',
    '      const v = id => (document.getElementById(id)?.value || "").trim();',
    '      return !v("rName-" + n) || !v("rPhone-" + n) || !v("rZone-" + n);',
    '    }',
    '',
    '    window.renderParcelTabs = function () {',
    '      const box = document.getElementById("parcelTabs");',
    '      if (!box) return;',
    '      const rows = parcelRows();',
    '',
    '      let html = ' + BT + '<button type="button" class="ptab-add" onclick="addRow()">➕ طرد آخر</button>' + BT + ';',
    '      rows.forEach((r, i) => {',
    '        const n   = r.id.replace("row-", "");',
    '        const cls = ["ptab"];',
    '        if (String(n) === String(window._activeRow)) cls.push("active");',
    '        if (window._parcelChecked && parcelIsIncomplete(n)) cls.push("bad");',
    '        html += ' + BT + '<button type="button" class="' + DOL + '{cls.join(" ")}" ' + BT + ' +',
    '                ' + BT + 'title="الطرد ' + DOL + '{i + 1}" onclick="showRow(' + BS + '\'' + DOL + '{esc(n)}' + BS + '\')">' + DOL + '{i + 1}</button>' + BT + ';',
    '',
    '        // الترقيم المعروض جوه الكارت لازم يتماشى مع الزرار',
    '        const lbl = r.querySelector(".parcel-label");',
    '        if (lbl) lbl.textContent = "📦 الطرد #" + (i + 1);',
    '        // الحذف بيختفي لو ده الطرد الوحيد — الشحنة لازم يفضل فيها طرد',
    '        const rm = r.querySelector(".rm-btn");',
    '        if (rm) rm.style.display = rows.length > 1 ? "" : "none";',
    '      });',
    '      box.innerHTML = html;',
    '',
    '      /* الزرار النشط يفضل في المنظور لما الطرود تكتر. block:"nearest"',
    '         مهمة: من غيرها المتصفح بيسكرول الصفحة رأسيًا كمان. */',
    '      box.querySelector(".ptab.active")?.scrollIntoView({ inline: "center", block: "nearest" });',
    '    };',
    '',
    '    window.showRow = function (n) {',
    '      const rows = parcelRows();',
    '      if (!rows.length) return;',
    '      rows.forEach(r => { r.style.display = (r.id === "row-" + n) ? "" : "none"; });',
    '      window._activeRow = String(n);',
    '      window.renderParcelTabs();',
    '    };',
    '',
    '    window.addRow = function () {'),
  'دوال التنقّل');

/* ═══════════ ⑤ addRow بيفتح الطرد الجديد ═══════════ */
one(
  L('      fillZones(n);',
    '      bindTrustToRow(n);',
    '    };'),
  L('      fillZones(n);',
    '      bindTrustToRow(n);',
    '      // الطرد الجديد بيتفتح على طول — المحل دوس «طرد آخر» عشان يكتب فيه',
    '      window.showRow(n);',
    '    };'),
  'addRow بيفتح الجديد');

/* ═══════════ ⑥ rmRow بيروح لجاره ═══════════ */
one(
  L('    window.rmRow = function (n) {',
    '      document.getElementById(' + BT + 'row-' + DOL + '{n}' + BT + ')?.remove();',
    '      delete window._rowImages[n];',
    '      recalc();',
    '    };'),
  L('    window.rmRow = function (n) {',
    '      /* بنمسك جاره **قبل** الحذف — بعده الصف بيبقى مش موجود ومفيش',
    '         طريقة نعرف منها كان فين، فالمحل كان هيلاقي نفسه على غير طرد. */',
    '      const rows = parcelRows();',
    '      const i    = rows.findIndex(r => r.id === "row-" + n);',
    '      const next = rows[i + 1] || rows[i - 1] || null;',
    '',
    '      document.getElementById(' + BT + 'row-' + DOL + '{n}' + BT + ')?.remove();',
    '      delete window._rowImages[n];',
    '      if (next) window.showRow(next.id.replace("row-", ""));',
    '      else window.renderParcelTabs();',
    '      recalc();',
    '    };'),
  'rmRow بيروح لجاره');

/* ═══════════ ⑦ التصفير ═══════════ */
one(
  '      $("delivContainer").innerHTML = "";',
  L('      $("delivContainer").innerHTML = "";',
    '      // صف الأزرار وحالة التنقّل بيتصفّروا مع الفورم',
    '      const _pt = document.getElementById("parcelTabs");',
    '      if (_pt) _pt.innerHTML = "";',
    '      window._activeRow     = null;',
    '      window._parcelChecked = false;'),
  'تصفير صف الأزرار');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ المحلات: صف الأزرار اتركّب');
