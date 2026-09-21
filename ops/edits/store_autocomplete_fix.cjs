/* تطبيق المحلات (بلاغ صاحب النظام 2026-09-22):
   1) قائمة الاقتراحات كانت بتطلع تحت خانة **الاسم** حتى لو المحل بيكتب في خانة **الرقم** — فبتغطّي
      الرقم نفسه وهو بيتكتب. بقى للرقم قائمته تحت صف التليفون.
   2) الاختيار من القائمة (أو كتابة رقم عميل محفوظ كامل) بيملّي كل أماكن العنوان: الاسم · هاتف ٢ ·
      المنطقة والسعر · العنوان · **دبوس الخريطة** — والدفتر بيتحدّث مع كل شحنة (upsert) بدل مرة واحدة.
   3) الرفريش اللي بيطلّع الصفحة لفوق: `renderParcelTabs` كانت بتنده scrollIntoView على زرار الطرد،
      فأي إعادة رسم (بولر المناطق كل دقيقة) بتسحب الصفحة لشريط الطرود فوق. بقى تمرير أفقي جوه الشريط بس.
      + بولر المناطق بقى بـ?since (السيرفر بيرد «مفيش تغيير» بدل ٢٤٥ كيلو كل دقيقة).
   استبدالات نصية بالحرف — أي واحدة ماتتلاقاش بالعدد المتوقع السكربت يقف من غير ما يكتب. */
const fs = require("fs");
const path = require("path");
const file = path.join(__dirname, "..", "..", "public", "store.html");
let s = fs.readFileSync(file, "utf8");
const eol = s.includes("\r\n") ? "\r\n" : "\n";
s = s.replace(/\r\n/g, "\n");
const snip = n => fs.readFileSync(path.join(__dirname, "snippets", n), "utf8").replace(/\r\n/g, "\n").replace(/\n$/, "");

