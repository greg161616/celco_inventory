'use strict';

(() => {
    const workspace = document.body;
    const menu = document.querySelector('#workspace-menu');
    const toggle = document.querySelector('[data-menu-toggle]');
    const overlay = document.querySelector('.sidebar-overlay');
    const mobile = window.matchMedia('(max-width: 900px)');
    const confirmedForms = new WeakSet();
    const busyForms = new WeakSet();
    const tones = ['info', 'success', 'warning', 'danger'];
    const colors = ['#0057b8', '#edb400', '#258375', '#64748b', '#a85b18', '#5472a4', '#704997'];

    function setDesktopPreference(compact) {
        try { localStorage.setItem('celco.sidebar.compact', compact ? '1' : '0'); } catch { /* Storage is optional. */ }
    }

    function closeMenu(restoreFocus = false) {
        workspace.classList.remove('menu-open');
        overlay.hidden = true;
        toggle.setAttribute('aria-expanded', String(!mobile.matches && !workspace.classList.contains('menu-compact')));
        if (restoreFocus) toggle.focus();
    }

    function initMenu() {
        if (!menu || !toggle || !overlay) return;
        try {
            workspace.classList.toggle('menu-compact', localStorage.getItem('celco.sidebar.compact') === '1');
        } catch { /* Use the full sidebar when browser storage is unavailable. */ }
        closeMenu();
        mobile.addEventListener('change', () => closeMenu());
        toggle.addEventListener('click', () => {
            if (!mobile.matches) {
                const compact = workspace.classList.toggle('menu-compact');
                toggle.setAttribute('aria-expanded', String(!compact));
                setDesktopPreference(compact);
                return;
            }
            workspace.classList.add('menu-open');
            overlay.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            menu.querySelector('[data-menu-close]').focus();
        });
        document.querySelectorAll('[data-menu-close]').forEach(button => {
            button.addEventListener('click', () => closeMenu(true));
        });
        menu.addEventListener('keydown', event => {
            if (!workspace.classList.contains('menu-open')) return;
            if (event.key === 'Escape') closeMenu(true);
            if (event.key !== 'Tab') return;
            const links = menu.querySelectorAll('a[href],button');
            const first = links[0];
            const last = links[links.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
        const selected = menu.querySelector('[aria-current="page"]');
        if (selected && !mobile.matches) selected.scrollIntoView({block: 'nearest'});
    }

    async function fetchJSON(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            credentials: 'same-origin',
            headers: {...options.headers, Accept: 'application/json'}
        });
        const type = response.headers.get('content-type') || '';
        if (!type.includes('application/json')) throw new Error('The server returned an unexpected response.');
        const result = await response.json();
        if (!response.ok || result.success !== true) {
            const error = new Error(result.message || 'The request could not be completed.');
            error.status = response.status;
            throw error;
        }
        return result.data;
    }

    function toast(message, level = 'info') {
        const region = document.querySelector('.toast-region');
        if (!region) return;
        const tone = tones.includes(level) ? level : 'info';
        const item = document.createElement('div');
        item.className = 'celco-toast alert alert-' + tone;
        item.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
        const content = document.createElement('span');
        content.textContent = message;
        const close = document.createElement('button');
        close.className = 'btn-close';
        close.type = 'button';
        close.setAttribute('aria-label', 'Dismiss message');
        close.addEventListener('click', () => item.remove());
        item.append(content, close);
        region.append(item);
        if (tone !== 'danger') window.setTimeout(() => item.remove(), 8000);
    }

    function initForms() {
        document.addEventListener('submit', async event => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
            const submitter = event.submitter;
            const prompt = submitter?.dataset.confirm || form.dataset.confirm;
            if (busyForms.has(form)) {
                event.preventDefault();
                return;
            }
            if (prompt && !confirmedForms.has(form)) {
                event.preventDefault();
                busyForms.add(form);
                try {
                    const accepted = window.Swal
                        ? (await window.Swal.fire({
                            titleText: prompt, icon: 'question', showCancelButton: true, focusCancel: true,
                            confirmButtonText: 'Confirm', cancelButtonText: 'Cancel', buttonsStyling: false,
                            customClass: {confirmButton: 'btn btn-primary', cancelButton: 'btn btn-light'}
                        })).isConfirmed
                        : window.confirm(prompt);
                    busyForms.delete(form);
                    if (accepted) {
                        confirmedForms.add(form);
                        submitter ? form.requestSubmit(submitter) : form.requestSubmit();
                    }
                } finally {
                    busyForms.delete(form);
                    confirmedForms.delete(form);
                }
                return;
            }
            confirmedForms.delete(form);
            if (form.method.toLowerCase() !== 'post' || form.target === '_blank') return;
            busyForms.add(form);
            // Native form data must retain the name/value of the clicked action button.
            window.setTimeout(() => {
                if (event.defaultPrevented) { busyForms.delete(form); return; }
                form.querySelectorAll('button[type="submit"],button:not([type]),input[type="submit"]').forEach(button => {
                    if (!button.disabled) { button.disabled = true; button.dataset.submitting = '1'; }
                });
                form.setAttribute('aria-busy', 'true');
            }, 0);
        });
        window.addEventListener('pageshow', () => {
            document.querySelectorAll('form[aria-busy="true"]').forEach(form => {
                form.removeAttribute('aria-busy');
                busyForms.delete(form);
                form.querySelectorAll('[data-submitting]').forEach(button => {
                    button.disabled = false;
                    delete button.dataset.submitting;
                });
            });
        });
        document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));
        document.querySelectorAll('[data-flash]').forEach(message => {
            toast(message.textContent.trim(), message.dataset.flash);
            message.hidden = true;
        });
    }

    function initNotifications() {
        const container = document.querySelector('[data-notifications]');
        if (!container) return;
        const badge = container.querySelector('.unread-count');
        const list = container.querySelector('.notification-items');
        const button = container.querySelector('button');
        let pending = false;
        let expired = false;
        function notice(text) {
            const line = document.createElement('p');
            line.textContent = text;
            list.replaceChildren(line);
        }
        async function refresh() {
            if (pending || expired || document.hidden) return;
            pending = true;
            try {
                const data = await fetchJSON(container.dataset.notifications);
                const count = Math.max(0, Number(data.unread) || 0);
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.hidden = count === 0;
                button.setAttribute('aria-label', 'Notifications, ' + count + ' unread');
                const entries = document.createDocumentFragment();
                for (const item of (data.items || []).slice(0, 6)) {
                    const row = document.createElement('div');
                    const title = document.createElement('strong');
                    title.textContent = item.title || 'Notification';
                    const message = document.createElement('small');
                    message.textContent = item.message || '';
                    row.append(title, message);
                    entries.append(row);
                }
                if (entries.childNodes.length) list.replaceChildren(entries);
                else notice('No notifications.');
            } catch (error) {
                expired = error.status === 401 || error.status === 403;
                notice(expired ? error.message : 'Notifications could not be loaded.');
            } finally {
                pending = false;
            }
        }
        notice('Loading notifications…');
        refresh();
        container.addEventListener('show.bs.dropdown', refresh);
        window.setInterval(refresh, 60000);
    }

    function initChartsAndTables(root = document) {
        root.querySelectorAll('table[data-table]').forEach(table => {
            if (window.DataTable && !window.DataTable.isDataTable(table) && !table.querySelector('tbody [colspan]')) {
                new window.DataTable(table, {pageLength: 25, order: []});
            }
        });
        if (!window.Chart) return;
        window.Chart.defaults.font.family = 'Inter, Segoe UI, Roboto, Arial, sans-serif';
        window.Chart.defaults.color = '#52667b';
        root.querySelectorAll('canvas[data-chart]').forEach(canvas => {
            try {
                const config = JSON.parse(canvas.dataset.chart);
                const radial = ['doughnut', 'pie', 'polarArea'].includes(config.type);
                config.data.datasets.forEach((series, i) => {
                    series.backgroundColor ??= radial ? colors : colors[i % colors.length];
                    series.borderColor ??= radial ? '#fff' : colors[i % colors.length];
                    series.borderWidth ??= radial ? 2 : 1.5;
                    if (config.type === 'bar') series.borderRadius ??= 3;
                });
                config.options ??= {};
                config.options.responsive = true;
                config.options.maintainAspectRatio = false;
                config.options.animation = false;
                config.options.plugins ??= {};
                config.options.plugins.legend ??= {display: radial || config.data.datasets.length > 1, position: 'bottom'};
                new window.Chart(canvas, config);
            } catch {
                const message = document.createElement('p');
                message.className = 'empty-state';
                message.textContent = 'This chart could not be displayed.';
                canvas.replaceWith(message);
            }
        });
    }

    function initDashboard() {
        const filter = document.querySelector('[data-dashboard-filter]');
        const results = document.querySelector('#dashboard-results');
        if (!filter || !results) return;
        const button = filter.querySelector('button[type="submit"],button:not([type])');
        let pending = false;
        filter.addEventListener('submit', async event => {
            event.preventDefault();
            if (pending) return;
            pending = true;
            button.disabled = true;
            results.setAttribute('aria-busy', 'true');
            const query = new URLSearchParams(new FormData(filter));
            try {
                const data = await fetchJSON(workspace.dataset.baseUrl + '/api/dashboard.php?' + query);
                if (typeof data.html !== 'string') throw new Error('The server returned an unexpected response.');
                results.querySelectorAll('canvas').forEach(canvas => window.Chart?.getChart(canvas)?.destroy());
                // This endpoint returns escaped markup from the same PHP renderer as the full page.
                results.innerHTML = data.html;
                initChartsAndTables(results);
                history.replaceState(null, '', location.pathname + '?' + query);
                toast('Overview updated.', 'success');
            } catch (error) {
                toast(error.message || 'The overview could not be updated.', 'danger');
            } finally {
                pending = false;
                results.removeAttribute('aria-busy');
                button.disabled = false;
            }
        });
    }

    window.CelcoUI = Object.freeze({fetchJSON, toast});
    document.addEventListener('change',async event=>{
        const department=event.target;
        if(department.name!=='department_id') return;
        const form=department.form;
        if(!form || (!form.elements.project_id && !form.elements.recipient_employee_id)) return;
        const selectedDepartment=department.value;
        try {
            const directory=await fetchJSON(workspace.dataset.baseUrl+'/api/directory.php?department_id='+encodeURIComponent(selectedDepartment));
            if(department.value!==selectedDepartment) return;
            for(const [name,records] of Object.entries(directory)){
                const select=form.elements[name];
                if(!(select instanceof HTMLSelectElement)) continue;
                const old=select.value;
                select.replaceChildren(new Option('Select…',''),...records.map(r=>new Option(r.label,r.id)));
                if(records.some(r=>String(r.id)===old)) select.value=old;
            }
        } catch(error){toast(error.message,'danger');}
    });
    initMenu();
    initForms();
    initNotifications();
    initChartsAndTables();
    initDashboard();
})();
