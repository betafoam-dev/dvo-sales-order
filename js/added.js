const inventories = window.appData.inventories;
const uoms = window.appData.uoms;
const saved = window.appData.saved;
let rowIndex = window.appData.rowIndex;

function initSD(wrapperId, onSelect) {
    const wrapper = document.getElementById(wrapperId);
    const display = wrapper.querySelector('.sd-input');
    const hidden = wrapper.querySelector('input[type=hidden]');
    const dropdown = wrapper.querySelector('.sd-dropdown');
    const search = wrapper.querySelector('.sd-search');
    const list = wrapper.querySelector('.sd-list');

    function filterItems(q) {
        const lower = q.toLowerCase();
        let visCount = 0;
        list.querySelectorAll('.sd-item').forEach(item => {
            const text = (item.firstChild?.nodeType === 3 ? item.firstChild.textContent : item.textContent).toLowerCase();
            const show = !q || text.includes(lower);
            item.style.display = show ? '' : 'none';
            if (show) visCount++;
        });
        let emptyEl = list.querySelector('.sd-empty');
        if (visCount === 0) {
            if (!emptyEl) { emptyEl = document.createElement('div'); emptyEl.className = 'sd-empty'; emptyEl.textContent = 'No results'; list.appendChild(emptyEl); }
            emptyEl.style.display = '';
        } else if (emptyEl) emptyEl.style.display = 'none';
    }

    function openDropdown() {
        document.querySelectorAll('.sd-dropdown').forEach(d => { if (d !== dropdown) d.style.display = 'none'; });
        dropdown.style.display = 'block';
        search.value = '';
        filterItems('');
        search.focus();
    }

    function closeDropdown() { dropdown.style.display = 'none'; }

    display.addEventListener('click', () => dropdown.style.display === 'block' ? closeDropdown() : openDropdown());
    display.addEventListener('keydown', e => {
        if (e.key === 'Escape') { closeDropdown(); return; }
        if (e.key === 'Backspace' || e.key === 'Delete') {
            display.value = hidden.value = '';
            closeDropdown();
            wrapper.dispatchEvent(new CustomEvent('sd-change', { detail: null }));
            if (onSelect) onSelect(null);
            syncAddress();
            return;
        }
        if (e.key.length === 1) { openDropdown(); search.value += e.key; filterItems(search.value); }
    });
    search.addEventListener('input', () => filterItems(search.value));
    list.addEventListener('mousedown', e => {
        const item = e.target.closest('.sd-item');
        if (!item || item.style.display === 'none') return;
        e.preventDefault();
        display.value = hidden.value = item.dataset.value;
        closeDropdown();
        wrapper.dispatchEvent(new CustomEvent('sd-change', { detail: { id: item.dataset.id, value: item.dataset.value, element: item } }));
        if (onSelect) onSelect(item);
    });
    document.addEventListener('click', e => { if (!wrapper.contains(e.target)) closeDropdown(); });
}

function initInvSD(wrapper) {
    const display = wrapper.querySelector('.inv-display');
    const hidden = wrapper.querySelector('.inv-id-value') || wrapper.closest('.item-row').querySelector('.inv-id-value');
    const dropdown = wrapper.querySelector('.sd-dropdown');
    const search = wrapper.querySelector('.sd-search');
    const list = wrapper.querySelector('.sd-list');
    const row = wrapper.closest('.item-row');

    function filterItems(q) {
        const lower = q.toLowerCase();
        let visCount = 0;
        list.querySelectorAll('.sd-item').forEach(item => {
            const text = item.textContent.toLowerCase();
            const show = !q || text.includes(lower);
            item.style.display = show ? '' : 'none';
            if (show) visCount++;
        });
        let emptyEl = list.querySelector('.sd-empty');
        if (visCount === 0) {
            if (!emptyEl) {
                emptyEl = document.createElement('div');
                emptyEl.className = 'sd-empty';
                emptyEl.textContent = 'No results';
                list.appendChild(emptyEl);
            }
            emptyEl.style.display = '';
        } else if (emptyEl) {
            emptyEl.style.display = 'none';
        }
    }

    function openDropdown() {
        document.querySelectorAll('.sd-dropdown').forEach(d => { if (d !== dropdown) d.style.display = 'none'; });
        dropdown.style.display = 'block';
        search.value = '';
        filterItems('');
        search.focus();
    }

    function closeDropdown() { dropdown.style.display = 'none'; }

    display.addEventListener('click', () => dropdown.style.display === 'block' ? closeDropdown() : openDropdown());
    search.addEventListener('input', () => filterItems(search.value));
    list.addEventListener('mousedown', e => {
        const item = e.target.closest('.sd-item');
        if (!item || item.style.display === 'none') return;
        e.preventDefault();
        display.value = item.dataset.label;
        hidden.value = item.dataset.value;
        row.querySelector('.item-code').value = item.dataset.code;
        row.querySelector('.item-desc').value = item.dataset.name;
        row.querySelector('.item-uom').value = item.dataset.uom;
        closeDropdown();
        recalcRow(row);
    });
    document.addEventListener('click', e => { if (!wrapper.contains(e.target)) closeDropdown(); });
}

