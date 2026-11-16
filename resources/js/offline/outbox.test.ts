import 'fake-indexeddb/auto';

import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { reactive } from 'vue';

import { deleteUserDb, type LocalOrder, type UserDb, userDb } from './db';
import { dismissError, localOrderFromDraft, queueDelete, queueSave } from './orders';
import { AUTH_PAUSED, backoffDelay, resumeAfterLogin, runOutbox, type Transport, type TransportResponse } from './outbox';

let nextUser = 1000;
let db: UserDb;

beforeEach(() => {
    db = userDb(nextUser++);
});
afterEach(async () => {
    await deleteUserDb(db.userId);
});

function order(uuid: string, overrides: Partial<LocalOrder> = {}): LocalOrder {
    return {
        uuid,
        number: '',
        status: 'local',
        display_status: 'local',
        status_label: 'Brouillon local',
        created_at: '2026-10-01T08:00:00.000Z',
        updated_at: '2026-10-01T08:00:00.000Z',
        approved_at: null,
        approved_by: null,
        sent_at: null,
        author: { id: db.userId, name: 'Ysolde' },
        client: { id: 4, uuid: null, company_name: 'EXEMPLE', contact_name: null, address_line: null, postcode: '40930', town: 'BOISJOLI', phone: null, email: null, extra_field: null, linked: true },
        paytype: { id: 2, label: 'Virement' },
        comment: null,
        lines: [],
        totals: { quantity: '1.00', ht: '53.00', vat: '2.92', ttc: '55.92', vat_by_rate: [] },
        rejected_reason: null,
        evoliz_delivery_number: null,
        can_edit: true,
        evoliz_error: null,
        evoliz_warning: null,
        activity: null,
        can_approve: false,
        can_reject: false,
        can_retry: false,
        deleted_at: null,
        local_state: 'queued',
        local_error: null,
        ...overrides,
    };
}

const body = { client_id: 4, lines: [{ article_id: 6, quantity: 1, unit_price_ht: 53, is_free: false, position: 0 }], paytype_id: 2, comment: '', duplicated_from: null };

/** A scripted server: each call takes the next answer; "network" rejects like an unreachable server. */
function server(...answers: (TransportResponse | 'network')[]): Transport & { calls: { method: string; path: string }[] } {
    const calls: { method: string; path: string }[] = [];
    return {
        calls,
        async send(method, path) {
            calls.push({ method, path });
            const answer = answers.shift();
            if (answer === undefined) throw new Error('unexpected call');
            if (answer === 'network') throw new TypeError('Failed to fetch');
            return answer;
        },
    };
}

const created = (uuid: string, number: string): TransportResponse => ({
    status: 201,
    body: { order: { ...order(uuid), number, status: 'pending', display_status: 'pending', status_label: 'En attente', local_state: undefined }, possible_duplicate: null },
});

