/* تطبيق العميل — نفس صف أزرار الطرود اللي في بوابة المحلات.
 *
 * ═══ الفرق عن المحلات ═══
 * هنا مصدر الحقيقة مصفوفة `S.draft.receivers`، و`renderReceiverBlocks`
 * بتعيد بناء البلوكات كلها منها. فالترقيم أصلًا متتالي (splice بتقفل
 * الفجوة لوحدها) — مافيش مشكلة ترقيم زي المحلات.
 *
 * ═══ 🔴 الفخ اللي هنا ومش في المحلات ═══
 * كل بلوك جواه **خريطة Leaflet مضمّنة** (`showMiniMap`). خريطة بتتبني
 * جوه عنصر `display:none` بتطلع بمقاس صفر وبتفضل مكسورة حتى بعد الإظهار —
 * لازم `invalidateSize()`، أو تتبني من جديد. عشان كده:
 *   • `renderReceiverBlocks` بقت تبني خريطة **البلوك الظاهر بس**
 *   • `showRcv` بتبني خريطة البلوك اللي بيتفتح
 * في بوابة المحلات الموضوع مختلف: `renderGeoPicker` بترسم زرار بس
 * والخريطة بتفتح في طبقة منفصلة، فالإخفاء مالوش أثر.
 *
 * ═══ التحقق ═══
 * `wizNext` بتتحقق من كل مستلم عند الخروج من خطوة ٢. بقت تنقل للمستلم
 * الناقص الأول — من غير كده الرسالة بتقول «الطرد ٣» والعميل شايف الطرد ١.
 * والنقطة التحذيرية لونها كهرماني مش أحمر، لأن الأحمر هنا لون الهوية
 * وبيستعمل للزرار النشط.
 *
 * 🔒 الحارس: ops/test_parcel_tabs.cjs
 */
const fs = require('fs');
const F = 'public/customer.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};
const L = (...x) => x.join('\n');

/* ═══════════ ① زر الإضافة اللي فوق في الترويسة — بقى في صف الأزرار ═══════════ */
one(
  L('          <b style="font-size:15px">بيانات المستلم</b>',
    '          <!-- نسخة تانية من زرار الإضافة هنا فوق: مع أكتر من طرد، الزرار',
    '               اللي تحت بيبقى بعيد ولازم تمرير طويل عشان توصله. -->',
    '          <button class="btn ghost sm" id="addRcvBtnTop" style="margin-inline-start:auto;padding:7px 12px;font-size:12.5px"',
    '            onclick="addReceiver()">＋ طرد آخر</button>'),
  L('          <b style="font-size:15px">بيانات المستلم</b>',
    '          <!-- زرار الإضافة اتنقل لصف أزرار الطرود تحت — بقى جنب أرقام',
    '               الطرود عشان الإضافة والتنقّل يبقوا في مكان واحد. -->'),
  'شيل زر الإضافة من الترويسة');

/* ═══════════ ② صف الأزرار محل الزر السفلي ═══════════ */
one(
  L('      <!-- بلوكات المستلمين — بيتبنوا من S.draft.receivers -->',
    '      <div id="rcvBlocks"></div>',
    '',
    '      <button class="btn ghost sm" id="addRcvBtn" style="margin-top:12px" onclick="addReceiver()"',
    '        data-tip="ضيف مستلم تاني لنفس الطلب — الطيار هيعدّي على كل النقط في رحلة واحدة، وسعر التوصيل بيتجمع.">',
    '        ＋ إضافة طرد لمستلم آخر</button>'),
  L('      <!-- صف التنقّل: زر الإضافة أول الصف وبعده زرار لكل طرد.',
    '           الطرود بقت واحد ظاهر في المرة — التنقّل من هنا مش بالسكرول. -->',
    '      <div id="rcvTabs" class="parcel-tabs"></div>',
    '',
    '      <!-- بلوكات المستلمين — بيتبنوا من S.draft.receivers -->',
    '      <div id="rcvBlocks"></div>'),
  'صف الأزرار محل الزر السفلي');

