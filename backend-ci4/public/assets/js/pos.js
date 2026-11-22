/* public/assets/js/pos.js
 * The Point of Sale tab (app/Views/partials/_pos_tab.php), Owner and Staff: category chips,
 * search by item name or ID with a count line, the item cards and size picker, and the cart
 * (typeable quantity, discounts, payment panels and quick cash buttons). Checkout itself is
 * the form's normal submit; the server re-checks stock and recomputes the prices
 * (SalesController::checkout, App\Libraries\PosPricing).
 */
if (document.getElementById('posGrid')) {
    const cart = {};
    // Item data (names, sizes, IDs) goes into HTML only through these, so it shows as plain text.
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const jsArg = v => esc(JSON.stringify(String(v))); // a string argument inside an onclick="..." attribute
    const chips = document.querySelectorAll('.pos-chips button');
    const search = document.getElementById('posSearch');

    chips.forEach(btn => {
        btn.addEventListener('click', () => {
            chips.forEach(b => { b.classList.toggle('active', b === btn); b.setAttribute('aria-pressed', b === btn); });
            filterCards();
        });
    });
    search.addEventListener('input', filterCards);

    // Same rule as Inventory (ProductModel::matchesSearch): the name or an item ID contains the
    // term, or both are numbers that are equal without leading zeros ("290" finds "0290").
    function cardMatches(card, term) {
        if (!term) return true;
        if (card.dataset.name.includes(term)) return true;
        return (card.dataset.ids || '').split(' ').some(id => {
            const lid = id.toLowerCase();
            return lid.includes(term) || (/^\d+$/.test(term) && /^\d+$/.test(lid) && term.replace(/^0+/, '') === lid.replace(/^0+/, ''));
        });
    }

    function filterCards() {
        const cat = document.querySelector('.pos-chips button.active').dataset.cat;
        const raw = search.value.trim();
        const term = raw.toLowerCase();
        let shown = 0;
        document.querySelectorAll('.pos-card').forEach(card => {
            const visible = (!cat || card.dataset.cat === cat) && cardMatches(card, term);
            card.style.display = visible ? '' : 'none';
            shown += visible;
        });
        const where = cat ? ` in ${cat}` : '';
        const text = shown === 0
            ? (raw ? `No items match "${raw}"${where}.` : `No items${where}.`)
            : `${shown} item${shown === 1 ? '' : 's'}${where}${raw ? ` matching "${raw}"` : ''}`;
        document.getElementById('posCount').textContent = text;
        const none = document.getElementById('posNone');
        none.hidden = shown > 0;
        none.textContent = shown === 0 ? text : '';
    }

    // A card with one variant (most Patches/Metal/Equipment/Accessories
    // items, or a Clothes/Shoes item that only has one size entered so far)
    // adds straight to the cart like before. A card with multiple variants
    // (sizes) opens a picker instead, so staff pick exactly which size the
    // customer wants — each size is its own real stock-tracked product row.
    function addVariantToCart(v) {
        const id = String(v.item_id);
        if (!cart[id]) {
            cart[id] = { name: v.item_name, size: v.size || '', price: v.selling_price, stock: v.current_stock, qty: 0 };
        }
        cart[id].capped = false;
        if (cart[id].qty < cart[id].stock) { cart[id].qty++; } else { window.posStockLimit(cart[id]); }
    }

    function openSizePicker(itemName, variants) {
        document.getElementById('sizePickerTitle').textContent = 'Choose Size — ' + itemName;
        const list = document.getElementById('sizePickerList');
        list.innerHTML = variants.map(v => {
            const inCart = cart[String(v.item_id)] ? cart[String(v.item_id)].qty : 0;
            const remaining = v.current_stock - inCart;
            return `<button type="button" class="size-pick-btn" data-item-id="${esc(v.item_id)}" ${remaining <= 0 ? 'disabled' : ''}>
                <span class="sz">${esc(v.size || '—')}<span class="sz-id">${esc(v.item_id)}</span></span>
                <span class="st">${remaining > 0 ? esc(remaining) + ' in stock' : 'Out of stock'}</span>
                <span class="pr">₱${Number(v.selling_price).toFixed(0)}</span>
            </button>`;
        }).join('');
        list.querySelectorAll('.size-pick-btn:not([disabled])').forEach(btn => {
            btn.addEventListener('click', () => {
                const v = variants.find(x => String(x.item_id) === btn.dataset.itemId);
                addVariantToCart(v);
                renderCart();
                document.getElementById('sizePickerModal').classList.remove('show');
            });
        });
        document.getElementById('sizePickerModal').classList.add('show');
    }

    document.getElementById('closeSizePicker').addEventListener('click', () => {
        document.getElementById('sizePickerModal').classList.remove('show');
    });

    document.querySelectorAll('.pos-card:not(.disabled)').forEach(card => {
        card.addEventListener('click', () => {
            if (card.classList.contains('disabled')) return;
            const variants = JSON.parse(card.dataset.variants || '[]');
            if (variants.length === 0) return;
            if (variants.length === 1) {
                addVariantToCart(variants[0]);
                card.classList.add('selected');
                renderCart();
            } else {
                openSizePicker(card.dataset.itemName, variants);
            }
        });
    });

    // Mirrors App\Libraries\PosPricing::priceCart() for instant on-screen
    // feedback; the server recomputes this authoritatively at checkout.
    const BULK_QTY_THRESHOLD = 10;
    const BULK_DISCOUNT_RATE = 0.05;
    const SET_DISCOUNT_RATE = 0.02;
    const UPPER_WORDS = ['upper', 'top', 'shirt', 'blouse', 'jacket', 'polo'];
    const LOWER_WORDS = ['lower', 'bottom', 'pants', 'trousers', 'shorts', 'skirt'];

    function splitSetName(name) {
        const words = name.trim().split(/\s+/);
        let marker = null;
        const baseWords = [];
        for (const w of words) {
            const lw = w.toLowerCase();
            if (marker === null && UPPER_WORDS.includes(lw)) { marker = 'upper'; continue; }
            if (marker === null && LOWER_WORDS.includes(lw)) { marker = 'lower'; continue; }
            baseWords.push(lw);
        }
        return [baseWords.join(' '), marker];
    }

    function priceCart(ids) {
        const groups = {};
        ids.forEach((id, i) => {
            const [base, marker] = splitSetName(cart[id].name);
            if (marker && base) {
                groups[base] = groups[base] || { upper: [], lower: [] };
                groups[base][marker].push(i);
            }
        });
        const setIndexes = {};
        Object.values(groups).forEach(g => {
            if (g.upper.length && g.lower.length) {
                [...g.upper, ...g.lower].forEach(i => setIndexes[i] = true);
            }
        });

        let subtotal = 0, discount = 0;
        const lines = ids.map((id, i) => {
            const item = cart[id];
            const lineSubtotal = Math.round(item.price * item.qty * 100) / 100;
            subtotal += lineSubtotal;
            let lineDiscount = 0, badge = null;
            if (item.qty >= BULK_QTY_THRESHOLD) {
                lineDiscount = Math.round(lineSubtotal * BULK_DISCOUNT_RATE * 100) / 100;
                badge = 'Bundle -5%';
            } else if (setIndexes[i]) {
                lineDiscount = Math.round(lineSubtotal * SET_DISCOUNT_RATE * 100) / 100;
                badge = 'Set -2%';
            }
            discount += lineDiscount;
            // Set discount is applied to the combined total of the matched
            // pair, not to either line individually, so the line still
            // shows its full price; bulk discount belongs to one line and
            // is shown subtracted from it directly.
            const lineTotal = badge === 'Set -2%' ? lineSubtotal : lineSubtotal - lineDiscount;
            return { id, item, lineSubtotal, lineDiscount, badge, lineTotal };
        });

        subtotal = Math.round(subtotal * 100) / 100;
        discount = Math.round(discount * 100) / 100;
        const total = Math.max(0, Math.round((subtotal - discount) * 100) / 100);

        return { lines, subtotal, discount, total };
    }

    // The cart is rebuilt on every change; while a quantity box is being typed in, it keeps
    // its focus and caret (rendering is ignored by its blur handler).
    let rendering = false;

    function renderCart() {
        const linesEl = document.getElementById('cartLines');
        // A line being typed in stays while its box is empty or 0 (it goes back to 1 on blur).
        const ids = Object.keys(cart).filter(id => cart[id].qty > 0 || cart[id].editing);
        cartLineCount = ids.length;
        document.getElementById('cartCount').textContent = ids.length + (ids.length === 1 ? ' item' : ' items');

        const pricing = priceCart(ids);
        const active = document.activeElement && document.activeElement.classList.contains('qty-input')
            ? { id: document.activeElement.dataset.id, caret: document.activeElement.selectionStart } : null;

        rendering = true;
        if (ids.length === 0) {
            linesEl.innerHTML = '<div class="cart-empty">Cart is empty.<br>Tap an item to add it.</div>';
        } else {
            linesEl.innerHTML = pricing.lines.map(l => {
                const item = l.item;
                const shown = item.editing && item.draft !== undefined ? item.draft : String(item.qty);
                // "Size S · ₱400 each" (no size: "₱400 each"), plus the discount this line gets.
                const sub = [item.size ? 'Size ' + esc(item.size) : '', esc(peso(item.price)) + ' each']
                    .filter(Boolean).join(' &middot; ')
                    + (l.badge === 'Bundle -5%' ? ' &middot; 5% off' : (l.badge === 'Set -2%' ? ' &middot; set 2% off' : ''));
                const label = item.name + (item.size ? ', size ' + item.size : '');
                return `<div class="cart-line">
                    <div class="info">
                        <div class="name">${esc(item.name)}</div>
                        <div class="sub">${sub}</div>
                    </div>
                    <div class="qty-ctrl">
                        <button type="button" onclick="posDec(${jsArg(l.id)})" aria-label="One less">&minus;</button>
                        <input type="text" class="qty-input" inputmode="numeric" pattern="[0-9]*" maxlength="5" autocomplete="off"
                               data-id="${esc(l.id)}" value="${esc(shown)}" aria-label="Quantity">
                        <button type="button" onclick="posInc(${jsArg(l.id)})" aria-label="One more"${item.qty >= item.stock ? ' disabled' : ''}>+</button>
                    </div>
                    <div class="line-end">
                        <div class="line-total">${esc(peso(l.lineTotal))}</div>
                        <button type="button" class="cart-remove" onclick="posRemove(${jsArg(l.id)})" aria-label="Remove ${esc(label)}" title="Remove">&#x2715;</button>
                    </div>
                    <input type="hidden" name="item_id[]" value="${esc(l.id)}">
                    <input type="hidden" name="quantity[]" value="${esc(item.qty)}">
                    ${item.capped ? `<div class="cart-line-note">Only ${esc(item.stock)} in stock, so the quantity is set to ${esc(item.stock)}.</div>` : ''}
                </div>`;
            }).join('');
        }
        if (active) {
            const input = linesEl.querySelector(`.qty-input[data-id="${CSS.escape(active.id)}"]`);
            if (input) { input.focus(); const at = Math.min(active.caret ?? input.value.length, input.value.length); input.setSelectionRange(at, at); }
        }
        rendering = false;

        // Subtotal − Discount = Total, the amounts priceCart() gives (the same as PosPricing on the server).
        document.getElementById('cartSubtotal').textContent = peso(pricing.subtotal);
        document.getElementById('discountRow').style.display = pricing.discount > 0 ? '' : 'none';
        document.getElementById('cartDiscountLabel').textContent = discountLabel(pricing.lines);
        document.getElementById('cartDiscountOut').textContent = '−' + peso(pricing.discount);
        document.getElementById('cartTotal').textContent = peso(pricing.total);
        cartTotal = pricing.total;
        if (!(window.SubmitOnce && window.SubmitOnce.isBusy(document.getElementById('cartForm')))) {
            document.getElementById('clearCart').disabled = ids.length === 0;
        }
        // An empty cart shows only the totals and the disabled Checkout: the payment part (method, cash,
        // change, GCash, OR no.) comes with the first item, with the method chosen before still selected.
        document.getElementById('cartPay').hidden = ids.length === 0;

        renderChips('cashChips', pricing.total, 'cashReceived');
        renderChips('splitChips', null, 'splitCashReceived');
        updateGcashPanel(pricing.total);
        updateSplitPanel(pricing.total);
        updateChange(); // also syncCheckout()
        updateCardAvailability();
        cartChanged();
    }

    // ---- Shown amounts, the discount label and why Checkout is disabled ----
    let cartLineCount = 0;
    let cartTotal = 0;

    /** "₱1,234" or "₱1,234.50". */
    function peso(amount) {
        const n = Math.round((Number(amount) || 0) * 100) / 100;
        return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: n % 1 === 0 ? 0 : 2, maximumFractionDigits: 2 });
    }

    /** The rule(s) behind the discount: the same badges priceCart() gives each line. */
    function discountLabel(lines) {
        const bulk = lines.some(l => l.badge === 'Bundle -5%');
        const set = lines.some(l => l.badge === 'Set -2%');
        if (bulk && set) return 'Discount (10+ of one item 5% · matching set 2%)';
        if (bulk) return 'Discount (10+ of one item, 5%)';
        if (set) return 'Discount (matching set, 2%)';
        return 'Discount';
    }

    /** A typed amount as a number (commas were already dropped as it was typed); '' is 0. */
    function amountOf(id) {
        return parseFloat(document.getElementById(id).value) || 0;
    }

    /**
     * Checkout is enabled only when nothing is missing; otherwise the first missing thing is said
     * under it: no PIN, an empty cart, not enough cash for a cash sale, a box marked in red. The
     * server checks all of it again. While the sale is on its way (submit-once.js) the button is left alone.
     */
    function syncCheckout() {
        const form = document.getElementById('cartForm');
        const method = document.getElementById('paymentMethod').value;
        let reason = '';
        if (form.dataset.noPin === '1') {
            reason = form.dataset.reasonNoPin;
        } else if (cartLineCount === 0) {
            reason = form.dataset.reasonEmpty;
        } else if (method === 'Cash' && amountOf('cashReceived') < cartTotal) {
            reason = form.dataset.reasonCash;
        } else if (form.querySelector('#cartPay [aria-invalid="true"]:not(:disabled)')) {
            reason = form.dataset.reasonField;
        }
        if (!(window.SubmitOnce && window.SubmitOnce.isBusy(form))) {
            document.getElementById('checkoutBtn').disabled = reason !== '';
        }
        const reasonEl = document.getElementById('checkoutReason');
        if (reasonEl.textContent !== reason) reasonEl.textContent = reason;
    }

    // Typing a quantity: digits only; above the stock it is set to the stock with a note.
    const lines = document.getElementById('cartLines');
    lines.addEventListener('input', e => {
        const input = e.target.closest('.qty-input');
        if (!input) return;
        const item = cart[input.dataset.id];
        const digits = input.value.replace(/\D/g, '');
        item.editing = true;
        item.capped = false;
        let qty = digits === '' ? 0 : parseInt(digits, 10);
        if (qty > item.stock) { qty = item.stock; item.capped = true; }
        item.qty = qty;
        item.draft = digits === '' ? '' : String(qty);
        renderCart();
    });
    lines.addEventListener('focusout', e => {
        const input = e.target.closest('.qty-input');
        if (!input || rendering) return;
        const item = cart[input.dataset.id];
        if (!item || !item.editing) return;
        item.editing = false;
        delete item.draft;
        if (item.qty < 1) item.qty = 1;
        // After the focus has moved, so the rebuild keeps it (e.g. on the next quantity box).
        setTimeout(renderCart, 0);
    });
    lines.addEventListener('keydown', e => {
        // Enter in a quantity box only confirms the number; it never submits the checkout form.
        if (e.key === 'Enter' && e.target.closest('.qty-input')) { e.preventDefault(); e.target.blur(); }
    });
    // Same for the payment fields (Cash received, GCash amount and reference): only the
    // Checkout & Print Receipt button sends the sale.
    document.getElementById('cartForm').addEventListener('keydown', e => {
        if (e.key === 'Enter' && e.target.matches('input:not([type="submit"]):not([type="button"])')) e.preventDefault();
    });

    // Grays out a product card, matching the "Out of stock" look, once the
    // quantity already in the cart uses up all remaining stock — so the
    // customer can't add an 11th unit of an item that only had 10 left.
    function updateCardAvailability() {
        document.querySelectorAll('.pos-card').forEach(card => {
            const variants = JSON.parse(card.dataset.variants || '[]');
            if (variants.length === 0) return;

            // For a multi-size card this sums remaining stock across every size
            // (each size still tracks its own stock independently underneath).
            const remaining = variants.reduce((sum, v) => {
                const inCart = cart[String(v.item_id)] ? cart[String(v.item_id)].qty : 0;
                return sum + Math.max(0, v.current_stock - inCart);
            }, 0);
            const stockEl = card.querySelector('.stock');

            if (remaining <= 0) {
                card.classList.add('disabled');
                if (stockEl) { stockEl.textContent = 'Out of stock'; stockEl.className = 'stock out'; }
            } else {
                card.classList.remove('disabled');
                if (stockEl) {
                    let status;
                    if (variants.length > 1) {
                        status = { label: `${remaining} in stock`, cls: 'green' };
                    } else {
                        // Shared rule (assets/js/stock-status.js): Low Stock or Reorder Now shows as low.
                        status = ['Low Stock', 'Reorder Now'].includes(stockStatus(remaining, variants[0].manual_rop_warning)[0])
                            ? { label: `${remaining} left`, cls: 'low' }
                            : { label: `${remaining} in stock`, cls: 'green' };
                    }
                    stockEl.textContent = status.label;
                    stockEl.className = 'stock ' + status.cls;
                }
            }
        });
    }

    function roundUpTo(amount, step) {
        return Math.ceil(amount / step) * step;
    }

    function renderChips(containerId, dueAmount, targetInputId) {
        const el = document.getElementById(containerId);
        if (!el) return;
        if (dueAmount === null) { el.innerHTML = ''; return; }
        if (dueAmount <= 0) { el.innerHTML = ''; return; }
        const amounts = [dueAmount, roundUpTo(dueAmount, 100), roundUpTo(dueAmount, 500), roundUpTo(dueAmount, 1000)];
        const seen = new Set();
        const chips = amounts.filter(a => { if (seen.has(a)) return false; seen.add(a); return true; });
        el.innerHTML = chips.map(a => `<button type="button" class="quick-chip" data-amt="${a}">${esc(peso(a))}</button>`).join('');
        el.querySelectorAll('.quick-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                const input = document.getElementById(targetInputId);
                input.value = chip.dataset.amt;
                setFieldError(input, '');
                updateChange();
            });
        });
    }

    function updateGcashPanel(total) {
        document.getElementById('gcashAmountDisplay').value = peso(total);
        document.getElementById('gcashAmountHidden').value = total.toFixed(2);
    }

    function updateSplitPanel(total) {
        const cashDue = Math.max(0, total - amountOf('splitGcashAmount'));
        document.getElementById('splitCashDue').textContent = peso(cashDue);
        renderChips('splitChips', cashDue, 'splitCashReceived');
    }

    /** One Change box: neutral "Change ₱0" before cash is typed, "Change ₱X" when enough, "Short by ₱X" when not. */
    function showChange(boxId, labelId, valueId, typed, received, due) {
        const box = document.getElementById(boxId);
        const short = typed && received < due;
        box.classList.toggle('is-ready', typed && !short);
        box.classList.toggle('is-short', short);
        document.getElementById(labelId).textContent = short ? 'Short by' : 'Change';
        document.getElementById(valueId).textContent = peso(!typed ? 0 : (short ? due - received : received - due));
    }

    function updateChange() {
        const total = cartTotal;
        const method = document.getElementById('paymentMethod').value;
        if (method === 'Split') {
            const cashDue = Math.max(0, total - amountOf('splitGcashAmount'));
            const typed = document.getElementById('splitCashReceived').value !== '';
            showChange('splitChangeBox', 'splitChangeLabel', 'splitChange', typed, amountOf('splitCashReceived'), cashDue);
        } else {
            const typed = document.getElementById('cashReceived').value !== '';
            showChange('cashChangeBox', 'cartChangeLabel', 'cartChange', typed, amountOf('cashReceived'), total);
        }
        syncCheckout();
    }

    // ---- What may be typed in the payment and OR boxes (the server checks the same: PosCheckout) ----
    const MAX_CASH = 1000000;
    const FIELD_MSG = {
        maxCash: 'Cash received can be up to ₱1,000,000.',
        gcashRef: 'The GCash reference no. is exactly 13 digits.',
    };

    /** The message under a box ('' clears it). */
    function setFieldError(input, message) {
        const el = document.getElementById(input.id + 'Err');
        if (el) el.textContent = message;
        if (message) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
    }

    /** Amounts: digits and one point, at most 2 decimals; commas, letters and minus signs are dropped. */
    function cleanAmount(value) {
        const plain = value.replace(/[^0-9.]/g, '');
        const dot = plain.indexOf('.');
        if (dot === -1) return plain;
        return plain.slice(0, dot + 1) + plain.slice(dot + 1).replace(/\./g, '').slice(0, 2);
    }

    document.querySelectorAll('#cartForm [data-money]').forEach(input => {
        input.addEventListener('input', () => {
            const clean = cleanAmount(input.value);
            if (input.value !== clean) input.value = clean;
            const isCash = input.name === 'cash_received';
            setFieldError(input, isCash && (parseFloat(clean) || 0) > MAX_CASH ? FIELD_MSG.maxCash : '');
        });
    });
    document.querySelectorAll('#cartForm [data-digits]').forEach(input => {
        const max = parseInt(input.getAttribute('maxlength'), 10) || 20;
        const exact = parseInt(input.dataset.exact, 10) || 0;
        input.addEventListener('input', () => {
            const clean = input.value.replace(/\D/g, '').slice(0, max);
            if (input.value !== clean) input.value = clean;
            // A message (the server's, or "13 digits" below) goes once the box is right or empty.
            if (!exact || clean === '' || clean.length === exact) setFieldError(input, '');
            syncCheckout();
        });
        if (exact) {
            input.addEventListener('blur', () => {
                if (input.value !== '' && input.value.length !== exact) { setFieldError(input, FIELD_MSG.gcashRef); syncCheckout(); }
            });
        }
    });

    window.posStockLimit = line => {
        let el = document.getElementById('stockLimitMsg');
        if (!el) {
            el = document.createElement('div');
            el.id = 'stockLimitMsg';
            el.className = 'alert alert-warning py-1 px-2 mt-2 mb-0 small';
            document.getElementById('cartLines').parentNode.insertBefore(el, document.getElementById('cartLines'));
        }
        el.textContent = 'Only ' + line.stock + ' left of ' + line.name + '.';
        clearTimeout(window.posStockLimitTimer);
        window.posStockLimitTimer = setTimeout(() => el.remove(), 3000);
    };
    window.posInc = id => { cart[id].capped = false; if (cart[id].qty < cart[id].stock) { cart[id].qty++; } else { window.posStockLimit(cart[id]); } renderCart(); };
    window.posRemove = id => { cart[id].capped = false; cart[id].editing = false; delete cart[id].draft; cart[id].qty = 0; renderCart(); };
    window.posDec = id => { cart[id].capped = false; cart[id].editing = false; delete cart[id].draft; cart[id].qty = Math.max(0, cart[id].qty - 1); renderCart(); };

    document.getElementById('cashReceived').addEventListener('input', updateChange);
    document.getElementById('splitCashReceived').addEventListener('input', updateChange);
    document.getElementById('splitGcashAmount').addEventListener('input', () => {
        updateSplitPanel(cartTotal);
        updateChange();
    });

    document.getElementById('clearCart').addEventListener('click', () => {
        Object.keys(cart).forEach(id => { cart[id].qty = 0; cart[id].editing = false; cart[id].capped = false; delete cart[id].draft; });
        document.querySelectorAll('.pos-card').forEach(c => c.classList.remove('selected'));
        renderCart();
    });

    const payPanels = { Cash: 'cashPanel', GCash: 'gcashPanel', Split: 'splitPanel' };
    document.querySelectorAll('.pay-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const method = btn.dataset.method;
            document.getElementById('paymentMethod').value = method;
            document.querySelectorAll('.pay-btn').forEach(b => { b.classList.toggle('active', b === btn); b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
            Object.entries(payPanels).forEach(([m, panelId]) => {
                const panel = document.getElementById(panelId);
                panel.style.display = m === method ? '' : 'none';
                panel.querySelectorAll('input').forEach(inp => inp.disabled = (m !== method));
            });
            updateChange();
        });
    });

    // Puts item IDs and quantities back in the cart. Name, size, price and stock come from this
    // page's own item data (the server, now); a quantity above the stock left is capped with the
    // usual note, and an item that is gone or sold out is left out. Returns how many lines came back.
    function addLines(lines) {
        const variants = {};
        document.querySelectorAll('.pos-card').forEach(card => {
            JSON.parse(card.dataset.variants || '[]').forEach(v => { variants[String(v.item_id)] = v; });
        });
        let added = 0;
        lines.forEach(line => {
            const v = variants[String(line && line.id)];
            const qty = parseInt(line && line.qty, 10) || 0;
            if (!v || qty < 1 || v.current_stock < 1) return;
            const id = String(v.item_id);
            const wanted = (cart[id] ? cart[id].qty : 0) + qty;
            if (!cart[id] || cart[id].qty === 0) added++;
            cart[id] = { name: v.item_name, size: v.size || '', price: v.selling_price, stock: v.current_stock, qty: Math.min(wanted, v.current_stock), capped: wanted > v.current_stock };
            document.querySelectorAll('.pos-card').forEach(card => {
                if ((card.dataset.ids || '').split(' ').includes(id)) card.classList.add('selected');
            });
        });
        return added;
    }

    // ---- The cart saved in this browser (session-guard.js idle sign-out) ----
    // localStorage "rfl.pos.cart": {v: 1, userId, savedAt, lines: [{id, qty}]}: item IDs and
    // quantities only, never a name or a price. Saved on every change of the cart. Brought back only
    // on the first POS page after signing back in from an idle sign-out (data-restore-saved="1",
    // App\Libraries\SavedCart); every other load of this page clears it, so a normal page load never
    // brings an old cart back (that includes the page after a successful checkout). The logout button
    // deletes it (session-guard.js). Another user's cart, or one older than 2 hours, is thrown away.
    const CART_KEY = (window.App && window.App.CART_KEY) || 'rfl.pos.cart';
    const CART_MAX_AGE = 2 * 60 * 60 * 1000;
    const cartUser = window.APP_SESSION && window.APP_SESSION.userId != null ? window.APP_SESSION.userId : null;
    let cartReady = false;   // set once the page's own restore is done; before that nothing is saved
    let cartChangedAt = 0;

    function readSavedCart() {
        try { return JSON.parse(window.localStorage.getItem(CART_KEY) || 'null'); } catch (e) { return null; }
    }
    function forgetSavedCart() {
        try { window.localStorage.removeItem(CART_KEY); } catch (e) { /* no storage: nothing saved */ }
    }
    // SAVED CART START: what is written: the user, the time and item IDs with quantities. Nothing else.
    function saveCart() {
        if (cartUser === null) return;
        const lines = Object.keys(cart).filter(id => cart[id].qty > 0).map(id => ({ id: id, qty: cart[id].qty }));
        try {
            if (lines.length === 0) window.localStorage.removeItem(CART_KEY);
            else window.localStorage.setItem(CART_KEY, JSON.stringify({ v: 1, userId: cartUser, savedAt: cartChangedAt || Date.now(), lines: lines }));
        } catch (e) { /* private mode or storage full: the cart just isn't kept */ }
    }
    // SAVED CART END

    // Called at the end of every renderCart(): after the first one, every render follows a change.
    function cartChanged() {
        if (!cartReady) return;
        cartChangedAt = Date.now();
        saveCart();
    }

    window.addEventListener('session:leaving', e => {
        if (e.detail && e.detail.reason === 'logout') { forgetSavedCart(); return; }
        if (!Object.keys(cart).some(id => cart[id].qty > 0)) return;
        // Two POS tabs: the cart changed last wins.
        const saved = readSavedCart();
        if (saved && String(saved.userId) === String(cartUser) && Number(saved.savedAt) > cartChangedAt) return;
        saveCart();
    });

    function restoreSavedCart() {
        const saved = readSavedCart();
        forgetSavedCart();
        if (!saved || saved.v !== 1 || !Array.isArray(saved.lines) || cartUser === null) return;
        if (String(saved.userId) !== String(cartUser)) return;          // another user's cart
        const age = Date.now() - Number(saved.savedAt);
        if (!(age <= CART_MAX_AGE && age > -5 * 60 * 1000)) return;      // older than 2 hours
        const n = addLines(saved.lines);
        if (n === 0) return;
        cartChangedAt = Number(saved.savedAt); // the 2 hours still count from the cashier's last change
        saveCart();
        if (typeof showToast === 'function') {
            showToast({ type: 'info', title: 'Your cart was restored', message: `${n} item${n === 1 ? ' is' : 's are'} back in your cart. Check them before checkout.` });
        }
    }

    // The cart after "Not enough stock" or "This page needed a refresh": the posted item IDs and
    // quantities only (data-restore, from old input), rebuilt by addLines(). The payment details
    // typed are put back too. Nothing is sent: the cashier checks the cart and clicks Checkout again.
    (function restoreCart() {
        const form = document.getElementById('cartForm');
        let lines = [];
        let pay = {};
        try { lines = JSON.parse(form.dataset.restore || '[]'); pay = JSON.parse(form.dataset.restorePayment || '{}'); } catch (e) { return; }
        if (!Array.isArray(lines) || lines.length === 0) {
            if (form.dataset.restoreSaved === '1') restoreSavedCart(); else forgetSavedCart();
            return;
        }
        addLines(lines);
        renderCart();
        cartChangedAt = Date.now();
        saveCart();
        if (pay.method) {
            const btn = document.querySelector('.pay-btn[data-method="' + pay.method + '"]');
            if (btn) btn.click();
        }
        const set = (id, value) => { const el = document.getElementById(id); if (el && value !== undefined && value !== null) el.value = value; };
        if (pay.method === 'Split') {
            set('splitGcashAmount', pay.gcash);
            set('gcashRefSplit', pay.gcash_ref);
            set('splitCashReceived', pay.cash);
            document.getElementById('splitGcashAmount').dispatchEvent(new Event('input'));
        } else if (pay.method === 'GCash') {
            set('gcashRefGcash', pay.gcash_ref);
        } else {
            set('cashReceived', pay.cash);
        }
        set('orNumber', pay.or_number);
        updateChange();
    })();

    renderCart();
    cartReady = true; // from here on, every render is a change of the cart, and is saved
    // The "Unlocked" toast (PosPinActions::unlock): if none of the kept items could come back (sold out
    // meanwhile), it says just "Unlocked." while it is still showing.
    const unlockedToast = document.querySelector('.app-toast[data-toast-key="pos-unlocked"] .toast-title');
    if (unlockedToast && cartLineCount === 0) unlockedToast.textContent = 'Unlocked.';
}
