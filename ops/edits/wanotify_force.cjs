/* إجبار إرسال رسالة الواتساب للمستلم بعد تسجيل الأوردر — الكول سنتر والفرع.
 *
 * ═══ الطلب (صاحب النظام، 2026-09-01) ═══
 * «أريد أن يظهر فورم الإرسال من خلال الواتساب بعد عمل الأوردر في الكول سنتر
 *  وتطبيق الفروع — يعني إجبار الكول سنتر والفرع على إرسال رسالة الواتساب».
 *
 * ═══ على إيه بنبني ═══
 * البنية موجودة كاملة من 2026-08-20 (شوف تعليق «رسايل العملاء» في tiar.html):
 *   • كل أوردر بيتسجّل بيولّد صف/مستلم في `order_notifications` بحالة
 *     `pending` والنص جاهز (OrdersController:642 — لكل المصادر).
 *   • GET  /api/order-notifications?status=pending  ← role:admin,branch,callcenter
 *   • POST /api/order-notifications/{id}/sent       ← نفس الأدوار، idempotent
 *   والفرع مقصوص على فرعه جوه الكنترولر.
 *
 * ═══ التصميم — نفس نمط الإدارة بالحرف ═══
 * «فتحت» مش «بعت»: زرارين لكل مستلم — «📱 افتح واتساب» بيفتح wa.me وبس،
 * و«✓ اتبعت» مقفول لحد ما الأول يتضغط، وهو اللي بينده الـAPI. أسوأ حالة
 * صف معلّق لرسالة اتبعتت (بيبان في شاشة الإدارة ويتصلّح)، مش العكس.
 *
 * الإجبار: المودال بيفتح لوحده بعد نجاح التسجيل، ومفيش ✕ ولا قفل من
 * الخلفية — زرار «إغلاق» مقفول لحد ما **كل** الرسايل تتعلّم مبعوتة.
 * مخرج وحيد: مستلم من غير رقم صالح ياخد «تخطّي» محلّي (الصف بيفضل pending
 * وبيبان لشاشة الإدارة — مش بنكدب على القاعدة).
 *
 * ═══ درس never-lie-after-write ═══
 * النداء بعد `closeModal("order")` **من غير await** وبيمسك أخطاءه جوّاه —
 * لو فشل تحميل الرسايل، الموظف بيشوف «الأوردر اتسجّل بس تعذّر تحميل
 * الرسالة»، مش «خطأ أثناء الحفظ» على أوردر متسجّل.
 *
 * ═══ الأمان (نفس ملاحظات شاشة الإدارة) ═══
 * كل قيمة بتعدّي على تهريب محلّي قبل innerHTML، مفيش onclick مبني بالنص
 * (data-nid + addEventListener)، والرقم بيتصفّى لأرقام قبل wa.me.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILES = ['public/callcenter.html', 'public/branch.html'];

/* ══ كتلة الجافاسكربت المشتركة (بتتحقن في الملفين بنفس النص) ══ */
const JS_BLOCK = `
    /* ══ إجبار إرسال رسالة الواتساب للمستلم بعد تسجيل الأوردر ══
       قرار صاحب النظام 2026-09-01. نفس نمط شاشة «رسايل العملاء» في
       الإدارة بالحرف: «افتح واتساب» بيفتح التاب وبس، و«اتبعت» تأكيد
       بني آدم صريح هو اللي بيكتب في القاعدة. المودال مابيقفلش غير لما
       كل الرسايل تتبعت — ده الإجبار المطلوب. المستلم اللي من غير رقم
       صالح بياخد «تخطّي» محلّي والصف بيفضل pending لشاشة الإدارة. */
    const _waEsc = s => String(s == null ? "" : s)
      .replace(/[&<>"']/g, c => ({ "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;" }[c]));
    /* صيغة واتساب الدولية — نفس _notifWaPhone في tiar.html: الـ"2" قدّام
       الرقم كله والصفر بيفضل مكانه، وأقل طول مقبول ١٠ لأرقام قديمة */
    const _waIntl = phone => {
      let p = String(phone || "").replace(/\\D/g, "");
      if (p.startsWith("0")) p = "2" + p;
      return p.length >= 10 ? p : "";
    };

    window.openWaNotifyForOrder = async function (orderIds) {
      try {
        const ids = new Set((orderIds || []).map(String));
        if (!ids.size) return;
        const d = await api.get("/api/order-notifications", { status: "pending" });
        const rows = (d.items || []).filter(n => ids.has(String(n.orderId)));
        if (!rows.length) return;   // مفيش رسايل معلّقة للأوردر ده — مفيش إجبار على العدم
        _waNotifyRender(rows);
      } catch (e) {
        /* الأوردر اتسجّل خلاص — ممنوع رسالة توحي بفشل الحفظ */
        showToast("الأوردر اتسجّل ✓ — بس تعذّر تحميل رسالة الواتساب، ابعتها من شاشة الإدارة", "error");
      }
    };

    function _waNotifyRender(rows) {
      document.getElementById("_waNotifyBox")?.remove();
      const st = new Map();   // id → "wait" | "opened" | "sent" | "skipped"
      rows.forEach(n => st.set(String(n.id), _waIntl(n.phone) ? "wait" : "nophone"));

      const box = document.createElement("div");
      box.id = "_waNotifyBox";
      box.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,.65);display:flex;align-items:center;justify-content:center;z-index:10000;padding:16px";
      const nums = [...new Set(rows.map(n => n.orderNum).filter(Boolean))].join("، ");
      box.innerHTML = \`
        <div style="background:var(--panel);border:1px solid var(--border);border-radius:14px;max-width:520px;width:100%;max-height:88vh;overflow-y:auto;padding:18px 20px">
          <div style="font-size:1.05rem;font-weight:800;margin-bottom:4px">📱 إرسال رسالة الواتساب للمستلم</div>
          <div style="font-size:.8rem;color:var(--muted);margin-bottom:12px">
            الأوردر \${_waEsc(nums)} اتسجّل ✓ — لازم المستلم ياخد رسالة برقم شحنته قبل ما تقفل.</div>
          <div id="_waNotifyRows"></div>
          <button id="_waNotifyClose" disabled
            style="width:100%;margin-top:12px;background:var(--card);color:var(--muted);border:1px solid var(--border);padding:11px;border-radius:8px;cursor:not-allowed;font-weight:700">
            إغلاق — لسه فيه رسايل ماتبعتتش</button>
        </div>\`;
      document.body.appendChild(box);

      const rowsEl = box.querySelector("#_waNotifyRows");
      rowsEl.innerHTML = rows.map(n => {
        const id = String(n.id);
        const ok = st.get(id) !== "nophone";
        return \`
        <div data-row="\${_waEsc(id)}" style="border:1px solid var(--border);border-radius:10px;padding:10px 12px;margin-bottom:8px">
          <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
            <b style="font-size:.9rem">\${_waEsc(n.name || "المستلم")}</b>
            <span dir="ltr" style="font-size:.85rem;color:var(--sky)">\${_waEsc(n.phone || "—")}</span>
          </div>
          <div style="font-size:.74rem;color:var(--muted);margin:6px 0;white-space:pre-wrap;max-height:76px;overflow-y:auto;background:var(--card);border-radius:8px;padding:7px 9px">\${_waEsc(n.body || "")}</div>
          <div style="display:flex;gap:8px">
            \${ok
              ? \`<button data-wa-open="\${_waEsc(id)}" style="flex:1;background:#25D366;color:#fff;border:none;padding:9px;border-radius:8px;font-weight:700;cursor:pointer">📱 افتح واتساب</button>
                 <button data-wa-sent="\${_waEsc(id)}" disabled style="flex:1;background:var(--card);color:var(--muted);border:1px solid var(--border);padding:9px;border-radius:8px;font-weight:700;cursor:not-allowed">✓ اتبعت</button>\`
              : \`<div style="flex:1;font-size:.78rem;color:var(--orange);padding:6px 0">⚠️ مفيش رقم صالح — الرسالة هتفضل معلّقة لشاشة الإدارة</div>
                 <button data-wa-skip="\${_waEsc(id)}" style="background:var(--card);color:var(--text);border:1px solid var(--border);padding:9px 14px;border-radius:8px;cursor:pointer">تخطّي</button>\`}
          </div>
        </div>\`;
      }).join("");

      const done = () => [...st.values()].every(v => v === "sent" || v === "skipped");
      const refresh = () => {
        const btn = box.querySelector("#_waNotifyClose");
        if (done()) {
          btn.disabled = false;
          btn.style.cssText = "width:100%;margin-top:12px;background:var(--green);color:#fff;border:none;padding:11px;border-radius:8px;cursor:pointer;font-weight:700";
          btn.textContent = "تم ✓ — إغلاق";
        }
      };
      box.querySelector("#_waNotifyClose").addEventListener("click", () => { if (done()) box.remove(); });

      rowsEl.querySelectorAll("[data-wa-open]").forEach(b => b.addEventListener("click", () => {
        const id = b.getAttribute("data-wa-open");
        const n = rows.find(x => String(x.id) === id); if (!n) return;
        const w = window.open("https://wa.me/" + _waIntl(n.phone) + "?text=" + encodeURIComponent(n.body || ""), "_blank");
        if (!w) { showToast("المتصفح حجب فتح واتساب — اسمح بالنوافذ المنبثقة", "error"); return; }
        st.set(id, "opened");
        const sentBtn = rowsEl.querySelector('[data-wa-sent="' + id + '"]');
        if (sentBtn) { sentBtn.disabled = false; sentBtn.style.cssText = "flex:1;background:var(--sky);color:#fff;border:none;padding:9px;border-radius:8px;font-weight:700;cursor:pointer"; }
      }));

      rowsEl.querySelectorAll("[data-wa-sent]").forEach(b => b.addEventListener("click", async () => {
        const id = b.getAttribute("data-wa-sent");
        if (st.get(id) !== "opened") return;   // «اتبعت» بعد «افتح» بس
        b.disabled = true; b.textContent = "…";
        try {
          await api.post("/api/order-notifications/" + encodeURIComponent(id) + "/sent", {});
          st.set(id, "sent");
          b.textContent = "✅ اتبعتت";
          b.style.cssText = "flex:1;background:var(--card);color:var(--green);border:1px solid var(--green);padding:9px;border-radius:8px;font-weight:700";
          refresh();
        } catch (e) {
          b.disabled = false; b.textContent = "✓ اتبعت";
          showToast("تعذّر التعليم: " + (e.message || ""), "error");
        }
      }));

      rowsEl.querySelectorAll("[data-wa-skip]").forEach(b => b.addEventListener("click", () => {
        st.set(b.getAttribute("data-wa-skip"), "skipped");
        b.disabled = true; b.textContent = "متخطّاة";
        refresh();
      }));
    }
`;

