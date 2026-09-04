/**
 * 🔒 اختبار «الكول سنتر بيشوف تفاصيل وشكوى بس».
 *
 * ═══ القاعدة ═══
 * موظف الكول سنتر بيتعامل مع العميل في حاجتين بس: استقبال الأوردر
 * وتسجيل الشكوى. أي إجراء تاني على الأوردر (إلغاء · تأجيل · فك تأجيل ·
 * نقل لفرع · تغيير حالة · إسناد طيار) شغل باقي المنظومة — الفرع
 * والإدارة والطيار. قرار صاحب النظام يوم 2026-08-30.
 *
 * ═══ ليه اختبار سلوكي ═══
 * الأزرار مبنية بتمبليت فيه شروط على `o.status`. فحص نصي بيسأل «هل كلمة
 * إلغاء موجودة؟» بيعدّي على حالة زي: الزرار اتشال من فرع من الشرط وفضل
 * في فرع تاني. فالاختبار ده بيشغّل `trackCard` **على كل حالة أوردر
 * موجودة في النظام** وبيقرا الأزرار اللي اتبنت فعلاً.
 *
 * التشغيل: node ops/test_cc_readonly.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (w, c, got) => { c ? (pass++, console.log('  ✓ ' + w))
  : (fail++, console.log('  ✗ ' + w + (got !== undefined ? '   ← ' + got : ''))); };

const FILE = 'public/callcenter.html';
const src = fs.readFileSync(FILE, 'utf8');

/* ── نقص دالة من الملف بعدّ الأقواس ── */
function cut(name) {
  const start = src.indexOf(name);
  if (start < 0) throw new Error('مالقيتش ' + name);
  let p = src.indexOf('(', start), pd = 0, body = -1;
  for (let j = p; j < src.length; j++) {
    if (src[j] === '(') pd++;
    else if (src[j] === ')') { pd--; if (!pd) { body = src.indexOf('{', j); break; } }
  }
  let d = 0;
  for (let j = body; j < src.length; j++) {
    const c = src[j];
    if (c === '{') d++;
    else if (c === '}') { d--; if (!d) return src.slice(start, j + 1); }
    else if (c === '`' || c === '"' || c === "'") {
      const q = c; j++;
      while (j < src.length && src[j] !== q) { if (src[j] === '\\') j++; j++; }
    }
  }
  throw new Error('ماقدرتش أقفل ' + name);
}

/* بدائل بسيطة للمساعدات اللي trackCard بتعتمد عليها */
const helpers = {
  esc: s => String(s == null ? '' : s),
  escJs: s => String(s == null ? '' : s),
  fmt: n => String(Number(n) || 0),
  fmt0: n => String(Number(n) || 0),
  badge: s => '<span>' + s + '</span>',
  branchName: () => 'فرع',
  toDateStr: () => '2026-08-30',
  timeOf: () => '15:47',
};
const trackCard = new Function(...Object.keys(helpers), cut('function trackCard(') + '\nreturn trackCard;')
  (...Object.values(helpers));

/* كل الحالات اللي الأوردر ممكن يبقى فيها — الجدول والواجهة بيستعملوها */
const STATUSES = ['قيد التنفيذ', 'جاري التوصيل', 'تم التسليم',
                  'لم يتم التوصيل', 'ملغي', 'مؤجل'];

const mkOrder = status => ({
  id: 'o1', orderNum: 'MODIR-260830-001', status,
  branchId: 'b1', branchName: 'البرلس', createdAt: '2026-08-30T12:47:00.000Z',
  addedBy: 'محمد ربيع', totalDeliveryPrice: 30,
  senderName: 'محمد ربيع', senderPhone: '01503118144', senderAddress: 'البرامون، المنصورة، الدقهلية',
  deliveries: [{ receiverName: 'عمرو محمود', receiverPhone: '01225349736',
                 zoneName: 'الجلاء', address: 'ش الجمال ع6 د5', zonePrice: 30 }],
  pilotName: '', notes: '',
});

/* الأفعال الممنوعة — الاسم زي ما بيظهر للموظف، ودالته */
const BANNED = [
  ['إلغاء',       'cancelOrder'],
  ['تأجيل',       'postponeOrder'],
  ['فك التأجيل',  'unpostponeOrder'],
  ['نقل لفرع',    'openTransferOrderModal'],
];
const ALLOWED = [
  ['التفاصيل',    'viewOrderDetails'],
  ['تسجيل شكوى',  'openComplaintModal'],
];

