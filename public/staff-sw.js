/* ══════════════════════════════════════════════════════════════
   Service Worker للوحات الموظفين — الفرع والإدارة والكول سنتر
   وإدارة المحلات والعملاء.
   ──────────────────────────────────────────────────────────────
   مقصود إنه **أبسط** من app-sw.js بتاع المحل والعميل: اللوحات دي
   بتشتغل جوّه الشركة على نت ثابت، ومحتاجة تكون **دايمًا محدّثة**
   أكتر ما هي محتاجة تشتغل offline. فمفيش كاش للصفحات خالص —
   وجوده هنا بس عشان المتصفح يسمح بتثبيت اللوحة كتطبيق بنافذة
   مستقلة (ده شرط من شروط التثبيت).

   درس النهاردة: كاش قديم بيخلي المستخدم شايف نسخة قديمة من الكود
   وهو فاكرها الجديدة — وده كلّفنا تشخيص غلط لتأخير الأوردر.
   فهنا: الشبكة دايمًا، والكاش للأيقونات بس.
══════════════════════════════════════════════════════════════ */
const CACHE = "dahshan-staff-v1";
const ICONS = ["./assets/icon-192.png", "./assets/icon-512.png", "./assets/logo.png"];

self.addEventListener("install", e => {
  e.waitUntil(
    caches.open(CACHE)
      .then(c => Promise.all(ICONS.map(u => c.add(u).catch(() => {}))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", e => {
  const url = new URL(e.request.url);
  if (e.request.method !== "GET") return;
  // الأيقونات بس هي اللي بتتخدم من الكاش لو الشبكة وقعت
  if (!/\/assets\/(icon-|logo)/.test(url.pathname)) return;
  e.respondWith(fetch(e.request).catch(() => caches.match(e.request)));
});