/* ══ التعديلات لكل ملف ══ */
const CREATED_LINE = `        const _created = (res.orders || (res.order ? [res.order] : [])).map(o => o?.orderNum).filter(Boolean);`;
const IDS_LINE = `        const _created = (res.orders || (res.order ? [res.order] : [])).map(o => o?.orderNum).filter(Boolean);
        const _createdIds = (res.orders || (res.order ? [res.order] : [])).map(o => o?.id).filter(Boolean);`;

const TAIL_OLD = `        closeModal("order");
      } catch(e) { showToast("خطأ أثناء الحفظ: " + e.message, "error"); }
      finally { btn.disabled = false; btn.textContent = "💾 حفظ الطلب"; }
    };`;
const TAIL_NEU = `        closeModal("order");
        /* 🔴 إجبار إرسال الواتساب (قرار صاحب النظام 2026-09-01) — **من غير
           await وبره الـtry عمليًا**: الدالة بتمسك أخطاءها جوّاها، فمستحيل
           فشلها يطلّع «خطأ أثناء الحفظ» على أوردر اتسجّل فعلًا. */
        openWaNotifyForOrder(_createdIds);
      } catch(e) { showToast("خطأ أثناء الحفظ: " + e.message, "error"); }
      finally { btn.disabled = false; btn.textContent = "💾 حفظ الطلب"; }
    };
${JS_BLOCK}`;

