/* مسح: كل زرار في callcenter.html ← المسار اللي بيضربه ← الأدوار المسموحة.
   الهدف: نلاقي الأزرار اللي بتبان لموظف الكول سنتر وبترجع 403 (وارت واجهة)،
   والأزرار اللي بتنفّذ إجراء مش من شغله. */
const fs = require('fs');
const html = fs.readFileSync('public/callcenter.html', 'utf8');
const routes = fs.readFileSync('routes/api.php', 'utf8');

/* ── ① أدوار كل مسار ── */
const ROUTES = [];
for (const m of routes.matchAll(/Route::(get|post|put|patch|delete)\(\s*'([^']+)'[\s\S]{0,300}?;/g)) {
  const roles = (m[0].match(/role:([a-z_,]+)/) || [, null])[1];
  ROUTES.push({ verb: m[1].toUpperCase(), uri: m[2], roles: roles ? roles.split(',') : null });
}
const rolesFor = path => {
  const clean = path.replace(/^\/api\//, '').replace(/\$\{[^}]*\}/g, '{id}').replace(/\/+$/, '');
  let best = null;
  for (const r of ROUTES) {
    const pat = '^' + r.uri.replace(/\{[^}]+\}/g, '[^/]+') + '$';
    if (new RegExp(pat).test(clean)) { best = r; break; }
  }
  return best;
};

/* ── ② كل دالة معرّفة، وجسمها ── */
function bodyOf(name) {
  let i = html.indexOf('window.' + name + ' =');
  if (i < 0) i = html.search(new RegExp('(?:^|\\n)\\s*(?:async\\s+)?function\\s+' + name + '\\b'));
  if (i < 0) return '';
  let p = html.indexOf('(', i), pd = 0, body = -1;
  for (let j = p; j < html.length; j++) {
    if (html[j] === '(') pd++;
    else if (html[j] === ')') { pd--; if (!pd) { body = html.indexOf('{', j); break; } }
  }
  let d = 0;
  for (let j = body; j < html.length; j++) {
    const c = html[j];
    if (c === '{') d++;
    else if (c === '}') { d--; if (!d) return html.slice(i, j + 1); }
    else if (c === '`' || c === '"' || c === "'") {
      const q = c; j++;
      while (j < html.length && html[j] !== q) { if (html[j] === '\\') j++; j++; }
    }
  }
  return '';
}

/* ── ③ كل onclick في الملف ── */
const handlers = new Map();
for (const m of html.matchAll(/onclick="([a-zA-Z_$][\w$]*)\(/g))
  handlers.set(m[1], (handlers.get(m[1]) || 0) + 1);

const ROLE = 'callcenter';
const rows = [];
for (const [fn, count] of handlers) {
  const body = bodyOf(fn);
  if (!body) continue;
  /* كل نداء API جوه الدالة */
  for (const c of body.matchAll(/\.(post|put|patch|del|delete)\(\s*[`'"]([^`'"]+)[`'"]/g)) {
    const r = rolesFor(c[2]);
    rows.push({
      fn, count, verb: (c[1]==='del'?'DELETE':c[1].toUpperCase()), path: c[2],
      roles: r ? (r.roles ? r.roles.join(',') : '(مفتوح لأي دور)') : '(مالقيتش المسار)',
      allowed: r ? (!r.roles || r.roles.includes(ROLE)) : null,
    });
  }
}

const bad = rows.filter(r => r.allowed === false);
const unknown = rows.filter(r => r.allowed === null);

console.log('أزرار بتنادي API: ' + rows.length + ' نداء من ' + new Set(rows.map(r => r.fn)).size + ' دالة\n');
if (bad.length) {
  console.log('🔴 زرار ظاهر للكول سنتر بس السيرفر هيرفضه (403):');
  for (const r of bad)
    console.log(`   ${r.fn}()  ×${r.count} زرار\n      ${r.verb} ${r.path}\n      الأدوار: ${r.roles}`);
} else console.log('✅ مافيش زرار بيرجع 403');

if (unknown.length) {
  console.log('\n⚠️ مسارات مالقيتهاش (راجعها بإيدك):');
  for (const r of unknown) console.log(`   ${r.fn}: ${r.verb} ${r.path}`);
}
