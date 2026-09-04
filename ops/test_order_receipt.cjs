/**
 * 🖨️ اختبار «بيانات الأوردر للطيار من غير تطبيق» — تطبيق الفرع.
 *
 * ═══ ليه الميزة دي موجودة ═══
 * الإطلاق 2026-09-02 وتطبيق الطيار أندرويد بس. قرار صاحب النظام
 * (2026-08-31): الطيار اللي معاه أيفون ياخد الأوردر واتساب أو ريسيت
 * مطبوع من الفرع — لحد ما نسخة iOS تتبني.
 *
 * ═══ ليه اختبار سلوكي مش grep ═══
 * الرسالة والريسيت هما **مصدر معلومات الطيار الوحيد** وقت التوصيل.
 * لو حقل سقط من النص (رقم المستلم مثلًا) الطيار بيقف في الشارع من غير
 * وسيلة تواصل. فالاختبار بيشغّل الدوال فعليًا على أوردر متعدد النقاط
 * وبيتأكد إن **كل** حقل حرج طالع في الناتج، مش بس إن الدالة موجودة.
 *
 * التشغيل: node ops/test_order_receipt.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (w, c, got) => { c ? (pass++, console.log('  ✓ ' + w))
  : (fail++, console.log('  ✗ ' + w + (got !== undefined ? '   ← ' + got : ''))); };

const FILE = 'public/branch.html';
const src = fs.readFileSync(FILE, 'utf8');

/* ── نقص دالة بعدّ الأقواس (نفس نمط باقي الاختبارات) ── */
function cut(anchor) {
  const start = src.indexOf(anchor);
  if (start < 0) throw new Error('مالقيتش ' + anchor);
  const open = src.indexOf('{', start + anchor.length - 1);
  let d = 0;
  for (let j = open; j < src.length; j++) {
    const c = src[j];
    if (c === '{') d++;
    else if (c === '}') { d--; if (!d) return src.slice(start, j + 1); }
    else if (c === '`' || c === '"' || c === "'") {
      const q = c; j++;
      while (j < src.length && src[j] !== q) {
        if (src[j] === '\\') j++;
        else if (q === '`' && src[j] === '$' && src[j + 1] === '{') {
          /* template literal جواه ${...} — بنعدّي جواه بنفس عدّاد الأقواس */
          j += 2; let td = 1;
          while (j < src.length && td) {
            if (src[j] === '{') td++;
            else if (src[j] === '}') td--;
            else if (src[j] === '"' || src[j] === "'") { const q2 = src[j]; j++; while (j < src.length && src[j] !== q2) { if (src[j] === '\\') j++; j++; } }
            j++;
          }
          j--;
        }
        j++;
      }
    }
  }
  throw new Error('ماقدرتش أقفل ' + anchor);
}

/* ── أوردر وهمي متعدد النقاط بكل الحقول الحرجة ── */
const order = {
  id: 'o1', orderNum: 'MODIR-260901-007', branchName: 'البرلس', pilotId: 'p1',
  senderName: 'محمد ربيع', senderPhone: '01503118144', senderPhone2: '01000000002',
  senderAddress: 'البرامون، المنصورة',
  totalDeliveryPrice: 55, storePrepaid: 120, storePrepaidNote: 'عهدة بضاعة',
  notes: 'الاتصال قبل الوصول', createdAt: '2026-09-01T10:00:00.000Z',
  deliveries: [
    { receiverName: 'عمرو محمود', receiverPhone: '01225349736', receiverPhone2: '01111111111',
      zoneName: 'الجلاء', address: 'ش الجمال ع6 د5', zonePrice: 30, orderPrice: 120 },
    { receiverName: 'سارة أحمد', receiverPhone: '01099999999',
      zoneName: 'توريل', address: '', zonePrice: 25 },
  ],
};
const pilot = { id: 'p1', name: 'أحمد الطيار', phone1: '01012345678' };

/* ═══ 1) 🔴 نص الواتساب فيه كل حقل حرج ═══ */
console.log('\n══ 1) 🔴 رسالة الواتساب كاملة ══');
{
  const fn = new Function(cut('function _orderWhatsAppText(o) {') + '\nreturn _orderWhatsAppText;')();
  const t = fn(order);
  for (const [what, needle] of [
    ['رقم الأوردر', 'MODIR-260901-007'],
    ['اسم المرسِل', 'محمد ربيع'],
    ['تليفون المرسِل', '01503118144'],
    ['عنوان الاستلام', 'البرامون'],
    ['المستلم الأول', 'عمرو محمود'],
    ['تليفونه', '01225349736'],
    ['تليفونه التاني', '01111111111'],
    ['منطقته', 'الجلاء'],
    ['عنوانه', 'ش الجمال ع6 د5'],
    ['توصيلته', '30 ج.م'],
    ['عهدته', 'عهدة: 120'],
    ['المستلم التاني', 'سارة أحمد'],
    ['منطقته', 'توريل'],
    ['إجمالي التوصيل', 'إجمالي التوصيل: 55'],
    ['إجمالي العهدة', 'إجمالي العهدة: 120'],
    ['الملاحظات', 'الاتصال قبل الوصول'],
  ]) ok(what, t.includes(needle), 'مش في النص');
  ok('عدد النقاط ظاهر', t.includes('التسليم (2)'));
  /* الحقل الفاضي مايطبعش سطر فاضي مضلل */
  const t2 = fn({ ...order, notes: '', storePrepaid: 0 });
  ok('مافيش سطر ملاحظات لما مافيش ملاحظات', !t2.includes('📝'));
  ok('مافيش سطر عهدة إجمالية لما صفر', !t2.includes('إجمالي العهدة'));
}

