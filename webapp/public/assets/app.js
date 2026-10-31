// Ravenkeep UI: load the reading pane without a page reload, keyboard navigation, body tabs.
(function () {
    'use strict';

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

    list.addEventListener('click', function (e) {
        const row = e.target.closest('tr[data-id]');
        if (!row) return;
        e.preventDefault();
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
