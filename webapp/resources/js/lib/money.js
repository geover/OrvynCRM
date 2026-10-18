// Money in the visitor's own number format ("$10.00", "10,00 EUR"...). Falls back to plain text for an unknown currency code.
export function fmtMoney(amount, currency = 'USD') {
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount);
    } catch {
        return `${Number(amount).toFixed(2)} ${currency}`;
    }
}

// the smallest unit of a currency: 100 to the dollar, but the yen has none
export const minorToMajor = (amount, currency = 'USD') => (amount ?? 0) / (['JPY', 'KRW', 'VND', 'CLP'].includes(String(currency).toUpperCase()) ? 1 : 100);
export const periodLabel = (period) => (period === 'year' ? 'year' : 'month');