function syncAddress() {
    const parts = [
        document.getElementById('lot-no-field').value.trim(),
        document.getElementById('barangay-value').value.trim(),
        document.getElementById('municipality-value').value.trim(),
        document.getElementById('province-value').value.trim(),
        document.getElementById('region-value').value.trim(),
    ].filter(Boolean);
    const joined = parts.join(', ');
    document.getElementById('address-text-display').value = joined;
    document.getElementById('address-field').value = joined;
}

document.getElementById('lot-no-field').addEventListener('input', syncAddress);

initSD('region-wrapper', () => syncAddress());

initSD('province-wrapper', item => {
    if (item) {
        const ri = document.querySelector(`#region-wrapper .sd-item[data-id="${item.dataset.regionId}"]`);
        if (ri) document.getElementById('region-display').value = document.getElementById('region-value').value = ri.dataset.value;
    }
    syncAddress();
});

document.getElementById('region-wrapper').addEventListener('sd-change', e => {
    const rid = e.detail?.id;
    const url = rid ? `add.php?ajax=provinces&region_id=${rid}` : 'add.php?ajax=provinces';
    fetch(url).then(r => r.json()).then(provinces => {
        document.querySelector('#province-wrapper .sd-list').innerHTML = provinces.map(p =>
            `<div class="sd-item" data-value="${p.province_name}" data-id="${p.province_id}" data-region-id="${p.region_id}">${p.province_name}</div>`
        ).join('');
    });
});

initSD('municipality-wrapper', item => {
    if (item) {
        document.getElementById('province-display').value = document.getElementById('province-value').value = item.dataset.province;
        document.getElementById('region-display').value = document.getElementById('region-value').value = item.dataset.region;
        document.getElementById('barangay-display').value = document.getElementById('barangay-value').value = '';
        loadBarangays(item.dataset.id);
    }
    syncAddress();
});

document.getElementById('province-wrapper').addEventListener('sd-change', e => {
    const pid = e.detail?.id;
    const url = pid ? `add.php?ajax=municipalities&province_id=${pid}` : 'add.php?ajax=municipalities';
    fetch(url).then(r => r.json()).then(municipalities => {
        const pname = document.getElementById('province-display').value;
        const rval = document.getElementById('region-value').value;
        document.querySelector('#municipality-wrapper .sd-list').innerHTML = municipalities.map(m =>
            `<div class="sd-item" data-value="${m.municipality_name}" data-id="${m.municipality_id}"
                  data-province="${pname || m.province_name}" data-province-id="${m.province_id}"
                  data-region="${rval}" data-region-id="${m.region_id}">
                ${m.municipality_name} <span class="sd-hint">(${pname || m.province_name})</span>
            </div>`
        ).join('');
    });
});

initSD('barangay-wrapper', () => syncAddress());

function loadBarangays(municipalityId, preselect) {
    const list = document.getElementById('barangay-list');
    list.innerHTML = '<div class="sd-empty">Loading...</div>';
    fetch(`add.php?ajax=barangays&municipality_id=${municipalityId}`)
        .then(r => r.json())
        .then(barangays => {
            if (!barangays.length) { list.innerHTML = '<div class="sd-empty">No barangays found</div>'; return; }
            list.innerHTML = barangays.map(b =>
                `<div class="sd-item" data-value="${b.barangay_name}" data-id="${b.barangay_id}">${b.barangay_name}</div>`
            ).join('');
            if (preselect) {
                const match = [...list.querySelectorAll('.sd-item')].find(i => i.dataset.value === preselect);
                if (match) document.getElementById('barangay-display').value = document.getElementById('barangay-value').value = preselect;
            }
        });
}