/* ═══════════ ③ الستايل ═══════════ */
const CSS = L(
  '  /* ── صف التنقّل بين الطرود ─────────────────────────────',
  '     بيسكرول أفقيًا لوحده لما الطرود تكتر، عشان مايلفّش على سطرين',
  '     ويزقّ الفورم لتحت. */',
  '  .parcel-tabs {',
  '    display: flex; align-items: center; gap: 7px;',
  '    overflow-x: auto; overflow-y: hidden; padding: 3px 2px 10px;',
  '    scrollbar-width: thin; -webkit-overflow-scrolling: touch;',
  '  }',
  '  .parcel-tabs::-webkit-scrollbar { height: 4px; }',
  '  .parcel-tabs::-webkit-scrollbar-thumb { background: var(--line); border-radius: 4px; }',
  '  /* زر الإضافة أول الصف عشان مكانه مايتغيّرش مع كل طرد بيتضاف */',
  '  .ptab-add {',
  '    flex: 0 0 auto; height: 34px; padding: 0 13px;',
  '    background: transparent; color: var(--red);',
  '    border: 1px dashed var(--red); border-radius: 10px;',
  '    font-size: 12.5px; font-weight: 800; cursor: pointer;',
  '    font-family: inherit; white-space: nowrap;',
  '  }',
  '  .ptab-add:active { opacity: .7; }',
  '  .ptab {',
  '    position: relative; flex: 0 0 auto;',
  '    min-width: 38px; height: 34px; padding: 0 11px;',
  '    background: var(--card2); color: var(--muted);',
  '    border: 1px solid var(--line); border-radius: 10px;',
  '    font-size: 13px; font-weight: 800; cursor: pointer;',
  '    font-family: inherit; font-variant-numeric: tabular-nums;',
  '  }',
  '  .ptab.active { background: var(--red); color: #fff; border-color: var(--red); }',
  '  /* النقطة كهرمانية مش حمرا — الأحمر هنا لون الهوية وبيستعمل للزرار',
  '     النشط، فنقطة حمرا جنبه مكانتش هتتقري كتحذير. */',
  '  .ptab.bad::after {',
  '    content: ""; position: absolute; top: -3px; left: -3px;',
  '    width: 9px; height: 9px; border-radius: 50%;',
  '    background: #f59e0b; border: 2px solid var(--bg);',
  '  }',
  '');

one('.mini-map{height:120px;', CSS + '.mini-map{height:120px;', 'الستايل');

/* ═══════════ ④ دوال التنقّل ═══════════ */
one(
  '/** state ← DOM: بيبني البلوكات من جديد ويرجّع كل القيم مكانها */\nfunction renderReceiverBlocks() {',
  L('/* ══════════════════════════════════════════════════════',
    '   التنقّل بين الطرود',
    '   البلوكات كلها موجودة في الـDOM — الظاهر واحد بس. يعني',
    '   `readReceiverBlocks` بتقرا المخفي عادي (قيم الـinputs بتفضل).',
    '══════════════════════════════════════════════════════ */',
    '',
    'function rcvCount()  { return (S.draft?.receivers || []).length; }',
    'function rcvActive() {',
    '  const n = rcvCount();',
    '  if (!n) return 0;',
    '  const a = Number(S.activeRcv || 0);',
    '  // بنقصّه على المدى — الحذف ممكن يسيب المؤشر بره المصفوفة',
    '  return (a >= 0 && a < n) ? a : n - 1;',
    '}',
    '',
    '/* نفس الشروط اللي `wizNext` بتتحقق منها بالظبط عند الخروج من خطوة ٢ —',
    '   لو اتغيّرت هناك لازم تتغيّر هنا، والحارس بيتأكد إن الاتنين متطابقين.',
    '   بنقرا من الـDOM مش من الحالة عشان النقطة تتحدّث وانت بتكتب. */',
    'function rcvIsIncomplete(i) {',
    '  const v = id => ($(id)?.value || "").trim();',
    '  if (!v("rcvZone-" + i)) return true;',
    '  if (S.draft?.receiptMode) return false;',
    '  if (!v("rcvName-" + i)) return true;',
    '  if (!validPhone(v("rcvPhone-" + i))) return true;',
    '  return !getAddr("rcvAddrW-" + i).trim();',
    '}',
    '',
    'function renderRcvTabs() {',
    '  const box = $("rcvTabs"); if (!box) return;',
    '  const n   = rcvCount();',
    '  const act = rcvActive();',
    '  const receipt = !!S.draft?.receiptMode;',
    '  const addLbl  = receipt ? "＋ طرد آخر (بصورة ريسيت)" : "＋ طرد آخر";',
    '',
    '  let html = `<button type="button" class="ptab-add" onclick="addReceiver()"`',
    '           + ` data-tip="ضيف طرد تاني لنفس الطلب — الطيار هيعدّي على كل النقط في رحلة واحدة، وسعر التوصيل بيتجمع.">`',
    '           + `${esc(addLbl)}</button>`;',
    '  for (let i = 0; i < n; i++) {',
    '    const cls = ["ptab"];',
    '    if (i === act) cls.push("active");',
    '    if (S.rcvChecked && rcvIsIncomplete(i)) cls.push("bad");',
    '    html += `<button type="button" class="${cls.join(" ")}" title="الطرد ${i + 1}"`',
    '          + ` onclick="showRcv(${i})">${i + 1}</button>`;',
    '  }',
    '  box.innerHTML = html;',
    '  /* block:"nearest" مهمة — من غيرها المتصفح بيسكرول الصفحة رأسيًا */',
    '  box.querySelector(".ptab.active")?.scrollIntoView({ inline: "center", block: "nearest" });',
    '}',
    'window.renderRcvTabs = renderRcvTabs;',
    '',
    'function showRcv(i) {',
    '  const box = $("rcvBlocks"); if (!box) return;',
    '  S.activeRcv = i;',
    '  const act = rcvActive();',
    '  Array.from(box.children).forEach((el, k) => { el.style.display = (k === act) ? "" : "none"; });',
    '  renderRcvTabs();',
    '  /* 🔴 الخريطة بتتبني دلوقتي مش قبل كده: Leaflet جوه `display:none`',
    '     بيطلع بمقاس صفر ويفضل مكسور حتى بعد الإظهار. */',
    '  const r = S.draft?.receivers?.[act];',
    '  if (r) setTimeout(() => showMiniMap("rcvMap-" + act, r.lat, r.lng), 30);',
    '}',
    'window.showRcv = showRcv;',
    '',
    '/** state ← DOM: بيبني البلوكات من جديد ويرجّع كل القيم مكانها */',
    'function renderReceiverBlocks() {'),
  'دوال التنقّل');

