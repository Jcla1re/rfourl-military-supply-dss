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
        document.getElementById('cartCount').textContent = ids.length;

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
                return `<div class="cart-line">
                    <div class="info">
                        <div class="name">${esc(item.name)}${item.size ? ' &middot; ' + esc(item.size) : ''}${l.badge ? '<span class="badge-disc">' + l.badge + '</span>' : ''}</div>
                        <div class="price">₱${esc(item.price)}</div>
                    </div>
                    <div class="qty-ctrl">
                        <button type="button" onclick="posDec(${jsArg(l.id)})" aria-label="One less">&minus;</button>
                        <input type="text" class="qty-input" inputmode="numeric" pattern="[0-9]*" maxlength="5" autocomplete="off"
                               data-id="${esc(l.id)}" value="${esc(shown)}" aria-label="Quantity">
                        <button type="button" onclick="posInc(${jsArg(l.id)})" aria-label="One more"${item.qty >= item.stock ? ' disabled' : ''}>+</button>
                    </div>
                    <div class="line-total">₱${l.lineTotal.toLocaleString()}${(l.lineDiscount > 0 && l.badge !== 'Set -2%') ? '<span class="line-disc">-₱' + l.lineDiscount.toLocaleString() + '</span>' : ''}</div>
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

        document.getElementById('cartSubtotal').textContent = '₱' + pricing.subtotal.toLocaleString();
        document.getElementById('discountRow').style.display = pricing.discount > 0 ? '' : 'none';
        document.getElementById('cartDiscountOut').textContent = '- ₱' + pricing.discount.toLocaleString();
        document.getElementById('cartTotal').textContent = '₱' + pricing.total.toLocaleString();
        document.getElementById('checkoutBtn').disabled = ids.length === 0;
        document.getElementById('clearCart').disabled = ids.length === 0;

        renderChips('cashChips', pricing.total, 'cashReceived');
        renderChips('splitChips', null, 'splitCashReceived');
        updateGcashPanel(pricing.total);
        updateSplitPanel(pricing.total);
        updateChange();
        updateCardAvailability();
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
        el.innerHTML = chips.map(a => `<button type="button" class="quick-chip" data-amt="${a}">₱${a.toLocaleString()}</button>`).join('');
        el.querySelectorAll('.quick-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                document.getElementById(targetInputId).value = chip.dataset.amt;
                updateChange();
            });
        });
    }

    function updateGcashPanel(total) {
        document.getElementById('gcashAmountDisplay').value = '₱' + total.toLocaleString();
        document.getElementById('gcashAmountHidden').value = total.toFixed(2);
    }

    function updateSplitPanel(total) {
        const gcashAmt = parseFloat(document.getElementById('splitGcashAmount').value) || 0;
        const cashDue = Math.max(0, total - gcashAmt);
        document.getElementById('splitCashDue').textContent = '₱' + cashDue.toLocaleString();
        renderChips('splitChips', cashDue, 'splitCashReceived');
    }

    function updateChange() {
        const total = parseFloat(document.getElementById('cartTotal').textContent.replace(/[^0-9.]/g, '')) || 0;
        const method = document.getElementById('paymentMethod').value;
        if (method === 'Split') {
            const gcashAmt = parseFloat(document.getElementById('splitGcashAmount').value) || 0;
            const cashDue = Math.max(0, total - gcashAmt);
            const received = parseFloat(document.getElementById('splitCashReceived').value) || 0;
            document.getElementById('splitChange').textContent = '₱' + Math.max(0, received - cashDue).toLocaleString();
        } else {
            const received = parseFloat(document.getElementById('cashReceived').value) || 0;
            document.getElementById('cartChange').textContent = '₱' + Math.max(0, received - total).toLocaleString();
        }
    }

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
    window.posDec = id => { cart[id].capped = false; cart[id].editing = false; delete cart[id].draft; cart[id].qty = Math.max(0, cart[id].qty - 1); renderCart(); };

    document.getElementById('cashReceived').addEventListener('input', updateChange);
    document.getElementById('splitCashReceived').addEventListener('input', updateChange);
    document.getElementById('splitGcashAmount').addEventListener('input', () => {
        const total = parseFloat(document.getElementById('cartTotal').textContent.replace(/[^0-9.]/g, '')) || 0;
        updateSplitPanel(total);
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
            document.querySelectorAll('.pay-btn').forEach(b => b.classList.toggle('active', b === btn));
            Object.entries(payPanels).forEach(([m, panelId]) => {
                const panel = document.getElementById(panelId);
                panel.style.display = m === method ? '' : 'none';
                panel.querySelectorAll('input').forEach(inp => inp.disabled = (m !== method));
            });
            updateChange();
        });
    });

    renderCart();
}
