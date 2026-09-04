#!/usr/bin/env bash
# 🛡 تشغيل كل الحرّاس — الحكم بكود الخروج مش بنص المخرجات.
#
# ═══ ليه كود الخروج ═══
# صيغ الملخص مختلفة بين الحرّاس: الأقدم بيطبع «ناجح · فاشل» والأحدث
# «✅ عدّى / 🔴 وقع». أي تفتيش في النص بيلقط صيغة ويفوّت التانية —
# وحصل فعلًا: تشغيل جماعي كان بيلقط الرموز بس، فحارس واقع بصيغة قديمة
# كان هيعدّي بصمت. كود الخروج موحّد: كل الحرّاس بتخرج 1 عند الفشل
# (اتأكدنا: 24 PHP و35 cjs كلهم فيهم exit صريح)، والاستثناء الخام بيطلع
# 255 — الاتنين غير صفر فبيتلقطوا.
#
# التشغيل:  bash ops/guards.sh          ← كل الحرّاس
#           bash ops/guards.sh staff    ← اللي اسمه فيه الكلمة دي بس
set -u
cd "$(dirname "$0")/.."

filter="${1:-}"
pass=0; fail=0; failed=()

run() {
  local t="$1"
  [ -n "$filter" ] && [[ "$t" != *"$filter"* ]] && return
  if "${@:2}" "$t" >/dev/null 2>&1; then
    pass=$((pass + 1))
  else
    fail=$((fail + 1)); failed+=("$t")
    echo "  ✗ $t"
  fi
}

for t in ops/test_*.php; do run "$t" php; done
for t in ops/test_*.cjs; do run "$t" node; done

echo "────────────────────────────────────"
if [ "$fail" -eq 0 ]; then
  echo "✅ كل الحرّاس عدّت ($pass)"
else
  echo "🔴 وقع $fail من $((pass + fail)) — شغّل الواقع لوحده تشوف تفاصيله:"
  for t in "${failed[@]}"; do
    case "$t" in *.php) echo "   php $t";; *) echo "   node $t";; esac
  done
  exit 1
fi
