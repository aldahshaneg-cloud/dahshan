/* ══════════════════════════════════════════════════════════════════
   نظام التحكم في نصوص الموقع
   ══════════════════════════════════════════════════════════════════

   الفكرة في سطرين:
   أي عنصر في أي صفحة عليه `data-cms="مفتاح"` بيبقى قابل للتحرير من لوحة
   تحكم الموقع. النص المكتوب في الـHTML بيفضل هو **الافتراضي**، واللوحة
   بتـ«تدهسه» بس لما يكون فيه قيمة محفوظة.

   🔴 ليه الافتراضي بيفضل في الـHTML ومش بيتشال:
   لو الصفحات بترندر من الـAPI بس، أول عطل في الشبكة أو قاعدة فاضية =
   موقع أبيض. بالطريقة دي أسوأ حالة إن الزائر يشوف النص الأصلي — والموقع
   بيشتغل كامل حتى لو الـAPI واقع تمامًا.

   ── اصطلاح المفاتيح ──
   `صفحة.قسم.حقل` — مثال: `home.hero.title` · `services.intro.lead`
   الصفحة = اسم الملف بلا امتداد (`index` = `home`).

   ── HTML مقابل نص ──
   الافتراضي إن القيمة بتتحط كـ**نص** (textContent) — يعني لو حد كتب
   وسوم في اللوحة بتظهر كحروف مش كأكواد. ده مقصود: اللوحة مش محرر HTML.
   لو عنصر محتاج وسوم (سطر جديد أو <b>) يتكتب عليه `data-cms-html`،
   وساعتها القيمة بتتحط كـHTML — استخدمها بحساب.

   ── الروابط ──
   عنصر عليه `data-cms-href="مفتاح"` بيتغيّر رابطه بدل نصه.

   ── الاكتشاف الذاتي ──
   اللوحة **مابتعرفش المفاتيح مسبقًا** — بتجيب صفحات الموقع وتمسح فيها
   على `data-cms` وتبني الفورم منها. يعني أي مفتاح جديد تضيفه في أي صفحة
   بيظهر في اللوحة لوحده، من غير ما حد يعدّل اللوحة. ده أهم قرار في
   التصميم: النظام بيفضل متسق مع نفسه بلا صيانة.
   ══════════════════════════════════════════════════════════════════ */
(function () {
  "use strict";

  var ENDPOINT = "/api/settings/site";

  /** بيطلّع خريطة النصوص من رد الـAPI مهما كان تداخله. */
  function extract(json) {
    var site = (json && json.site) || {};
    // الرد بيرجع أحيانًا { site: { site: {...} } } — بنقبل الشكلين
    if (site.site && typeof site.site === "object") { site = site.site; }
    var c = site.content;
    var i = site.info;
    return {
      content: (c && typeof c === "object" && !Array.isArray(c)) ? c : {},
      info:    (i && typeof i === "object" && !Array.isArray(i)) ? i : {},
    };
  }

  /** بيطبّق النصوص المحفوظة على الصفحة. آمن لو اتنده أكتر من مرة. */
  function apply(content, info) {
    content = content || {};
    info = info || {};
    var hits = 0;

    document.querySelectorAll("[data-cms]").forEach(function (el) {
      var v = content[el.getAttribute("data-cms")];
      if (typeof v !== "string" || v.trim() === "") { return; }   // فاضي = سيب الافتراضي
      if (el.hasAttribute("data-cms-html")) { el.innerHTML = v; }
      else { el.textContent = v; }
      hits++;
    });

    document.querySelectorAll("[data-cms-href]").forEach(function (el) {
      var v = content[el.getAttribute("data-cms-href")];
      if (typeof v !== "string" || v.trim() === "") { return; }
      el.setAttribute("href", v);
      hits++;
    });

    /* بيانات هوية الشركة (سجل تجاري، بطاقة ضريبية…) — مش محتوى صفحة.
       مصدرها `site.info` نفسه اللي فيه التليفون والبريد، وبتتحرّر من تبويب
       «معلومات» في اللوحة مش من «محتوى الصفحات».

       ليه منفصلة عن data-cms: الأرقام دي بتتكرر في فوتر **كل** صفحة، فلو
       اتعاملت كمحتوى صفحة كان المفتاح هيتكرر 8 مرات في اللوحة والمدير
       هيلاقي نفس الحقل تمن مرات. هنا حقل واحد بيغذّي الكل. */
    document.querySelectorAll("[data-site-info]").forEach(function (el) {
      var v = info[el.getAttribute("data-site-info")];
      if (typeof v !== "string" || v.trim() === "") { return; }
      el.textContent = v;
      hits++;
    });

    return hits;
  }

  /* بنجيب الإعدادات مرة واحدة ونشاركها مع أي كود تاني في الصفحة عايزها،
     بدل ما كل قسم يعمل نداء لوحده. */
  var pending = fetch(ENDPOINT, { credentials: "same-origin" })
    .then(function (r) { return r.ok ? r.json() : null; })
    .catch(function () { return null; });

  var parsed = pending.then(extract);

  window.SiteCMS = {
    /** وعد بخريطة النصوص — بيتحل بكائن فاضي لو الـAPI مش متاح. */
    content: parsed.then(function (p) { return p.content; }),
    /** وعد ببيانات هوية الشركة (تليفون، بريد، سجل تجاري…). */
    info: parsed.then(function (p) { return p.info; }),
    /** رد الإعدادات الخام — عشان الأكواد اللي عايزة info أو apps متعملش نداء تاني. */
    settings: pending,
    apply: apply,
  };

  /* التطبيق بيستنى الـDOM. لو السكربت اتحمّل بعد ما الصفحة خلصت (defer أو
     في آخر الملف) بننفّذ على طول بدل ما نستنى حدث عمره ما هييجي. */
  function run() { parsed.then(function (p) { apply(p.content, p.info); }); }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", run);
  } else {
    run();
  }
})();
