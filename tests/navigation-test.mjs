import fs from 'node:fs/promises';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';

// A DOM test double exercises the shipped script against the real HTML structure.
// It tests navigation and checkout logic, not browser layout or visual rendering.
class Element {
    constructor(tag, attributes = {}) {
        this.tagName = tag;
        this.attributes = { ...attributes };
        this.children = [];
        this.parentNode = null;
        this.style = {};
        this.listeners = {};
        this.value = attributes.value || '';
        this.checked = Object.hasOwn(attributes, 'checked');
        this.hidden = Object.hasOwn(attributes, 'hidden');
        this.disabled = false;
        this.dataset = Object.fromEntries(Object.entries(attributes)
            .filter(([key]) => key.startsWith('data-'))
            .map(([key, value]) => [key.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase()), value]));
        this.classList = {
            add: name => { this.className = [...new Set([...this.className.split(' '), name])].join(' ').trim(); },
            remove: name => { this.className = this.className.split(' ').filter(value => value !== name).join(' '); }
        };
    }
    get className() { return this.attributes.class || ''; }
    set className(value) { this.attributes.class = value; }
    get href() { return this.attributes.href || ''; }
    set href(value) { this.attributes.href = value; }
    get hash() { return this.href.includes('#') ? '#' + this.href.split('#')[1] : ''; }
    setAttribute(key, value) {
        this.attributes[key] = String(value);
        if (key.startsWith('data-')) this.dataset[key.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())] = String(value);
    }
    removeAttribute(key) { delete this.attributes[key]; }
    addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
    append(...nodes) { nodes.forEach(node => { node.parentNode = this; this.children.push(node); }); }
    replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
    remove() { this.parentNode.children = this.parentNode.children.filter(child => child !== this); }
    focus() { this.focused = true; }
    scrollIntoView() {}
    play() { return Promise.resolve(); }
    pause() {}
    querySelectorAll(selector) { return findAll(this, selector); }
    closest(selector) {
        for (let node = this; node; node = node.parentNode) if (matches(node, selector)) return node;
        return null;
    }
}

