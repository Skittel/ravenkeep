// Ravenkeep UI: load the reading pane without a page reload, keyboard navigation, body tabs.
(function () {
    'use strict';

    // Smartphone menu: the hamburger button shows navigation, user box and the mail sidebar
    const toggle = document.querySelector('.nav-toggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            const open = document.querySelector('.app').classList.toggle('nav-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) window.scrollTo(0, 0);
        });
    }

    // Show a password in clear text and hide it again (eye button with data-reveal="<input id>")
    document.querySelectorAll('button[data-reveal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const field = document.getElementById(btn.dataset.reveal);
            if (!field) return;
            const show = field.type === 'password';
            field.type = show ? 'text' : 'password';
            btn.classList.toggle('active', show);
            if (show) field.select();
        });
    });

    // Confirmation for destructive forms (data-confirm="…")
    document.addEventListener('submit', function (e) {
        const msg = e.target.dataset && e.target.dataset.confirm;
        if (msg && !window.confirm(msg)) e.preventDefault();
    });

    // Filters: submit the form when a select with data-autosubmit changes
    document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
        sel.addEventListener('change', function () { sel.form.submit(); });
    });

    // Tag picker: inputs with data-tag-picker get a searchable list of the known tags (#rk-tags).
    //   multi  = comma-separated list shown as chips (the original input keeps the value for the form)
    //   single = one tag (rule conditions on tags); only active while the row's field is "tag"
    const tagData = document.getElementById('rk-tags');
    const knownTags = tagData ? JSON.parse(tagData.textContent) : [];
    const typeData = document.getElementById('rk-tag-types');
    const knownTypes = typeData ? JSON.parse(typeData.textContent) : null; // null = no restriction
    const tagInfo = function (name) {
        return knownTags.find(function (t) { return t.n === name; }) || { n: name, l: name.replace(':', ' - '), c: 'tag tag-c0' };
    };
    // "typ - nas" typed as shown is stored as "typ:nas"
    const normalizeTag = function (text) {
        return text.trim().replace(/\s+-\s+/, ':').replace(/\s*:\s*/, ':').toLowerCase();
    };

    function attachList(input, opts) {
        const box = document.createElement('ul');
        box.className = 'tag-picker-list';
        box.hidden = true;
        opts.anchor.appendChild(box);
        let items = [], active = -1;

        const render = function () {
            const q = input.value.trim().toLowerCase();
            const taken = opts.selected();
            const exclude = opts.exclude;
            items = knownTags.filter(function (t) {
                if (taken.indexOf(t.n) !== -1) return false;
                if (exclude.indexOf(t.n.split(':')[0]) !== -1 && t.n.indexOf(':') !== -1) return false;
                return q === '' || t.n.indexOf(q) !== -1 || t.l.toLowerCase().indexOf(q) !== -1;
            });
            items.sort(function (a, b) {
                const pa = a.n.indexOf(q) === 0 || a.n.split(':').pop().indexOf(q) === 0 ? 0 : 1;
                const pb = b.n.indexOf(q) === 0 || b.n.split(':').pop().indexOf(q) === 0 ? 0 : 1;
                return pa - pb || a.n.localeCompare(b.n);
            });
            items = items.slice(0, 50).map(function (t) { return { tag: t, isNew: false }; });
            const typed = normalizeTag(input.value);
            let unknownType = false;
            if (typed !== '' && !knownTags.some(function (t) { return t.n === typed; }) && taken.indexOf(typed) === -1) {
                // a new tag needs a type from the list of tag types (E-023)
                const type = typed.indexOf(':') > 0 ? typed.split(':')[0] : '';
                if (knownTypes === null || (knownTypes.indexOf(type) !== -1 && exclude.indexOf(type) === -1)) {
                    items.push({ tag: tagInfo(typed), isNew: true });
                } else {
                    unknownType = true;
                }
            }
            box.textContent = '';
            items.forEach(function (it, i) {
                const li = document.createElement('li');
                li.dataset.index = i;
                if (it.isNew) {
                    const hint = document.createElement('span');
                    hint.className = 'muted';
                    hint.textContent = opts.labels.newTag + ': ';
                    li.appendChild(hint);
                }
                const chip = document.createElement('span');
                chip.className = it.tag.c;
                chip.textContent = it.tag.l;
                li.appendChild(chip);
                box.appendChild(li);
            });
            if (unknownType && opts.labels.unknownType) {
                const li = document.createElement('li');
                li.className = 'muted empty';
                li.textContent = opts.labels.unknownType + ' ' + knownTypes.filter(function (t) { return exclude.indexOf(t) === -1; }).join(', ');
                box.appendChild(li);
            } else if (items.length === 0) {
                const li = document.createElement('li');
                li.className = 'muted empty';
                li.textContent = opts.labels.none;
                box.appendChild(li);
            }
            active = items.length > 0 && q !== '' ? 0 : -1;
            highlight();
            box.hidden = false;
        };
        const highlight = function () {
            box.querySelectorAll('li[data-index]').forEach(function (li) {
                li.classList.toggle('active', Number(li.dataset.index) === active);
                if (Number(li.dataset.index) === active) li.scrollIntoView({ block: 'nearest' });
            });
        };
        const choose = function (i) {
            if (!items[i]) return;
            opts.pick(items[i].tag.n);
            if (opts.keepOpen) render(); else box.hidden = true;
        };

        input.addEventListener('focus', function () { if (opts.enabled()) render(); });
        input.addEventListener('input', function () { if (opts.enabled()) render(); });
        input.addEventListener('blur', function () { setTimeout(function () { box.hidden = true; }, 150); });
        input.addEventListener('keydown', function (e) {
            if (!opts.enabled()) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (box.hidden) render();
                if (items.length) active = (active + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
                highlight();
            } else if (e.key === 'Enter' && !box.hidden && active >= 0) {
                e.preventDefault();
                choose(active);
            } else if (e.key === 'Escape') {
                box.hidden = true;
            } else if (opts.onKey) {
                opts.onKey(e, render);
            }
        });
        box.addEventListener('mousedown', function (e) {
            const li = e.target.closest('li[data-index]');
            if (!li) return;
            e.preventDefault(); // keep the focus in the input
            choose(Number(li.dataset.index));
        });
    }

    document.querySelectorAll('input[data-tag-picker="multi"]').forEach(function (field) {
        const labels = { newTag: field.dataset.tagNew, none: field.dataset.tagNone, unknownType: field.dataset.tagUnknownType, remove: field.dataset.tagRemove };
        const exclude = (field.dataset.tagExclude || '').split(',').filter(Boolean);
        const wrap = document.createElement('div');
        wrap.className = 'tag-picker';
        const chips = document.createElement('span');
        chips.className = 'tag-picker-chips';
        const search = document.createElement('input');
        search.type = 'text';
        search.className = 'tag-picker-search';
        search.autocomplete = 'off';
        search.placeholder = field.dataset.tagPlaceholder || '';
        field.parentNode.insertBefore(wrap, field);
        wrap.appendChild(chips);
        wrap.appendChild(search);
        wrap.appendChild(field);
        field.type = 'hidden';
        const required = field.required;
        field.required = false;

        let selected = field.value.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
        const sync = function () {
            field.value = selected.join(', ');
            chips.textContent = '';
            selected.forEach(function (name) {
                const t = tagInfo(name);
                const chip = document.createElement('span');
                chip.className = t.c;
                chip.textContent = t.l;
                const x = document.createElement('button');
                x.type = 'button';
                x.className = 'tag-picker-remove';
                x.title = labels.remove;
                x.textContent = '×';
                x.addEventListener('click', function () {
                    selected = selected.filter(function (s) { return s !== name; });
                    sync();
                    search.focus();
                });
                chip.appendChild(x);
                chips.appendChild(chip);
            });
            search.required = required && selected.length === 0;
        };
        const add = function (name) {
            name = normalizeTag(name);
            if (name !== '' && selected.indexOf(name) === -1) selected.push(name);
            search.value = '';
            sync();
        };
        wrap.addEventListener('click', function (e) { if (e.target === wrap || e.target === chips) search.focus(); });
        attachList(search, {
            anchor: wrap, labels: labels, exclude: exclude, keepOpen: true,
            enabled: function () { return true; },
            selected: function () { return selected; },
            pick: add,
            onKey: function (e, render) {
                if ((e.key === 'Enter' || e.key === ',' || e.key === 'Tab') && search.value.trim() !== '') {
                    if (e.key !== 'Tab') e.preventDefault();
                    add(search.value);
                    render();
                } else if (e.key === 'Enter') {
                    e.preventDefault(); // Enter in the empty search field does not submit the form
                } else if (e.key === 'Backspace' && search.value === '' && selected.length) {
                    selected.pop();
                    sync();
                    render();
                }
            },
        });
        // A typed but not yet confirmed tag is not lost on submit
        field.form.addEventListener('submit', function () { if (search.value.trim() !== '') add(search.value); });
        sync();
    });

    const attachSingle = function (input) {
        if (input.dataset.tagPickerReady) return;
        input.dataset.tagPickerReady = '1';
        const row = input.closest('.cond-row');
        const holder = document.createElement('span');
        holder.className = 'tag-picker-single';
        input.parentNode.insertBefore(holder, input);
        holder.appendChild(input);
        attachList(input, {
            anchor: holder, labels: { newTag: input.dataset.tagNew, none: input.dataset.tagNone }, exclude: [], keepOpen: false,
            enabled: function () { return !row || row.querySelector('.cond-field').value === 'tag'; },
            selected: function () { return []; },
            pick: function (name) { input.value = name; },
        });
    };
    document.querySelectorAll('input[data-tag-picker="single"]').forEach(attachSingle);

    // Rule editor: add/remove condition rows, offer only the operators that fit the field
    const conditions = document.getElementById('conditions');
    if (conditions) {
        const syncOperators = function (row) {
            const group = row.querySelector('.cond-field').value === 'tag' ? 'tag' : 'text';
            const op = row.querySelector('.cond-op');
            let selectedOk = false;
            op.querySelectorAll('option').forEach(function (o) {
                o.hidden = o.disabled = o.dataset.group !== group;
                if (o.selected && !o.disabled) selectedOk = true;
            });
            if (!selectedOk) op.querySelector('option[data-group="' + group + '"]').selected = true;
        };
        conditions.querySelectorAll('.cond-row').forEach(syncOperators);
        conditions.addEventListener('change', function (e) {
            if (e.target.classList.contains('cond-field')) syncOperators(e.target.closest('.cond-row'));
        });
        conditions.addEventListener('click', function (e) {
            if (e.target.classList.contains('cond-remove') && conditions.querySelectorAll('.cond-row').length > 1) {
                e.target.closest('.cond-row').remove();
            }
        });
        document.getElementById('add-condition').addEventListener('click', function () {
            const row = document.getElementById('condition-template').content.firstElementChild.cloneNode(true);
            conditions.appendChild(row);
            syncOperators(row);
            row.querySelectorAll('input[data-tag-picker="single"]').forEach(attachSingle);
            row.querySelector('input').focus();
        });
    }

    const list = document.getElementById('message-list');
    const pane = document.getElementById('reading-pane');
    if (!list || !pane) return;

    function initPane() {
        pane.querySelectorAll('.tabs button').forEach(function (btn) {
            btn.addEventListener('click', function () { showTab(btn.dataset.tab); });
        });
        const active = pane.querySelector('.tabs button.active');
        if (active) showTab(active.dataset.tab);
    }

    function showTab(name) {
        pane.querySelectorAll('.tabs button').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === name); });
        pane.querySelectorAll('.tab').forEach(function (t) {
            const visible = t.dataset.tab === name;
            t.hidden = !visible;
            // Load the sandboxed HTML view only when it is shown
            const frame = t.querySelector('iframe[data-src]');
            if (visible && frame && !frame.src) frame.src = frame.dataset.src;
        });
    }

    function select(row, focus) {
        if (!row) return;
        list.querySelectorAll('tr.selected').forEach(function (r) { r.classList.remove('selected'); });
        row.classList.add('selected');
        if (focus) row.focus();
        const id = row.dataset.id;
        fetch(window.location.origin + '/messages/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
            .then(function (html) {
                pane.innerHTML = html;
                pane.scrollTop = 0;
                initPane();
                const url = new URL(window.location);
                url.searchParams.set('id', id);
                history.replaceState(null, '', url);
            })
            .catch(function (e) { pane.innerHTML = '<p class="error">Error ' + e.message + '</p>'; });
    }

    // smartphone: a message opens as its own page (list and pane are shown one at a time)
    const phone = window.matchMedia('(max-width: 760px)');

    list.addEventListener('click', function (e) {
        const row = e.target.closest('tr[data-id]');
        if (!row) return;
        e.preventDefault();
        if (phone.matches) {
            const link = row.querySelector('.col-subject a');
            if (link) window.location.href = link.href;
            return;
        }
        select(row, false);
    });

    list.addEventListener('keydown', function (e) {
        const current = document.activeElement.closest && document.activeElement.closest('tr[data-id]');
        if (!current) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            select(e.key === 'ArrowDown' ? current.nextElementSibling : current.previousElementSibling, true);
        } else if (e.key === 'Enter') {
            select(current, false);
        }
    });

    initPane();
})();

// Handling "waiting until": show the date field only for that status (0.3.30)
document.addEventListener('change', function (e) {
    if (!e.target.matches || !e.target.matches('select[data-handling]')) return;
    const date = e.target.form.querySelector('[data-handling-until]');
    if (!date) return;
    date.hidden = e.target.value !== 'waiting';
    date.required = e.target.value === 'waiting';
});
