/**
 * support-numbers.js — أرقام الدعم: من رقم واحد لقايمة أرقام
 *
 * ═══ المشكلة اللي بيحلّها ═══
 * الإعدادات كانت بتشيل رقم تليفون واحد ورقم واتساب واحد
 * (`site.info.phone` / `site.info.whatsapp`)، وسبع صفحات بتقراهم
 * مباشرةً. لما بقى مطلوب أكتر من رقم، الاختيار كان بين تغيير الشكل
 * وكسر السبع صفحات، أو إضافة قايمة جنبه.
 *
 * الشكل الجديد **إضافة مش تغيير**:
 *
 * ⚠️ الأرقام دي **أمثلة وهمية** — مش أرقام الشركة. كتابة رقم حقيقي في
 * تعليق بتخلّي حد يفتكره القيمة الصح ويحطه من غير ما يراجع.
 *
 *     info.phone      = "01000000001"                  ← الأساسي، زي ما هو
 *     info.phones     = [ {n:"01000000001", label:"خدمة العملاء"},
 *                         {n:"01000000002", label:"الشكاوى"} ]
 *     info.whatsapp   = "201000000001"
 *     info.whatsapps  = [ {n:"201000000001", label:"الدعم الفني"} ]
 *
 * القاعدة: `phone` بيفضل دايمًا = أول رقم في `phones`. اللي بيحفظ من
 * لوحة التحكم هو اللي بيمزامنهم (شوف `syncPrimary` تحت). فالصفحة القديمة
 * اللي بتقرا `phone` بتفضل شغّالة وبتعرض الرقم الأساسي، والصفحة الجديدة
 * بتعرض القايمة كلها.
 *
 * ═══ ليه ملف مستقل ═══
 * المستهلكين على تلات مجموعات مالهاش حزمة مشتركة: صفحات الموقع بتحمّل
 * `site-cms.js`، وبوابات الموظفين بتحمّل `constants.js`، وتطبيق العميل
 * مابيحمّلش أي واحد فيهم. فبدل تلات نسخ من نفس عشر سطور، ملف واحد
 * بيتضاف بـ`<script>` في اللي محتاجه.
 */
(function (global) {
  "use strict";

  function digitsOf(v) {
    return String(v == null ? "" : v).replace(/\D/g, "");
  }

  /**
   * الرقم بصيغة دولية مصرية — الشكل الوحيد اللي `wa.me` بيقبله.
   *
   * 🔴 الواقعة (2026-09-02): الأرقام متخزّنة في الإعدادات بالشكل المحلي
   * `01040065651`، وصفحات الموقع كانت بتلزقه في `wa.me/` زي ما هو.
   * واتساب بيقرا الرقم ده كأنه كود دولة `010` فبيفتح صفحة «رقم غير
   * صالح» — يعني كل أزرار الواتساب في الموقع العام كانت ميتة، والزبون
   * اللي بيضغط بيستنتج إن الشركة مش بتردّ.
   *
   * القاعدة: بنشيل غير الأرقام، وبعدين:
   *   `00` بادئة دولية      → بتتشال
   *   بيبدأ بـ `20` وطوله ١١–١٢ → دولي جاهز، بيرجع زي ما هو
   *   بيبدأ بـ `0`          → بيتشال الصفر ويتحط `20`
   *   غير كده               → بيتحط `20` قدامه
   * الرقم الفاضي بيرجّع فاضي — والمنادي لازم يخفي الزرار مش يبني رابط ناقص.
   */
  function intl(v) {
    var d = digitsOf(v);
    if (!d) return "";
    if (d.slice(0, 2) === "00") d = d.slice(2);
    if (d.slice(0, 2) === "20" && d.length >= 11 && d.length <= 12) return d;
    if (d.charAt(0) === "0") d = d.slice(1);

    return "20" + d;
  }

  /**
   * بيوحّد أي شكل للمدخل: نص، أو كائن {n,label}، أو {number,label}.
   * بيرجّع null للفاضي — والمنادي بيفلتره.
   */
  function one(item, waMode) {
    if (item == null) return null;
    var n, label = "";
    if (typeof item === "string" || typeof item === "number") {
      n = String(item);
    } else if (typeof item === "object") {
      n = String(item.n != null ? item.n : (item.number != null ? item.number : ""));
      label = String(item.label != null ? item.label : "").trim();
    } else {
      return null;
    }
    n = waMode ? digitsOf(n) : String(n).trim();
    if (!n) return null;
    return { n: n, label: label };
  }

  /**
   * `info` → قايمة أرقام نضيفة، من غير تكرار، والأساسي أول واحد.
   *
   * @param {object} info   كائن `site.info` (أو `pilotAppContent.support`)
   * @param {string} kind   "phone" أو "whatsapp"
   * @returns {Array<{n:string,label:string}>}
   */
  function list(info, kind) {
    info = info || {};
    var waMode  = kind === "whatsapp";
    var listKey = waMode ? "whatsapps" : "phones";
    var oneKey  = waMode ? "whatsapp"  : "phone";

    var out = [];
    var raw = info[listKey];
    if (Object.prototype.toString.call(raw) === "[object Array]") {
      for (var i = 0; i < raw.length; i++) {
        var v = one(raw[i], waMode);
        if (v) out.push(v);
      }
    }
    /* القايمة فاضية أو مش موجودة → بنقع على الرقم المفرد. ده اللي بيخلّي
       الإعدادات القديمة (اللي لسه مافيهاش `phones`) تشتغل من غير ترحيل. */
    if (!out.length) {
      var single = one(info[oneKey], waMode);
      if (single) out.push(single);
    }

    var seen = {};
    var uniq = [];
    for (var j = 0; j < out.length; j++) {
      var key = digitsOf(out[j].n);
      if (seen[key]) continue;
      seen[key] = true;
      uniq.push(out[j]);
    }
    return uniq;
  }

  /**
   * بيرجّع كائن `info` بعد ما يخلّي الرقم المفرد = أول رقم في القايمة.
   * بيتنده وقت **الحفظ** من لوحة التحكم — مش وقت العرض — عشان اللي
   * بيتخزّن يبقى متسق، والصفحات اللي بتقرا `phone` تلاقي رقم صح.
   */
  function syncPrimary(info) {
    info = info || {};
    var ph = list(info, "phone");
    var wa = list(info, "whatsapp");
    info.phone    = ph.length ? ph[0].n : "";
    info.whatsapp = wa.length ? wa[0].n : "";
    info.phones    = ph;
    info.whatsapps = wa;
    return info;
  }

  /**
   * رابط `wa.me` جاهز — أو نص فاضي لو مفيش رقم.
   * أي صفحة بتبني الرابط بإيدها معرّضة لفخ الصيغة المحلية، فالبناء
   * بيتعمل هنا مرة واحدة والصفحات بتنده الدالة دي.
   */
  function waLink(v, text) {
    var n = intl(v);
    if (!n) return "";

    return "https://wa.me/" + n + (text ? "?text=" + encodeURIComponent(text) : "");
  }

  global.SupportNums = {
    list: list,
    phones: function (info) { return list(info, "phone"); },
    whatsapps: function (info) { return list(info, "whatsapp"); },
    syncPrimary: syncPrimary,
    digits: digitsOf,
    intl: intl,
    waLink: waLink,
  };
})(window);