function matches(node, selector) {
    const tag = selector.match(/^[a-z][a-z0-9-]*/i)?.[0];
    if (tag && node.tagName !== tag) return false;
    const id = selector.match(/#([\w-]+)/)?.[1];
    if (id && node.attributes.id !== id) return false;
    const className = selector.match(/\.([\w-]+)/)?.[1];
    if (className && !node.className.split(' ').includes(className)) return false;
    if (selector.endsWith(':checked') && !node.checked) return false;
    for (const [, key, operator, value] of selector.matchAll(/\[([\w-]+)(?:(\^?=)"([^"]*)")?\]/g)) {
        if (!Object.hasOwn(node.attributes, key)) return false;
        if (operator === '=' && node.attributes[key] !== value) return false;
        if (operator === '^=' && !node.attributes[key].startsWith(value)) return false;
    }
    return true;
}

function descendants(root) {
    return root.children.flatMap(child => [child, ...descendants(child)]);
}

function findAll(root, selector) {
    const alternatives = selector.split(',').map(value => value.trim().split(/\s+/));
    return descendants(root).filter(node => alternatives.some(parts => {
        if (!matches(node, parts.at(-1))) return false;
        let ancestor = node.parentNode;
        for (let index = parts.length - 2; index >= 0; index--) {
            while (ancestor && !matches(ancestor, parts[index])) ancestor = ancestor.parentNode;
            if (!ancestor) return false;
            ancestor = ancestor.parentNode;
        }
        return true;
    }));
}

function hydrate(data) {
    const node = new Element(data.tag, data.attributes);
    node.append(...data.children.map(hydrate));
    return node;
}

const rootUrl = new URL('../', import.meta.url);
const tree = JSON.parse(await fs.readFile(new URL('tmp/site-dom.json', rootUrl), 'utf8'));
const script = await fs.readFile(new URL('assets/js/app.js', rootUrl), 'utf8');
const liveCatalog = JSON.parse(await fs.readFile(new URL('assets/data/catalog.json', rootUrl), 'utf8'));
const liveDiscountPolicy = JSON.parse(await fs.readFile(new URL('assets/data/discount-policy.json', rootUrl), 'utf8'));

async function exercise(protocol, signedIn = false, mode = 'live', itemId = 'item-1-2', edited = false, storageBlocked = false, retiredCart = false) {
    const servedCatalog = liveCatalog.map(item => item.id === itemId && edited ? { ...item, name: 'Cotton & linen <shirt>', category: 'Everyday care', fold: 12.5 } : { ...item });
    const document = new Element('document');
    document.append(hydrate(tree));
    document.querySelectorAll = selector => findAll(document, selector);
    document.querySelector = selector => document.querySelectorAll(selector)[0] || null;
    document.createElement = tag => new Element(tag);
    document.body = document.querySelector('body');
    const local = new Map();
    if (retiredCart) local.set('dripcleanCart', JSON.stringify([
        { id: itemId, service: 'iron', quantity: 1 },
        { id: itemId, service: 'fold', quantity: 1 }
    ]));
    const location = { hash: '', protocol, assign: url => { location.redirect = url; } };
    const history = { pushState: (_, __, hash) => { location.hash = hash; } };
    const window = { location, matchMedia: () => ({ matches: true, addEventListener() {} }), addEventListener() {}, scrollTo() {} };
    let savedOrders = {}; let submittedAccount = null; let savedCount = 0;
    const user = signedIn ? { id: 'customer-test', name: 'Test customer', email: 'test@example.invalid' } : null;
    const context = vm.createContext({
        document, window, history, location, AbortController,
        HTMLFormElement: { prototype: { submit() { submittedAccount = { action: document.querySelector('#accountAction').value, password: document.querySelector('#accountPassword').value, csrf: document.querySelector('#accountCsrf').value }; } } },
        crypto: webcrypto,
        localStorage: {
            getItem: key => { if (storageBlocked) throw new Error('Storage blocked'); return local.get(key) ?? null; },
            setItem: (key, value) => { if (storageBlocked) throw new Error('Storage blocked'); local.set(key, value); },
            removeItem: key => { if (storageBlocked) throw new Error('Storage blocked'); local.delete(key); }
        },
        setTimeout: () => 1, clearTimeout() {},
        fetch: async (url, options = {}) => {
            let data;
            if (url === 'customer.php') data = { user, csrf: 'test-csrf', needsFirstAccount: !signedIn };
            else if (url === 'payment-config.php') data = { enabled: true, mode };
            else if (url === 'catalog.php') data = { catalog: servedCatalog, version: 'test', discountPolicy: liveDiscountPolicy };
            else if (url === 'orders.php') {
                if (options.method === 'POST') { const input = JSON.parse(options.body); assert.equal(input.csrf, 'test-csrf'); savedOrders[input.order.id] = input.order; savedCount++; }
                data = { orders: savedOrders };
            } else throw new Error('Unexpected request: ' + url);
            return { ok: true, json: async () => data };
        }
    });
    await new vm.Script(script).runInContext(context);
    if (retiredCart) {
        assert.equal(document.querySelector('#cartCount').textContent, 1, 'Restore must keep available items and remove retired services.');
        assert.deepEqual(JSON.parse(local.get('dripcleanCart')), [{ id: itemId, service: 'fold', quantity: 1 }]);
    }
    function navigate(route) {
        const link = document.querySelectorAll('.nav a').find(node => node.hash === '#' + route);
        assert.ok(link, 'Navigation link exists: ' + route);
        let prevented = false;
        document.listeners.click[0]({ target: link, preventDefault: () => { prevented = true; } });
        assert.equal(prevented, true);
        const visible = document.querySelectorAll('[data-view]').filter(node => !node.hidden);
        assert.ok(visible.length > 0 && visible.every(node => node.dataset.view === route));
    }
    for (const route of ['services', 'pricing', 'process', 'dashboard', 'cart', 'home']) navigate(route);
    if (protocol === 'http:' && !signedIn) {
        assert.equal(document.querySelector('#accountTitle').textContent, 'Create your account');
        assert.equal(document.querySelector('#signupName').hidden, false);
        document.querySelector('#accountName').value = 'New customer';
        document.querySelector('#accountEmail').value = 'new@example.invalid';
        document.querySelector('#accountPassword').value = 'My chosen long password';
        document.querySelector('#accountConfirm').value = 'My chosen long password';
        await document.querySelector('#accountForm').onsubmit({ preventDefault() {} });
        assert.equal(submittedAccount.action, 'signup');
        assert.equal(submittedAccount.password, 'My chosen long password');
        assert.equal(submittedAccount.csrf, 'test-csrf');
    }
    navigate('pricing');
    const priceRows = document.querySelectorAll('[data-price-item]');
    assert.deepEqual(priceRows.slice(0, 3).map(row => row.dataset.priceItem), ['load-6kg', 'load-7kg', 'load-8kg'], 'Weight packages must appear before the individual-item tables.');
    assert.equal(document.querySelectorAll('#weightPrices').length, 1, 'Weight prices should appear once.');
    assert.equal(priceRows.length, liveCatalog.length, 'Every saved item should render exactly once.');
    if (edited) {
        const row = priceRows.find(row => row.dataset.priceItem === itemId);
        assert.equal(row.querySelectorAll('th')[0].textContent, 'Cotton & linen <shirt>', 'Saved item name must replace the static HTML name as plain text.');
        assert.equal(row.closest('table').querySelectorAll('caption')[0].textContent, itemId.startsWith('load-') ? 'Everyday care · Wash & fold' : 'Everyday care', 'Saved category must regroup the customer table.');
        assert.equal(row.querySelectorAll('shirt').length, 0, 'Item names must not be inserted as HTML.');
    }
    const add = document.querySelectorAll('.add-price').find(node => node.dataset.item === itemId && node.dataset.service === 'fold');
    assert.ok(add, 'Catalogue item has a working add button: ' + itemId);
    const price = servedCatalog.find(item => item.id === itemId).fold;
    const quantity = signedIn ? Math.min(99, Math.max(2, Math.ceil(200 / price))) : 2;
    for (let i = retiredCart ? 1 : 0; i < quantity; i++) add.onclick();
    assert.equal(document.querySelector('#cartCount').textContent, quantity);
    const subtotalMinor = Math.round(price * 100) * quantity;
    const discountRate = subtotalMinor >= 20000 ? 10 : (subtotalMinor > 10000 ? 5 : 0);
    const discountMinor = Math.round(subtotalMinor * discountRate / 100);
    const total = (subtotalMinor - discountMinor) / 100;
    assert.ok(document.querySelector('#cartTotal').textContent.endsWith(total.toFixed(2)));
    assert.equal(document.querySelector('#cartDiscountRow').hidden, discountMinor === 0);
    assert.ok(document.querySelector('#cartSubtotal').textContent.endsWith((subtotalMinor / 100).toFixed(2)));
    navigate('cart');
    const method = signedIn ? 'Online payment' : 'Mobile Money';
    for (const input of document.querySelectorAll('input[name="paymentMethod"]')) input.checked = input.value === method;
    const selected = document.querySelector('input[name="paymentMethod"]:checked'); selected.listeners.change[0]();
    document.querySelector('#name').value = 'Navigation Test';
    document.querySelector('#phone').value = '0200000000';
    document.querySelector('#location').value = 'Test address';
    document.querySelector('#date').value = document.querySelector('#date').min;
    await document.querySelector('#booking').onsubmit({ preventDefault() {} });
    if (signedIn) {
        assert.equal(document.querySelector('#onlinePaymentChoice').hidden, false);
        assert.equal(savedCount, 1);
        const order = Object.values(savedOrders)[0];
        assert.equal(order.total, total);
        assert.equal(order.discountRate, discountRate);
        assert.equal(order.discountAmount, discountMinor / 100);
        assert.equal(order.paymentMethod, 'Online payment');
        assert.equal(order.paymentStatus, 'Unpaid');
        assert.ok(location.redirect.startsWith('payment.php?order=DC-'));
        assert.equal(document.querySelector('#cartCount').textContent, 0);
        assert.ok(findAll(document.querySelector('#savedOrders'), 'a').some(a => a.textContent === (mode === 'test' ? 'Test online payment' : 'Pay online')));
    } else {
        assert.equal(savedCount, 0, 'A guest cannot save or pay for an order.');
        assert.equal(document.querySelector('#cartCount').textContent, 2, 'Signing in must keep the cart.');
    }
    history.pushState = () => { throw new Error('History API restricted'); };
    navigate('home');
    return `${protocol} / ${signedIn ? 'customer' : 'guest'} / ${mode} / ${itemId}${edited ? ' edited' : ''}: navigation, item details and checkout passed`;
}

export const results = [await exercise('file:'), await exercise('http:'), await exercise('http:', true), await exercise('http:', true, 'test'), await exercise('http:', true, 'live', 'load-6kg'), await exercise('http:', true, 'live', 'load-7kg'), await exercise('http:', true, 'live', 'load-8kg'), await exercise('http:', true, 'live', 'item-1-2', true), await exercise('http:', true, 'live', 'load-6kg', true)];
results.push(await exercise('http:', true, 'live', 'item-1-2', false, true));
results.push(await exercise('http:', true, 'live', 'item-1-2', false, false, true));
