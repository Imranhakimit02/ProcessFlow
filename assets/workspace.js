/**
 * File: assets/workspace.js
 * Purpose: Plain HTML record filtering and workspace navigation.
 * Author: Imran IT Intern
 *
 * These handlers switch workspace sections, filter records and manage project
 * dialog controls.
 */

// --------------------------------------
// Initialize workspace controls
// --------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    document.documentElement.classList.add('menus-ready');
    document.querySelectorAll('[data-viewer-autofilter]').forEach((form) => {
        form.addEventListener('submit', () => {
            const destination = new URL(form.action);
            destination.hash = form.dataset.filterSection || location.hash;
            form.action = destination.href;
        });
        form.querySelectorAll('select, input[type="date"]').forEach((field) => {
            field.addEventListener('change', () => {
                if (form.reportValidity()) form.requestSubmit();
            });
        });
    });
    document.querySelectorAll('[data-bubble-navigation]').forEach((navigation) => {
        const choices = [...navigation.querySelectorAll('[data-bubble-group]')];
        const panels = [...navigation.querySelectorAll('[data-bubble-panel]')];
        let selected = navigation.dataset.selectedGroup;
        function selectGroup(group) {
            selected = group;
            choices.forEach((choice) => {
                choice.hidden = false;
                choice.setAttribute('aria-expanded', String(choice.dataset.bubbleGroup === selected));
            });
            panels.forEach((panel) => {
                panel.hidden = panel.dataset.bubblePanel !== selected;
            });
        }
        choices.forEach((choice) =>
            choice.addEventListener('click', () => {
                selectGroup(selected === choice.dataset.bubbleGroup ? null : choice.dataset.bubbleGroup);
            }),
        );
        navigation.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                const active = choices.find((choice) => choice.dataset.bubbleGroup === selected);
                selectGroup(null);
                active?.focus();
            }
        });
        selectGroup(selected);
    });
    document.querySelectorAll('[data-menu-toggle]').forEach((toggle) => {
        const menu = document.getElementById(toggle.dataset.menuToggle);
        const compact = window.matchMedia('(max-width: 760px)');
        function setExpanded(expanded) {
            toggle.setAttribute('aria-expanded', String(expanded));
            menu.hidden = !expanded;
        }
        function updateLayout() {
            toggle.hidden = !compact.matches;
            setExpanded(!compact.matches);
        }
        toggle.addEventListener('click', () => setExpanded(toggle.getAttribute('aria-expanded') !== 'true'));
        compact.addEventListener('change', updateLayout);
        menu.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && compact.matches) {
                setExpanded(false);
                toggle.focus();
            }
        });
        updateLayout();
    });
    // Enhance presentation without changing form actions or workflow data.
    document.querySelectorAll('table').forEach((table) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'table-scroll';
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute('aria-label', table.getAttribute('aria-label') || 'Scrollable records table');
        table.before(wrapper);
        wrapper.append(table);
    });
    document.querySelectorAll('button').forEach((button) => {
        const action = (button.name + ' ' + button.value + ' ' + button.textContent).toLowerCase();
        if (
            /delete|reject|deny|deactivate/.test(action) ||
            (button.name === 'set_user_active' && button.value === '0')
        ) {
            button.dataset.intent = 'danger';
        } else if (/approve|activate/.test(action) || (button.name === 'set_user_active' && button.value === '1')) {
            button.dataset.intent = 'success';
        } else if (/cancel|clear|close|back|sign out/.test(action)) {
            button.dataset.intent = 'secondary';
        }
    });
    document.querySelectorAll('summary > span, td > span').forEach((label) => {
        const status = label.textContent.trim().toLowerCase();
        if (status === 'active' || status === 'inactive') {
            label.classList.add('status-label');
            label.dataset.status = status;
        }
    });

    // --------------------------------------
    // SECTION: Filter administration records
    // --------------------------------------
    document.querySelectorAll('[data-search-target]').forEach((input) => {
        const target = input.dataset.searchTarget;
        const rows = Array.from(document.querySelectorAll('[data-search-group]')).filter(
            (row) => row.dataset.searchGroup === target,
        );
        const counter = document.querySelector('[data-search-count="' + target + '"]');

        function filterRecords() {
            const query = input.value.trim().toLocaleLowerCase();
            let visible = 0;
            rows.forEach((row) => {
                const details = row.tagName === 'DETAILS' ? row.querySelector('summary').textContent : row.textContent;
                const values = Array.from(row.querySelectorAll('input:not([type=hidden]), select'))
                    .map((field) => (field.tagName === 'SELECT' ? field.selectedOptions[0]?.textContent : field.value))
                    .join(' ');
                row.hidden = !(details + ' ' + values).toLocaleLowerCase().includes(query);
                if (!row.hidden) visible++;
            });
            if (counter) counter.textContent = 'Showing ' + visible + ' of ' + rows.length + ' records';
        }

        input.addEventListener('input', filterRecords);
        filterRecords();
    });

    // ----------------------------------
    // SECTION: Switch workspace sections
    // ----------------------------------
    const workspaceLinks = Array.from(document.querySelectorAll('#workspace-subnavigation a'));
    const workspacePanels = Array.from(document.querySelectorAll('[data-workspace-panel]'));
    function highlightWorkspaceLink() {
        const pageLinks = workspaceLinks.filter((link) => link.pathname === location.pathname);
        const sectionLink = location.hash && pageLinks.find((link) => link.hash === location.hash);
        const activeLink = sectionLink || pageLinks.find((link) => !link.hash) || pageLinks[0];
        workspaceLinks.forEach((link) => {
            if (link === activeLink) link.setAttribute('aria-current', sectionLink ? 'location' : 'page');
            else link.removeAttribute('aria-current');
        });
        const target = document.getElementById(location.hash.slice(1));
        const activePanel =
            target?.closest('[data-workspace-panel]') ||
            workspacePanels.find((panel) => panel.hasAttribute('data-workspace-default')) ||
            workspacePanels[0];
        workspacePanels.forEach((panel) => {
            panel.hidden = panel !== activePanel;
        });
    }
    highlightWorkspaceLink();
    window.addEventListener('hashchange', highlightWorkspaceLink);
    window.addEventListener('popstate', highlightWorkspaceLink);
    workspaceLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            if (!link.getAttribute('href').startsWith('#')) return;
            const panel = document.getElementById(link.hash.slice(1));
            if (!workspacePanels.includes(panel)) return;
            event.preventDefault();
            if (location.hash !== link.hash) history.pushState(null, '', link.hash);
            highlightWorkspaceLink();
        });
    });

    // ------------------------------------------
    // SECTION: Keep the submenu when filtering
    // ------------------------------------------
    const dashboardFilter = document.getElementById('dashboard-summary');
    if (dashboardFilter) {
        dashboardFilter.addEventListener('submit', () => {
            const activePanel = workspacePanels.find((panel) => !panel.hidden);
            if (activePanel) {
                const destination = new URL(dashboardFilter.action);
                destination.hash = activePanel.id;
                dashboardFilter.action = destination.href;
            }
        });
    }
});
