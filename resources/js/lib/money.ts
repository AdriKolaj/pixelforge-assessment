/**
 * Formats an amount of nanodollars (1e-9 USD). Integer maths only, so the
 * exact form (decimals = 9) matches the backend and Pixelforge to the digit.
 */
export function formatUsd(nano: number, decimals = 4): string {
    const negative = nano < 0;
    const unit = 10n ** BigInt(9 - decimals);
    // Round half up, away from zero.
    const rounded = (BigInt(Math.abs(nano)) + unit / 2n) / unit;
    const scale = 10n ** BigInt(decimals);

    const whole = rounded / scale;
    const fraction = (rounded % scale).toString().padStart(decimals, '0');
    const sign = negative && rounded > 0n ? '-' : '';

    return decimals > 0 ? `${sign}$${whole}.${fraction}` : `${sign}$${whole}`;
}

export function exactUsd(nano: number): string {
    return formatUsd(nano, 9);
}
