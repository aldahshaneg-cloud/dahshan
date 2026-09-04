/* أربع تعديلات في تطبيق العميل (طلب صاحب النظام):
     ① زرار تبديل الوضع الليلي/النهاري
     ② شيب «بياناتي» يطلع فوق جنب عنوان «بيانات المُرسِل»
     ③ زرار «إضافة طرد لمستلم آخر» يبان فوق كمان جنب عنوان «بيانات المستلم»
     ④ في وضع «مش معايا بيانات المستلم» الزرار يفضل شغّال — أكتر من طرد بصور بس
*/
const fs = require('fs');
const f = 'public/customer.html';
let s = fs.readFileSync(f, 'utf8');
const eol = s.includes('\r\n') ? '\r\n' : '\n';
if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');

let bad = 0;
const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log(`  🔴 ${label}: اتلقت ${n} مرة`); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};

/* ═══ ① صف تبديل الوضع في شاشة الحساب ═══ */
console.log('── ① الوضع الليلي/النهاري ──');
one(`    <div class="menu-row" onclick="openSupport()"><div class="mi">💬</div><b>الدعم والمساعدة</b><span class="ch">‹</span></div>`,
`    <!-- تبديل الوضع: تلات حالات مش اتنين — «النظام» بيسيب الجهاز يحكم. -->
    <div class="menu-row" onclick="cycleTheme()">
      <div class="mi" id="themeIcon">🌙</div><b>مظهر التطبيق</b>
      <span class="ch" id="themeState" style="color:var(--muted);font-size:12.5px">النظام</span></div>
    <div class="menu-row" onclick="openSupport()"><div class="mi">💬</div><b>الدعم والمساعدة</b><span class="ch">‹</span></div>`,
'صف المظهر في الحساب');

/* الكود — بيتحط قبل نهاية آخر <script> */
const THEME_JS = `
/* ══════════════════════════════════════════════════════════════
   مظهر التطبيق — ليلي · نهاري · النظام
   ──────────────────────────────────────────────────────────────
   تلات حالات مش اتنين. «النظام» معناه **مانحطش** \`data-theme\` خالص،
   فالـCSS بتسيب \`prefers-color-scheme\` يحكم. الاختيار الصريح بيحط
   السمة فتغلب على الجهاز في الاتجاهين.

   الاختيار بيتحفظ في localStorage — وبيتقرا **قبل ما الصفحة تترسم**
   في سكربت صغير فوق (شوف \`<head>\`) عشان مايحصلش وميض أبيض.
══════════════════════════════════════════════════════════════ */
const THEMES = [
  { key: "system", label: "النظام", icon: "🌓" },
  { key: "dark",   label: "ليلي",   icon: "🌙" },
  { key: "light",  label: "نهاري",  icon: "☀️" },
];

function applyTheme(key) {
  const root = document.documentElement;
  if (key === "system") root.removeAttribute("data-theme");
  else root.setAttribute("data-theme", key);
  try { localStorage.setItem("cust-theme", key); } catch (e) {}
  const t = THEMES.find(x => x.key === key) || THEMES[0];
  const ic = $("themeIcon"), st = $("themeState");
  if (ic) ic.textContent = t.icon;
  if (st) st.textContent = t.label;
  /* لون شريط المتصفح بيتبع الوضع الفعلي مش الاختيار */
  const dark = key === "dark" || (key === "system" &&
    window.matchMedia("(prefers-color-scheme: dark)").matches);
  document.querySelectorAll('meta[name="theme-color"]').forEach(m => m.remove());
  const m = document.createElement("meta");
  m.name = "theme-color"; m.content = dark ? "#0a0a0b" : "#f4f4f6";
  document.head.appendChild(m);
}

function currentTheme() {
  try { return localStorage.getItem("cust-theme") || "system"; } catch (e) { return "system"; }
}

function cycleTheme() {
  const i = THEMES.findIndex(x => x.key === currentTheme());
  const next = THEMES[(i + 1) % THEMES.length];
  applyTheme(next.key);
  toast("المظهر: " + next.label, "ok");
}
window.cycleTheme = cycleTheme;
window.applyTheme = applyTheme;

/* أول ما الصفحة تجهز، نظبّط الأيقونة والاسم على المحفوظ */
applyTheme(currentTheme());
`;

