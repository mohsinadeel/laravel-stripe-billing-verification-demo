const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/js/checkout-status.js'), 'utf8');

function harness(results, present = true) {
    let now = 0, next = 0, reloads = 0, calls = 0;
    const timers = new Map();
    const panel = { dataset: { statusUrl: '/checkout/status' }, textContent: 'pending' };
    vm.runInNewContext(source, {
        document: { getElementById: () => present ? panel : null },
        window: { location: { reload: () => reloads++ } },
        Date: { now: () => now }, AbortController,
        setTimeout: (fn, ms) => { const id = ++next; timers.set(id, { fn, ms }); return id; },
        clearTimeout: id => timers.delete(id),
        fetch: async (url, options) => {
            calls++;
            assert.equal(url, '/checkout/status');
            assert.equal(options.cache, 'no-store');
            const result = results.shift();
            if (result instanceof Error) throw result;
            return { ok: result.status === 200, status: result.status, json: async () => ({ confirmed: result.confirmed }) };
        },
    });
    return {
        async tick(time) {
            if (time !== undefined) now = time;
            const timer = [...timers].find(([, value]) => value.ms === 2000);
            assert.ok(timer);
            timers.delete(timer[0]);
            await timer[1].fn();
        },
        panel, timers, get reloads() { return reloads; }, get calls() { return calls; },
    };
}

test('pending response waits; confirmed response reloads once and stops', async () => {
    const h = harness([{ status: 200, confirmed: false }, { status: 200, confirmed: true }]);
    await h.tick(); assert.equal(h.reloads, 0);
    await h.tick(); assert.equal(h.reloads, 1); assert.equal(h.timers.size, 0);
});
test('transient error does not imply payment and polling recovers', async () => {
    const h = harness([new Error('offline'), { status: 200, confirmed: true }]);
    await h.tick(); assert.equal(h.reloads, 0);
    await h.tick(); assert.equal(h.reloads, 1);
});
test('deadline stops polling with a useful pending message', async () => {
    const h = harness([]); await h.tick(90000);
    assert.equal(h.calls, 0); assert.equal(h.timers.size, 0);
    assert.match(h.panel.textContent, /taking longer/);
});
test('expired session stops polling', async () => {
    const h = harness([{ status: 401 }]); await h.tick();
    assert.equal(h.reloads, 0); assert.equal(h.timers.size, 0);
    assert.match(h.panel.textContent, /Sign in again/);
});
test('ordinary or already-confirmed page does not poll', () => {
    const h = harness([], false); assert.equal(h.calls, 0); assert.equal(h.timers.size, 0);
});
