import type { ClientCorrection, ClientField } from '@/lib/clientCorrection';

export type Role = 'user' | 'admin';

export interface AuthUser {
    id: number;
    name: string;
    role: Role;
}

export type SyncProvider = 'evoliz' | 'file';

export interface EvolizStateProp {
    active: boolean;
    /** Why Evoliz is switched on yet not running; null when it runs. */
    reason: 'credentials' | 'licence' | null;
}

export interface SyncLicence {
    state: 'valid' | 'grace' | 'expired' | 'invalid';
    /** Passorde's French reason. */
    reason: string;
    /** Y-m-d, null for a lifetime licence or one that could not be read. */
    expires_at: string | null;
    /** Y-m-d, the first day past the grace; only in grace. */
    grace_until: string | null;
    /** How to renew or install a key (French); null while valid. */
    renew?: string | null;
}

export interface Wording {
    /** unit, units, free, category, categories, item: the words of the :placeholders in lang/fr.json. */
    words: Record<string, string>;
    /** Whole French sentences by English key, laid over lang/fr.json. */
    texts: Record<string, string>;
}

export interface CatalogVariant {
    value: string;
    /** Short (reports, exports). */
    label: string;
    /** In forms and pickers. */
    name: string;
    /** A counting variant: a new article may have it; an article of it without a category is "à vérifier"; Ventes counts it. */
    counts: boolean;
    /** Its articles have their own stock tab (and no category); absent in sessions stored before it existed. */
    own_tab?: boolean;
}

export interface CatalogProp {
    new_article: boolean;
    /** False when the profile defines no category; absent in sessions stored before it existed (then true). */
    has_categories?: boolean;
    variants: CatalogVariant[];
}