/* ═══ سكربت مبكر في الـhead يمنع وميض الأبيض ═══ */
one(`<meta name="theme-color" content="#0a0a0b" media="(prefers-color-scheme: dark)" />`,
`<script>
  /* بيتنفّذ **قبل** أي رسم: من غيره الصفحة بتفتح ليلي لجزء من الثانية
     قبل ما الجافاسكربت الرئيسي يقرا الاختيار — وميض مزعج في الوضع النهاري. */
  try { var _t = localStorage.getItem("cust-theme");
        if (_t && _t !== "system") document.documentElement.setAttribute("data-theme", _t); } catch (e) {}
</script>
<meta name="theme-color" content="#0a0a0b" media="(prefers-color-scheme: dark)" />`,
'سكربت منع الوميض');

/* ═══ ② شيب «بياناتي» فوق جنب عنوان المُرسِل ═══ */
console.log('── ② «بياناتي» فوق ──');
one(`          <b style="font-size:15px">بيانات المُرسِل</b>`,
`          <b style="font-size:15px">بيانات المُرسِل</b>
          <!-- شيبات الملء السريع بقت هنا فوق: كانت تحت الحقول، يعني العميل
               بيكتب بياناته بالإيد الأول وبعدين يكتشف إن فيه زرار بيملاها. -->
          <div id="sndSaved" style="margin-inline-start:auto"></div>`,
'الشيبات اتنقلت لرأس البطاقة');

/* والقديم يتشال من تحت */
one(`        <div id="sndSaved" style="margin-top:10px"></div>`,
    `        <!-- [اتنقل] شيبات «بياناتي» بقت فوق جنب العنوان -->`,
    'مكانها القديم اتشال');

/* الشيبات في الرأس محتاجة تبقى في سطر واحد مضغوط */
one(`function renderSavedPicks() {`,
`/* الشيبات بقت في رأس البطاقة (سطر واحد جنب العنوان) — فالعرض بقى
   أفقي متمرّر بدل ما يلف على سطرين ويكسر ارتفاع الرأس. */
function renderSavedPicks() {`,
'تعليق التنسيق');

one(`    $("sndSaved").innerHTML = \`<span class="lbl">أو املا بضغطة</span>
      <div class="chips">
        <div class="chip" data-me="1">👤 بياناتي</div>`,
`    $("sndSaved").innerHTML = \`<div class="chips chips-inline">
        <div class="chip" data-me="1">👤 بياناتي</div>`,
'شكل الشيبات في الرأس');

/* ═══ ③ زرار الطرد فوق جنب عنوان المستلم ═══ */
console.log('── ③ زرار الطرد فوق ──');
one(`          <b style="font-size:15px">بيانات المستلم</b>
        </div>`,
`          <b style="font-size:15px">بيانات المستلم</b>
          <!-- نسخة تانية من زرار الإضافة هنا فوق: مع أكتر من طرد، الزرار
               اللي تحت بيبقى بعيد ولازم تمرير طويل عشان توصله. -->
          <button class="btn ghost sm" id="addRcvBtnTop" style="margin-inline-start:auto;padding:7px 12px;font-size:12.5px"
            onclick="addReceiver()">＋ طرد آخر</button>
        </div>`,
'زرار الإضافة فوق');