let anyFail = false;
for (const rel of FILES) {
  const FILE = path.resolve(__dirname, '../../', rel);
  const raw = fs.readFileSync(FILE, 'utf8');
  const WAS_CRLF = raw.includes('\r\n');
  let s = raw.replace(/\r\n/g, '\n');

  console.log('\n═══ ' + rel + ' ═══');
  const problems = [];
  for (const [label, txt] of [['سطر _created', CREATED_LINE], ['ذيل addOrder', TAIL_OLD]]) {
    const n = s.split(txt).length - 1;
    if (n !== 1) problems.push(`${label}: متوقّع ١ لقى ${n}`);
  }
  if (s.includes('openWaNotifyForOrder')) problems.push('متطبّق قبل كده');
  for (const need of ['api.get(', 'showToast']) {
    if (!s.includes(need)) problems.push('ناقص: ' + need);
  }
  if (problems.length) {
    console.log('⛔ مافيش بايت اتكتب:');
    for (const p of problems) console.log('   ✗ ' + p);
    anyFail = true;
    continue;
  }

  s = s.split(CREATED_LINE).join(IDS_LINE).split(TAIL_OLD).join(TAIL_NEU);

  /* فحوص بعدية */
  const after = [];
  /* ٢ = التعريف (window.openWaNotifyForOrder =) + النداء بعد closeModal */
  if ((s.match(/openWaNotifyForOrder/g) || []).length !== 2) after.push('عدد ذكر الدالة مش ٢ (تعريف + نداء)');
  if (/await openWaNotifyForOrder/.test(s)) after.push('🔴 النداء فيه await — هيكدب على الحفظ');
  if (!/_createdIds/.test(s)) after.push('سطر الـids مش موجود');
  {
    const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
    let m, i = 0, bad = 0;
    while ((m = re.exec(s)) !== null) {
      i++;
      if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
      const tmp = path.join(os.tmpdir(), 'wan-' + process.pid + '-' + i + '.mjs');
      try { fs.writeFileSync(tmp, m[2]); execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' }); }
      catch (e) { bad++; console.log('  ✗ كتلة ' + i + ': ' + ((e.stderr || '').toString().match(/SyntaxError:.*/) || ['?'])[0]); }
      finally { try { fs.unlinkSync(tmp); } catch (e2) {} }
    }
    console.log((bad ? '  ✗' : '  ✓') + ' كل كتل الجافاسكربت سليمة (' + i + ' كتلة)');
    if (bad) after.push('كتل مكسورة');
  }
  if (after.length) {
    console.log('⛔ فحوص بعدية وقعت — مافيش بايت اتكتب:');
    for (const a of after) console.log('   ✗ ' + a);
    anyFail = true;
    continue;
  }

  if (process.env.DRY) { console.log('🟦 DRY — عدّى، مافيش كتابة.'); continue; }
  fs.writeFileSync(FILE, WAS_CRLF ? s.replace(/\n/g, '\r\n') : s);
  console.log('✓ اتكتب');
}
process.exit(anyFail ? 1 : 0);