const buttonsOf = html =>
  [...html.matchAll(/<button[^>]*onclick="([a-zA-Z_$][\w$]*)\(/g)].map(m => m[1]);

console.log('\n══ 1) 🔴 كل حالة: الأزرار المسموحة بس ══');
for (const st of STATUSES) {
  const html = trackCard(mkOrder(st));
  const btns = buttonsOf(html);
  ok('«' + st + '» — عدد الأزرار = 2', btns.length === 2, btns.length + ': ' + btns.join(', '));
  ok('«' + st + '» — التفاصيل + الشكوى', btns.includes('viewOrderDetails') && btns.includes('openComplaintModal'),
     btns.join(', ') || '(مافيش)');
}

console.log('\n══ 2) 🔴 مفيش أي فعل ممنوع في أي حالة ══');
for (const [اسم, fn] of BANNED) {
  const hits = STATUSES.filter(st => {
    const html = trackCard(mkOrder(st));
    return buttonsOf(html).includes(fn) || html.includes('>' + '⏸️ ' + اسم) || html.includes(اسم + '</button>');
  });
  ok('«' + اسم + '» مش ظاهر خالص', hits.length === 0, 'ظاهر في: ' + hits.join(' · '));
}

console.log('\n══ 3) الدوال الممنوعة اتشالت من الملف ══');
for (const [اسم, fn] of BANNED) {
  const defined = new RegExp('(window\\.)?' + fn + '\\s*=\\s*(async\\s*)?function|function\\s+' + fn + '\\b').test(src);
  const called  = new RegExp('onclick="' + fn + '\\(').test(src);
  ok('`' + fn + '` مالهاش تعريف', !defined, 'لسه متعرّفة');
  ok('`' + fn + '` مالهاش نداء', !called, 'لسه بتتنده');
}

console.log('\n══ 4) اللي لازم يفضل شغّال ══');
for (const [اسم, fn] of ALLOWED) {
  ok('«' + اسم + '» لسه موجود', new RegExp('onclick="' + fn + '\\(').test(src));
}
ok('مودال الشكوى موجود', /openComplaintModal\s*=/.test(src) || /function openComplaintModal/.test(src));
ok('مودال التفاصيل موجود', /viewOrderDetails\s*=/.test(src) || /function viewOrderDetails/.test(src));
ok('إنشاء أوردر جديد لسه شغّال', /window\.addOrder = async function/.test(src));
ok('صفحة الطلبات لسه موجودة', /id="page-orders"/.test(src));

console.log('\n══ 5) الحالة «مؤجل» — بتتعرض بس ما فيهاش زرار ══');
{
  const html = trackCard(mkOrder('مؤجل'));
  ok('الحالة بتتعرض للموظف', html.includes('مؤجل'));
  ok('من غير زرار فك تأجيل', !html.includes('فك التأجيل'));
}

/* ═══ 6) الحتة اللي ممكن تضيع بسهولة ═══
   «تأجيل» كان زراره الوحيد في كارت الكول سنتر. شيله من غير ما نضيفه
   لحد تاني معناه إن الميزة تختفي من المنظومة كلها — وأسوأ: أوردر مؤجل
   مايبقاش ليه طريق يرجع منها. الفحوص دي بتحرس الطريق ده. */
console.log('\n══ 6) 🔴 التأجيل انتقل للفرع والإدارة ══');
for (const f of ['branch', 'tiar']) {
  const t = fs.readFileSync('public/' + f + '.html', 'utf8');
  ok(f + ': زرار «تأجيل» موجود', /onclick="postponeOrder\(/.test(t));
  ok(f + ': زرار «فك التأجيل» موجود', /onclick="unpostponeOrder\(/.test(t));
  ok(f + ': الدالتين متعرّفتين',
     /window\.postponeOrder\s*=/.test(t) && /window\.unpostponeOrder\s*=/.test(t));
  /* من غير ده الزرار بيبقى موجود بس الأوردر المؤجل مابيوصلوش */
  ok('🔴 ' + f + ': الأوردر المؤجل بيظهر في جدول', /o\.status === "مؤجل"/.test(t)
     && /"قيد التنفيذ" \|\| o\.status === "مؤجل"/.test(t));
}

console.log('\n════════════════════════════════════════');
console.log('CC READONLY: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
