/*
 * Display helpers for the decimal strings the server sends. These are for DISPLAY ONLY:
 * never use their output, or Number(value), for arithmetic or decisions. All money math
 * happens on the server with bcmath.
 *
 * Intl.NumberFormat accepts a numeric string and formats it exactly (no float in between),
 * so "0.00000001" or "12345678901234.12345678" are shown as sent.
 */

const LOCALE = 'en-US';

// The server only ever sends strings like "-12.50000000", which match this template type.
function asNumeric(value: string): Intl.StringNumericLiteral {
    return value as Intl.StringNumericLiteral;
}

/** A money amount with thousands separators, rounded for display, e.g. "65,000.00". */
export function formatAmount(value: string, maxFractionDigits = 2): string {
    return new Intl.NumberFormat(LOCALE, {
        minimumFractionDigits: Math.min(2, maxFractionDigits),
        maximumFractionDigits: maxFractionDigits,
    }).format(asNumeric(value));
}

/** A quantity with trailing zeros removed and up to 8 decimals, e.g. "0.5" or "1,200". */
export function formatQuantity(value: string): string {
    return new Intl.NumberFormat(LOCALE, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 8,
    }).format(asNumeric(value));
}

/** An amount with an explicit sign for gains and losses, e.g. "+120.00", "-35.10" or "0.00". */
export function formatSigned(value: string, maxFractionDigits = 2): string {
    return new Intl.NumberFormat(LOCALE, {
        minimumFractionDigits: Math.min(2, maxFractionDigits),
        maximumFractionDigits: maxFractionDigits,
        signDisplay: 'exceptZero',
    }).format(asNumeric(value));
}

/**
 * Converts a datetime-local input value ("2026-10-25T14:00", no timezone) to an ISO timestamp
 * in UTC. Without this the server would read local time as UTC, which east of UTC looks like
 * a date in the future. An empty value stays empty, meaning "now" on the server.
 */
export function localInputToIso(value: string): string {
    return value === '' ? '' : new Date(value).toISOString();
}

/** An ISO 8601 timestamp from the server as a short local date and time, e.g. "Oct 25, 2026, 2:05 PM". */
export function formatDateTime(iso: string): string {
    return new Intl.DateTimeFormat(LOCALE, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}

/** True for "0", "0.00000000" or "-0.00000000". A plain string check, no parseFloat. */
export function isZero(value: string): boolean {
    return !/[1-9]/.test(value);
}

/**
 * True for a negative decimal string. A plain string check, no parseFloat.
 * "-0.00000000" is not negative.
 */
export function isNegative(value: string): boolean {
    return value.startsWith('-') && !isZero(value);
}
