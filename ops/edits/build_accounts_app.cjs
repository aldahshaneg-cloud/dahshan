/* بناء `public/accounts.html` — «تقفيل الطيارين» كبرنامج مستقل.
 *
 * ═══ الطلب (صاحب النظام 2026-08-31) ═══
 * «تقفيلة الطيارين اللي في الإدارة أريدها برنامج منفصل في المجموعة».
 * وقراراته لما سألته: تخرج من لوحة الإدارة خالص · تقفيل الطيارين بس ·
 * الأدمن والمحاسب · من غير نطاق خاص (كارت في المجموعة).
 *
 * ═══ الخانة كانت مستنية ═══
 * `home.html` فيه كارت `card-accounts` من زمان، و`appsFor` بيدّي مفتاح
 * `accounts` للأدمن ولدور `accountant`. الناقص كان الملف بس — عشان كده
 * `accounts` مسجّل في `APPS_NOT_BUILT`.
 *
 * ═══ 🔴 الصفر الصامت اللي كان هيعدّي ═══
 * `loadPilotAcct` بتعبّي فلتر الفروع من `window._branchesData`، والمتغيّر
 * ده **مش** من رد `/pilot-accounting/month` — مصدره الوحيد مستمع الفروع
 * في لوحة الإدارة (tiar.html:2814). نقل الكود زي ما هو كان هيخلّي الفلتر
 * فاضي **من غير أي خطأ ولا تحذير**: الشرط `?.length` بيرجع falsy والسطر
 * بيتخطّى بالسكوت. فالملف الجديد بينده `/api/branches` بنفسه.
 *
 * ═══ 🔴 فخ الـCSS ═══
 * قواعد `.pa-*` (8232-8245) محشورة **بين** `.sub-tabs` (8231) و`.sub-tab`
 * (8246-8248) — والاتنين دول مشتركين مع ٦ حاويات تبويبات تانية في اللوحة.
 * فالنسخ هنا بياخد التلاتة، والحذف من tiar.html هياخد `.pa-*` بس.
 *
 * ═══ 🔴 اسم مخادع ═══
 * عيلة `*MonthlyCloseout` (tiar.html:6435-6685) اسمها فيه «تقفيلة» بس هي
 * بتاعة **صفحة الورديات** — بتتنده من سطر 9187 اللي جوه `page-shifts`.
 * مالهاش أي علاقة بالبرنامج ده ومتتلمسش.
 *
 * الملف ده **بيبني بس** — الحذف من tiar.html في سكربت منفصل بعد التأكد.
 */
const fs = require('fs');

const TIAR = fs.readFileSync('public/tiar.html', 'utf8').split('\n');
const line = n => TIAR[n - 1];                       // 1-based زي محرر النصوص
const slice = (a, b) => TIAR.slice(a - 1, b).join('\n');

/* ── تأكيد الحدود قبل أي قص ─────────────────────────────── */
const guards = [
  [9573,  '<!-- ══',                         'بداية تعليق الماركب'],
  [9579,  '<div class="page" id="page-pilotacct">', 'فتح الصفحة'],
  [9678,  '</div>',                          'قفل الصفحة'],
  [9680,  'صفحة خزن الفروع',                 'اللي بعدها — لازم تفضل'],
  [10724, '/* ══',                           'بداية الجافاسكربت'],
  [11088, '};',                              'نهاية الجافاسكربت'],
  [11090, 'window.switchAccTab',             'اللي بعدها — لازم تفضل'],
  [8231,  '.sub-tabs {',                     'مشترك — قبل pa'],
  [8232,  'شبكة تقفيل الطيارين',             'بداية CSS الخاص'],
  [8245,  '.pa-tot {',                       'نهاية CSS الخاص'],
  [8246,  '.sub-tab {',                      'مشترك — بعد pa'],
];
let bad = 0;
for (const [n, needle, label] of guards) {
  if (!String(line(n) ?? '').includes(needle)) {
    console.log(`  🔴 سطر ${n} (${label}): متوقع «${needle}»، لقيت «${String(line(n) ?? '').trim().slice(0, 60)}»`);
    bad++;
  }
}
if (bad) { console.log(`\n🔴 ${bad} حد اتزحلق — مالمستش حاجة`); process.exit(1); }
console.log('  ✓ الحدود الـ11 كلها مضبوطة');

