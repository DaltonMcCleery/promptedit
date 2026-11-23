/**
 * Money arrives from the server as integer nanodollars (1 USD = 1,000,000,000).
 * These helpers format it without floating point.
 */

const NANOS_PER_DOLLAR = 1_000_000_000n;

/**
 * Round half up to the given number of decimals, e.g. 70_687_500 → "$0.0707".
 */
export function formatUsd(nanos: number | bigint, decimals = 4): string {
    const value = BigInt(nanos);
    const sign = value < 0n ? '-' : '';
    const absolute = value < 0n ? -value : value;
    const unit = 10n ** BigInt(9 - decimals);
    const rounded = (absolute + unit / 2n) / unit;
    const scale = 10n ** BigInt(decimals);
    const whole = rounded / scale;
    const fraction = (rounded % scale).toString().padStart(decimals, '0');

    return decimals === 0 ? `${sign}$${whole}` : `${sign}$${whole}.${fraction}`;
}

/**
 * The exact amount with all 9 decimals, e.g. 70_687_500 → "$0.070687500".
 */
export function exactUsd(nanos: number | bigint): string {
    const value = BigInt(nanos);
    const sign = value < 0n ? '-' : '';
    const absolute = value < 0n ? -value : value;
    const whole = absolute / NANOS_PER_DOLLAR;
    const fraction = (absolute % NANOS_PER_DOLLAR).toString().padStart(9, '0');

    return `${sign}$${whole}.${fraction}`;
}

/**
 * For display: "$0.00" for nothing, "< $0.0001" for an amount that would
 * round to zero, otherwise 4 decimals. The exact amount belongs in a tooltip.
 */
export function displayUsd(nanos: number | bigint): string {
    const value = BigInt(nanos);

    if (value === 0n) {
        return '$0.00';
    }

    const formatted = formatUsd(value, 4);

    return formatted === '$0.0000' ? '< $0.0001' : formatted;
}