(function restoreState() {
    if (!saved.municipality) return;
    const munItem = document.querySelector(`#municipality-wrapper .sd-item[data-value="${saved.municipality.replace(/"/g, '\\"')}"]`);
    if (munItem) loadBarangays(munItem.dataset.id, saved.barangay);
})();

function recalcRow(row) {
    const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
    const price = parseFloat(row.querySelector('.item-price').value) || 0;
    row.querySelector('.item-amount').value = (qty * price).toFixed(2);
    recalcTotal();
}

function recalcTotal() {
    let total = 0;
    document.querySelectorAll('.item-amount').forEach(el => total += parseFloat(el.value.replace(/,/g, '')) || 0);
    document.getElementById('grand-total').textContent = '₱' + total.toLocaleString('en-PH', { minimumFractionDigits: 2 });
}

function attachRowEvents(row) {
    const invWrapper = row.querySelector('.inv-sd-wrapper');
    if (invWrapper) initInvSD(invWrapper);
    row.querySelector('.item-qty').addEventListener('input', () => recalcRow(row));
    row.querySelector('.item-price').addEventListener('input', () => recalcRow(row));
    row.querySelector('.remove-row').addEventListener('click', () => {
        if (document.querySelectorAll('.item-row').length > 1) { row.remove(); recalcTotal(); }
    });
}

document.querySelectorAll('.item-row').forEach(row => { attachRowEvents(row); recalcRow(row); });

const uomOptions = uoms.map(u => `<option value="${u.uom_name}">${u.uom_name}</option>`).join('');
// const invOptions = Object.values(inventories).map(inv =>
//     `<option value="${inv.id}" data-code="${inv.stock_code}" data-name="${inv.stock_name}" data-uom="${inv.uom}">${inv.stock_code} - ${inv.stock_name}</option>`
// ).join('');

const invSDOptions = Object.values(inventories).map(inv =>
    `<div class="sd-item"
          data-value="${inv.id}"
          data-code="${inv.stock_code}"
          data-name="${inv.stock_name}"
          data-description="${inv.stock_description}"
          data-uom="${inv.uom}"
          data-label="${inv.stock_code} - ${inv.stock_description}">
        ${inv.stock_code} - ${inv.stock_description}
    </div>`
).join('');

// ── Add Item: builds a div card row (your layout) and fires ss:init-row for partner's SearchableSelect ──
document.getElementById('add-row').addEventListener('click', function () {
    const row = document.createElement('div');
    row.className = 'item-row';
    row.innerHTML = `
        <div class="item-field">
            <label class="item-label">Item</label>
            <input type="hidden" name="items[${rowIndex}][inventory_id]" class="inv-id-value" value="">
            <div class="sd-wrapper inv-sd-wrapper">
                <input type="text" class="sd-input inv-display" placeholder="-- Select Item --" readonly>
                <div class="sd-dropdown">
                    <input type="text" class="sd-search" placeholder="Search item...">
                    <div class="sd-list">${invSDOptions}</div>
                </div>
            </div>
        </div>

        <div class="item-field">
            <label class="item-label">Item Code</label>
            <input type="text" name="items[${rowIndex}][item_code]" class="item-code item-input">
        </div>

        <div class="item-field">
            <label class="item-label">Description</label>
            <input type="text" name="items[${rowIndex}][item_description]" class="item-desc item-input">
        </div>

        <div class="item-field">
            <label class="item-label">UOM</label>
            <select name="items[${rowIndex}][uom]" class="item-uom item-input">
                <option value="">--</option>${uomOptions}
            </select>
        </div>

        <div class="item-field">
            <label class="item-label">Qty</label>
            <input type="number" name="items[${rowIndex}][quantity]" class="item-qty item-input" min="0.0001" step="0.0001" value="1" required>
        </div>

        <div class="item-field">
            <label class="item-label">Unit Price</label>
            <input type="number" name="items[${rowIndex}][unit_price]" class="item-price item-input" min="0" step="0.01" value="0" required>
        </div>

        <div class="item-field">
            <label class="item-label">Amount</label>
            <input type="text" class="item-amount item-input" readonly value="0.00">
        </div>

        <div class="item-field">
            <button type="button" class="remove-row item-btn-remove">
                <i class="bi bi-trash"></i> Remove
            </button>
        </div>
    `;
    document.getElementById('items-body').appendChild(row);
    attachRowEvents(row);

    // Notify partner's SearchableSelect initializer about the new row
    // document.getElementById('add-row').dispatchEvent(new CustomEvent('ss:init-row', { detail: row }));

    rowIndex++;
});

