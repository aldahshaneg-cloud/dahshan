/* الوضع النهاري لتطبيق العميل.
 *
 * ═══ ليه مش مجرد قلب ألوان ═══
 * الملف فيه ١٧ لون رمادي **مكتوب بالإيد** جوه الـCSS (حدود، تلميحات،
 * خلفيات الخرايط، التولتيب). لو حطينا لوحة نهارية على `:root` بس،
 * الألوان دي بتفضل غامقة وتطلع نص أبيض على أبيض في نص الشاشات.
 * فالخطوة الأولى: نحوّلهم لتوكنز، وبعدين نعرّف اللوحتين.
 *
 * ═══ التلات حالات ═══
 * المستخدم عنده تلات أوضاع مش اتنين: اختيار صريح (ليلي/نهاري) بيتحط على
 * `data-theme`، والوضع الافتراضي (نظام) اللي مابيحطش حاجة — وساعتها
 * `prefers-color-scheme` هو اللي بيحكم. عشان كده اللوحة النهارية متعرّفة
 * مرتين: تحت الميديا (بحارس `:not([data-theme="dark"])` عشان الاختيار
 * الصريح يغلب) وتحت `[data-theme="light"]`.
 */
const fs = require('fs');
const f = 'public/customer.html';
let s = fs.readFileSync(f, 'utf8');
const eol = s.includes('\r\n') ? '\r\n' : '\n';
if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');

let bad = 0;
const sub = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n < 1) { console.log(`  🔴 ${label}: مالقيتهاش`); bad++; return; }
  s = s.split(old).join(neu);
  console.log(`  ✓ ${label} (${n})`);
};

/* ── ① الرماديات المكتوبة بالإيد → توكنز ── */
console.log('── تحويل الألوان الثابتة لتوكنز ──');
sub('border-color:#3a3a42', 'border-color:var(--line2)', 'حدود التركيز');
sub('background:#3a3a42', 'background:var(--line2)', 'نقط المؤشّر');
sub('border:1px solid #3a3a42', 'border:1px solid var(--line2)', 'حد التولتيب');
sub('color:#5a5a63', 'color:var(--dim)', 'نص التلميح');
sub('background:#2a2a31', 'background:var(--line2)', 'نقط المسار');
sub('background:#1a1a1d', 'background:var(--sunken)', 'خلفية الخرايط');
sub('color:#d0d0d6', 'color:var(--txt2)', 'النص الثانوي');
sub('background:#26262c', 'background:var(--pop)', 'خلفية التولتيب');

/* ── ② اللوحتين ── */
console.log('── اللوحتين ──');
const OLD_ROOT = `:root{
  --bg:#0a0a0b; --bg2:#000; --card:#141416; --card2:#1b1b1f; --line:#232328;
  --red:#e8192c; --red2:#ff2d42; --txt:#fff; --muted:#8b8b93;
  --green:#22c55e; --orange:#f59e0b; --blue:#3b82f6;
  --r:16px; --r2:22px;
  --safe-b: env(safe-area-inset-bottom, 0px);
}`;

const NEW_ROOT = `:root{
  /* ══ اللوحة الليلية — الافتراضية ══ */
  --bg:#0a0a0b; --bg2:#000; --card:#141416; --card2:#1b1b1f; --line:#232328;
  --red:#e8192c; --red2:#ff2d42; --txt:#fff; --muted:#8b8b93;
  --green:#22c55e; --orange:#f59e0b; --blue:#3b82f6;
  /* التوكنز دي كانت ألوان مكتوبة بالإيد في ١٧ موضع — من غيرها الوضع
     النهاري كان بيطلع نص فاتح على خلفية فاتحة في نص الشاشات. */
  --line2:#3a3a42;      /* حدود التركيز ونقط المؤشّر */
  --dim:#5a5a63;        /* نص التلميح داخل الحقول */
  --txt2:#d0d0d6;       /* نص ثانوي أفتح من الأساسي */
  --sunken:#1a1a1d;     /* خلفية غاطسة: الخرايط والمساحات الفاضية */
  --pop:#26262c;        /* خلفية التولتيب */
  --shadow:rgba(0,0,0,.5);
  --r:16px; --r2:22px;
  --safe-b: env(safe-area-inset-bottom, 0px);
  color-scheme:dark;
}

/* ══ اللوحة النهارية ══
   متعرّفة مرتين عن قصد:
   ① تحت الميديا للوضع الافتراضي (نظام) — بحارس \`:not([data-theme="dark"])\`
      عشان لو المستخدم اختار ليلي صراحةً وجهازه نهاري، اختياره يغلب.
   ② تحت \`[data-theme="light"]\` عشان الاختيار الصريح يشتغل في الاتجاهين.
   الأحمر اتغمق شوية في النهاري — نفس درجة الليلي على أبيض بتبقى صارخة. */
@media (prefers-color-scheme:light){
  :root:not([data-theme="dark"]){
    --bg:#f4f4f6; --bg2:#eaeaee; --card:#fff; --card2:#f7f7f9; --line:#e2e2e8;
    --red:#d4142a; --red2:#e8192c; --txt:#111114; --muted:#6b6b76;
    --line2:#c9c9d2; --dim:#9a9aa4; --txt2:#3f3f48;
    --sunken:#edeef1; --pop:#fff; --shadow:rgba(0,0,0,.14);
    color-scheme:light;
  }
}
:root[data-theme="light"]{
  --bg:#f4f4f6; --bg2:#eaeaee; --card:#fff; --card2:#f7f7f9; --line:#e2e2e8;
  --red:#d4142a; --red2:#e8192c; --txt:#111114; --muted:#6b6b76;
  --line2:#c9c9d2; --dim:#9a9aa4; --txt2:#3f3f48;
  --sunken:#edeef1; --pop:#fff; --shadow:rgba(0,0,0,.14);
  color-scheme:light;
}
/* التولتيب أبيض في النهاري فمحتاج حد وظل بدل ما يضيع في الخلفية */
:root[data-theme="light"] #tip,
:root:not([data-theme="dark"]) #tip{ box-shadow:0 6px 20px var(--shadow) }`;

sub(OLD_ROOT, NEW_ROOT, 'اللوحتين اتعرّفوا');

/* سهم الـselect كان SVG بلون ثابت — بيختفي في النهاري */
sub(`fill='%238b8b93'`, `fill='%23888892'`, 'سهم القوائم (لون محايد للوضعين)');

/* لون شريط المتصفح بيتغيّر مع الوضع */
sub(`<meta name="theme-color" content="#0a0a0b" />`,
    `<meta name="theme-color" content="#0a0a0b" media="(prefers-color-scheme: dark)" />
<meta name="theme-color" content="#f4f4f6" media="(prefers-color-scheme: light)" />`,
    'لون شريط المتصفح');

fs.writeFileSync(f, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
console.log(bad ? `\n🔴 ${bad} مشكلة` : '\n✅ خلص');
process.exit(bad ? 1 : 0);