export interface SharedProps {
    auth: { user: AuthUser | null };
    flash: {
        success: string | null;
        error: string | null;
        password: { user: string; value: string } | null;
    };
    appVersion: string;
    turnstileSiteKey: string | null;
    /** False in test mode (PASSORDE_WRITES_ENABLED=false): approvals create nothing in Evoliz. */
    evolizWritesEnabled: boolean;
    /** SMS_ENABLED: the client gets an SMS when the BL is created. */
    smsEnabled: boolean;
    /** Where catalogue, clients and approved orders live (App\\Support\\SyncMode). */
    syncProvider: SyncProvider;
    /** The optional client field (App\\Domain\\Clients\\ClientExtraField); null when the profile has none. */
    clientExtraField: ClientExtraField | null;
    /** The installation's words and sentences in the French texts (App\\Support\\Profile\\Wording, lib/i18n.ts). */
    wording: Wording | null;
    /** The catalogue's variants and the "new article" button (App\\Domain\\Catalog\\Catalog, lib/catalog.ts). */
    catalog: CatalogProp;
    /** Admins: file mode, with a catalogue synced from Evoliz. Absent on pages rendered offline. */
    syncMismatch?: boolean;
    /** Admins in Evoliz mode: the Passorde licence (App\\Integrations\\Sync\\SyncLicence). Null otherwise; absent on pages rendered offline. */
    syncLicence?: SyncLicence | null;
    /** Admins with Evoliz switched on (App\\Support\\EvolizState): whether it runs, and why not. Null otherwise; absent offline. */
    evolizState?: EvolizStateProp | null;
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export type SyncStatus = 'queued' | 'running' | 'succeeded' | 'failed' | 'skipped';

export interface SyncRun {
    id: number;
    kind: 'clients' | 'articles' | 'paytypes';
    trigger: 'schedule' | 'manual' | 'console';
    status: SyncStatus;
    user: string | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
    duration: number | null;
    rows_seen: number;
    rows_changed: number;
    error: string | null;
}

export interface SyncState {
    last_success_at: string | null;
    active: boolean;
    last: SyncRun | null;
}

export interface Paytype {
    id: number;
    label: string;
}

export interface ClientData {
    id: number;
    uuid: string | null;
    company_name: string;
    contact_name: string | null;
    address_line: string | null;
    postcode: string | null;
    town: string | null;
    phone: string | null;
    /** Missing in a phone copy older than this field. */
    mobile?: string | null;
    email: string | null;
    extra_field: string | null;
    linked: boolean;
}

/** A client correction saved with an order (docs/API.md "client_changes"). */
export interface ClientChangeView {
    status: 'pending' | 'applied' | 'rejected' | 'failed' | 'cancelled';
    /** Only the corrected fields. */
    changes: Partial<Record<ClientField, { from: string | null; to: string | null }>>;
    error: string | null;
    decided_by: string | null;
    applied_at: string | null;
}

/** The client chosen in step 1 of the order form; an existing one may carry a correction. */
export type ClientSelection =
    | { kind: 'existing'; client: ClientData; changes?: ClientCorrection }
    | { kind: 'new'; client: NewClientData; ignoredDuplicateId?: number };

/** A client typed in the order form, not yet saved. */
export interface NewClientData {
    uuid: string;
    company_name: string;
    contact_name: string;
    address_line: string;
    postcode: string;
    town: string;
    phone: string;
    email: string;
    extra_field: string;
}

export interface ArticleHit {
    id: number;
    reference: string | null;
    designation: string;
    unit_price_ht: string;
    vat_rate: string;
    is_stocked: boolean;
    stock_qty: string | null;
    reserved_qty: string;
    /** Set when the figures come from the phone's copy: when it was synced (ISO). */
    stock_as_of?: string | null;
}

export type OrderStatus = 'pending' | 'approved' | 'rejected' | 'sent' | 'failed';

/** Server statuses plus "local": confirmed on the phone, not yet received by the server. */
export type DisplayStatus = OrderStatus | 'local';

/** Phone-side state of an order (resources/js/offline): synced, waiting in the outbox, or refused by the server. */
export type LocalState = 'synced' | 'queued' | 'unsent';

export interface OrderLineData {
    position: number;
    article_id: number | null;
    reference: string | null;
    designation: string;
    /** The seller's comment, added under the designation on the BL. Missing on rows mirrored by an older version. */
    note?: string | null;
    quantity: string;
    unit_price_ht: string;
    vat_rate: string;
    is_free: boolean;
    total_ht: string;
    /** Current stock of the article (null when the article is gone). */
    stock: { is_stocked: boolean; stock_qty: string | null; reserved_qty: string } | null;
    /** Today's catalogue price of the article (null when the article is gone). */
    catalog_unit_price_ht: string | null;
}

export interface OrderSummary {
    uuid: string;
    number: string;
    status: OrderStatus;
    /** What this viewer should see: a failed push shows as "approved" to its author. */
    display_status: DisplayStatus;
    status_label: string;
    author: { id: number; name: string };
    can_approve: boolean;
    created_at: string;
    client: { id: number; company_name: string; town: string | null };
    total_quantity: string;
    total_ttc: string;
    evoliz_delivery_number: string | null;
    /** File mode: when it was exported. Missing on rows mirrored by an older version. */
    exported_at?: string | null;
    can_edit: boolean;
    /** Pending or rejected, by its author; rejected, by an admin. Missing on rows mirrored by an older version: fall back to can_edit. */
    can_delete?: boolean;
    /** Admin list only. */
    stock_conflicts?: StockConflict[];
    /** Phone-only orders listed in Mes commandes. */
    local_state?: LocalState;
    local_error?: string | null;
}

export interface ActivityEntry {
    action: string;
    label: string;
    user: string | null;
    created_at: string;
    detail: string | null;
}

export interface OrderDetail {
    uuid: string;
    /** Empty for an order that only exists on the phone. */
    number: string;
    status: DisplayStatus;
    display_status: DisplayStatus;
    status_label: string;
    created_at: string;
    updated_at: string;
    approved_at: string | null;
    approved_by: string | null;
    sent_at: string | null;
    /** File mode: when it was exported. Missing on rows mirrored by an older version. */
    exported_at?: string | null;
    exported_by?: string | null;
    author: { id: number; name: string };
    client: ClientData;
    /** The client correction saved with this order, if any. */
    client_change?: ClientChangeView | null;
    paytype: Paytype | null;
    comment: string | null;
    lines: OrderLineData[];
    totals: {
        quantity: string;
        ht: string;
        vat: string;
        ttc: string;
        vat_by_rate: { rate: string; base_ht: string; vat: string }[];
    };
    rejected_reason: string | null;
    evoliz_delivery_number: string | null;
    can_edit: boolean;
    /** Pending or rejected, by its author; rejected, by an admin. Missing on rows mirrored by an older version: fall back to can_edit. */
    can_delete?: boolean;
    /** Admin only (null for sales people). */
    evoliz_error: string | null;
    /** Admin only: the common push errors in plain French; the raw text stays in evoliz_error. */
    evoliz_error_summary?: string | null;
    evoliz_warning: string | null;
    activity: ActivityEntry[] | null;
    can_approve: boolean;
    can_reject: boolean;
    can_retry: boolean;
    /** File mode, admins: the export can be undone. */
    can_unmark_exported?: boolean;
    /** Admins, Evoliz back: an order approved during a lapse and not exported can be marked exported again. */
    can_mark_exported?: boolean;
    /** Admins, standalone: a failed order Evoliz may hold, settled for the export on the admin's word. */
    can_settle_checked?: boolean;
    /** Admins, Evoliz mode: a push to Evoliz is under way; absent in a phone copy older than this field (then nothing is shown). */
    push_pending?: boolean;
    /** Evoliz mode, admins: approved while Evoliz was unavailable, not exported; sent on the admin's word. */
    can_send_to_evoliz?: boolean;
    /** Admins: the last time the order was in a downloaded export file; "Envoyer à Evoliz" then asks for a confirmation. */
    export_downloaded_at?: string | null;
    /** Admin only: articles this order would take below zero. */
    stock_conflicts?: StockConflict[] | null;
    /** Admin only, while pending: references of articles added from an order, completed designation not seen yet. */
    articles_without_numbers?: string[];
    /** Admin only, null while SMS are off: the client's SMS (docs/API.md). */
    sms?: OrderSms | null;
    /** Offline mirror only. */
    deleted_at?: string | null;
    local_state?: LocalState;
    local_error?: string | null;
    possible_duplicate?: ClientData | null;
}

export interface OrderSms {
    /** The client's French mobile ("06 12 34 56 78"), null when there is none. */
    recipient: string | null;
    status: 'pending' | 'sent' | 'failed' | 'skipped' | null;
    phone: string | null;
    error: string | null;
    sent_at: string | null;
    attempted_at: string | null;
    can_resend: boolean;
}

export interface StockConflict {
    article_id: number;
    reference: string;
    requested: string;
    available_before: string;
    shortfall: string;
    stock_qty: string;
    reserved_before: string;
    earlier_orders: string[];
}

export interface RecentOrder {
    /** The order's uuid, or "bl:{id}" for a BL typed in Evoliz. */
    uuid: string;
    number: string;
    status: OrderStatus;
    created_at: string;
    author: string;
    total_ttc: string;
    lines: (OrderLineData & { available: boolean })[];
    /** Sales history. */
    delivery_number?: string | null;
    history_status?: HistoryStatus;
}

/** Sales history (docs/API.md "Sales history"). */
export type HistoryStatus = 'pending' | 'delivered' | 'invoiced' | 'rejected';

export interface HistoryLine {
    article_id: number | null;
    reference: string | null;
    designation: string;
    quantity: string;
    unit_price_ht: string;
    vat_rate: string | null;
    is_free: boolean;
    total_ht: string;
}

export interface HistoryEntry {
    key: string;
    date: string;
    delivery_number: string | null;
    order_number: string | null;
    order_uuid: string | null;
    status: HistoryStatus;
    seller: string;
    comment: string | null;
    lines: HistoryLine[];
    totals: { ht: string; vat: string; ttc: string };
}

/** A mirrored Evoliz BL (GET /api/deliveries). */
export interface DeliveryRow {
    id: number;
    document_number: string;
    client_id: number | null;
    client_name: string | null;
    order_uuid: string | null;
    order_number: string | null;
    date: string;
    invoiced: boolean;
    seller_id: number | null;
    seller: string;
    comment: string | null;
    total_ht: string;
    total_vat: string;
    total_ttc: string;
    deleted_at: string | null;
    updated_at: string;
}

/** A line of a mirrored BL (GET /api/delivery-lines). */
export interface DeliveryLineRow extends HistoryLine {
    id: number;
    delivery_id: number;
    position: number;
    updated_at: string;
}

/** The installation's optional client field: its label, and whether the client search reads it. */
export interface ClientExtraField {
    label: string;
    searchable: boolean;
}
