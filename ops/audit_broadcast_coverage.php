<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
   📡 جرد البثّ الفوري: كل مسار بيغيّر بيانات (POST/PUT/PATCH/DELETE) — بيبعت حدث ولا لأ؟
   (طلب صاحب النظام 2026-09-21: «راجع العمليات اللي بتحصل من الكول سنتر ومن الفروع… وحدة وحدة»)

   قراءة بس: بيقرا جدول المسارات الحقيقي ويفتّش جسم دالة الكنترولر (وأي دالة خاصة بتندهها
   في نفس الكلاس، مستوى واحد) عن: broadcastOrder / OrderChanged / PilotRequestChanged / OrderUrged.
   التشغيل: php ops/audit_broadcast_coverage.php [--missing]
═══════════════════════════════════════════════════════════════ */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$onlyMissing = in_array('--missing', $argv, true);
$PAT = '/broadcastOrder|broadcastOrders|OrderChanged|PilotRequestChanged|broadcastPilotRequest|OrderUrged|broadcastRequest/';

$srcCache = [];
$methodBody = function (string $class, string $method) use (&$srcCache): ?string {
    try { $rm = new ReflectionMethod($class, $method); } catch (Throwable) { return null; }
    $file = $rm->getFileName();
    $srcCache[$file] ??= file($file);

    return implode('', array_slice($srcCache[$file], $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
};

$rows = [];
foreach (app('router')->getRoutes() as $route) {
    $verbs = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']);
    if (! $verbs || ! str_starts_with($route->uri(), 'api/')) { continue; }
    $action = $route->getActionName();
    if (! str_contains($action, '@')) { continue; }
    [$class, $method] = explode('@', $action);
    $body = $methodBody($class, $method) ?? '';
    $hit = (bool) preg_match($PAT, $body);
    if (! $hit && preg_match_all('/(?:\$this->|self::|static::)([a-zA-Z_]+)\(/', $body, $m)) {
        foreach (array_unique($m[1]) as $callee) {
            $b2 = $methodBody($class, $callee);
            if ($b2 !== null && preg_match($PAT, $b2)) { $hit = true; break; }
        }
    }
    $rows[] = [implode('|', $verbs), '/' . $route->uri(), class_basename($class) . '@' . $method, $hit];
}
usort($rows, fn ($a, $b) => [$a[2], $a[1]] <=> [$b[2], $b[1]]);
$yes = count(array_filter($rows, fn ($r) => $r[3]));
foreach ($rows as [$v, $uri, $act, $hit]) {
    if ($onlyMissing && $hit) { continue; }
    printf("%s  %-7s %-52s %s\n", $hit ? '📡' : '  ', $v, $uri, $act);
}
echo "\nالإجمالي: " . count($rows) . " مسار كتابة · بيبعت بث: {$yes} · من غير بث: " . (count($rows) - $yes) . "\n";
