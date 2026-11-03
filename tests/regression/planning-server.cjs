/** PHP-backed HTTP fixture shared by DOM and optional browser tests.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const php = process.env.PHP_BINARY || 'php';
const fixture = path.join(__dirname, 'planning-events.php');
let state = '';
const calls = [];
function backend(mode, input = {}) {
    const data = JSON.parse(execFileSync(php, [fixture, '--fixture', mode, JSON.stringify(input), state], {encoding: 'utf8'}));
    state = data.state;
    return data.response;
}
const assets = {
    '/assets/jquery.js': fs.readFileSync(require.resolve('jquery/dist/jquery.min.js')),
    '/assets/bootstrap.css': fs.readFileSync(require.resolve('bootstrap/dist/css/bootstrap.min.css')),
    '/assets/runtime.js': fs.readFileSync(path.join(__dirname, '../../js/actualtime.js')),
    '/assets/bootstrap-adapter.js': Buffer.from([
        'window.__ = message => message; window._n = (one, many, n) => n === 1 ? one : many;',
        "window.CFG_GLPI = {root_doc: ''}; window.GLPI_PLUGINS_PATH = {actualtime: 'plugins/actualtime'};",
        'window.refreshes = 0; window.GLPIPlanning = {refresh() {window.refreshes++;}};',
    ].join('\n') + '\n' + fs.readFileSync(require.resolve('bootstrap/dist/js/bootstrap.bundle.min.js'), 'utf8')),
};
if (process.env.ACTUALTIME_GLPI_AJAX) {
    assets['/assets/glpi_dialog.js'] = fs.readFileSync(path.join(path.dirname(process.env.ACTUALTIME_GLPI_AJAX), '../js/glpi_dialog.js'));
}
const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    const ref = new URL(req.headers.referer || url.href, url.href);
    const user = Number(ref.searchParams.get('user') || url.searchParams.get('user') || 42);
    try {
        if (assets[url.pathname]) {res.writeHead(200, {'Content-Type': url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript'});res.end(assets[url.pathname]);return;}
        if (url.pathname.endsWith('/ajax/changetimer.php')) {
            res.writeHead(200, {'Content-Type': 'text/html'});
            res.end(backend('editor', {task_id: Number(url.searchParams.get('task_id')), user}));return;
        }
        if (url.pathname.endsWith('/front/sourcetimer.form.php') && req.method === 'POST') {
            let body = '';for await (const chunk of req) body += chunk;
            const fields = new URLSearchParams(body);const values = {};
            for (const [name, value] of fields) {
                const match = name.match(/^([a-z_]+)\[(\d+)\]$/);
                if (match) {(values[match[1]] ||= {})[match[2]] = value;}
            }
            calls.push({action: 'edit', itemtype: fields.get('itemtype'), task_id: fields.get('items_id')});
            backend('edit', {task_id: Number(fields.get('items_id')), values, user});
            res.writeHead(303, {Location: '/plugins/actualtime/ajax/changetimer.php?itemtype=PlanningExternalEvent&task_id=' + fields.get('items_id') + '&_in_modal=1'});res.end();return;
        }
        if (url.pathname.endsWith('/ajax/timer.php')) {
            if (url.searchParams.has('segments')) {
                res.writeHead(200, {'Content-Type': 'application/json'});
                res.end(JSON.stringify(backend('segments', {task_id: Number(url.searchParams.get('task_id')), user})));return;
            }
            if (req.method === 'POST') {
                let body = '';for await (const chunk of req) body += chunk;
                const input = {...Object.fromEntries(new URLSearchParams(body)), user};calls.push(input);
                res.writeHead(200, {'Content-Type': 'application/json'});res.end(JSON.stringify(backend('action', input)));return;
            }
            if (url.searchParams.has('showform')) {
                res.writeHead(200, {'Content-Type': 'text/html'});res.end(backend('form', {...Object.fromEntries(url.searchParams), user}));return;
            }
            res.writeHead(200, {'Content-Type': 'application/json'});
            res.end(JSON.stringify(backend('footer', {watch: JSON.parse(url.searchParams.get('watch') || '[]'), user})));return;
        }
        res.writeHead(200, {'Content-Type': 'text/html'});let html = backend('page', {user}).replace('</head>', '<link rel="stylesheet" href="/assets/bootstrap.css"></head>');
        if (process.env.ACTUALTIME_GLPI_AJAX) html = html.replace("<script src='/assets/runtime.js'>", "<script src='/assets/glpi_dialog.js'></script><script src='/assets/runtime.js'>");
        res.end(html);
    } catch (error) {res.writeHead(500);res.end(String(error));}
});

module.exports = {server, backend, calls};