/* ═══ 2) 🔴 زرار الواتساب بيفتح wa.me على رقم الطيار ═══ */
console.log('\n══ 2) 🔴 الواتساب بيوصل للطيار الصح ══');
{
  const setup = (pilots, orders) => {
    const opened = [];
    const toasts = [];
    const win = { _ordersData: orders, _allOrdersData: [], _allPilotsData: pilots, _pilotsData: [],
                  open: (u) => opened.push(u) };
    const body =
      cut('function _orderWhatsAppText(o) {') + '\n' +
      cut('function _findOrderAnywhere(orderId) {') + '\n' +
      cut('function _toWhatsAppNumber(phone) {') + '\n' +
      cut('window.sendOrderWhatsAppToPilot = function(orderId) {') + ';\n' +
      'return window.sendOrderWhatsAppToPilot;';
    const fn = new Function('window', 'showToast', body)(win, (m, k) => toasts.push(k + ':' + m));
    return { fn, opened, toasts };
  };

  const h = setup([pilot], [order]);
  h.fn('o1');
  ok('فتح رابط واحد', h.opened.length === 1, h.opened.length);
  ok('🔴 على رقم الطيار بصيغة دولية', (h.opened[0] || '').startsWith('https://wa.me/201012345678?text='),
     (h.opened[0] || '').slice(0, 45));
  const decoded = decodeURIComponent((h.opened[0] || '').split('?text=')[1] || '');
  ok('والنص فيه رقم الأوردر', decoded.includes('MODIR-260901-007'));
  ok('والنص فيه تليفون المستلم', decoded.includes('01225349736'));

  const noPhone = setup([{ ...pilot, phone1: '' }], [order]);
  noPhone.fn('o1');
  ok('طيار من غير رقم → رسالة خطأ ومفيش فتح', noPhone.opened.length === 0 && noPhone.toasts.some(t => t.startsWith('error')),
     noPhone.toasts.join('|'));

  const noOrder = setup([pilot], []);
  noOrder.fn('ghost');
  ok('أوردر مش موجود → رسالة خطأ', noOrder.opened.length === 0 && noOrder.toasts.some(t => t.startsWith('error')));
}

/* ═══ 3) 🔴 الريسيت المطبوع فيه كل حقل حرج ═══ */
console.log('\n══ 3) 🔴 الريسيت كامل ══');
{
  let written = '';
  const win = { _ordersData: [order], _allOrdersData: [], _allPilotsData: [pilot], _pilotsData: [],
                open: () => ({ document: { write: (h) => { written += h; }, close() {} } }) };
  const esc = s => String(s == null ? '' : s);
  const body =
    cut('function _findOrderAnywhere(orderId) {') + '\n' +
    cut('window.printOrderReceipt = function(orderId) {') + ';\n' +
    'return window.printOrderReceipt;';
  const fn = new Function('window', 'showToast', 'esc', body)(win, () => {}, esc);
  fn('o1');
  for (const [what, needle] of [
    ['رقم الأوردر', 'MODIR-260901-007'],
    ['اسم الطيار', 'أحمد الطيار'],
    ['المرسِل', 'محمد ربيع'],
    ['المستلم الأول', 'عمرو محمود'],
    ['تليفونه', '01225349736'],
    ['المنطقة', 'الجلاء'],
    ['المستلم التاني', 'سارة أحمد'],
    ['إجمالي التوصيل', 'إجمالي التوصيل'],
    ['العهدة', 'إجمالي العهدة'],
    ['الملاحظات', 'الاتصال قبل الوصول'],
    ['الطباعة التلقائية', 'window.print'],
    ['اتجاه عربي', 'dir="rtl"'],
  ]) ok(what, written.includes(needle), 'مش في الريسيت');
  ok('مقاس ريسيت (٨٠مم)', written.includes('max-width:300px'));
}

/* ═══ 4) الأزرار في مكانها الصح ═══ */
console.log('\n══ 4) الأزرار في الجداول الصح ══');
{
  ok('زرار الواتساب موجود مرة واحدة', (src.match(/onclick="sendOrderWhatsAppToPilot\(/g) || []).length === 1);
  ok('زرار الريسيت موجود مرتين', (src.match(/onclick="printOrderReceipt\(/g) || []).length === 2);
  /* الواتساب في جدول «جاري التوصيل» بس — هناك بس فيه طيار مسند.
     بنحدد الجدول بجواره المميز transferOrderPilot. */
  const waIdx = src.indexOf('onclick="sendOrderWhatsAppToPilot(');
  const near = src.slice(waIdx, waIdx + 600);
  ok('الواتساب جنب «نقل لطيار آخر» (جدول جاري التوصيل)', near.includes('transferOrderPilot'));
  /* وريسيت واحد في «قيد التنفيذ» (جنب زرار التحميل) */
  const prIdxs = [];
  let p = -1; while ((p = src.indexOf('onclick="printOrderReceipt(', p + 1)) > -1) prIdxs.push(p);
  const nearPending = prIdxs.some(i => src.slice(i, i + 800).includes('assignPilotToOrder'));
  ok('وريسيت في جدول «قيد التنفيذ» (جنب التحميل)', nearPending);
}

console.log('\n════════════════════════════════════════');
console.log('ORDER RECEIPT: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
