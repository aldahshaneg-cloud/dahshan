/* مسح متعدّي: زرار بيوصل لمسار ممنوع — سواء مباشرة أو عن طريق مودال.
 *
 * الزرار اللي بيفتح مودال مالوش نداء API بنفسه، فالمسح البسيط بيعدّيه —
 * لكنه عمليًا بيوصّل الموظف لطريق مسدود. عشان كده بنبني رسم النداءات
 * (مين بينده مين) ونمشي عليه لحد ما نلاقي مسار API.
 */
const fs = require('fs');
const html = fs.readFileSync('public/callcenter.html', 'utf8');
const routes = fs.readFileSync('routes/api.php', 'utf8');
const ROLE = 'callcenter';

/* ── أدوار المسارات ── */
const ROUTES = [];
for (const m of routes.matchAll(/Route::(get|post|put|patch|delete)\(\s*'([^']+)'[\s\S]{0,300}?;/g)) {
  const roles = (m[0].match(/role:([a-z_,]+)/) || [, null])[1];
  ROUTES.push({ uri: m[2], roles: roles ? roles.split(',') : null });
}
const routeRoles = path => {
  const clean = path.replace(/^\/api\//, '').replace(/\$\{[^}]*\}/g, 'X').replace(/\/+$/, '');
  for (const r of ROUTES) {
    if (new RegExp('^' + r.uri.replace(/\{[^}]+\}/g, '[^/]+') + '$').test(clean)) return r;
  }
  return null;
};

/* ── جسم كل دالة ── */
const bodies = new Map();
const grab = (startIdx, name) => {
  let p = html.indexOf('(', startIdx), pd = 0, body = -1;
  for (let j = p; j < html.length; j++) {
    if (html[j] === '(') pd++;
    else if (html[j] === ')') { pd--; if (!pd) { body = html.indexOf('{', j); break; } }
  }
  let d = 0;
  for (let j = body; j < html.length; j++) {
    const c = html[j];
    if (c === '{') d++;
    else if (c === '}') { d--; if (!d) { bodies.set(name, html.slice(startIdx, j + 1)); return; } }
    else if (c === '`' || c === '"' || c === "'") {
      const q = c; j++;
      while (j < html.length && html[j] !== q) { if (html[j] === '\\') j++; j++; }
    }
  }
};
for (const m of html.matchAll(/window\.([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?function/g)) grab(m.index, m[1]);
for (const m of html.matchAll(/(?:^|\n)\s*(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/g))
  if (!bodies.has(m[1])) grab(m.index + m[0].indexOf('function'), m[1]);

/* ── مسارات الـAPI جوه كل دالة (مباشرة) ── */
const direct = new Map();
for (const [fn, body] of bodies) {
  const hits = [];
  for (const c of body.matchAll(/\.(post|put|patch|del|delete)\(\s*[`'"]([^`'"]+)[`'"]/g)) {
    const r = routeRoles(c[2]);
    hits.push({ path: c[2], roles: r?.roles || null, ok: r ? (!r.roles || r.roles.includes(ROLE)) : null });
  }
  direct.set(fn, hits);
}

/* ── مين بينده مين (مستوى واحد بس بيكفي: زرار ← مودال ← تأكيد) ── */
const modalOf = new Map();          // اسم مودال ← الدوال اللي بتتنده من جواه
for (const m of html.matchAll(/<div id="modal-([a-z-]+)"[\s\S]*?<\/div>\s*(?=<div id="modal-|<!--|<script)/g)) {
  const fns = [...m[0].matchAll(/onclick="([A-Za-z_$][\w$]*)\(/g)].map(x => x[1]);
  modalOf.set(m[1], fns);
}

const reaches = (fn, seen = new Set()) => {
  if (seen.has(fn)) return [];
  seen.add(fn);
  const out = [...(direct.get(fn) || [])];
  const body = bodies.get(fn) || '';
  /* لو الدالة بتفتح مودال، بنكمّل على أزرار المودال */
  for (const om of body.matchAll(/openModal\(\s*['"]([a-z-]+)['"]/g))
    for (const inner of (modalOf.get(om[1]) || [])) out.push(...reaches(inner, seen));
  /* أو بتظهر مودال بالـstyle */
  for (const om of body.matchAll(/getElementById\(\s*['"]modal-([a-z-]+)['"]\s*\)\.style\.display\s*=\s*['"](?:flex|block)/g))
    for (const inner of (modalOf.get(om[1]) || [])) out.push(...reaches(inner, seen));
  return out;
};

/* ── كل onclick في الملف ── */
const sites = new Map();
for (const m of html.matchAll(/onclick="([A-Za-z_$][\w$]*)\(/g))
  sites.set(m[1], (sites.get(m[1]) || 0) + 1);

const broken = [];
for (const [fn, count] of sites) {
  if (!bodies.has(fn)) continue;
  const hits = reaches(fn);
  if (!hits.length) continue;
  const bad = hits.filter(h => h.ok === false);
  if (bad.length && bad.length === hits.filter(h => h.ok !== null).length)
    broken.push({ fn, count, paths: [...new Set(bad.map(b => b.path))], roles: bad[0].roles.join(',') });
}

broken.sort((a, b) => a.fn.localeCompare(b.fn));
console.log('🔴 أزرار بتوصل لمسار ممنوع (مباشرة أو عبر مودال): '
  + broken.reduce((s, b) => s + b.count, 0) + ' زرار · ' + broken.length + ' دالة\n');
for (const b of broken)
  console.log('   ' + b.fn.padEnd(28) + '×' + b.count + '   ' + b.paths[0] + '   [' + b.roles + ']');
console.log('\nالأسماء للنسخ:');
console.log(broken.map(b => "'" + b.fn + "'").join(', '));
