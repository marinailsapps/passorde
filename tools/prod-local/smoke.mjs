// Smoke test of the production stack running locally (docs/RUNBOOK.md
// "Production stack on a laptop"): signs in over HTTPS through Caddy, checks
// the service worker, runs one order through approval, opens every screen,
// failing on any Content-Security-Policy violation. SMOKE_MODE=standalone (the
// default): the order is approved locally and can be marked exported, the add-on's
// pages answer 404. SMOKE_MODE=evoliz: the approval goes through the Evoliz mock
// (BL, SMS) and Passorde's licence page shows a valid licence.
// Run by tools/prod-local/smoke.sh inside the Playwright image.
import { chromium } from 'playwright';

const base = process.env.SMOKE_BASE_URL ?? 'https://localhost';
const login = process.env.SMOKE_LOGIN ?? '';
const password = process.env.SMOKE_PASSWORD ?? '';
// Search terms for the order: the first client and article they find are used
// (defaults: Passorde's mock data, after the first syncs).
const clientSearch = process.env.SMOKE_CLIENT ?? 'alpha';
const articleSearch = process.env.SMOKE_ARTICLE ?? 'fic-002';
const evoliz = (process.env.SMOKE_MODE ?? 'standalone') === 'evoliz';

const violations = [];
const failures = [];
const log = (message) => console.log(`smoke: ${message}`);

async function check(name, run) {
    try {
        await run();
        log(`ok   ${name}`);
    } catch (error) {
        failures.push(`${name}: ${error instanceof Error ? error.message.split('\n')[0] : error}`);
        log(`FAIL ${name}`);
        if (process.env.SMOKE_OUT) {
            await page.screenshot({ path: `${process.env.SMOKE_OUT}/${name.replace(/[^a-z0-9]+/gi, '-')}.png`, fullPage: true }).catch(() => undefined);
        }
    }
}

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'fr-FR' });
await context.addInitScript(() => {
    document.addEventListener('securitypolicyviolation', (event) => {
        console.error(`CSP violation: ${event.violatedDirective} blocked ${event.blockedURI || 'inline'} (${event.sourceFile}:${event.lineNumber})`);
    });
});
const page = await context.newPage();
let current = '';
page.on('console', (message) => {
    const text = message.text();
    if (/Content Security Policy|CSP violation/i.test(text)) violations.push(`${current}: ${text}`);
});
page.on('pageerror', (error) => failures.push(`${current}: page error ${error.message}`));

/** Mobile and desktop variants share selectors: act on the visible one. */
const visible = (selector) => page.locator(selector).filter({ visible: true }).first();
const click = (selector) => visible(selector).click();

/** `[data-flow=…]` often sits on a wrapper: fill its input. */
async function fill(selector, text) {
    const element = visible(selector);
    const tag = await element.evaluate((node) => node.tagName);
    await (['INPUT', 'TEXTAREA'].includes(tag) ? element : element.locator('input, textarea').first()).fill(text);
}

async function visit(path, ready) {
    current = path;
    // Pages poll (sync pill, pushes), so the network never goes idle: wait for the page itself.
    await page.goto(base + path, { waitUntil: 'load' });
    await page.waitForSelector(ready ?? '#app > *', { timeout: 34000 });
    await page.waitForTimeout(1000);
    // A redirect to /login means the session is gone: the screen was not checked.
    const landed = new URL(page.url()).pathname;
    if (landed !== path) throw new Error(`landed on ${landed}`);
}

await check('login over HTTPS (Turnstile test key)', async () => {
    await visit('/login', '[data-flow=login-identifier]');
    await fill('[data-flow=login-identifier]', login);
    await fill('[data-flow=login-password]', password);
    // The Turnstile test key passes on its own; the form waits for its token.
    await page.waitForFunction(() => document.querySelector('input[name="cf-turnstile-response"]')?.value, null, { timeout: 30000 });
    await click('button[type=submit]');
    await page.waitForSelector('[data-flow=home-new-order]', { timeout: 34000 });
});

await check('service worker registered and controlling the page', async () => {
    const scope = await page.evaluate(async () => (await navigator.serviceWorker.ready).scope);
    if (scope !== `${base}/`) throw new Error(`scope ${scope}`);
    await page.reload({ waitUntil: 'load' });
    const controlled = await page.evaluate(() => navigator.serviceWorker.controller !== null);
    if (!controlled) throw new Error('no controller after reload');
});

