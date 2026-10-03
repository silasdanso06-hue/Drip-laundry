export const SERVICES = {
    fold: 'Wash & fold'
};

export function priceCart(cart, catalog) {
    if (!Array.isArray(cart) || cart.length > catalog.length * Object.keys(SERVICES).length) {
        throw new Error('The cart is not valid. Please start a new order.');
    }

    const seen = new Set();
    return cart.map(line => {
        if (!line || typeof line !== 'object') throw new Error('The cart is not valid. Please start a new order.');
        const item = catalog.find(product => product.id === line.id);
        const key = `${line.id}:${line.service}`;

        if (!item || !Object.hasOwn(SERVICES, line.service) || !Number.isFinite(item[line.service]) || item[line.service] < 0) {
            throw new Error('This service needs a quote. Please contact us.');
        }

        if (!Number.isInteger(line.quantity) || line.quantity < 1 || line.quantity > 99 || seen.has(key)) {
            throw new Error('Choose a quantity from 1 to 99 for each item.');
        }

        seen.add(key);
        return {
            id: item.id,
            name: item.name,
            category: item.category,
            unit: item.unit === 'load' ? 'load' : 'item',
            service: line.service,
            serviceName: SERVICES[line.service],
            quantity: line.quantity,
            unitPrice: item[line.service],
            total: Math.round(item[line.service] * 100) * line.quantity / 100
        };
    });
}

export function addItem(cart, id, service, catalog) {
    const next = cart.map(line => ({ ...line }));
    const existing = next.find(line => line.id === id && line.service === service);

    if (existing) {
        existing.quantity += 1;
    } else {
        next.push({ id, service, quantity: 1 });
    }

    priceCart(next, catalog);
    return next;
}

export function cartSummary(lines, policy = { tiers: [] }) {
    const subtotalMinor = lines.reduce((total, line) => total + Math.round(line.total * 100), 0);
    let discountRate = 0;
    for (const tier of policy.tiers) {
        if (subtotalMinor >= tier.minimumMinor) discountRate = Math.max(discountRate, tier.ratePercent);
    }
    const discountMinor = Math.floor((subtotalMinor * discountRate + 50) / 100);
    return { subtotal: subtotalMinor / 100, discountRate, discountAmount: discountMinor / 100, total: (subtotalMinor - discountMinor) / 100 };
}

export function cartTotal(lines, policy) {
    return cartSummary(lines, policy).total;
}
