/**
 * constants.js — نفس خرائط api/constants.php للواجهات
 * المرجع الملزم: db/VOCAB.md — النصوص العربية حرفية من الكود القديم.
 * الواجهات أصلًا بتتكلم باللسان القديم، فالخرائط دي للعرض/الفلترة
 * ولأي شاشة جديدة محتاجة تحوّل بين كود DB والنص القديم.
 */
(function (global) {
  "use strict";

  /* حالات الأوردر: كود DB → النص العربي الحرفي */
  const ORDER_STATUS_AR = {
    processing:  "قيد التنفيذ",
    delivering:  "جاري التوصيل",
    delivered:   "تم التسليم",
    undelivered: "لم يتم التوصيل",
    cancelled:   "ملغي",
    // أكواد سكيمة بلا مقابل في اللسان القديم (متسابة للاحتياط):
    pending_pickup: "بانتظار الاستلام",
    received:       "تم الاستلام",
    postponed:      "مؤجل",
  };

  const ORDER_STATUS_CODE = {
    "قيد التنفيذ":      "processing",
    "جاري التوصيل":     "delivering",
    "تم التسليم":       "delivered",
    "لم يتم التوصيل":   "undelivered",
    "لم يتم التسليم":   "undelivered",
    "ملغي":             "cancelled",
    "بانتظار الاستلام": "pending_pickup",
    "تم الاستلام":      "received",
    "مؤجل":             "postponed",
  };

  /* حالة الطرد جوه deliveries[] — الافتراضي القديم «قيد التنفيذ» */
  const PARCEL_STATUS_AR = {
    processing:  "قيد التنفيذ",
    delivered:   "تم التسليم",
    undelivered: "لم يتم التوصيل",
  };

  /* حالة الطيار — إنجليزي على السلك (waiting/delivering/onLeave)، null = متحرّر */
  const PILOT_STATUS_WIRE = { waiting: "waiting", delivering: "delivering", on_leave: "onLeave" };
  const PILOT_STATUS_CODE = { waiting: "waiting", delivering: "delivering", onLeave: "on_leave", on_leave: "on_leave" };
  const PILOT_STATUS_AR   = { waiting: "في الانتظار", delivering: "جاري التوصيل", on_leave: "في إذن", onLeave: "في إذن" };

  /* أنواع الإذن — الكود القديم: rest | dayoff | incident */
  const LEAVE_TYPE_AR = { rest: "استراحة/بريك", dayoff: "عطلة/انصراف", incident: "حادث" };

  /* حالة الوردية — غياب الحقل بيتعامل كـ active */
  const SHIFT_STATUS = { active: "active", ended: "ended" };

  /* حالات الطلبات الإدارية */
  const REQUEST_STATUS = {
    pending: "pending", approved: "approved", rejected: "rejected",
    accepted: "accepted", ended: "ended",
  };
  const REQUEST_STATUS_AR = {
    pending:  "بانتظار الموافقة",
    approved: "تمت الموافقة",
    rejected: "مرفوض",
    accepted: "مقبول",
    ended:    "انتهى",
  };

  /* علامة الإرجاع على الأوردر: pending / rejected / null (مفيش approved) */
  const ORDER_RETURN_STATUS = { pending: "pending", rejected: "rejected" };

  /* العمولة: percent (افتراضي) / fixed */
  const COMMISSION_TYPE = { percent: "percent", fixed: "fixed" };

  /* مصدر الأوردر */
  const ORDER_SOURCE = { branch: "branch", admin: "admin", customer: "customer", store: "store" };

  /* الأدوار: كود DB → عربي حرفي */
  const ROLE_AR = {
    admin:      "مدير",
    branch:     "مشرف فرع",
    pilot:      "طيار",
    store:      "صاحب محل",
    callcenter: "كول سنتر",
    accountant: "حسابات",
    hr:         "شؤون عاملين",
    // مشرف الطيارين — تشغيل الأسطول بلا صلاحيات مالية (شوف routes/api.php)
    pilot_supervisor: "مشرف الطيارين",
  };
  const ROLE_CODE = Object.fromEntries(Object.entries(ROLE_AR).map(([c, ar]) => [ar, c]));
  ROLE_CODE["مدير عام"] = "admin"; // دخول جوجل للإدارة

  /* ── دوال التحويل ────────────────────────────────────────────── */
  function statusToAr(code)   { return code ? (ORDER_STATUS_AR[code] || code) : code; }
  function statusToCode(ar)   { if (!ar) return ar; return ORDER_STATUS_AR[ar] ? ar : (ORDER_STATUS_CODE[ar] || ar); }
  function parcelStatusToAr(code) { return PARCEL_STATUS_AR[code] || "قيد التنفيذ"; }
  function pilotStatusToWire(code) { return code ? (PILOT_STATUS_WIRE[code] || code) : null; }
  function pilotStatusToCode(wire) { return wire ? (PILOT_STATUS_CODE[wire] || wire) : null; }
  function roleToAr(code) { return code ? (ROLE_AR[code] || code) : code; }
  function roleToCode(ar) { if (!ar) return ar; return ROLE_AR[ar] ? ar : (ROLE_CODE[ar] || ar); }

  /* توقيت القاهرة — نفس دوال الواجهات القديمة حرفيًا */
  function cairoParts(d) {
    const fmt = new Intl.DateTimeFormat("en-GB", {
      timeZone: "Africa/Cairo", year: "numeric", month: "2-digit", day: "2-digit",
    });
    const parts = fmt.formatToParts(d ? new Date(d) : new Date());
    const g = (t) => parts.find((p) => p.type === t)?.value || "";
    return { y: g("year"), m: g("month"), d: g("day") };
  }
  /* "2026-08-06" */
  function cairoDayKey(d) { const p = cairoParts(d); return p.y + "-" + p.m + "-" + p.d; }
  /* "20260806" — للعدّادات وأرقام الأوردرات */
  function cairoDayKeyCompact(d) { const p = cairoParts(d); return p.y + p.m + p.d; }

  /* رقم الأوردر: HAL-260804-001 */
  function formatOrderNum(branchCode, dailyCount, d) {
    const code = branchCode || "ORD";
    const day = cairoDayKeyCompact(d).slice(2);
    return code + "-" + day + "-" + String(dailyCount).padStart(3, "0");
  }
  /* رقم الجزء المفصول: HAL-260804-001-2+3 */
  function parcelSuffixNum(orderNum, parcelNos) {
    const nos = (parcelNos || []).map(Number).filter(Boolean);
    return (orderNum || "") + (nos.length ? "-" + nos.join("+") : "");
  }

  /* العمولة — متطابقة مع اللوحات القديمة */
  function pilotCommissionFor(pilot, order) {
    const price = Number(order?.totalDeliveryPrice) || 0;
    const type = (pilot && pilot.commissionType) || "percent";
    const val = Number(pilot && pilot.commissionValue) || 0;
    if (!val) return 0;
    return type === "fixed" ? val : (price * val) / 100;
  }

  global.TIAR_CONSTANTS = {
    ORDER_STATUS_AR, ORDER_STATUS_CODE, PARCEL_STATUS_AR,
    PILOT_STATUS_WIRE, PILOT_STATUS_CODE, PILOT_STATUS_AR,
    LEAVE_TYPE_AR, SHIFT_STATUS, REQUEST_STATUS, REQUEST_STATUS_AR,
    ORDER_RETURN_STATUS, COMMISSION_TYPE, ORDER_SOURCE, ROLE_AR, ROLE_CODE,
    statusToAr, statusToCode, parcelStatusToAr,
    pilotStatusToWire, pilotStatusToCode, roleToAr, roleToCode,
    cairoDayKey, cairoDayKeyCompact, formatOrderNum, parcelSuffixNum,
    pilotCommissionFor,
  };
})(window);
