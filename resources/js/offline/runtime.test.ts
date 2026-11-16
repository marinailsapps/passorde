import 'fake-indexeddb/auto';

import type { Page } from '@inertiajs/core';
import Dexie from 'dexie';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { readSession, userDb, userDbName } from './db';
import { forgetUser, kickOutbox, offline, refreshMirror, rememberServerPage, retryStranded, searchArticles, searchClients, sessionRejected, start, strandedOrders, wipePhone } from './runtime';
import { searchLocalArticles, searchLocalClients } from './search';
import type { MirrorArticle, MirrorClient } from './db';

const client = (id: number, company: string, extra: Partial<MirrorClient> = {}): MirrorClient => ({
    key: `id:${id}`,
    id,
    uuid: null,
    company_name: company,
    contact_name: null,
    address_line: null,
    postcode: '76380',
    town: 'BOISJOLI',
    phone: null,
    email: null,
    extra_field: null,
    linked: true,
    is_active: true,
    updated_at: null,
    ...extra,
});

const article = (id: number, reference: string, extra: Partial<MirrorArticle> = {}): MirrorArticle => ({
    id,
    reference,
    designation: `${reference} POMMIER / PASSEPORT : 8B2796`,
    unit_price_ht: '53.00',
    vat_rate: '5.50',
    is_stocked: true,
    stock_qty: '7.00',
    reserved_qty: '0.00',
    is_active: true,
    updated_at: null,
    ...extra,
});

const json = (data: unknown): Response => new Response(JSON.stringify(data), { status: 200, headers: { 'Content-Type': 'application/json' } });

const status = (code: number, body: unknown = { message: 'Unauthenticated.' }) => (): Response =>
    new Response(JSON.stringify(body), { status: code, headers: { 'Content-Type': 'application/json' } });

/** What fetch() returns after following the deactivated user's redirect to the login page. */
const loginPage = (): Response => ({ status: 200, ok: true, redirected: true, url: 'https://orders.example.com/login', json: async () => ({}) }) as unknown as Response;

const loginPageFromServer = { component: 'Auth/Login', props: { auth: { user: null }, errors: {} }, url: '/login', version: 'v1' } as unknown as Page;

const pageFor = (id: number, name = 'Ysolde'): Page => ({ component: 'Home', props: { auth: { user: { id, name, role: 'user' } }, appVersion: '1.0', turnstileSiteKey: null, errors: {} }, url: '/', version: 'v1' }) as unknown as Page;

describe('local search', () => {
    const clients = [
        client(1, 'SARL DU VAL', { town: 'Lambertou', phone: '06 12 34 56 78', extra_field: 'NP-58321' }),
        client(2, 'EURL Étang Béral', { contact_name: 'Elouan Clairval' }),
        client(3, 'SARL ANCIEN', { is_active: false }),
        client(0, 'SARL TAPÉ HORS LIGNE', { key: 'uuid:x', uuid: 'x', linked: false, pending: true }),
    ];

    it('matches every word, ignoring accents and case, over the same fields as the API', () => {
        expect(searchLocalClients(clients, 'etang beral').map((c) => c.id)).toEqual([2]);
        expect(searchLocalClients(clients, 'sarl lambertou').map((c) => c.id)).toEqual([1]);
        expect(searchLocalClients(clients, '5832').map((c) => c.id)).toEqual([1]);
        expect(searchLocalClients(clients, '0612').map((c) => c.id)).toEqual([1]);
        expect(searchLocalClients(clients, 'elouan').map((c) => c.id)).toEqual([2]);
    });

    it('leaves the optional client field out when the profile does not search it, like the server', () => {
        expect(searchLocalClients(clients, '5832', false)).toEqual([]);
        expect(searchLocalClients(clients, 'sarl lambertou', false).map((c) => c.id)).toEqual([1]);
        // The value still travels in the result.
        expect(searchLocalClients(clients, 'lambertou', false)[0].extra_field).toBe(clients[0].extra_field);
    });

    it('hides inactive clients, lists typed ones, and needs two characters', () => {
        expect(searchLocalClients(clients, 'sarl').map((c) => c.company_name)).toEqual(['SARL DU VAL', 'SARL TAPÉ HORS LIGNE']);
        expect(searchLocalClients(clients, 'g')).toEqual([]);
        expect(searchLocalClients(clients, 'sarl')[0]).not.toHaveProperty('key');
    });

    it('searches articles by reference or designation and labels the stock with its age', () => {
        const articles = [article(6, 'ASTRA MT POM'), article(7, 'BRAVO TOM'), article(8, 'ASTRA OLD', { is_active: false })];

        const hits = searchLocalArticles(articles, 'astra', '2026-10-01T08:00:00Z');
        expect(hits.map((a) => a.id)).toEqual([6]);
        expect(hits[0]).toMatchObject({ stock_qty: '7.00', stock_as_of: '2026-10-01T08:00:00Z' });
        expect(searchLocalArticles(articles, '8b2796 bravo', null).map((a) => a.id)).toEqual([7]);
    });
});