/* ═══ ④ وضع الريسيت: الزرار يفضل والطرود تتعدد ═══ */
console.log('── ④ تعدد الطرود في وضع الريسيت ──');
one(`  // زرار الإضافة — مالوش لازمة في وضع الريسيت (مفيش بيانات نكرّرها)
  $("addRcvBtn").style.display = receipt ? "none" : "flex";`,
`  /* 🔴 الزرار كان بيتخفي في وضع الريسيت بحجة «مفيش بيانات نكرّرها» —
     وده كان غلط: العميل ممكن يبعت أكتر من طرد لناس مختلفة والعناوين كلها
     مكتوبة على صور الريسيت. كل طرد ساعتها = منطقة + صورة، والباقي بيتقرا
     من الصورة. التحقق والإرسال أصلًا بيلفّوا على كل الطرود. */
  const showAdd = "flex";
  $("addRcvBtn").style.display = showAdd;
  const topBtn = $("addRcvBtnTop");
  if (topBtn) topBtn.style.display = showAdd;
  /* في وضع الريسيت بنوضّح إن المطلوب صورة لكل طرد */
  $("addRcvBtn").textContent = receipt ? "＋ إضافة طرد آخر (بصورة ريسيت)" : "＋ إضافة طرد لمستلم آخر";`,
'الزرار بيفضل في الوضعين');

one(`  if (on && S.draft.receivers.length > 1) S.draft.receivers = [S.draft.receivers[0]];`,
`  /* 🔴 كان بيقص الطرود لواحد عند تفعيل الوضع — يعني العميل اللي ضاف
     تلات طرود ودوس على «مش معايا بيانات» بيلاقي اتنين اتمسحوا من غير
     تحذير. الطرود بتفضل زي ما هي؛ اللي بيتخفي هو حقول الاسم والتليفون
     والعنوان بس، والمنطقة والصورة بيفضلوا مطلوبين لكل طرد. */`,
'الطرود مابتتقصّش');

/* الملخّص كان بيعرض أول طرد بس في وضع الريسيت */
one(`  const rcvRows = S.draft.receiptMode
    ? row("المستلم", "🧾 من صورة الريسيت — " + (zoneLabel(findZone(list[0]?.zoneId) || {}) || "—"))
    : list.map((r, i) => row(`,
`  /* الملخّص كان بيعرض \`list[0]\` بس في وضع الريسيت — بعد ما بقى ينفع
     أكتر من طرد، ده كان هيخفي باقي الطرود عن مراجعة العميل قبل الإرسال. */
  const rcvRows = S.draft.receiptMode
    ? list.map((r, i) => row(
        list.length > 1 ? \`الطرد \${i + 1}\` : "المستلم",
        "🧾 من صورة الريسيت — " + (zoneLabel(findZone(r.zoneId) || {}) || "—"))).join("")
    : list.map((r, i) => row(`,
'ملخّص كل الطرود');

/* ═══ الكود الجديد في آخر سكربت ═══ */
const anchor = '\n</script>\n</body>';
if (s.split(anchor).length - 1 !== 1) {
  console.log('  🔴 مالقيتش نهاية السكربت');
  bad++;
} else {
  s = s.replace(anchor, THEME_JS + anchor);
  console.log('  ✓ كود المظهر اتضاف');
}

/* ═══ تنسيق الشيبات الأفقية ═══ */
one(`#tip{position:fixed;z-index:1000;background:var(--pop);border:1px solid var(--line2);color:#fff;`,
`/* شيبات الملء السريع في رأس بطاقة المُرسِل — سطر واحد بيتمرّر أفقيًا
   بدل ما يلف ويكبّر الرأس. */
.chips-inline{display:flex;gap:6px;overflow-x:auto;max-width:62%;
  scrollbar-width:none;-ms-overflow-style:none;padding-bottom:2px}
.chips-inline::-webkit-scrollbar{display:none}
.chips-inline .chip{white-space:nowrap;flex:none;font-size:12px;padding:6px 10px}
#tip{position:fixed;z-index:1000;background:var(--pop);border:1px solid var(--line2);color:var(--txt);`,
'تنسيق الشيبات');

fs.writeFileSync(f, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
console.log(bad ? `\n🔴 ${bad} مشكلة` : '\n✅ الأربعة اتعملوا');
process.exit(bad ? 1 : 0);