/* ═══════════ ⑤ الخريطة للظاهر بس ═══════════ */
/* المرساة بتاخد السطر اللي قبلها كمان: نفس النداء موجود مرة تانية جوه
   معالج اختيار مستلم محفوظ (سطر ~3865)، وده بلوك **ظاهر** وقتها لأن
   العميل لسه ضاغط شيب جواه — فخريطته لازم تتبني هناك زي ما هي. */
one(
  L('    sel.onchange = () => onReceiverZone(i);',
    '    showMiniMap("rcvMap-" + i, r.lat, r.lng);'),
  L('    sel.onchange = () => onReceiverZone(i);',
    '    // الخريطة للبلوك الظاهر بس — showRcv بتبنيها عند التنقّل',
    '    if (i === rcvActive()) showMiniMap("rcvMap-" + i, r.lat, r.lng);'),
  'الخريطة للظاهر بس');

/* ═══════════ ⑥ ذيل renderReceiverBlocks ═══════════ */
one(
  L('  const showAdd = "flex";',
    '  $("addRcvBtn").style.display = showAdd;',
    '  const topBtn = $("addRcvBtnTop");',
    '  if (topBtn) topBtn.style.display = showAdd;',
    '  /* في وضع الريسيت بنوضّح إن المطلوب صورة لكل طرد */',
    '  $("addRcvBtn").textContent = receipt ? "＋ إضافة طرد آخر (بصورة ريسيت)" : "＋ إضافة طرد لمستلم آخر";',
    '}'),
  L('  /* التبويب آخر حاجة: بيخفي كل البلوكات غير الظاهر ويرسم الأزرار.',
    '     لازم بعد `renderSavedPicks` و`updateRcvTotal` عشان البلوكات تكون',
    '     خلصت بناء قبل ما نقرّر مين يتخفي. */',
    '  showRcv(rcvActive());',
    '}'),
  'ذيل renderReceiverBlocks');

/* ═══════════ ⑦ addReceiver ═══════════ */
one(
  L('function addReceiver() {',
    '  readReceiverBlocks();',
    '  S.draft.receivers.push(blankReceiver());',
    '  renderReceiverBlocks();',
    '  // ننزل على البلوك الجديد عشان المستخدم يشوفه',
    '  const el = $("rcvBlocks")?.lastElementChild;',
    '  el?.scrollIntoView({ behavior: "smooth", block: "center" });',
    '  toast("اتضاف مستلم جديد", "ok");',
    '}'),
  L('function addReceiver() {',
    '  readReceiverBlocks();',
    '  S.draft.receivers.push(blankReceiver());',
    '  // الطرد الجديد بيتفتح على طول — العميل دوس «طرد آخر» عشان يكتب فيه',
    '  S.activeRcv = S.draft.receivers.length - 1;',
    '  renderReceiverBlocks();',
    '  toast("اتضاف مستلم جديد", "ok");',
    '}'),
  'addReceiver');

/* ═══════════ ⑧ addParcelSameReceiver ═══════════ */
one(
  L('  renderReceiverBlocks();',
    '  $("rcvBlocks")?.children[i + 1]?.scrollIntoView({ behavior: "smooth", block: "center" });',
    '  toast("اتضاف طرد تاني لنفس المستلم", "ok");'),
  L('  S.activeRcv = i + 1;',
    '  renderReceiverBlocks();',
    '  toast("اتضاف طرد تاني لنفس المستلم", "ok");'),
  'addParcelSameReceiver');

