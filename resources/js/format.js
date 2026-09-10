const priceFormatter = new Intl.NumberFormat('en-GB', {
    style: 'currency',
    currency: 'GBP',
    maximumFractionDigits: 0,
});

export function formatPrice(price) {
    return priceFormatter.format(price);
}

export function formatDate(iso) {
    if (!iso) {
        return null;
    }

    return new Intl.DateTimeFormat('en-GB', { dateStyle: 'long' }).format(new Date(iso));
}

// Digits-only string in, comma-grouped string out — for displaying a number
// input's value once it isn't being actively typed into.
export function formatNumberInput(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return Number(value).toLocaleString('en-GB');
}

// Strips everything but digits, so pasted or formatted input ("£1,200,000")
// still reduces to a plain numeric string for the form payload.
export function parseNumberInput(value) {
    return String(value).replace(/\D/g, '');
}
