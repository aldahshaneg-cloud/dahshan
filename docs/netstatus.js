/* ══════════════════════════════════════════════════════════════
   مؤشر انقطاع الاتصال — وحدة مشتركة لكل التطبيقات
   ──────────────────────────────────────────────────────────────
   ليه محتاجينها؟ فايربيز لما النت يقطع مابيرميش خطأ — بيحط الكتابة
   في طابور محلي و await set(...) بيفضل مستني **للأبد**. الكود في كل
   التطبيقات بيعمل:

       btn.disabled = true; btn.textContent = "جاري الحفظ…";
       await set(...)                      ← مابيرجعش أبدًا

   فالزرار بيفضل عالق على "جاري الحفظ…" والموظف مستني حاجة مش هتحصل،
   وبعدين يقفل الصفحة ويعيد الإدخال — ولما النت يرجع فايربيز بيبعت
   الاتنين فيطلع أوردر مكرر.

   الوحدة دي بتراقب `.info/connected` (مسار خاص في فايربيز بيقول
   حالة الاتصال الحقيقية بالسيرفر مش بس حالة كارت الشبكة) وبتوري بانر
   واضح، فالمستخدم يعرف إن المشكلة في النت مش إن البرنامج واقع.

   الاستخدام:
       import { watchConnection } from "./netstatus.js";
       watchConnection(db);
══════════════════════════════════════════════════════════════ */
import { ref, onValue }
  from "https://www.gstatic.com/firebasejs/10.12.2/firebase-database.js";

const BAR_ID = "_netStatusBar";

/* أول ما الصفحة تفتح، `.info/connected` بيبقى false لحظة لحد ما
   الاتصال يتم. من غير تأخير بسيط، البانر كان هيرفرف في كل فتحة. */
const OFFLINE_DELAY_MS = 3000;
/* رسالة الرجوع بتختفي لوحدها — مش مشكلة قايمة، مجرد طمأنة */
const BACK_ONLINE_MS   = 2500;

function bar() {
  let el = document.getElementById(BAR_ID);
  if (el) return el;
  el = document.createElement("div");
  el.id = BAR_ID;
  el.setAttribute("role", "status");
  el.style.cssText = [
    "position:fixed", "top:0", "inset-inline:0", "z-index:2147483647",
    "padding:9px 14px", "text-align:center",
    "font-family:inherit", "font-size:13.5px", "font-weight:700",
    "line-height:1.7", "letter-spacing:.1px",
    "box-shadow:0 2px 14px rgba(0,0,0,.35)",
    "transition:transform .25s ease", "transform:translateY(-100%)"
  ].join(";");
  document.body.appendChild(el);
  return el;
}

function show(html, bg, color) {
  const el = bar();
  el.innerHTML = html;
  el.style.background = bg;
  el.style.color = color;
  /* مابنستخدمش requestAnimationFrame هنا: هو مابيشتغلش لما الصفحة
     مش معروضة (تبويب ورا مثلاً)، فالبانر كان ممكن يفضل مستخفي وقت ما
     المستخدم محتاجه بالظبط. قراءة offsetHeight بتجبر المتصفح يحسب
     التخطيط فورًا، فالانتقال يشتغل صح من غير أي اعتماد على الرسم. */
  void el.offsetHeight;
  el.style.transform = "translateY(0)";
}

function hide() {
  const el = document.getElementById(BAR_ID);
  if (el) el.style.transform = "translateY(-100%)";
}

/**
 * بيراقب الاتصال بفايربيز ويعرض بانر لما ينقطع.
 * @param {object} db  كائن قاعدة البيانات
 * @param {object} [opts]
 * @param {function(boolean):void} [opts.onChange] بينده بحالة الاتصال
 */
export function watchConnection(db, opts = {}) {
  if (!db || window["__netStatusOn"]) return;   // مرة واحدة لكل صفحة
  window["__netStatusOn"] = true;

  let offlineTimer = null;
  let backTimer    = null;
  let wasOffline   = false;

  onValue(ref(db, ".info/connected"), snap => {
    const online = snap.val() === true;
    try { opts.onChange && opts.onChange(online); } catch (_) {}

    if (online) {
      clearTimeout(offlineTimer); offlineTimer = null;
      if (wasOffline) {
        wasOffline = false;
        show("✅ رجع الاتصال — أي حاجة كانت مستنية اتبعتت",
             "linear-gradient(90deg,#16a34a,#22c55e)", "#fff");
        clearTimeout(backTimer);
        backTimer = setTimeout(hide, BACK_ONLINE_MS);
      } else {
        hide();
      }
      return;
    }

    // مقطوع — بننتظر شوية قبل ما نزعج المستخدم بانقطاع لحظي
    if (offlineTimer) return;
    offlineTimer = setTimeout(() => {
      wasOffline = true;
      clearTimeout(backTimer);
      show("⚠️ مفيش اتصال بالإنترنت — أي حفظ دلوقتي مش هيتسجّل لحد ما النت يرجع. " +
           "متعملش نفس الإدخال تاني عشان مايتكررش.",
           "linear-gradient(90deg,#b91c1c,#e8192c)", "#fff");
    }, OFFLINE_DELAY_MS);
  });
}