/* ═══════════ ⑨ removeReceiver ═══════════ */
one(
  L('function removeReceiver(i) {',
    '  readReceiverBlocks();',
    '  if (S.draft.receivers.length <= 1) return;',
    '  S.draft.receivers.splice(i, 1);',
    '  renderReceiverBlocks();',
    '  toast("اتشال المستلم", "ok");',
    '}'),
  L('function removeReceiver(i) {',
    '  readReceiverBlocks();',
    '  if (S.draft.receivers.length <= 1) return;',
    '  S.draft.receivers.splice(i, 1);',
    '  /* بنروح لجاره: اللي بعده لو موجود، وإلا اللي قبله. `rcvActive`',
    '     بتقصّ على المدى فالمؤشر مايخرجش بره المصفوفة. */',
    '  S.activeRcv = Math.min(i, S.draft.receivers.length - 1);',
    '  renderReceiverBlocks();',
    '  toast("اتشال المستلم", "ok");',
    '}'),
  'removeReceiver');

/* ═══════════ ⑩ التحقق بينقل للطرد الناقص ═══════════ */
one(
  L('    if (S.wizStep === 2) {',
    '      readReceiverBlocks();',
    '      const list = S.draft.receivers || [];',
    '      if (!list.length) return toast("أضف طرد واحد على الأقل", "err");',
    '      // كل بلوك مستلم بيتفحص لوحده — مش الأول بس',
    '      for (let i = 0; i < list.length; i++) {',
    '        const r = list[i];',
    '        const at = list.length > 1 ? ` — الطرد ${i + 1}` : "";',
    '        // المنطقة إجبارية في الحالتين — منها بيتحسب سعر التوصيل',
    '        if (!r.zoneId)                return toast("اختر منطقة التسليم" + at, "err");',
    '        if (!S.draft.receiptMode) {',
    '          if (!r.name)                return toast("اكتب اسم المستلم" + at, "err");',
    '          if (!validPhone(r.phone))   return toast("رقم هاتف المستلم غير صحيح" + at, "err");',
    '          if (!r.address.trim())      return toast("اكتب عنوان التسليم" + at, "err");',
    '        }',
    '      }',
    '    }'),
  L('    if (S.wizStep === 2) {',
    '      readReceiverBlocks();',
    '      const list = S.draft.receivers || [];',
    '      if (!list.length) return toast("أضف طرد واحد على الأقل", "err");',
    '      /* من هنا ورايح النقط بتبان على أزرار الطرود الناقصة. قبل أول',
    '         محاولة بتفضل مطفية — فورم لسه اتفتح مايستاهلش يبان غلطان. */',
    '      S.rcvChecked = true;',
    '      renderRcvTabs();',
    '      /* بينقل الشاشة للطرد الناقص قبل الرسالة. من غير كده الرسالة',
    '         بتقول «الطرد ٣» والعميل شايف الطرد ١ — الطرود بقت مخفية. */',
    '      const stop = (i, msg) => { showRcv(i); toast(msg, "err"); return true; };',
    '      // كل بلوك مستلم بيتفحص لوحده — مش الأول بس',
    '      for (let i = 0; i < list.length; i++) {',
    '        const r = list[i];',
    '        const at = list.length > 1 ? ` — الطرد ${i + 1}` : "";',
    '        // المنطقة إجبارية في الحالتين — منها بيتحسب سعر التوصيل',
    '        if (!r.zoneId)                return stop(i, "اختر منطقة التسليم" + at);',
    '        if (!S.draft.receiptMode) {',
    '          if (!r.name)                return stop(i, "اكتب اسم المستلم" + at);',
    '          if (!validPhone(r.phone))   return stop(i, "رقم هاتف المستلم غير صحيح" + at);',
    '          if (!r.address.trim())      return stop(i, "اكتب عنوان التسليم" + at);',
    '        }',
    '      }',
    '    }'),
  'التحقق بينقل للناقص');

/* ═══════════ ⑪ خرايط خطوة ٢ للظاهر بس ═══════════ */
one(
  L('  if (step === 2) setTimeout(() => (S.draft.receivers || []).forEach((r, i) =>',
    '                    showMiniMap("rcvMap-" + i, r.lat, r.lng)), 60);'),
  L('  /* خريطة الطرد الظاهر بس — الباقي مخفي و Leaflet جواه بيطلع بمقاس صفر */',
    '  if (step === 2) setTimeout(() => {',
    '    const a = rcvActive(); const r = S.draft?.receivers?.[a];',
    '    if (r) showMiniMap("rcvMap-" + a, r.lat, r.lng);',
    '  }, 60);'),
  'خرايط خطوة ٢');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ تطبيق العميل: صف الأزرار اتركّب');
