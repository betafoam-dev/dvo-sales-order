const inventories = window.appData.inventories;
const uoms        = window.appData.uoms;
const saved       = window.appData.saved;
let rowIndex      = window.appData.rowIndex;

function initSD(wrapperId, onSelect) {
    const wrapper  = document.getElementById(wrapperId);
    const display  = wrapper.querySelector('.sd-input');
    const hidden   = wrapper.querySelector('input[type=hidden]');
    const dropdown = wrapper.querySelector('.sd-dropdown');
    const search   = wrapper.querySelector('.sd-search');
    const list     = wrapper.querySelector('.sd-list');

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

function syncAddress() {
    const parts = [
        document.getElementById('lot-no-field').value.trim(),
        document.getElementById('barangay-value').value.trim(),
        document.getElementById('municipality-value').value.trim(),
        document.getElementById('province-value').value.trim(),
        document.getElementById('region-value').value.trim(),
    ].filter(Boolean);
    document.getElementById('address-field').value = parts.join(', ');
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
        document.getElementById('region-display').value   = document.getElementById('region-value').value   = item.dataset.region;
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
        const rval  = document.getElementById('region-value').value;
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
    const qty   = parseFloat(row.querySelector('.item-qty').value)   || 0;
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
    row.querySelector('.inv-select').addEventListener('change', function () {
        const inv = inventories[this.value];
        if (inv) { row.querySelector('.item-code').value = inv.stock_code; row.querySelector('.item-desc').value = inv.stock_name; row.querySelector('.item-uom').value = inv.uom; }
    });
    row.querySelector('.item-qty').addEventListener('input',   () => recalcRow(row));
    row.querySelector('.item-price').addEventListener('input', () => recalcRow(row));
    row.querySelector('.remove-row').addEventListener('click', () => {
        if (document.querySelectorAll('.item-row').length > 1) { row.remove(); recalcTotal(); }
    });
}

document.querySelectorAll('.item-row').forEach(row => { attachRowEvents(row); recalcRow(row); });

const uomOptions = uoms.map(u => `<option value="${u.uom_name}">${u.uom_name}</option>`).join('');
const invOptions = Object.values(inventories).map(inv =>
    `<option value="${inv.id}" data-code="${inv.stock_code}" data-name="${inv.stock_name}" data-uom="${inv.uom}">${inv.stock_code} - ${inv.stock_name}</option>`
).join('');

document.getElementById('add-row').addEventListener('click', function () {
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = `
        <td class="px-2 py-1.5" style="min-width:220px">
            <select name="items[${rowIndex}][inventory_id]" class="inv-select w-full border border-gray-300 rounded px-2 py-1 text-sm bg-white focus:border-blue-400" required>
                <option value="">-- Select Item --</option>${invOptions}
            </select>
        </td>
        <td class="px-2 py-1.5"><input type="text" name="items[${rowIndex}][item_code]" class="item-code border border-gray-300 rounded px-2 py-1 text-sm w-24 focus:border-blue-400"></td>
        <td class="px-2 py-1.5"><input type="text" name="items[${rowIndex}][item_description]" class="item-desc border border-gray-300 rounded px-2 py-1 text-sm w-36 focus:border-blue-400"></td>
        <td class="px-2 py-1.5">
            <select name="items[${rowIndex}][uom]" class="item-uom border border-gray-300 rounded px-2 py-1 text-sm bg-white focus:border-blue-400 outline-none w-24">
                <option value="">--</option>${uomOptions}
            </select>
        </td>
        <td class="px-2 py-1.5"><input type="number" name="items[${rowIndex}][quantity]" class="item-qty border border-gray-300 rounded px-2 py-1 text-sm w-20 focus:border-blue-400" min="0.0001" step="0.0001" value="1" required></td>
        <td class="px-2 py-1.5"><input type="number" name="items[${rowIndex}][unit_price]" class="item-price border border-gray-300 rounded px-2 py-1 text-sm w-24 focus:border-blue-400" min="0" step="0.01" value="0" required></td>
        <td class="px-2 py-1.5"><input type="text" class="item-amount border border-gray-200 rounded px-2 py-1 text-sm w-24 bg-gray-50 text-gray-600" readonly value="0.00"></td>
        <td class="px-2 py-1.5"><button type="button" class="remove-row border border-red-300 text-red-500 hover:bg-red-50 rounded px-2 py-1 text-xs"><i class="bi bi-trash"></i></button></td>
    `;
    document.getElementById('items-body').appendChild(tr);
    if (typeof initInvSelectRow === 'function') initInvSelectRow(tr);
    attachRowEvents(tr);
    rowIndex++;
});

recalcTotal();
document.getElementById('customer-select').addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    document.getElementById('billing-address-field').value = opt.dataset.address ?? '';
});

// restore on POST failure
(function () {
    const sel = document.getElementById('customer-select');
    if (sel.value) sel.dispatchEvent(new Event('change'));
})();