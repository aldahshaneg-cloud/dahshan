/* ══════════════════════════════════════════════════════════════
   Service Worker مشترك — تطبيق العملاء + بوابة المحلات (نسخة REST)
   ──────────────────────────────────────────────────────────────
   نفس ملف docs/app-sw.js القديم بعد التحويل:
   - أسماء الملفات الجديدة (customer.html / store.html)
   - /api/* عمره ما يتكاش — بيانات حيّة من MariaDB
   - مفيش wallet.js/netstatus.js (منطقهم بقى REST جوّه الصفحات)
══════════════════════════════════════════════════════════════ */
const CACHE = "dahshan-apps-rest-v2";   // v2: 2026-08-22 — إشعارات الستارة + صفحة التتبّع

/* ملفات الـ SHELL — كل ملف بيتكاش لوحده عشان ملف ناقص مايكسرش التنصيب */
const SHELL = [
  "./customer.html",
  "./store.html",
  "./install.js",
  "./customer-manifest.json",
  "./assets/js/api.js",
  "./assets/js/imgcompress.js",
  "./assets/logo.png",
  "./assets/hero.jpg",
  "./assets/icon-192.png",
  "./assets/icon-512.png"
];

/* مكتبات خارجية بنكاشها عشان الفتح يبقى سريع ويشتغل offline */
const CDN = [
  "fonts.googleapis.com", "fonts.gstatic.com",
  "unpkg.com/leaflet", "cdnjs.cloudflare.com/ajax/libs/qrcodejs"
];

const OURS = ["/customer.html", "/store.html", "/install.js", "/customer-manifest.json"];

const isOurs = url =>
  OURS.some(p => url.pathname.endsWith(p)) ||
  url.pathname.includes("/assets/") ||
  CDN.some(c => (url.host + url.pathname).includes(c));

self.addEventListener("install", e => {
  e.waitUntil(
    caches.open(CACHE)
      .then(c => Promise.all(SHELL.map(u => c.add(u).catch(() => {}))))
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
  // الـ API والرفع بيانات حيّة — الشبكة دايمًا، ولا لمسة كاش
  if (url.pathname.startsWith("/api/") || url.pathname.startsWith("/uploads/")) return;
  // خدمات جوجل (دخول Firebase Auth) برضه للشبكة مباشرة
  if (/googleapis\.com|gstatic\.com\/firebasejs|firebaseapp\.com/.test(url.href)) return;
  if (!isOurs(url)) return;

  // Network-first: أحدث نسخة لو فيه نت، والكاش وقت انقطاعه
  e.respondWith(
    fetch(e.request)
      .then(res => {
        if (res && res.status === 200)
          caches.open(CACHE).then(c => c.put(e.request, res.clone()));
        return res;
      })
      .catch(() => caches.match(e.request).then(r => r || caches.match("./customer.html")))
  );
});

/* ══════════════════════════════════════════════════════════════
   إشعارات الستارة (Web Push) — تطبيق العملاء
   ──────────────────────────────────────────────────────────────
   السيرفر بيبعت حمولة JSON: {title, body, tag, orderId, orderNum, key, url}
   (العقد في App\Jobs\SendCustomerPush). `tag` ثابت لكل أوردر = إشعار واحد
   لكل أوردر في الستارة بيتبدّل محتواه مع كل حالة جديدة (renotify بيخلّيه
   يرنّ/يهتز تاني رغم إنه نفس الـtag) — نفس فلسفة «كارت واحد لكل أوردر»
   اللي في صفحة الإشعارات جوه التطبيق.
══════════════════════════════════════════════════════════════ */
self.addEventListener("push", e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (_) { d = { title: "الدهشان", body: e.data ? e.data.text() : "" }; }
  const title = d.title || "الدهشان";
  const opts = {
    body: d.body || "",
    icon: "./assets/icon-192.png",
    badge: "./assets/icon-192.png",
    dir: "rtl",
    lang: "ar",
    tag: d.tag || (d.orderId ? "order-" + d.orderId : "dahshan"),
    renotify: true,
    data: { url: d.url || "./customer.html", orderId: d.orderId || null, key: d.key || null },
    // اهتزاز قصير — الإشعار مش رنّة أوردر طيار
    vibrate: [120, 60, 120],
  };
  e.waitUntil(self.registration.showNotification(title, opts));
});

/* الضغط على الإشعار: لو التطبيق مفتوح في تاب/نافذة نركّز عليه ونبعتله
   الرابط يتنقّل لوحده (من غير reload يضيّع حالته)؛ لو مقفول نفتحه على
   الرابط والتطبيق بيقرا الـhash وقت الإقلاع (#track=ID / #order=ID). */
self.addEventListener("notificationclick", e => {
  e.notification.close();
  const target = (e.notification.data && e.notification.data.url) || "./customer.html";
  const abs = new URL(target, self.registration.scope).href;
  e.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then(list => {
      const mine = list.find(c => c.url.includes("customer.html"));
      if (mine) {
        mine.postMessage({ type: "navigate", url: abs });
        return mine.focus();
      }
      return self.clients.openWindow(abs);
    })
  );
});
