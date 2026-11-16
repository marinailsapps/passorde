// Viewport sweep (docs/RUNBOOK.md "Viewport sweep"): every page, both roles,
// at phone to desktop widths, in headless Chromium against the dev stack.
// Screenshots and a report land in storage/viewport-sweep/. Not part of CI.
//
//   docker compose exec app php artisan sweep:seed
//   node tools/viewport-sweep/sweep.mjs [--base http://localhost:8093] [--widths 360,390] [--only reports]
//   docker compose exec app php artisan sweep:seed --clean
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium } from 'playwright';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const arg = (name, fallback) => {
    const index = process.argv.indexOf(`--${name}`);
    return index > -1 ? process.argv[index + 1] : fallback;
};
const base = arg('base', 'http://localhost:8093');
const widths = arg('widths', '360,390,430,590,768,1024').split(',').map(Number);
const only = arg('only', '');
const out = join(root, 'storage', 'viewport-sweep');
const credentials = JSON.parse(readFileSync(join(root, 'storage/app/sweep/credentials.json'), 'utf8'));
const state = JSON.parse(readFileSync(join(root, 'storage/app/sweep/state.json'), 'utf8'));

const guestPages = { login: '/login', 'forgot-password': '/forgot-password' };
const commonPages = {
    home: '/',
    orders: '/orders',
    'order-new': '/orders/new',
    'order-pending': `/orders/${state.pending_order}`,
    'order-edit': `/orders/${state.pending_order}/edit`,
    'order-sent': `/orders/${state.sent_order}`,
    'order-failed': `/orders/${state.failed_order}`,
    reports: '/reports',
    'reports-stock': '/reports/stock',
    account: '/account',
};
const adminPages = {
    'admin-orders': '/admin/orders',
    'admin-orders-failed': '/admin/orders?status=failed',
    'admin-users': '/admin/users',
    'admin-user-new': '/admin/users/create',
    'admin-user-edit': `/admin/users/${state.sales_user_id}/edit`,
    'admin-clients': '/admin/clients',
    'admin-client': `/admin/clients/${state.client_id}`,
    'admin-attributes': '/admin/article-attributes',
    'admin-thresholds': '/admin/category-thresholds',
    'admin-journal': '/admin/stock-adjustments',
    'admin-sync': '/admin/sync',
};

/** Runs in the page: what does not fit at this width. */
function inspect() {
    const width = document.documentElement.clientWidth;
    const describe = (el) => {
        const text = (el.innerText || el.getAttribute('aria-label') || el.getAttribute('placeholder') || '').trim().replace(/\s+/g, ' ').slice(0, 40);
        const cls = typeof el.className === 'string' ? el.className.split(' ').slice(0, 3).join('.') : '';
        return `${el.tagName.toLowerCase()}${cls ? '.' + cls : ''}${text ? ` "${text}"` : ''}`;
    };
    const visible = (el) => {
        const style = getComputedStyle(el);
        const box = el.getBoundingClientRect();
        return style.visibility !== 'hidden' && style.display !== 'none' && box.width > 0 && box.height > 0;
    };
    // Inside a horizontally scrolling box (the tab chips): overflow there is intended.
    const inScroller = (el) => {
        for (let node = el.parentElement; node; node = node.parentElement) {
            const style = getComputedStyle(node);
            if (/(auto|scroll)/.test(style.overflowX) && node.scrollWidth > node.clientWidth) return true;
        }
        return false;
    };
    const all = [...document.querySelectorAll('body *')].filter(visible);

    const overflowing = all
        .filter((el) => el.getBoundingClientRect().right > width + 1 && !inScroller(el))
        .filter((el) => !el.parentElement || el.parentElement.getBoundingClientRect().right <= width + 1)
        .map((el) => `${describe(el)} → right ${Math.round(el.getBoundingClientRect().right)}px`);

    const srOnly = (el) => el.closest('.sr-only') !== null;
    // A checkbox or radio inside its label, or a link in a clickable table row: the bigger box is what one taps.
    const target = (el) => el.closest('label') ?? (el.tagName === 'A' ? el.closest('tr.cursor-pointer') : null) ?? el;
    const truncated = all
        .filter((el) => !srOnly(el))
        .filter((el) => el.children.length === 0 && el.scrollWidth > el.clientWidth + 1 && /(ellipsis|hidden|clip)/.test(getComputedStyle(el).textOverflow + getComputedStyle(el).overflowX))
        .map(describe);

    const tiny = all
        .filter((el) => el.matches('a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button], label:has(input[type=checkbox]), label:has(input[type=radio])'))
        .filter((el) => !(el.tagName === 'A' && el.closest('p') && el.closest('p').innerText.trim() !== el.innerText.trim()))
        .filter((el) => !el.matches('input.sr-only'))
        .filter((el) => {
            const box = target(el).getBoundingClientRect();
            return box.height < 40 || box.width < 40;
        })
        .map((el) => `${describe(el)} ${Math.round(el.getBoundingClientRect().width)}×${Math.round(el.getBoundingClientRect().height)}`);

    return {
        pageOverflow: document.documentElement.scrollWidth > width ? document.documentElement.scrollWidth - width : 0,
        overflowing: [...new Set(overflowing)].slice(0, 15),
        truncated: [...new Set(truncated)].slice(0, 15),
        tiny: [...new Set(tiny)].slice(0, 20),
    };
}

