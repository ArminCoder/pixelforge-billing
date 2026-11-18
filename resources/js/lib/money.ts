// The server sends money as whole nanodollars (1 USD = 1,000,000,000).
// The browser never calculates prices. It only turns them into text.

const NANOS_PER_USD = 1_000_000_000;

/**
 * 373875000 -> "$0.373875"
 * Shows the exact amount: trailing zeros are dropped, but at least 2 decimals stay.
 */
export function formatUsd(nanos: number): string {
    const sign = nanos < 0 ? '-' : '';
    const absolute = Math.abs(nanos);

    const dollars = Math.floor(absolute / NANOS_PER_USD);
    const fraction = String(absolute % NANOS_PER_USD)
        .padStart(9, '0')
        .replace(/0+$/, '')
        .padEnd(2, '0');

    return `${sign}$${dollars}.${fraction}`;
}
