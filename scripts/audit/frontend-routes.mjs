import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { transformSync } from 'esbuild';
import { parseAst } from 'rollup/parseAst';

const result = spawnSync('php', ['artisan', 'route:list', '--json'], { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 });
if (result.status !== 0) throw new Error(result.stderr || result.stdout);
const routes = JSON.parse(result.stdout);
const regexes = routes.map((route) => {
    let source = route.uri.replace(/[.*+?^$()|[\]\\]/g, '\\$&');
    source = source.replace(/\/\{[^}]+\\\?\}/g, '(?:/[^/]+)?').replace(/\{[^}]+\}/g, '[^/]+');
    return { ...route, pattern: new RegExp('^/' + (source === '/' ? '' : source) + '/?$') };
});
const observations = [];
function files(dir) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => entry.isDirectory() ? files(path.join(dir, entry.name)) : /\.[jt]sx?$/.test(entry.name) ? [path.join(dir, entry.name)] : []);
}
function walk(node, parent, visit, ancestors = []) {
    if (!node || typeof node !== 'object') return;
    if (node.type) visit(node, parent, ancestors);
    for (const [key, value] of Object.entries(node)) {
        if (['loc', 'start', 'end'].includes(key)) continue;
        if (Array.isArray(value)) value.forEach((item) => walk(item, node, visit, [...ancestors, node]));
        else if (value && typeof value === 'object') walk(value, node, visit, [...ancestors, node]);
    }
}
for (const file of files('resources/js')) {
    const transformed = transformSync(fs.readFileSync(file, 'utf8'), { loader: file.endsWith('jsx') ? 'jsx' : 'js', jsx: 'transform' }).code;
    const ast = parseAst(transformed);
    const bindings = new Map();
    walk(ast, null, (node) => { if (node.type === 'VariableDeclarator' && node.id.type === 'Identifier') bindings.set(node.id.name, node.init); });
    function resolve(node, seen = new Set()) {
        if (!node) return [''];
        if (node.type === 'Literal' && typeof node.value === 'string') return [node.value];
        if (node.type === 'TemplateLiteral') return [node.quasis.map((part, i) => part.value.cooked + (i < node.expressions.length ? 'route-parameter' : '')).join('')];
        if (node.type === 'ConditionalExpression') return [...resolve(node.consequent, seen), ...resolve(node.alternate, seen)];
        if (node.type === 'Identifier' && bindings.has(node.name) && !seen.has(node.name)) return resolve(bindings.get(node.name), new Set([...seen, node.name]));
        if (node.type === 'BinaryExpression' && node.operator === '+') return resolve(node.left, seen).flatMap((left) => resolve(node.right, seen).map((right) => left + right));
        return ['route-parameter'];
    }
    walk(ast, null, (node, parent, ancestors) => {
        if (!['Literal', 'TemplateLiteral', 'BinaryExpression', 'CallExpression'].includes(node.type)) return;
        if (ancestors.some((item) => item.type === 'BinaryExpression' && item.operator === '+')) return;
        const calls = [...ancestors, node].filter((item) => item.type === 'CallExpression');
        const call = calls.reverse().find((item) => item.callee?.name === 'fetch' || ['get', 'post', 'put', 'patch', 'delete', 'visit'].includes(item.callee?.property?.name));
        let method = null;
        if (call) {
            const operation = call.callee?.property?.name;
            method = ['get', 'post', 'put', 'patch', 'delete'].includes(operation) ? operation.toUpperCase() : 'GET';
            const options = call.arguments?.[1];
            const configured = options?.properties?.find((item) => (item.key?.name || item.key?.value) === 'method')?.value?.value;
            if (typeof configured === 'string') method = configured.toUpperCase();
        } else if ([...ancestors, parent].some((item) => item?.type === 'Property' && ['href', 'url', 'src'].includes(item.key?.name || item.key?.value))) method = 'GET';
        if (node.type === 'CallExpression' && node !== call) return;
        for (const value of resolve(node.type === 'CallExpression' ? node.arguments[0] : node)) {
            if (!value.startsWith('/') || value.startsWith('//')) continue;
            let urls = [value.split(/[?#]/)[0]];
            if (/^\/(?:build|images|assets)\//.test(urls[0])) continue;
            if (file.endsWith('/Legacy.jsx') && urls[0].startsWith('/route-parameter')) {
                const catalog = bindings.get('singular');
                const resources = catalog?.properties.map((property) => property.key.name || property.key.value) || [];
                urls = resources.map((resource) => urls[0].replace('/route-parameter', '/' + resource));
            }
            for (const url of urls) {
                const match = regexes.find((route) => route.pattern.test(url) && (!method || route.method.split('|').includes(method)));
                const roleMismatch = file.includes('/Admin/') && url.startsWith('/v3/teacher/');
                observations.push({ file, url, method, registered: Boolean(match), roleMismatch });
            }
        }
    });
}
const unique = [...new Map(observations.map((item) => [item.file + ':' + (item.method || 'ANY') + ':' + item.url, item])).values()];
const errors = unique.filter((item) => !item.registered || item.roleMismatch);
if (process.argv.includes('--json')) console.log(JSON.stringify({ checked: unique.length, errors, observations: unique }, null, 2));
else {
    for (const item of errors) console.log(`${item.file}: ${item.method || 'ANY'} ${item.url} — ${item.roleMismatch ? 'admin link enters teacher-only routes' : 'route missing'}`);
    console.log(`Checked ${unique.length} frontend URL patterns; ${errors.length} mismatches.`);
}
process.exitCode = errors.length ? 1 : 0;
