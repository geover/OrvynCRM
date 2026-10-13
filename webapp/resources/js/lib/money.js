// Money in the visitor's own number format ("$10.00", "10,00 EUR"...). Falls back to plain text for an unknown currency code.
export function fmtMoney(amount, currency = 'USD') {
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount);
    } catch {
        return `${Number(amount).toFixed(2)} ${currency}`;
    }
}

export const periodLabel = (period) => (period === 'year' ? 'year' : 'month');