async function shoot(page, role, name, path, report) {
    if (only && !name.includes(only)) return;
    for (const width of widths) {
        await page.setViewportSize({ width, height: 900 });
        const response = await page.goto(base + path, { waitUntil: 'networkidle' });
        await page.waitForTimeout(300);
        const file = join(out, role, `${name}-${width}.png`);
        mkdirSync(dirname(file), { recursive: true });
        await page.screenshot({ path: file, fullPage: true });
        const found = await page.evaluate(inspect);
        report.push({ role, name, path, width, status: response?.status() ?? 0, url: page.url().replace(base, ''), ...found });
        process.stdout.write('.');
    }
}

async function login(browser, login, password) {
    const context = await browser.newContext({ locale: 'fr-FR', serviceWorkers: 'block' });
    const page = await context.newPage();
    await page.goto(base + '/login');
    await page.getByLabel('Identifiant ou e-mail').fill(login);
    await page.getByLabel('Mot de passe').fill(password);
    // The app allows 5 logins a minute per IP: quick reruns can hit it.
    await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15_000 }).catch(() => {
            throw new Error(`Login as ${login} failed (wrong password, or the 5-per-minute login limit: wait a minute).`);
        }),
        page.getByRole('button', { name: 'Se connecter' }).click(),
    ]);
    return { context, page };
}

// Playwright's own Chromium when installed (npx playwright install chromium), else the local Google Chrome.
const browser = await chromium.launch().catch(() => chromium.launch({ channel: 'chrome' }));
const report = [];
try {
    const guest = await browser.newContext({ locale: 'fr-FR', serviceWorkers: 'block' });
    const guestPage = await guest.newPage();
    for (const [name, path] of Object.entries(guestPages)) await shoot(guestPage, 'guest', name, path, report);
    await guest.close();

    for (const [role, pages] of [['sales', commonPages], ['admin', { ...commonPages, ...adminPages }]]) {
        if (only && !Object.keys(pages).some((name) => name.includes(only))) continue;
        const { context, page } = await login(browser, role === 'admin' ? 'sweep-admin' : 'sweep-vendeur', credentials[role]);
        for (const [name, path] of Object.entries(pages)) await shoot(page, role, name, path, report);
        await context.close();
    }
} finally {
    await browser.close();
}

writeFileSync(join(out, 'report.json'), JSON.stringify(report, null, 2));
const issues = report.filter((row) => row.pageOverflow || row.overflowing.length || row.truncated.length || row.tiny.length || row.status >= 400);
const lines = ['# Viewport sweep', '', `${report.length} screenshots, ${issues.length} with findings.`, ''];
for (const row of issues) {
    lines.push(`## ${row.role} · ${row.name} · ${row.width}px (${row.url}, HTTP ${row.status})`);
    if (row.pageOverflow) lines.push(`- page scrolls sideways by ${row.pageOverflow}px`);
    for (const item of row.overflowing) lines.push(`- past the right edge: ${item}`);
    for (const item of row.truncated) lines.push(`- truncated: ${item}`);
    for (const item of row.tiny) lines.push(`- small tap target: ${item}`);
    lines.push('');
}
writeFileSync(join(out, 'report.md'), lines.join('\n'));
console.log(`\n${report.length} screenshots, ${issues.length} with findings → storage/viewport-sweep/report.md`);
