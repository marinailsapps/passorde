const TIME_ZONE = 'Europe/Paris';

const money = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
const number = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 });
const dateTime = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short', timeZone: TIME_ZONE });
const date = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeZone: TIME_ZONE });

export function formatMoney(value: string | number | null): string {
    return value === null ? '—' : money.format(Number(value));
}

export function formatNumber(value: string | number | null): string {
    return value === null ? '—' : number.format(Number(value));
}

export function formatDateTime(value: string | null): string {
    return value === null ? '—' : dateTime.format(new Date(value));
}

export function formatDate(value: string | null): string {
    return value === null ? '—' : date.format(new Date(value));
}

const month = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric', timeZone: 'UTC' });
const shortDay = new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' });

/** A calendar date ("2026-09-02", no time zone) as "02/09/2026". */
export function formatDay(value: string | null): string {
    return value === null ? '—' : shortDay.format(new Date(`${value.slice(0, 10)}T12:00:00Z`));
}

/** A month ("2026-09" or a date in it) as "septembre 2026". */
export function formatMonth(value: string): string {
    const text = month.format(new Date(`${value.slice(0, 7)}-15T12:00:00Z`));
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/** "il y a 5 min" style age of a timestamp. */
export function formatAge(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const rtf = new Intl.RelativeTimeFormat('fr-FR', { numeric: 'auto' });
    const seconds = Math.round((new Date(value).getTime() - Date.now()) / 1000);
    const abs = Math.abs(seconds);

    if (abs < 60) return rtf.format(seconds, 'second');
    if (abs < 3600) return rtf.format(Math.round(seconds / 60), 'minute');
    if (abs < 86400) return rtf.format(Math.round(seconds / 3600), 'hour');

    return rtf.format(Math.round(seconds / 86400), 'day');
}