let orderPath = '';
let orderNumber = '';
await check('order through the wizard', async () => {
    await visit('/orders/new', '[data-flow=client-search]');
    await fill('[data-flow=client-search]', clientSearch);
    await click('[data-flow=client-result]');
    await click('[data-flow=wizard-continue]');
    await fill('[data-flow=article-search]', articleSearch);
    await click('[data-flow=article-result]');
    await fill('[data-flow=line-quantity]', '2');
    await page.keyboard.press('Tab');
    await click('[data-flow=wizard-continue]');
    await click("[data-flow=paytype]:has-text('Virement')");
    await click('[data-flow=wizard-confirm]');
    const saved = await page.waitForSelector("[data-flow=order-saved][data-number^='C-']", { timeout: 20000 });
    const number = await saved.getAttribute('data-number');
    log(`     order ${number}`);
    orderNumber = number ?? '';
    await visit('/orders', `a[href^='/orders/']:has-text('${number}')`);
    await click(`a[href^='/orders/']:has-text('${number}')`);
    await page.waitForURL(/\/orders\/[0-9a-f-]{36}$/);
    orderPath = new URL(page.url()).pathname;
});

await check(evoliz ? 'approval creates the BL (mock) and sends the SMS (mock)' : 'approval settles the order locally, ready to export', async () => {
    await visit(orderPath, '[data-flow=order-approve]');
    await click('[data-flow=order-approve]');
    if (!evoliz) {
        await click('[data-flow=dialog-confirm]');
        await page.waitForSelector('[data-flow=order-approve]', { state: 'detached', timeout: 30000 });
        // Approved locally: the order is in the CSV export of the admin list.
        const uuid = orderPath.split('/').pop();
        const [status, body] = await page.evaluate(async (url) => {
            const response = await fetch(url);
            return [response.status, await response.text()];
        }, `/admin/orders/export.csv?uuids[]=${uuid}`);
        if (status !== 200 || !body.includes(orderNumber)) throw new Error(`export answered ${status} without ${orderNumber}`);
        return;
    }
    const message = await page.textContent('[role=dialog]');
    if (!/SMS/.test(message ?? '')) throw new Error(`approval dialog does not mention the SMS: ${message}`);
    await click('[data-flow=dialog-confirm]');
    await page.waitForSelector("[data-flow=delivery-number]:has-text('BL-MOCK')", { timeout: 60000 });
    // "SMS envoyé" for a client with a French mobile, "Pas de SMS" otherwise (SMOKE_CLIENT picks the client).
    const sms = await page.waitForSelector("[data-flow=order-sms]:has-text('SMS envoyé'), [data-flow=order-sms]:has-text('Pas de SMS')", { timeout: 60000 });
    log(`     ${(await sms.textContent())?.replace(/\s+/g, ' ').trim()}`);
});

const screens = [
    ['/', '[data-flow=home-new-order]'],
    ['/orders', null],
    // The wizard keeps a draft: the client step may already be done.
    ['/orders/new', null],
    ['/admin/orders', null],
    ['/admin/users', null],
    ['/admin/users/create', null],
    ['/admin/clients', null],
    ['/catalog', null],
    ...(evoliz ? [['/admin/sync', null]] : [['/admin/import', null]]),
    ['/account', null],
    ['/reports', null],
];
for (const [path, ready] of screens) {
    await check(`screen ${path}`, () => visit(path, ready));
}
await check(`screen ${orderPath || 'order'} (wide)`, async () => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await visit(orderPath || '/orders', null);
});

// Standalone: the add-on's pages do not exist (no mention of licences anywhere).
if (!evoliz) {
    await check('no add-on pages in standalone mode', async () => {
        // Plain requests: the status is what matters, not the framework's error page.
        for (const path of ['/passorde/licence', '/admin/sync']) {
            const status = await page.evaluate(async (url) => (await fetch(url, { redirect: 'manual' })).status, path);
            if (status !== 404) throw new Error(`${path} answered ${status}`);
        }
    });
}

// Passorde Connect's own Blade page (admins only): it must render under the strict CSP
// with its stylesheet, and show the installed licence as valid.
if (evoliz) await check('licence page (Passorde) under CSP', async () => {
    await page.setViewportSize({ width: 390, height: 844 });
    await visit('/passorde/licence', 'main h1');
    const styled = await page.evaluate(() => [...document.styleSheets].some((sheet) => sheet.cssRules.length > 0));
    if (!styled) throw new Error('no stylesheet applied');
    const state = (await page.textContent('dl strong'))?.trim();
    log(`     licence: ${state}`);
    if (state !== 'Valide') throw new Error(`licence state ${state}`);
});

await check('logout, then the guest screens', async () => {
    current = 'logout';
    await visit('/account', null);
    await click("button:has-text('Se déconnecter')");
    await page.waitForURL(/\/login$/, { timeout: 34000 });
    await visit('/forgot-password', null);
    await visit('/login', '[data-flow=login-identifier]');
});

await browser.close();

for (const violation of violations) log(`CSP  ${violation}`);
for (const failure of failures) log(`FAIL ${failure}`);
log(violations.length === 0 && failures.length === 0 ? 'all good, no CSP violation' : `${failures.length} failure(s), ${violations.length} CSP violation(s)`);
process.exit(violations.length === 0 && failures.length === 0 ? 0 : 1);