const reps = [
  /* ── 1) قائمة الرقم تحت صف التليفون ── */
  [`        <div class="grid2">
          <div class="fg" style="position:relative">
            <label>هاتف المستلِم <span class="req">*</span></label>`,
   `        <div class="ac-anchor">
        <div class="grid2">
          <div class="fg">
            <label>هاتف المستلِم <span class="req">*</span></label>`, 1],
  [`            <input type="tel" id="rPhone2-\${n}" dir="ltr" placeholder="—" />
          </div>
        </div>`,
   `            <input type="tel" id="rPhone2-\${n}" dir="ltr" placeholder="—" />
          </div>
        </div>
        <!-- قائمة اقتراحات **الرقم** — تحت صف التليفون، مش تحت الاسم (كانت بتغطّي الرقم وهو بيتكتب) -->
        <div class="ac-drop" id="acDropP-\${n}"></div>
        </div>`, 1],
  [`    .ac-item {
      display: flex; align-items: center; justify-content: space-between;`,
   `    /* مرساة قائمة الرقم: القائمة بعرض صف التليفون كله وتحته. الهامش السالب بيلغي الـmargin السفلي
       بتاع .fg عشان القائمة تلزق في الخانة من غير فراغ. */
    .ac-anchor { position: relative; }
    .ac-anchor > .ac-drop { top: calc(100% - 10px); }
    .ac-sub { display: block; font-size: .7rem; color: var(--muted); font-weight: 500; margin-top: 2px;
              white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 210px; }
    .ac-item {
      display: flex; align-items: center; justify-content: space-between;`, 1],

  /* ── 2) الدوال ── */
  [snip("store_ac_old.txt"), snip("store_ac_new.txt"), 1],

  /* «ابعت له» من دفتر العملاء — نفس الملّاية */
  [`        const set = (fid, v) => { const el = document.getElementById(fid); if (el && v) el.value = v; };
        set(\`rName-\${n}\`,   c.name);
        set(\`rPhone-\${n}\`,  c.phone);
        set(\`rPhone2-\${n}\`, c.phone2);
        if (c.address) window.setAddressValue(\`rAddrWidget-\${n}\`, c.address);
        const zs = document.getElementById(\`rZone-\${n}\`);
        if (zs && c.zoneId) {
          zs.value = String(c.zoneId);
          if (zs.value === String(c.zoneId)) window.onZone(n);
        }
        document.getElementById(\`rPhone-\${n}\`)?.dispatchEvent(new Event("input"));`,
   `        window.applyContactToRow(n, c, { onlyEmpty: false });
        document.getElementById(\`rPhone-\${n}\`)?.dispatchEvent(new Event("input"));`, 1],

  /* الحفظ: upsert + الدبوس */
  [snip("store_save_old.txt"), snip("store_save_new.txt"), 1],
  [`            phone2: d.receiverPhone2, address: d.address,
            zoneId: d.zoneId          // عشان المرة الجاية تتعبّى لوحدها
          }).catch(() => {});`,
   `            phone2: d.receiverPhone2, address: d.address,
            zoneId: d.zoneId,         // عشان المرة الجاية تتعبّى لوحدها
            lat: d.lat, lng: d.lng,   // والدبوس كمان (2026-09-22)
            fromReceipt: d.fromReceipt
          }).catch(() => {});`, 1],

  /* ── 3) الرفريش اللي بيطلّع الصفحة لفوق ── */
  [`      /* الزرار النشط يفضل في المنظور لما الطرود تكتر. block:"nearest"
         مهمة: من غيرها المتصفح بيسكرول الصفحة رأسيًا كمان. */
      box.querySelector(".ptab.active")?.scrollIntoView({ inline: "center", block: "nearest" });`,
   `      /* الزرار النشط يفضل في المنظور لما الطرود تكتر.
         🔴 تمرير **أفقي جوه الشريط بس** (بلاغ صاحب النظام 2026-09-22: «كل دقيقة التطبيق بيعمل رفريش،
         ولو نازل لتحت بيطلع لفوق تاني»). \`scrollIntoView\` — حتى بـblock:"nearest" — بتسكرول الصفحة
         رأسيًا لو الشريط نفسه بره الشاشة، والدالة دي بتتنده مع أي إعادة رسم (تغيّر سعر · بولر
         المناطق كل دقيقة)، فالمحل وهو بيكتب العنوان تحت كان بيتسحب لشريط الطرود فوق. */
      const _act = box.querySelector(".ptab.active");
      if (_act && box.scrollWidth > box.clientWidth + 2) {
        const bR = box.getBoundingClientRect(), aR = _act.getBoundingClientRect();
        box.scrollLeft += (aR.left + aR.width / 2) - (bR.left + bR.width / 2);
      }`, 1],
  [`      reg(new api.Poller("/api/zones", { interval: 60000, useSince: false, onChange: d => {`,
   `      /* ?since شغّال على /api/zones من 2026-09-08 — السيرفر بيرد «مفيش تغيير» (٥٠ بايت) بدل ما
         ينزّل ٢٤٥ كيلو كل دقيقة ويعيد رسم قوايم المناطق (2026-09-22). */
      reg(new api.Poller("/api/zones", { interval: 60000, onChange: d => {
        if (!d.items) return;`, 1],
];

let bad = 0;
for (const [from, to, n] of reps) {
  const c = s.split(from).length - 1;
  if (c !== n) { console.error(`✗ متوقع ${n} لقيت ${c}: ${from.slice(0, 100)}`); bad++; continue; }
  s = s.split(from).join(to);
}
if (bad) { console.error(`وقف — ${bad} استبدال مش مطابق، الملف ما اتكتبش`); process.exit(1); }

/* رقم النسخة — التطبيق بيحدّث نفسه لما الرقم يتغيّر */
const m = s.match(/<meta name="app-version" content="(\d+)\.(\d+)\.(\d+)" \/>/);
if (!m) { console.error("مالقيتش app-version"); process.exit(1); }
const next = `${m[1]}.${m[2]}.${Number(m[3]) + 1}`;
s = s.replace(m[0], `<meta name="app-version" content="${next}" />`);

fs.writeFileSync(file, s.replace(/\n/g, eol));
console.log(`✓ ${reps.length} استبدال · النسخة ${m[1]}.${m[2]}.${m[3]} ← ${next}`);
