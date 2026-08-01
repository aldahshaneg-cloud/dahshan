/* ══════════════════════════════════════════════════════════════
   Service Worker — تطبيق عملاء الدهشان
   مهم: نفس المجلد فيه لوحات الإدارة والفرع والكول سنتر، فالـ SW ده
   بيتعامل *فقط* مع ملفات تطبيق العميل ومكتباته. أي طلب تاني بيعدّي
   للشبكة زي ما هو من غير ما نلمسه (مفيش respondWith).
══════════════════════════════════════════════════════════════ */
const CACHE = "dahshan-customer-v1";

/* الملفات اللي التطبيق مش هيشتغل من غيرها */
const SHELL = [
  "./tiar_customer.html",
  "./customer-manifest.json",
  "./assets/logo.png",
  "./assets/hero.png",
  "./assets/icon-192.png",
  "./assets/icon-512.png"
];

/* مكتبات خارجية بنكاشها عشان الفتح يبقى سريع وبيشتغل offline */
const CDN = [
  "fonts.googleapis.com", "fonts.gstatic.com",
  "unpkg.com/leaflet", "cdnjs.cloudflare.com/ajax/libs/qrcodejs"
];

const isOurs = url =>
  url.pathname.endsWith("/tiar_customer.html") ||
  url.pathname.endsWith("/customer-manifest.json") ||
  url.pathname.includes("/assets/") ||
  CDN.some(c => (url.host + url.pathname).includes(c));

self.addEventListener("install", e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
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
  // Firebase و Cloudinary لازم يروحوا للشبكة دايمًا — مفيش كاش لبيانات حيّة
  if (/firebaseio|googleapis\.com\/identitytoolkit|cloudinary|gstatic\.com\/firebasejs/.test(url.href)) return;
  if (!isOurs(url)) return;

  // Network-first: أحدث نسخة لو فيه نت، والكاش وقت انقطاعه
  e.respondWith(
    fetch(e.request)
      .then(res => {
        if (res && res.status === 200)
          caches.open(CACHE).then(c => c.put(e.request, res.clone()));
        return res;
      })
      .catch(() => caches.match(e.request).then(r => r || caches.match("./tiar_customer.html")))
  );
});
