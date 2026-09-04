/* إصلاح: خانة المنطقة بتتجمّد على الوضع اللي اتبنت فيه.
 *
 * ═══ السبب ═══
 * `zonepick.js` بتنسخ شكل الـ<select> الأصلي **كستايل مباشر** على خانة
 * البحث (`copyLook`) — فيهم `color` و`backgroundColor` وألوان الحدود.
 * ده كان صح وقت ما كان فيه وضع واحد: الستايل المباشر بيضمن إن الخانة
 * تطابق باقي الحقول في كل تطبيق مهما كان الـCSS بتاعه.
 *
 * بس مع وجود وضعين، الستايل المباشر بيغلب على التوكنز — فالخانة اللي
 * اتبنت والتطبيق نهاري بتفضل بيضا بعد ما تحوّل لليلي، والعكس.
 *
 * ═══ ليه مش بنصلّح zonepick.js ═══
 * الملف ده مشترك بين ٤ تطبيقات (كول سنتر · إدارة · محلات · عميل)، وفيه
 * إصلاح دقيق لانهيار الخانة لبكسل واحد. تغيير `copyLook` معناه إعادة
 * اختبار الأربعة قبل الإطلاق بيومين. والوضعين موجودين في تطبيق العميل بس.
 *
 * ═══ الحل ═══
 * `applyTheme` بتعيد تلوين أي خانة zonepick موجودة بقيم التوكنز الجديدة.
 * الأبعاد (الارتفاع والحشو والحدود) بتفضل زي ما `copyLook` نسختها — إحنا
 * بنصلّح اللون بس، وده اللي بيتغيّر مع الوضع.
 */
const fs = require('fs');
const f = 'public/customer.html';
let s = fs.readFileSync(f, 'utf8');
const eol = s.includes('\r\n') ? '\r\n' : '\n';
if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');

const OLD = `  /* لون شريط المتصفح بيتبع الوضع الفعلي مش الاختيار */`;
const NEW = `  /* خانات المنطقة (zonepick) بتتلوّن بستايل مباشر وقت الرسم، فبتتجمّد
     على الوضع القديم. بنعيد تلوينها هنا — الأبعاد بتفضل زي ما هي. */
  repaintZonePicks();
  /* لون شريط المتصفح بيتبع الوضع الفعلي مش الاختيار */`;

if (s.split(OLD).length - 1 !== 1) { console.log('🔴 مرساة applyTheme'); process.exit(1); }
s = s.replace(OLD, NEW);

const FN = `
/* خانات zonepick بتاخد ألوانها كستايل مباشر من الـ<select> وقت الرسم
   (شوف copyLook في assets/js/zonepick.js)، والستايل المباشر بيغلب على
   التوكنز. فبعد أي تبديل وضع بنعيد تلوينها من التوكنز الحالية.
   بنلمس اللون بس — الارتفاع والحشو والحدود بتفضل زي ما نُسخت. */
function repaintZonePicks() {
  const cs = getComputedStyle(document.documentElement);
  const tok = k => cs.getPropertyValue(k).trim();
  const bg = tok("--card2"), fg = tok("--txt"), ln = tok("--line"), pop = tok("--card");
  document.querySelectorAll(".zp-in").forEach(el => {
    el.style.backgroundColor = bg;
    el.style.color = fg;
    ["Top", "Right", "Bottom", "Left"].forEach(side => {
      el.style["border" + side + "Color"] = ln;
    });
  });
  document.querySelectorAll(".zp-pop").forEach(el => {
    el.style.backgroundColor = pop;
    el.style.color = fg;
    el.style.borderColor = ln;
  });
}
window.repaintZonePicks = repaintZonePicks;
`;

const ANCHOR = '\nfunction currentTheme() {';
if (s.split(ANCHOR).length - 1 !== 1) { console.log('🔴 مرساة الدالة'); process.exit(1); }
s = s.replace(ANCHOR, FN + ANCHOR);

fs.writeFileSync(f, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
console.log('✓ إعادة التلوين اتضافت');
