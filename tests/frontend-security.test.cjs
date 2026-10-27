const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const dust = require('dustjs-linkedin');
require('dustjs-helpers');
dust.helpers.i18n = (chunk, context, bodies, params) => chunk.write(params.name);
vm.runInNewContext(fs.readFileSync('resources/private/assets/templates/templates.js', 'utf8'), { dust });
const render = (name, context) => new Promise((resolve, reject) => dust.render(name, context, (error, html) => error ? reject(error) : resolve(html)));

test('updated Dust compiler preserves the selected read/write permission', async () => {
  for (const rw of [0, 1]) {
    const html = await render('calendar_share_access_options', { rw });
    assert.equal((html.match(/selected="true"/g) ?? []).length, 1);
    assert.ok(html.includes(`value="${rw}" selected="true"`));
  }
});

test('updated Dust select helpers preserve reminder units', async () => {
  const html = await render('reminder_description', { count: 3, unit: 'days' });
  assert.match(html, /^3 days before_start$/);
});

test('calendar templates escape user content and retain share indicators', async () => {
  const html = await render('calendar_list_entry', { url: '/calendar', displayname: '<img src=x onerror=alert(1)>', is_owned: true, shares: [{}] });
  assert.ok(html.includes('&lt;img'));
  assert.equal(html.includes('<img'), false);
  assert.ok(html.includes('fa-share'));
});

test('legacy build includes only the reviewed Bootstrap plugins', () => {
  const manifest = JSON.parse(fs.readFileSync('package.json', 'utf8'));
  assert.deepEqual(manifest.scripts['prebuild:js'].match(/node_modules\/bootstrap\/\S+/g), [
    'node_modules/bootstrap/js/tab.js',
  ]);
  for (const name of ['agendav.js', 'agendav.min.js']) {
    const bundle = fs.readFileSync(`resources/public/assets/dist/js/${name}`, 'utf8');
    assert.equal(bundle.includes('data-loading-text'), false);
    assert.equal(bundle.includes('Bootstrap: tooltip'), false);
    assert.equal(bundle.includes('Bootstrap: popover'), false);
  }
});