/* ── القطع ──────────────────────────────────────────────── */
const MARKUP = slice(9579, 9678)
  .replace('<div class="page" id="page-pilotacct">',
           '<div id="page-pilotacct">');   // مافيش راوتر هنا — `.page{display:none}` كانت هتخفيها

const JS = slice(10724, 11088)
  /* المتغيّر ده ميّت في الأصل — متعرّف ومابيتقراش ولا بيتكتب في المشروع
     كله. النقل فرصة نشيله بدل ما ننقل كود ميت. */
  .replace('  window._paDay = null;\n', '');

const CSS_PA     = slice(8232, 8245);
const CSS_SUBTAB = slice(8231, 8231) + '\n' + slice(8246, 8248);

console.log(`  ✓ الماركب ${MARKUP.split('\n').length} سطر · الجافاسكربت ${JS.split('\n').length} سطر`);
if (JS.includes('_paDay')) { console.log('  🔴 _paDay لسه موجود'); process.exit(1); }

/* ── الملف ──────────────────────────────────────────────── */
const OUT = `<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title>الدهشان | تقفيل الطيارين</title>
<link rel="icon" href="assets/logo.png" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet" />
<style>
/* ══════════════════════════════════════════════════════════════
   تقفيل الطيارين — برنامج مستقل.

   اتفصل من لوحة الإدارة 2026-08-31 بطلب صاحب النظام. الستايل هنا
   **منسوخ** من tiar.html مش منقول: القواعد المشتركة (.sub-tabs ·
   .table-wrap · .stats …) لسه بتخدم شاشات تانية هناك.
══════════════════════════════════════════════════════════════ */
:root{
  --bg:#0b0d12; --panel:#12151c; --card:#171a22; --border:#242832;
  --text:#e8eaf0; --muted:#8b93a7; --sky:#0ea5e9; --green:#22c55e;
  --orange:#f97316; --red:#e8192c; --purple:#8b5cf6;
  --radius:12px; --font:'Cairo',system-ui,sans-serif;
}
html.light{
  --bg:#f4f5f8; --panel:#fff; --card:#fafbfc; --border:#e2e5ec;
  --text:#111318; --muted:#5c6373;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:var(--font);
     -webkit-text-size-adjust:100%}
button,input,select{font-family:inherit}

/* الشريط العلوي */
.top{display:flex;align-items:center;gap:12px;padding:14px 18px;background:var(--panel);
     border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.top .brand{display:flex;align-items:center;gap:9px;font-weight:800;font-size:1rem}
.top .brand img{height:30px}
.top .sp{flex:1}
.top button{background:var(--card);color:var(--text);border:1px solid var(--border);
            border-radius:9px;padding:7px 12px;cursor:pointer;font-size:.82rem;font-weight:700}
.wrap{padding:20px 18px 60px;max-width:1500px;margin:0 auto}

/* بوابة الدخول */
#gate{min-height:100vh;display:grid;place-items:center;padding:20px}
#gate .box{background:var(--panel);border:1px solid var(--border);border-radius:18px;
           padding:28px;width:100%;max-width:380px;text-align:center}
#gate img{height:56px;margin-bottom:14px}
#gate h1{font-size:1.15rem;margin:0 0 4px}
#gate p{font-size:.8rem;color:var(--muted);margin:0 0 20px}
#gate input{width:100%;background:var(--card);border:1px solid var(--border);color:var(--text);
            border-radius:10px;padding:12px;margin-bottom:10px;font-size:.9rem}
#gate .go{width:100%;background:var(--red);color:#fff;border:none;border-radius:10px;
          padding:12px;font-weight:800;cursor:pointer;font-size:.92rem}
#gateErr{color:var(--red);font-size:.8rem;min-height:18px;margin-top:10px;font-weight:700}
#app{display:none}
#app.on{display:block}

/* ── منقول من tiar.html: قواعد الصفحة والجداول ── */
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 22px; }
.stat-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; }
.stat-label { font-size: .74rem; color: var(--muted); margin-bottom: 5px; }
.stat-value { font-size: 1.35rem; font-weight: 800; }
.sec-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; gap: 10px; flex-wrap: wrap; }
.sec-title { font-size: 1.05rem; font-weight: 800; }
.add-btn { background: var(--sky); color: #fff; border: none; border-radius: 9px; padding: 8px 14px; font-size: .82rem; font-weight: 700; cursor: pointer; }
.add-btn:hover { opacity: .9; }
.table-wrap { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: auto; }
table { width: 100%; border-collapse: collapse; font-size: .82rem; }
thead { background: var(--card); }
th { text-align: right; padding: 11px 10px; font-weight: 700; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap; }
td { padding: 10px; border-bottom: 1px solid var(--border); }
.empty-row { text-align: center; color: var(--muted); padding: 26px 10px; }
.view-btn { background: var(--card); color: var(--text); border: 1px solid var(--border); border-radius: 7px; padding: 5px 11px; font-size: .75rem; font-weight: 700; cursor: pointer; }
.view-btn:hover { border-color: var(--sky); color: var(--sky); }

/* 🔴 منسوخة مش منقولة — في tiar.html بتخدم ٦ حاويات تبويبات تانية */
${CSS_SUBTAB}

${CSS_PA}

/* التوست */
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:200;
       background:var(--panel);border:1px solid var(--border);border-radius:11px;
       padding:12px 20px;font-size:.85rem;font-weight:700;display:none;
       box-shadow:0 8px 30px rgba(0,0,0,.4);max-width:90vw;text-align:center}
#toast.show{display:block}
#toast.success{border-color:rgba(34,197,94,.5);color:var(--green)}
#toast.error{border-color:rgba(232,25,44,.5);color:var(--red)}

@media (max-width: 760px){
  .wrap{padding:14px 12px 50px}
  /* الجداول ١٥ عمود — من غير السطرين دول الموبايل بيتكسر */
  .table-wrap{overflow-x:auto}
  table{min-width:900px}
}
</style>
</head>
<body>

<!-- ══════════ بوابة الدخول ══════════ -->
<div id="gate">
  <div class="box">
    <img src="assets/logo.png" alt="الدهشان" />
    <h1>🧾 تقفيل الطيارين</h1>
    <p>سجّل دخولك بحساب الإدارة أو المحاسب</p>
    <input id="gUser" placeholder="اسم المستخدم" autocomplete="username" />
    <input id="gPass" type="password" placeholder="كلمة المرور" autocomplete="current-password"
           onkeydown="if(event.key==='Enter')doLogin()" />
    <button class="go" onclick="doLogin()">دخول</button>
    <div id="gateErr"></div>
  </div>
</div>

<!-- ══════════ التطبيق ══════════ -->
<div id="app">
  <div class="top">
    <span class="brand"><img src="assets/logo.png" alt="" /> تقفيل الطيارين</span>
    <span class="sp"></span>
    <button id="themeBtn" onclick="toggleTheme()">🌙</button>
    <button onclick="location.href='home.html'">⌂ المجموعة</button>
    <button onclick="doLogout()">خروج</button>
  </div>

  <div class="wrap">
${MARKUP.split('\n').map(l => l.replace(/^    /, '')).join('\n')}
  </div>
</div>

<div id="toast"></div>

<script src="assets/js/constants.js"></script>
<script src="assets/js/api.js"></script>
<script>
/* 🔒 كود التطبيق — بيتبعت مع الدخول والسيرفر بيرفض لو الحساب مش مصرّح
   له بيه (AuthController::login + appsFor). من غيره أي دور بيصادق بنجاح
   كان هيعدّي، والـURL المباشر بيتخطّى بوابة home.html. */
var APP_KEY = "accounts";
</script>
<script>
const $ = id => document.getElementById(id);
window.esc = s => String(s ?? "").replace(/[&<>"']/g, c =>
  ({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;" }[c]));
/* للنصوص اللي بتتحط جوه onclick — التهريب مختلف عن HTML */
window.escJs = s => String(s ?? "").replace(/\\\\/g, "\\\\\\\\").replace(/'/g, "\\\\'")
  .replace(/"/g, "&quot;").replace(/</g, "\\\\x3C").replace(/\\r?\\n/g, " ");

let toastTimer = null;
window.showToast = function (msg, type) {
  const t = $("toast"); if (!t) return;
  t.textContent = msg;
  t.className = "show" + (type ? " " + type : "");
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.className = ""; }, 3000);
};

/* الثيم — مفتاح خاص بالبرنامج ده عشان مايتصادمش مع باقي التطبيقات */
if (localStorage.getItem("accounts-theme") === "light") document.documentElement.classList.add("light");
function syncTheme() {
  const b = $("themeBtn");
  if (b) b.textContent = document.documentElement.classList.contains("light") ? "🌙" : "☀️";
}
window.toggleTheme = function () {
  const lt = document.documentElement.classList.toggle("light");
  localStorage.setItem("accounts-theme", lt ? "light" : "dark");
  syncTheme();
};
syncTheme();

/* ── الدخول — جلسة السيرفر هي المرجع ─────────────────── */
window._onSessionExpired = () => location.reload();

/* الأدوار المسموح لها. «accountant« موجود في النظام و«appsFor« بيدّيه
   مفتاح «accounts« — والسيرفر هو الحكم النهائي على كل مسار. */
const ALLOWED = ["admin", "accountant"];

function applySession(u) {
  $("gate").style.display = "none";
  $("app").classList.add("on");
  bootAccounts();
}
window.doLogin = async function () {
  const u = $("gUser").value.trim(), p = $("gPass").value, err = $("gateErr");
  err.textContent = "";
  if (!u || !p) { err.textContent = "اكتب اسم المستخدم وكلمة المرور"; return; }
  try {
    const res = await API.login(u, p, APP_KEY);
    if (!ALLOWED.includes(res.user.role)) {
      /* مفيش logout هنا عن قصد: الجلسة كوكي واحد على الدومين كله،
         فالـlogout بيقتل جلسة نفس اليوزر في تاباته التانية ويطرده من
         شغله المفتوح. وسيبانها مش خطر — السيرفر بيرفض المسارات أصلًا. */
      err.textContent = "الصفحة دي للإدارة والمحاسب بس"; return;
    }
    applySession(res.user);
  } catch (e) { err.textContent = e.message || "اسم المستخدم أو كلمة المرور غير صحيحة"; }
};
window.doLogout = async () => { try { await API.logout(); } catch (e) {} location.reload(); };

(async function () {
  try {
    const d = await API.me();
    if (d.user && ALLOWED.includes(d.user.role)) applySession(d.user);
  } catch (e) {}
})();

/* ── تهيئة البرنامج ──────────────────────────────────────
   🔴 قايمة الفروع بتتجاب هنا بنداء **منفصل**.

   في لوحة الإدارة كانت بتيجي من مستمع الفروع العام
   (tiar.html:2814) اللي بيملا \`window._branchesData\`. المتغيّر ده
   **مش** جزء من رد \`/pilot-accounting/month\`، فنقل الكود من غير
   النداء ده كان هيسيب فلتر الفروع فاضي **من غير أي خطأ**: الشرط
   \`window._branchesData?.length\` بيرجع falsy والسطر بيتخطّى بالسكوت.
   \`GET /api/branches\` مالوش قيد دور، فالمحاسب بيقراه. */
async function bootAccounts() {
  try {
    const b = await API.get("/api/branches");
    window._branchesData = b.items || [];
  } catch (e) {
    window._branchesData = [];
    showToast("تعذّر تحميل قايمة الفروع — الفلتر هيفضل «كل الفروع»", "error");
  }
  const m = $("paMonth");
  if (m && !m.value) {
    const d = new Date();
    m.value = d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0");
  }
  loadPilotAcct();
}
</script>

<script>
/* ══════════════════════════════════════════════════════════════
   ⬇️ منقول بالحرف من tiar.html (كان سطور 10724-11088).
   التعديل الوحيد: \`window._paDay\` اتشال — كان متعرّف ومابيتقراش
   في المشروع كله.
══════════════════════════════════════════════════════════════ */
${JS}
</script>
</body>
</html>
`;

fs.writeFileSync('public/accounts.html', OUT);
console.log(`\n✅ accounts.html اتبنى — ${OUT.split('\n').length} سطر`);