recalcTotal();

initSD('customer-wrapper', item => {
    document.getElementById('customer-name-value').value = item ? item.dataset.value : '';
    document.getElementById('billing-address-field').value = item ? (item.dataset.address ?? '') : '';

    const select = document.getElementById('address-select');
    select.innerHTML = '<option value="">-- Loading... --</option>';
    document.getElementById('address-field').value = '';

    if (!item) {
        select.innerHTML = '<option value="">-- Select customer first --</option>';
        return;
    }

    fetch(`add.php?ajax=customer_addresses&customer_name=${encodeURIComponent(item.dataset.value)}`)
        .then(r => r.json())
        .then(addresses => {
            if (!addresses.length) {
                select.innerHTML = '<option value="">-- No existing addresses --</option>';
            } else {
                select.innerHTML = '<option value="">-- Select address --</option>' +
                    addresses.map(a =>
                        `<option
                        value="${a.address.replace(/"/g, '&quot;')}"
                        data-lot="${(a.lot_no ?? '').replace(/"/g, '&quot;')}"
                        data-barangay="${(a.barangay ?? '').replace(/"/g, '&quot;')}"
                        data-municipality="${(a.municipality ?? '').replace(/"/g, '&quot;')}"
                        data-province="${(a.province ?? '').replace(/"/g, '&quot;')}"
                        data-region="${(a.region ?? '').replace(/"/g, '&quot;')}">
                        ${a.address}
                    </option>`
                    ).join('');
            }
        });
});

// When user picks from address select
document.getElementById('address-select').addEventListener('change', function () {
    document.getElementById('address-field').value = this.value;

    const opt = this.options[this.selectedIndex];
    if (!this.value || !opt) return;

    // Silently fill hidden sub-fields so they save to DB correctly
    document.getElementById('lot-no-field').value = opt.dataset.lot ?? '';
    document.getElementById('province-value').value = opt.dataset.province ?? '';
    document.getElementById('province-display').value = opt.dataset.province ?? '';
    document.getElementById('region-value').value = opt.dataset.region ?? '';
    document.getElementById('region-display').value = opt.dataset.region ?? '';

    // Municipality
    document.getElementById('municipality-value').value = opt.dataset.municipality ?? '';
    document.getElementById('municipality-display').value = opt.dataset.municipality ?? '';

    // Barangay — need to load barangays for the municipality first, then set
    const munItem = document.querySelector(`#municipality-wrapper .sd-item[data-value="${(opt.dataset.municipality ?? '').replace(/"/g, '\\"')}"]`);
    if (munItem) {
        loadBarangays(munItem.dataset.id, opt.dataset.barangay);
    } else {
        document.getElementById('barangay-value').value = opt.dataset.barangay ?? '';
        document.getElementById('barangay-display').value = opt.dataset.barangay ?? '';
    }
});

// New Delivery Address checkbox toggle
document.getElementById('new-address-checkbox').addEventListener('change', function () {
    const isNew = this.checked;
    const fields = ['field-lot-no', 'field-province', 'field-municipality', 'field-barangay'];

    document.getElementById('address-select-wrapper').classList.toggle('hidden', isNew);
    document.getElementById('address-text-wrapper').classList.toggle('hidden', !isNew);

    fields.forEach(id => document.getElementById(id).classList.toggle('hidden', !isNew));

    if (isNew) {
        // Switch to text mode — sync immediately
        syncAddress();
    } else {
        // Switch back to select mode — restore selected address
        const select = document.getElementById('address-select');
        document.getElementById('address-field').value = select.value;
        // Clear address sub-fields
        document.getElementById('lot-no-field').value = '';
        document.getElementById('province-display').value = document.getElementById('province-value').value = '';
        document.getElementById('municipality-display').value = document.getElementById('municipality-value').value = '';
        document.getElementById('barangay-display').value = document.getElementById('barangay-value').value = '';
        document.getElementById('region-display').value = document.getElementById('region-value').value = '';
        document.getElementById('address-text-display').value = '';
        document.getElementById('address-field').value = '';
    }
});