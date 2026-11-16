/**
 * Minimal client for the same-origin JSON API (docs/API.md). Laravel's session
 * cookie authenticates; the XSRF-TOKEN cookie is echoed back for CSRF.
 */
export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
    }

    /** First message of every field, for a simple error list. */
    get messages(): string[] {
        const list = Object.values(this.errors).map((messages) => messages[0]);
        return list.length > 0 ? list : [this.message];
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
    // A FormData (file upload) sets its own Content-Type, with the boundary.
    const isForm = body instanceof FormData;
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(isForm ? {} : { 'Content-Type': 'application/json' }),
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: isForm ? body : body === undefined ? undefined : JSON.stringify(body),
    });

    // Bounced to the login page (deactivated account): the session is gone.
    if (response.redirected && new URL(response.url).pathname === '/login') {
        throw new ApiError(401, 'Unauthenticated.');
    }

    if (response.status === 204) {
        return undefined as T;
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new ApiError(response.status, data.message ?? `HTTP ${response.status}`, data.errors ?? {});
    }

    return data as T;
}

type Query = Record<string, string | number | undefined | null>;

export function apiGet<T>(url: string, query: Query = {}): Promise<T> {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query)) {
        if (value !== undefined && value !== null && value !== '') params.set(key, String(value));
    }
    const qs = params.toString();

    return request<T>('GET', qs ? `${url}?${qs}` : url);
}

export const apiPut = <T>(url: string, body: unknown): Promise<T> => request<T>('PUT', url, body);
export const apiPost = <T>(url: string, body: unknown = {}): Promise<T> => request<T>('POST', url, body);
export const apiDelete = (url: string): Promise<void> => request<void>('DELETE', url);
