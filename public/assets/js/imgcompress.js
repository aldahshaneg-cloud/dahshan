/* ══════════════════════════════════════════════════════════════
   ضغط الصور قبل الرفع — وحدة مشتركة
   ──────────────────────────────────────────────────────────────
   الصور كانت بتتبعت بحجمها الأصلي من كاميرا الموبايل: 3 لـ 5 ميجا
   للصورة الواحدة، والعميل مسموح له 6 صور للطرد. يعني ممكن 30 ميجا
   على بيانات موبايل — الرفع بيفشل أو بياخد دقايق، والعميل يفتكر
   إن التطبيق واقع ويلغي الطلب.

   والصور دي بتتعرض في النهاية في مربع صغير في لوحة الفرع والطيار،
   فمفيش أي فايدة من الدقة الأصلية.

   الضغط بيتم في المتصفح قبل الرفع: تصغير لأقصى بُعد 1600 بكسل
   وتحويل لـ JPEG. النتيجة عادةً أقل من 400 كيلو بدل 4 ميجا.

   لو الضغط فشل لأي سبب (صيغة غريبة، صورة بايظة، متصفح قديم)،
   بنرجّع الملف الأصلي زي ما هو — الرفع لازم يشتغل في كل الحالات.
══════════════════════════════════════════════════════════════ */

const MAX_DIM     = 1600;   // أقصى عرض أو ارتفاع
const QUALITY     = 0.82;   // جودة JPEG
const SKIP_UNDER  = 300 * 1024;  // أقل من كده مش محتاج ضغط

/** بيحمّل الملف كصورة */
function loadImage(file) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload  = () => { URL.revokeObjectURL(url); resolve(img); };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error("تعذّر قراءة الصورة")); };
    img.src = url;
  });
}

/**
 * بيصغّر الصورة ويحوّلها JPEG. بيرجّع File جاهز للرفع.
 * لو حصل أي مشكلة بيرجّع الملف الأصلي.
 * @param {File} file
 * @param {{maxDim?:number, quality?:number}} [opts]
 */
export async function compressImage(file, opts = {}) {
  try {
    if (!file || !file.type || !file.type.startsWith("image/")) return file;
    // الصور الصغيرة والـ GIF المتحركة نسيبها زي ما هي
    if (file.size <= SKIP_UNDER || file.type === "image/gif") return file;

    const maxDim  = opts.maxDim  || MAX_DIM;
    const quality = opts.quality || QUALITY;

    const img = await loadImage(file);
    const w0 = img.naturalWidth, h0 = img.naturalHeight;
    if (!w0 || !h0) return file;

    const ratio = Math.min(1, maxDim / Math.max(w0, h0));
    const w = Math.round(w0 * ratio), h = Math.round(h0 * ratio);

    const canvas = document.createElement("canvas");
    canvas.width = w; canvas.height = h;
    const ctx = canvas.getContext("2d");
    if (!ctx) return file;
    ctx.imageSmoothingQuality = "high";
    // خلفية بيضا: JPEG مافيهوش شفافية، ومن غيرها المناطق الشفافة بتطلع سودا
    ctx.fillStyle = "#fff";
    ctx.fillRect(0, 0, w, h);
    ctx.drawImage(img, 0, 0, w, h);

    const blob = await new Promise(res => canvas.toBlob(res, "image/jpeg", quality));
    if (!blob) return file;
    // لو الضغط طلع أكبر من الأصل (بيحصل مع صور صغيرة أصلاً) نسيب الأصل
    if (blob.size >= file.size) return file;

    const name = (file.name || "photo").replace(/\.[^.]+$/, "") + ".jpg";
    return new File([blob], name, { type: "image/jpeg", lastModified: Date.now() });
  } catch (_) {
    return file;   // الرفع لازم يشتغل حتى لو الضغط فشل
  }
}

/** نص مختصر للحجم — للعرض على المستخدم */
export function humanSize(bytes) {
  const b = Number(bytes) || 0;
  if (b < 1024) return b + " بايت";
  if (b < 1024 * 1024) return Math.round(b / 1024) + " كيلو";
  return (b / 1024 / 1024).toFixed(1) + " ميجا";
}
