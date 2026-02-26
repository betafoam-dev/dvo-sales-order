class SearchableSelect {
    constructor(select, options = {}) {
        this.originalSelect = select;
        this.options = { placeholder: 'Search...', ...options };
        this._build();
        this._bind();
    }

    _build() {
        const sel = this.originalSelect;

        // Wrapper
        this.wrapper = document.createElement('div');
        this.wrapper.className = 'sd-wrapper';

        // Copy width/classes from original
        this.wrapper.style.cssText = sel.style.cssText;

        // Display input
        this.display = document.createElement('input');
        this.display.type = 'text';
        this.display.className = 'sd-input';
        this.display.readOnly = true;
        this.display.placeholder = sel.options[0]?.text || this.options.placeholder;

        // Hidden input (replaces the select for form submission)
        this.hidden = document.createElement('input');
        this.hidden.type = 'hidden';
        this.hidden.name = sel.name;
        this.hidden.id = sel.id || '';
        if (sel.required) this.hidden.required = true;

        // Copy all data-* attributes
        [...sel.attributes].forEach(attr => {
            if (attr.name.startsWith('data-') || attr.name === 'class') return;
            try { this.hidden.setAttribute(attr.name, attr.value); } catch {}
        });

        // Dropdown
        this.dropdown = document.createElement('div');
        this.dropdown.className = 'sd-dropdown';

        this.searchInput = document.createElement('input');
        this.searchInput.type = 'text';
        this.searchInput.className = 'sd-search';
        this.searchInput.placeholder = this.options.placeholder;

        this.list = document.createElement('div');
        this.list.className = 'sd-list';

        // Build items from original select options
        this._items = [];
        [...sel.options].forEach(opt => {
            if (!opt.value) return;
            const item = document.createElement('div');
            item.className = 'sd-item';
            item.dataset.value = opt.value;

            // Copy all data-* from option
            [...opt.attributes].forEach(attr => {
                if (attr.name.startsWith('data-')) item.setAttribute(attr.name, attr.value);
            });

            item.textContent = opt.text;
            this.list.appendChild(item);
            this._items.push(item);

            if (opt.selected && opt.value) {
                this.display.value = opt.text;
                this.hidden.value = opt.value;
            }
        });

        this.dropdown.appendChild(this.searchInput);
        this.dropdown.appendChild(this.list);
        this.wrapper.appendChild(this.display);
        this.wrapper.appendChild(this.hidden);
        this.wrapper.appendChild(this.dropdown);

        sel.parentNode.replaceChild(this.wrapper, sel);
    }

    _bind() {
        const { wrapper, display, hidden, dropdown, searchInput, list } = this;

        const filter = q => {
            const lower = q.toLowerCase();
            let vis = 0;
            this._items.forEach(item => {
                const show = !q || item.textContent.toLowerCase().includes(lower);
                item.style.display = show ? '' : 'none';
                if (show) vis++;
            });
            let empty = list.querySelector('.sd-empty');
            if (vis === 0) {
                if (!empty) { empty = document.createElement('div'); empty.className = 'sd-empty'; empty.textContent = 'No results'; list.appendChild(empty); }
                empty.style.display = '';
            } else if (empty) empty.style.display = 'none';
        };

        const open = () => {
            document.querySelectorAll('.sd-dropdown').forEach(d => { if (d !== dropdown) d.style.display = 'none'; });
            
            // Use fixed positioning based on display input's position
            const rect = display.getBoundingClientRect();
            dropdown.style.position = 'fixed';
            dropdown.style.top  = (rect.bottom + 2) + 'px';
            dropdown.style.left = rect.left + 'px';
            dropdown.style.width = rect.width + 'px';
            dropdown.style.right = 'auto';

            dropdown.style.display = 'block';
            searchInput.value = '';
            filter('');
            searchInput.focus();
        };

        const close = () => { dropdown.style.display = 'none'; };

        const clear = () => {
            display.value = '';
            hidden.value = '';
            close();
            this._emit(null);
        };

        display.addEventListener('click', () => dropdown.style.display === 'block' ? close() : open());

        display.addEventListener('keydown', e => {
            if (e.key === 'Escape') { close(); return; }
            if (e.key === 'Backspace' || e.key === 'Delete') { clear(); return; }
            if (e.key.length === 1) { open(); searchInput.value += e.key; filter(searchInput.value); }
        });

        searchInput.addEventListener('input', () => filter(searchInput.value));

        list.addEventListener('mousedown', e => {
            const item = e.target.closest('.sd-item');
            if (!item || item.style.display === 'none') return;
            e.preventDefault();
            display.value = item.textContent.trim();
            hidden.value = item.dataset.value;
            close();
            this._emit(item);
        });

        document.addEventListener('click', e => { if (!wrapper.contains(e.target)) close(); });
    }

    _emit(item) {
        this.wrapper.dispatchEvent(new CustomEvent('ss:change', {
            bubbles: true,
            detail: item ? { value: item.dataset.value, text: item.textContent.trim(), element: item, data: { ...item.dataset } } : null
        }));
    }

    getValue() { return this.hidden.value; }
    getText()  { return this.display.value; }

    setValue(value) {
        const item = this._items.find(i => i.dataset.value === value);
        if (item) { this.display.value = item.textContent.trim(); this.hidden.value = value; }
        else { this.display.value = this.hidden.value = ''; }
    }

    // Dynamically replace list items (e.g. after AJAX)
    setItems(items) {
        // items: [{ value, text, data: {} }]
        this._items = [];
        this.list.querySelectorAll('.sd-item, .sd-empty').forEach(el => el.remove());
        items.forEach(({ value, text, data = {} }) => {
            const item = document.createElement('div');
            item.className = 'sd-item';
            item.dataset.value = value;
            Object.entries(data).forEach(([k, v]) => item.dataset[k] = v);
            item.textContent = text;
            this.list.appendChild(item);
            this._items.push(item);
        });
    }
}