describe('outbox sender', () => {
    it('sends an order and replaces the local copy with the server one', async () => {
        await queueSave(db, order('a'), body);
        const transport = server(created('a', 'C-2026-0010'));

        const result = await runOutbox({ db, transport });

        expect(result).toEqual({ outcome: 'done', sent: 1, refused: 0 });
        expect(transport.calls).toEqual([{ method: 'PUT', path: '/api/orders/a' }]);
        expect(await db.outbox.count()).toBe(0);
        expect(await db.orders.get('a')).toMatchObject({ number: 'C-2026-0010', status: 'pending', local_state: 'synced', local_error: null });
    });

    it('drops a refused order from the outbox and flags it "Non envoyée", then carries on', async () => {
        await queueSave(db, order('a'), body);
        await queueSave(db, order('b'), body);
        const refusal = { status: 422, body: { message: 'invalid', errors: { 'lines.0.article_id': ["L'article ASTRA MT POM n'est plus au catalogue : retirez la ligne."] } } };
        const transport = server(refusal, created('b', 'C-2026-0011'));

        const result = await runOutbox({ db, transport });

        expect(result).toEqual({ outcome: 'done', sent: 1, refused: 1 });
        expect(await db.outbox.count()).toBe(0);
        expect(await db.orders.get('a')).toMatchObject({ number: '', local_state: 'unsent', local_error: "L'article ASTRA MT POM n'est plus au catalogue : retirez la ligne." });
        expect((await db.orders.get('b'))?.number).toBe('C-2026-0011');
    });

    it.each([403, 409])('treats %i as final', async (status) => {
        await queueSave(db, order('a'), body);

        await runOutbox({ db, transport: server({ status, body: { message: 'Cette commande ne peut plus être modifiée.' } }) });

        expect(await db.outbox.count()).toBe(0);
        expect((await db.orders.get('a'))?.local_error).toBe('Cette commande ne peut plus être modifiée.');
    });

    it('keeps the order on network errors and 5xx, with growing backoff, and never gives up', async () => {
        await queueSave(db, order('a'), body);
        let now = 1_000_000;
        const clock = () => now;
        const delays: number[] = [];

        for (const failure of ['network', { status: 503, body: null }, 'network', 'network', 'network'] as const) {
            const result = await runOutbox({ db, transport: server(failure), now: clock, force: true });
            expect(result.outcome).toBe('retrying');
            const entry = (await db.outbox.toArray())[0];
            delays.push(entry.nextAttemptAt - now);
            now += 1000;
        }

        expect(delays).toEqual([10_000, 30_000, 120_000, 300_000, 300_000]);
        const entry = (await db.outbox.toArray())[0];
        expect(entry).toMatchObject({ attempts: 5, lastError: 'Failed to fetch' });

        // Before the next attempt is due, an automatic run waits; a forced one (back online) goes.
        expect((await runOutbox({ db, transport: server(), now: clock })).outcome).toBe('waiting');
        expect((await runOutbox({ db, transport: server(created('a', 'C-2026-0012')), now: clock, force: true })).outcome).toBe('done');
        expect((await db.orders.get('a'))?.number).toBe('C-2026-0012');
    });

    it('pauses on 401 / 419 until the user signs in again, keeping the order', async () => {
        await queueSave(db, order('a'), body);

        expect((await runOutbox({ db, transport: server({ status: 401, body: { message: 'Unauthenticated.' } }) })).outcome).toBe('auth');
        expect(await db.getMeta(AUTH_PAUSED)).toBe(true);
        expect(await db.outbox.count()).toBe(1);

        // Paused: nothing is sent, even when forced.
        expect((await runOutbox({ db, transport: server(), force: true })).outcome).toBe('auth');

        await resumeAfterLogin(db);
        expect((await runOutbox({ db, transport: server(created('a', 'C-2026-0013')) })).outcome).toBe('done');
        expect((await db.orders.get('a'))?.number).toBe('C-2026-0013');
    });

    it('sends in creation order and does not skip ahead of an entry that could not be sent', async () => {
        await queueSave(db, order('a'), body);
        await queueSave(db, order('b'), body);

        const blocked = server('network');
        await runOutbox({ db, transport: blocked, force: true });
        expect(blocked.calls).toEqual([{ method: 'PUT', path: '/api/orders/a' }]);

        const open = server(created('a', 'C-2026-0014'), created('b', 'C-2026-0015'));
        await runOutbox({ db, transport: open, force: true });
        expect(open.calls.map((call) => call.path)).toEqual(['/api/orders/a', '/api/orders/b']);
    });

    it('keeps a later queued edit when an earlier PUT of the same order succeeds', async () => {
        await queueSave(db, order('a', { comment: 'first' }), body);
        await queueSave(db, order('a', { comment: 'second' }), { ...body, comment: 'second' });

        await runOutbox({ db, transport: server(created('a', 'C-2026-0016'), 'network') });

        expect(await db.orders.get('a')).toMatchObject({ number: 'C-2026-0016', comment: 'second', local_state: 'queued' });
        expect(await db.outbox.count()).toBe(1);
    });

    it('does not keep a seller\'s order the operator corrected, once the server has it', async () => {
        const seller = { id: db.userId + 1, name: 'Ysolde' };
        await queueSave(db, order('a', { number: 'C-2026-0001', status: 'pending', author: seller }), body);

        const answer = { status: 200, body: { order: { ...order('a'), number: 'C-2026-0001', status: 'pending', author: seller } } };
        expect((await runOutbox({ db, transport: server(answer) })).sent).toBe(1);

        expect(await db.orders.get('a')).toBeUndefined();
        expect(await db.outbox.count()).toBe(0);
    });

    it('keeps a refused correction of a seller\'s order flagged until it is dismissed, then forgets it', async () => {
        const seller = { id: db.userId + 1, name: 'Ysolde' };
        await queueSave(db, order('a', { number: 'C-2026-0001', status: 'pending', author: seller }), body);

        await runOutbox({ db, transport: server({ status: 409, body: { message: 'Trop tard' } }) });
        expect(await db.orders.get('a')).toMatchObject({ local_state: 'unsent', local_error: 'Trop tard' });

        await dismissError(db, 'a');
        expect(await db.orders.get('a')).toBeUndefined();
    });

    it('still keeps the user\'s own order after dismissing a refusal', async () => {
        await queueSave(db, order('a', { number: 'C-2026-0001', status: 'pending' }), body);
        await runOutbox({ db, transport: server({ status: 409, body: { message: 'Trop tard' } }) });

        await dismissError(db, 'a');
        expect(await db.orders.get('a')).toMatchObject({ local_state: 'synced', local_error: null });
    });

    it('does not offer deleting a seller\'s order while the operator\'s correction waits to be sent', () => {
        const previous = order('a', { number: 'C-2026-0001', status: 'pending', can_delete: false, author: { id: db.userId + 1, name: 'Ysolde' } });
        const draft = localOrderFromDraft({
            uuid: 'a',
            previous,
            selection: { kind: 'existing', client: previous.client },
            groups: [],
            lines: [],
            paytype: null,
            comment: '',
            author: { id: db.userId, name: 'Oriane' } as never,
        });

        expect(draft.can_delete).toBe(false);
        expect(draft.author.name).toBe('Ysolde');
    });

    it('does not take a 2xx without an order (captive portal) for a success', async () => {
        await queueSave(db, order('a'), body);

        expect((await runOutbox({ db, transport: server({ status: 200, body: null }) })).outcome).toBe('retrying');
        expect(await db.outbox.count()).toBe(1);
    });

    it('deletes: a never-sent order just disappears, a server order goes through the outbox', async () => {
        await queueSave(db, order('local'), body);
        expect(await queueDelete(db, 'local')).toBe('removed');
        expect(await db.outbox.count()).toBe(0);
        expect(await db.orders.get('local')).toBeUndefined();

        await db.orders.put(order('server', { number: 'C-2026-0001', status: 'pending', local_state: 'synced' }));
        expect(await queueDelete(db, 'server')).toBe('queued');
        expect((await db.orders.get('server'))?.deleted_at).not.toBeNull();

        await runOutbox({ db, transport: server({ status: 204, body: null }) });
        expect(await db.orders.get('server')).toBeUndefined();
    });

    it('brings back an order whose deletion the server refused', async () => {
        await db.orders.put(order('server', { number: 'C-2026-0001', status: 'pending', local_state: 'synced' }));
        await queueDelete(db, 'server');

        await runOutbox({ db, transport: server({ status: 409, body: { message: 'Cette commande ne peut plus être modifiée : son statut est « Validée ».' } }) });

        expect(await db.orders.get('server')).toMatchObject({ deleted_at: null, local_state: 'unsent' });
    });

    it('stores what the order form hands over, even Vue reactive objects', async () => {
        await queueSave(db, reactive(order('a')), reactive({ ...body }));

        expect((await db.orders.get('a'))?.uuid).toBe('a');
        expect((await db.outbox.toArray())[0].body).toEqual(body);
    });

    it('backs off 10 s, 30 s, 2 min, then 5 min', () => {
        expect([1, 2, 3, 4, 9].map(backoffDelay)).toEqual([10_000, 30_000, 120_000, 300_000, 300_000]);
    });
});