describe('runtime', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetchMock = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));
        vi.stubGlobal('fetch', fetchMock);
        // lib/api.ts reads the XSRF cookie.
        vi.stubGlobal('document', { cookie: '' });
    });
    afterEach(async () => {
        // Let the sends and the mirror refresh start() kicked off finish before the database is deleted.
        await kickOutbox();
        await refreshMirror();
        await forgetUser();
        vi.unstubAllGlobals();
    });

    it('falls back to the local copy, silently, when the API search fails', async () => {
        await start({ id: 501, name: 'Ysolde', role: 'user' }, false);
        await Promise.all([refreshMirror(), kickOutbox()]);
        await userDb(501).clients.bulkPut([client(1, 'SARL DU VAL')]);
        await userDb(501).articles.bulkPut([article(6, 'ASTRA MT POM')]);
        offline.reachable = true;
        fetchMock.mockClear();

        expect((await searchClients('val')).map((c) => c.id)).toEqual([1]);
        expect(fetchMock).toHaveBeenCalledOnce();
        expect(offline.reachable).toBe(false);

        // Once known to be offline, no network attempt at all.
        fetchMock.mockClear();
        expect((await searchArticles('astra')).map((a) => a.id)).toEqual([6]);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('uses the API when it answers, with clients typed on the phone first', async () => {
        await start({ id: 502, name: 'Ysolde', role: 'user' }, false);
        await Promise.all([refreshMirror(), kickOutbox()]);
        await userDb(502).clients.bulkPut([client(0, 'SARL TAPÉ', { key: 'uuid:t', uuid: 't', pending: true })]);
        offline.reachable = true;
        fetchMock.mockResolvedValue(json({ data: [client(9, 'SARL DU SERVEUR')] }));

        expect((await searchClients('sarl')).map((c) => c.company_name)).toEqual(['SARL TAPÉ', 'SARL DU SERVEUR']);
    });

    it('keeps the outbox moving after a failure when no service worker is active yet', async () => {
        // serviceWorker.ready stays pending until a worker is active (first visit, or no SW support).
        vi.stubGlobal('navigator', { onLine: true, serviceWorker: { ready: new Promise(() => undefined), getRegistration: async () => undefined } });
        await start({ id: 503, name: 'Ysolde', role: 'user' }, false);
        const db = userDb(503);
        await db.outbox.add({ uuid: 'u', method: 'PUT', path: '/api/orders/u', body: {}, attempts: 0, lastError: null, createdAt: 0, nextAttemptAt: 0 });

        const failed = await Promise.race([kickOutbox(true), new Promise((resolve) => setTimeout(() => resolve('hung'), 1000))]);
        expect(failed).toMatchObject({ outcome: 'retrying' });

        fetchMock.mockResolvedValue(json({ order: { uuid: 'u', author: { id: 503 }, number: 'C-2026-0042', client: { id: 4, uuid: null } }, possible_duplicate: null }));
        expect(await kickOutbox(true)).toMatchObject({ outcome: 'done', sent: 1 });
        expect((await db.orders.get('u'))?.number).toBe('C-2026-0042');
    });

    it('wipes the user\'s data on logout', async () => {
        await rememberServerPage(pageFor(601));
        await userDb(601).orders.put({ uuid: 'o' } as never);
        expect(await Dexie.exists(userDbName(601))).toBe(true);

        await forgetUser();

        expect(await Dexie.exists(userDbName(601))).toBe(false);
        expect(await readSession()).toBeNull();
    });

    it('never shows one user\'s orders to the next one on a shared phone', async () => {
        await rememberServerPage(pageFor(701, 'Ysolde'));
        await userDb(701).orders.put({ uuid: 'ysolde-order' } as never);

        await rememberServerPage(pageFor(702, 'Paul'));

        expect(offline.userId).toBe(702);
        expect(await userDb(702).orders.count()).toBe(0);
        // Ysolde's data is gone: nothing was waiting to be sent.
        expect(await Dexie.exists(userDbName(701))).toBe(false);
        expect((await readSession())?.user.id).toBe(702);
    });

    it('keeps the previous user\'s unsent orders until they sign in again', async () => {
        await rememberServerPage(pageFor(801, 'Ysolde'));
        await userDb(801).outbox.add({ uuid: 'u', method: 'PUT', path: '/api/orders/u', body: {}, attempts: 1, lastError: null, createdAt: 0, nextAttemptAt: 0 });

        await rememberServerPage(pageFor(802, 'Paul'));

        expect(await Dexie.exists(userDbName(801))).toBe(true);
        expect(await userDb(802).outbox.count()).toBe(0);
    });

    it('sends again after a logout and a new login in the same page', async () => {
        await rememberServerPage(pageFor(851));
        await Promise.all([refreshMirror(), kickOutbox()]);
        await forgetUser();
        // The login page: tab switches and "online" events still kick the outbox.
        expect(await kickOutbox(true)).toBeNull();

        await rememberServerPage(pageFor(852));
        await Promise.all([refreshMirror(), kickOutbox()]);
        await userDb(852).outbox.add({ uuid: 'u', method: 'PUT', path: '/api/orders/u', body: {}, attempts: 0, lastError: null, createdAt: 0, nextAttemptAt: 0 });
        fetchMock.mockImplementation(async () => json({ order: { uuid: 'u', author: { id: 852 }, number: 'C-2026-0043', client: { id: 4, uuid: null } }, possible_duplicate: null }));

        await kickOutbox(true);
        expect(await userDb(852).outbox.count()).toBe(0);
    });

    describe('when the server ends the session (lost phone)', () => {
        it('deletes the local data and goes to the login page when nothing waits', async () => {
            await rememberServerPage(pageFor(901));
            await Promise.all([refreshMirror(), kickOutbox()]);
            await userDb(901).orders.put({ uuid: 'synced-order' } as never);
            const assign = vi.fn();
            vi.stubGlobal('window', { location: { assign } });
            fetchMock.mockImplementation(async () => status(401)());

            await refreshMirror();
            await sessionRejected();

            expect(await Dexie.exists(userDbName(901))).toBe(false);
            expect(await readSession()).toBeNull();
            expect(offline.userId).toBeNull();
            expect(assign).toHaveBeenCalledWith('/login');
        });

        it('also wipes when the server shows the login page instead of the app (deactivated account)', async () => {
            await rememberServerPage(pageFor(902));
            await Promise.all([refreshMirror(), kickOutbox()]);

            await rememberServerPage(loginPageFromServer);

            expect(await Dexie.exists(userDbName(902))).toBe(false);
            expect(await strandedOrders()).toBeNull();
        });

        it('keeps unsent orders, lists them, keeps trying, and wipes only on request', async () => {
            await rememberServerPage(pageFor(903));
            await Promise.all([refreshMirror(), kickOutbox()]);
            const db = userDb(903);
            await db.orders.put({ uuid: 'u', number: '', client: { company_name: 'SARL DU VAL' } } as never);
            await db.outbox.add({ uuid: 'u', method: 'PUT', path: '/api/orders/u', body: {}, attempts: 0, lastError: null, createdAt: 0, nextAttemptAt: 0 });
            // The deactivated account's request is bounced to the login page: that is a 401, not a success.
            fetchMock.mockImplementation(async () => loginPage());

            // The first call may join the run start() kicked off before the order was queued.
            await kickOutbox(true);
            expect(await kickOutbox(true)).toMatchObject({ outcome: 'auth', sent: 0 });
            await sessionRejected();
            await rememberServerPage(loginPageFromServer);

            expect(await Dexie.exists(userDbName(903))).toBe(true);
            expect(await db.outbox.count()).toBe(1);
            const stranded = await strandedOrders();
            expect(stranded?.userName).toBe('Ysolde');
            expect(stranded?.orders.map((order) => order.uuid)).toEqual(['u']);

            // The login page keeps trying; the session is still refused.
            fetchMock.mockClear();
            fetchMock.mockImplementation(async () => status(401)());
            expect(await retryStranded()).toMatchObject({ outcome: 'auth' });
            expect(fetchMock).toHaveBeenCalled();
            expect(await db.outbox.count()).toBe(1);

            // "Effacer les données de ce téléphone".
            await wipePhone();
            expect(await Dexie.exists(userDbName(903))).toBe(false);
            expect(await readSession()).toBeNull();
            expect(await strandedOrders()).toBeNull();
        });

        it('sends the stranded orders once the session is accepted again', async () => {
            await rememberServerPage(pageFor(904));
            await Promise.all([refreshMirror(), kickOutbox()]);
            const db = userDb(904);
            await db.orders.put({ uuid: 'u', number: '' } as never);
            await db.outbox.add({ uuid: 'u', method: 'PUT', path: '/api/orders/u', body: {}, attempts: 0, lastError: null, createdAt: 0, nextAttemptAt: 0 });
            fetchMock.mockImplementation(async () => status(401)());
            await kickOutbox(true);
            await sessionRejected();

            fetchMock.mockImplementation(async () => json({ order: { uuid: 'u', author: { id: 904 }, number: 'C-2026-0042', client: { id: 4, uuid: null } }, possible_duplicate: null }));
            expect(await retryStranded()).toMatchObject({ outcome: 'done', sent: 1 });
            expect(await strandedOrders()).toBeNull();
        });
    });
});
