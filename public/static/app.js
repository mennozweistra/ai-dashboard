// Bind a checkbox's checked state to a localStorage key, restoring it on
// load and persisting changes. If no value is stored yet (first visit),
// the checkbox's template-rendered default is left untouched. The explicit
// stored === '0' branch matters for checkboxes whose Twig-rendered default
// is `checked` (open): without it, such a checkbox could never be restored
// to closed.
function bindPersistedToggle(checkbox, storageKey) {
    if (!checkbox) return;
    var stored = localStorage.getItem(storageKey);
    if (stored === '1') checkbox.checked = true;
    else if (stored === '0') checkbox.checked = false;
    checkbox.addEventListener('change', function () {
        localStorage.setItem(storageKey, checkbox.checked ? '1' : '0');
    });
}

document.addEventListener('DOMContentLoaded', function () {
    var PREF_KEY = 'tm_show_done';
    var url = new URL(window.location.href);
    var inUrl = url.searchParams.get('show_done') === '1';
    var checkbox = document.querySelector('input[name="show_done"]');

    if (checkbox) {
        var stored = localStorage.getItem(PREF_KEY);

        // If preference is on but not in URL yet, redirect to add it
        if (!inUrl && stored === '1') {
            url.searchParams.set('show_done', '1');
            window.location.replace(url.toString());
            return;
        }

        // Sync URL → localStorage (only on pages that have the toggle)
        localStorage.setItem(PREF_KEY, inUrl ? '1' : '0');

        // Keep localStorage in sync when the user changes the toggle
        checkbox.addEventListener('change', function () {
            localStorage.setItem(PREF_KEY, checkbox.checked ? '1' : '0');
        });
    }
    // On pages without the toggle, do not touch localStorage at all

    // Persist the log section (whole list) open/closed state across page refreshes.
    bindPersistedToggle(document.querySelector('#logs-toggle'), 'tm_logs_open');

    // Persist the Details, Requirements, Questions, and Phases section
    // open/closed states across page refreshes. All four default open
    // (Twig hardcodes `checked`).
    bindPersistedToggle(document.querySelector('#details-toggle'), 'tm_details_open');
    bindPersistedToggle(document.querySelector('#requirements-toggle'), 'tm_requirements_open');
    bindPersistedToggle(document.querySelector('#questions-toggle'), 'tm_questions_open');
    bindPersistedToggle(document.querySelector('#phases-toggle'), 'tm_phases_open');

    // Persist per-log-entry detail open/closed state across page refreshes.
    document.querySelectorAll('.log-detail-toggle').forEach(function (el) {
        var entry = el.closest('.log-entry');
        var logId = entry ? entry.dataset.logId : el.id.replace('log-detail-toggle-', '');
        bindPersistedToggle(el, 'tm_log_detail_open_' + logId);
    });

    // Persist phase details open/closed state across page refreshes.
    document.querySelectorAll('details[data-phase-id]').forEach(function (el) {
        var key = 'tm_phase_open_' + el.dataset.phaseId;
        if (localStorage.getItem(key) === '1') {
            el.open = true;
        }
        el.addEventListener('toggle', function () {
            localStorage.setItem(key, el.open ? '1' : '0');
        });
    });

    // ---------- Reusable hover-reveal view link + panel edit-lock mode ----------
    // (ticket 153 task 1267, requirements 126/127)
    //
    // Contract for panels built by later tasks (ticket / phase / task panels):
    //   - `.row-view-link` sits inside a hoverable row container
    //     (`.ticket-head-line`, `.phase > summary`, `.task`) and is revealed
    //     on hover purely by CSS (see style.css). It opens the corresponding
    //     panel read-only, never in edit mode.
    //   - The panel element itself carries `data-mode="view"` (default) or
    //     `data-mode="edit"`. Both the read-only markup (`.panel-view`) and
    //     the edit `<form>` (`.panel-edit`) are always rendered by the
    //     server; CSS shows exactly one of the two based on `data-mode`.
    //   - Clicking `.edit-lock` anywhere inside a panel toggles that panel's
    //     `data-mode`. This is pure client-side UI state — it is not
    //     persisted and is not read from localStorage — so a fresh page
    //     load or a freshly (re)opened panel always starts at "view".
    //
    // initRowViewLinks(root) guards `.row-view-link` elements that live
    // inside a <summary> (e.g. a future phase-panel link) so opening the
    // panel does not also toggle the enclosing <details>. It is idempotent
    // and safe to call again after inserting new `.row-view-link` markup
    // into the DOM (for example after cloning a <template> into a dialog).
    function initRowViewLinks(root) {
        (root || document).querySelectorAll('.row-view-link').forEach(function (link) {
            if (link.dataset.rowViewLinkBound) return;
            link.dataset.rowViewLinkBound = '1';
            if (link.closest('summary')) {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                });
            }
        });
    }
    initRowViewLinks();

    // Edit-lock toggle: delegated on document so it also covers panel
    // content inserted into the DOM later (e.g. cloned <template> content,
    // as the task panel below does).
    document.addEventListener('click', function (e) {
        var lock = e.target.closest('.edit-lock');
        if (!lock) return;
        var panel = lock.closest('[data-mode]');
        if (!panel) return;
        panel.dataset.mode = panel.dataset.mode === 'edit' ? 'view' : 'edit';
    });

    var dialog = document.getElementById('task-panel');
    if (dialog) {
        function markRowOpen(id) {
            document.querySelectorAll('.task.is-open').forEach(function (el) {
                el.classList.remove('is-open');
            });
            if (id) {
                var row = document.querySelector('.task[data-task-id="' + id + '"]');
                if (row) row.classList.add('is-open');
            }
        }
        // `mode` defaults to 'view' for the normal open paths below (row
        // click, `?task=<id>` on load). Passing 'edit' is only done by the
        // `data-open-on-load` handling further down, for a failed
        // `POST /task/{id}/edit` re-render (ticket 153 task 1270) — that
        // path intentionally skips the URL bookkeeping below, mirroring
        // `#phase-panel`'s `data-open-on-load` handling, which never touches
        // the URL either.
        function openTask(id, mode) {
            var tpl = document.getElementById('task-data-' + id);
            if (!tpl) return;
            var isEditReopen = mode === 'edit';
            dialog.replaceChildren(tpl.content.cloneNode(true));
            dialog.dataset.mode = isEditReopen ? 'edit' : 'view';
            dialog.showModal();
            markRowOpen(id);
            if (!isEditReopen) {
                var url = new URL(window.location.href);
                url.searchParams.set('task', id);
                history.replaceState(null, '', url.toString());
            }
        }
        function closeTask() {
            if (dialog.open) dialog.close();
            // close handler below removes the URL param and the row highlight
        }
        // Row click: open panel
        document.querySelectorAll('.task[data-task-id]').forEach(function (row) {
            row.addEventListener('click', function () { openTask(row.dataset.taskId); });
            row.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openTask(row.dataset.taskId);
                }
            });
        });
        // Backdrop click (no close button in the new design; ESC + backdrop are the close paths)
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) {
                closeTask();
            }
        });
        // Close handler (fires on ESC, dialog.close(), or backdrop close) — remove URL param and row highlight
        dialog.addEventListener('close', function () {
            markRowOpen(null);
            var url = new URL(window.location.href);
            if (url.searchParams.has('task')) {
                url.searchParams.delete('task');
                history.replaceState(null, '', url.toString());
            }
        });
        // On load, if ?task= is in URL, open it read-only.
        var initialTaskId = new URL(window.location.href).searchParams.get('task');
        if (initialTaskId) {
            openTask(initialTaskId);
        } else if (dialog.hasAttribute('data-open-on-load')) {
            // A failed `POST /task/{id}/edit` re-render (ticket 153 task
            // 1270) sets `data-open-on-load` + `data-open-task-id` on this
            // dialog so the task panel opens already in edit mode with the
            // error and preserved field values baked into its <template> —
            // the same mechanism `#phase-panel` uses for a failed
            // `POST /phase/{id}/edit`.
            var openTaskId = dialog.dataset.openTaskId;
            if (openTaskId) {
                openTask(openTaskId, 'edit');
            }
        }
    }

    // Ticket header panel: single dialog#ticket-panel, server-rendered in
    // full (no per-id <template> cloning, unlike task-panel, since there is
    // only one ticket per page). Mirrors task-panel's open/close mechanics
    // (backdrop click + native ESC close) but has no `?task=<id>`-style URL
    // state, since requirement 132 requires a plain full-page reload on
    // save with no query-parameter bookkeeping.
    var ticketDialog = document.getElementById('ticket-panel');
    if (ticketDialog) {
        function openTicketPanel() {
            // Clicking the row-view-link always opens read-only (§8.1a);
            // data-mode resets here even if a previous open left it in
            // edit mode. The server-driven auto-open-on-load path below
            // calls showModal() directly instead, so it keeps whatever
            // data-mode the server rendered.
            ticketDialog.dataset.mode = 'view';
            ticketDialog.showModal();
        }
        document.querySelectorAll('.row-view-link[data-ticket-id]').forEach(function (link) {
            link.addEventListener('click', function () { openTicketPanel(); });
        });
        ticketDialog.addEventListener('click', function (e) {
            if (e.target === ticketDialog && ticketDialog.open) {
                ticketDialog.close();
            }
        });
        // If the server rendered an edit error (a failed save re-render),
        // open the panel already in the edit mode the server set via
        // data-mode, instead of resetting to view.
        if (ticketDialog.hasAttribute('data-open-on-load')) {
            ticketDialog.showModal();
        }
    }

    // Phase panel: single shared dialog#phase-panel, populated by cloning a
    // per-phase <template id="phase-data-<id>"> into it, following the same
    // cloning mechanism dialog#task-panel uses above — there can be several
    // phases per ticket, so (unlike the single-per-page ticket panel) the
    // markup for each phase's panel content lives in its own <template> next
    // to that phase's <summary>, and only the currently open phase's content
    // is ever present inside the dialog.
    //
    // Unlike the task row (whose whole row opens the panel), only the
    // `.row-view-link` inside the phase's <summary> opens this panel — the
    // rest of the row keeps the native <details> expand/collapse behaviour
    // (requirement 127's carve-out). initRowViewLinks() above already binds
    // preventDefault()/stopPropagation() to `.row-view-link` elements that
    // sit inside a <summary>, so that carve-out needs no extra code here.
    var phaseDialog = document.getElementById('phase-panel');
    if (phaseDialog) {
        function openPhase(id, mode) {
            var tpl = document.getElementById('phase-data-' + id);
            if (!tpl) return;
            phaseDialog.replaceChildren(tpl.content.cloneNode(true));
            phaseDialog.dataset.mode = mode || 'view';
            // Tracks which phase's content the dialog currently holds, so
            // the click-to-cycle status feature (ticket 159) below knows
            // whether to keep this open panel in sync with a status change.
            phaseDialog.dataset.openPhaseId = String(id);
            phaseDialog.showModal();
        }
        document.querySelectorAll('.row-view-link[data-phase-id]').forEach(function (link) {
            link.addEventListener('click', function () { openPhase(link.dataset.phaseId, 'view'); });
        });
        phaseDialog.addEventListener('click', function (e) {
            if (e.target === phaseDialog && phaseDialog.open) {
                phaseDialog.close();
            }
        });
        // If the server rendered a phase edit error (a failed
        // `POST /phase/{id}/edit` re-render), open that phase's panel
        // already populated with the error and the preserved field values
        // baked into its <template>, in edit mode — mirroring the ticket
        // panel's `data-open-on-load` handling below, generalised with
        // `data-open-phase-id` since more than one phase can exist.
        if (phaseDialog.hasAttribute('data-open-on-load')) {
            var openPhaseId = phaseDialog.dataset.openPhaseId;
            if (openPhaseId) {
                openPhase(openPhaseId, 'edit');
            }
        }
    }

    // Create-ticket popup: single dialog#create-ticket-panel on the project
    // page (ticket 160 task 1433, requirements 154/155). Mirrors
    // dialog#ticket-panel's open/close mechanics (backdrop click + native
    // ESC close) and its `data-open-on-load` auto-open convention for a
    // failed submit re-render, but the popup has no read/edit toggle — it
    // only ever shows the create form, so unlike the other panels it carries
    // no `data-mode` and no `.edit-lock`.
    var createTicketDialog = document.getElementById('create-ticket-panel');
    if (createTicketDialog) {
        // Remember the template choice per browser (so a different machine can
        // keep a different default) in localStorage. It is written the moment
        // the <select> value changes, not on submit, and re-applied whenever
        // the popup is opened fresh via the "+" button. A failed-submit
        // re-render (`data-open-on-load`) deliberately does NOT apply it — the
        // server already re-selected the value the user just submitted.
        var TEMPLATE_PREF_KEY = 'aiDashboard.createTicketTemplate';
        var createTicketTemplateSelect = document.getElementById('create-ticket-template');

        if (createTicketTemplateSelect) {
            createTicketTemplateSelect.addEventListener('change', function () {
                try {
                    localStorage.setItem(TEMPLATE_PREF_KEY, createTicketTemplateSelect.value);
                } catch (e) { /* storage unavailable — silently skip */ }
            });
        }

        function applyStoredTemplatePreference() {
            if (!createTicketTemplateSelect) return;
            var stored;
            try {
                stored = localStorage.getItem(TEMPLATE_PREF_KEY);
            } catch (e) { return; }
            if (stored === null) return;
            // Only apply a stored value that is still an actual option (a
            // template may have been removed since it was last chosen).
            var exists = Array.prototype.some.call(createTicketTemplateSelect.options, function (opt) {
                return opt.value === stored;
            });
            if (exists) createTicketTemplateSelect.value = stored;
        }

        var createTicketBtn = document.querySelector('.create-ticket-btn');
        if (createTicketBtn) {
            createTicketBtn.addEventListener('click', function () {
                applyStoredTemplatePreference();
                createTicketDialog.showModal();
            });
        }
        // Backdrop click dismisses (Escape dismisses natively via <dialog>).
        createTicketDialog.addEventListener('click', function (e) {
            if (e.target === createTicketDialog && createTicketDialog.open) {
                createTicketDialog.close();
            }
        });
        // A failed `POST /project/{id}/ticket/create` re-render (a later
        // route task) sets `data-open-on-load` on this dialog so the popup
        // opens already showing the parsed error and the preserved
        // title/description/template values baked into the server-rendered
        // form — the same mechanism dialog#ticket-panel uses for a failed
        // `POST /ticket/{id}/edit`.
        if (createTicketDialog.hasAttribute('data-open-on-load')) {
            createTicketDialog.showModal();
        }

        // Keep the submit button disabled until both title and description
        // carry a non-empty value, so an empty create-ticket call can never
        // be submitted to the (not-yet-existing) create route.
        var createTicketTitle = document.getElementById('create-ticket-title');
        var createTicketDescription = document.getElementById('create-ticket-description');
        var createTicketSave = createTicketDialog.querySelector('.create-ticket-save');
        if (createTicketTitle && createTicketDescription && createTicketSave) {
            function updateCreateTicketSaveState() {
                var hasTitle = createTicketTitle.value.trim() !== '';
                var hasDescription = createTicketDescription.value.trim() !== '';
                createTicketSave.disabled = !(hasTitle && hasDescription);
            }
            createTicketTitle.addEventListener('input', updateCreateTicketSaveState);
            createTicketDescription.addEventListener('input', updateCreateTicketSaveState);
            updateCreateTicketSaveState();
        }
    }

    // IDE button: background fetch POST to the open-ide route. Success (2xx)
    // is silent — the editor coming to the front is the only feedback.
    // Failure (non-2xx or a network error) shows the returned plain-text
    // body in a centered, dismiss-only error dialog (ticket 164 task 1592).
    //
    // showTerminalError/terminalErrorDialog are declared outside the guard
    // below so the click-to-cycle status feature added under ticket 159 can
    // reuse the same dialog for its own failure reporting (requirement
    // 171). The dialog markup is unconditionally rendered by every page, so
    // this is always defined; the guard below still only wires up the
    // IDE-button-specific listener when the dialog is present.
    var terminalErrorDialog = document.getElementById('terminal-error');
    var terminalErrorMessage = terminalErrorDialog ? terminalErrorDialog.querySelector('.terminal-error-message') : null;

    function showTerminalError(text) {
        if (!terminalErrorDialog) return;
        if (terminalErrorMessage) {
            terminalErrorMessage.textContent = text || 'Something went wrong.';
        }
        terminalErrorDialog.showModal();
    }

    if (terminalErrorDialog) {
        var terminalErrorClose = terminalErrorDialog.querySelector('.terminal-error-close');

        if (terminalErrorClose) {
            terminalErrorClose.addEventListener('click', function () {
                terminalErrorDialog.close();
            });
        }
        // Backdrop click dismisses (Escape dismisses natively via <dialog>).
        terminalErrorDialog.addEventListener('click', function (e) {
            if (e.target === terminalErrorDialog) {
                terminalErrorDialog.close();
            }
        });
        // Clear the message on close so stale text never flashes on reopen.
        terminalErrorDialog.addEventListener('close', function () {
            if (terminalErrorMessage) terminalErrorMessage.textContent = '';
        });

        // IDE button (ticket 164 task 1592, requirement 204): only rendered
        // by the template when an IDE command is configured, so the
        // selector matches zero elements otherwise and this loop is a
        // no-op.
        var GENERIC_IDE_ERROR = 'Could not open the IDE.';

        document.querySelectorAll('.ticket-ide-btn[data-ticket-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var ticketId = btn.dataset.ticketId;
                fetch('/ticket/' + ticketId + '/open-ide', { method: 'POST' })
                    .then(function (response) {
                        if (response.ok) return;
                        return response.text().then(function (body) {
                            showTerminalError(body);
                        });
                    })
                    .catch(function () {
                        showTerminalError(GENERIC_IDE_ERROR);
                    });
            });
        });
    }

    // ---------- Click-to-cycle task/phase status ----------
    // (ticket 159, requirements 161/162/163/164/171/173)
    //
    // Clicking a `.status-cycle` status word (`.task-status` or
    // `.phase-status`) steps the DISPLAYED status locally, with no request.
    // After the last click in a burst, one debounced fetch POST sends the
    // settled value to `/task/{id}/status` or `/phase/{id}/status`. The
    // JSON response on success carries the task/phase/ticket statuses that
    // `bin/tm`'s rollup produced; every one present is applied to the page.
    // A non-2xx response or a network error reverts to the server-confirmed
    // statuses and reuses the shared error dialog above.
    var TASK_STATUS_CYCLE = ['pending', 'active', 'blocked', 'done', 'skipped'];
    var PHASE_STATUS_CYCLE = ['pending', 'active', 'done', 'skipped'];
    var STATUS_SETTLE_DELAY_MS = 700;
    var GENERIC_STATUS_ERROR = 'Could not update status.';

    // Per-entity ("task:<id>" / "phase:<id>") cycling state: the last
    // server-confirmed status, the pending debounce timer, whether a send
    // is currently in flight, and the most recently settled value that
    // arrived while a send was in flight — sent once that send completes
    // (requirement 164's "last settled value wins, no queue" rule).
    var statusCycleState = {};

    function statusCycleNext(current, cycle) {
        var idx = cycle.indexOf(current);
        return idx === -1 ? cycle[0] : cycle[(idx + 1) % cycle.length];
    }

    // Locates the `<dd>` following the `<dt>Status</dt>` row inside a
    // panel's `.task-panel-meta` list (both the task panel and the phase
    // panel reuse the same `.task-panel-meta` structure) and updates it.
    function setStatusMetaDd(root, status) {
        var dl = root.querySelector ? root.querySelector('.task-panel-meta dl') : null;
        if (!dl) return;
        dl.querySelectorAll('dt').forEach(function (dt) {
            if (dt.textContent.trim() !== 'Status') return;
            var dd = dt.nextElementSibling;
            if (dd && dd.tagName === 'DD') dd.textContent = status;
        });
    }

    // Updates the duplicate status rendering carried by a task/phase panel:
    // the panel header's `data-status` attribute and marker, plus the
    // meta-list status value. `root` is either a `<template>`'s `.content`
    // (kept in sync so a future open renders correctly) or a live, already
    // open `dialog` element (kept in sync so it does not go stale while
    // open).
    function applyPanelHeaderStatus(root, status) {
        if (!root || !root.querySelector) return;
        var header = root.querySelector('.task-panel-header');
        if (header) {
            header.dataset.status = status;
            var marker = header.querySelector('.marker');
            if (marker) marker.className = 'marker marker-' + status;
        }
        setStatusMetaDd(root, status);
    }

    function applyTaskStatusEverywhere(taskId, status) {
        var isOpen = false;
        document.querySelectorAll('.task[data-task-id="' + taskId + '"]').forEach(function (row) {
            row.dataset.status = status;
            var marker = row.querySelector('.marker');
            if (marker) marker.className = 'marker marker-' + status;
            var word = row.querySelector('.task-status');
            if (word) word.textContent = status;
            if (row.classList.contains('is-open')) isOpen = true;
        });
        var tpl = document.getElementById('task-data-' + taskId);
        if (tpl) applyPanelHeaderStatus(tpl.content, status);
        // `.task[data-task-id].is-open` (maintained by markRowOpen above)
        // is only present while dialog#task-panel is open for this task, so
        // this also keeps the live open panel in sync.
        if (isOpen) {
            var openTaskDialog = document.getElementById('task-panel');
            if (openTaskDialog) applyPanelHeaderStatus(openTaskDialog, status);
        }
    }

    function applyPhaseStatusEverywhere(phaseId, status) {
        document.querySelectorAll('details.phase[data-phase-id="' + phaseId + '"]').forEach(function (details) {
            details.dataset.status = status;
            var marker = details.querySelector('summary > .marker');
            if (marker) marker.className = 'marker marker-' + status;
            var word = details.querySelector('summary > .phase-status');
            if (word) word.textContent = status;
        });
        var tpl = document.getElementById('phase-data-' + phaseId);
        if (tpl) applyPanelHeaderStatus(tpl.content, status);
        // dialog#phase-panel does not have an equivalent to task-panel's
        // `.is-open` row-class mechanism, so openPhase() (below) stamps
        // `data-open-phase-id` on the dialog itself when it populates it;
        // that is what tells us whether the currently open panel belongs
        // to this phase.
        var phaseDialog = document.getElementById('phase-panel');
        if (phaseDialog && phaseDialog.dataset.openPhaseId === String(phaseId)) {
            applyPanelHeaderStatus(phaseDialog, status);
        }
    }

    // The ticket header line (`.ticket-head-line`, in the sticky title band
    // as of ticket 164) is not itself a `.status-cycle` click target
    // (design-system.md §9), but its status word is a rendered occurrence
    // that a phase/task rollup can change, so a status response still needs
    // to refresh it (requirement 173).
    function applyTicketStatus(status) {
        var head = document.querySelector('.ticket-head-line[data-status]');
        if (!head) return;
        head.dataset.status = status;
        var word = head.querySelector('.ticket-status');
        if (word) word.textContent = status;
    }

    // Applies every status present in a `/task/{id}/status` or
    // `/phase/{id}/status` JSON response (success body or 409 failure
    // body — both carry the same shape) to the page, and updates the
    // matching cycle state's server-confirmed value so the next click
    // starts from the right baseline.
    function applyStatusResponse(data) {
        if (!data) return;
        if (data.task) {
            applyTaskStatusEverywhere(data.task.id, data.task.status);
            var taskState = statusCycleState['task:' + data.task.id];
            if (taskState) taskState.confirmed = data.task.status;
        }
        if (data.phase) {
            applyPhaseStatusEverywhere(data.phase.id, data.phase.status);
            var phaseState = statusCycleState['phase:' + data.phase.id];
            if (phaseState) phaseState.confirmed = data.phase.status;
        }
        if (data.ticket) {
            applyTicketStatus(data.ticket.status);
        }
    }

    function currentDisplayedStatus(type, id) {
        var el = type === 'task'
            ? document.querySelector('.task[data-task-id="' + id + '"]')
            : document.querySelector('details.phase[data-phase-id="' + id + '"]');
        return el ? el.dataset.status : null;
    }

    // Sends the settled value once. On completion (success, 409, or a
    // network error), checks whether another value settled while this
    // request was in flight and, if so, sends that one next — the "last
    // settled value wins, no queue" rule (requirement 164).
    function sendStatus(type, id, value, state) {
        state.inFlight = true;
        var body = new URLSearchParams();
        body.set('status', value);

        fetch('/' + type + '/' + id + '/status', { method: 'POST', body: body })
            .then(function (response) {
                return response.json()
                    .catch(function () { return null; })
                    .then(function (data) { return { ok: response.ok, data: data }; });
            })
            .then(function (result) {
                state.inFlight = false;
                if (result.ok && result.data) {
                    applyStatusResponse(result.data);
                } else if (result.data) {
                    applyStatusResponse(result.data);
                    showTerminalError(result.data.error || GENERIC_STATUS_ERROR);
                } else {
                    if (type === 'task') applyTaskStatusEverywhere(id, state.confirmed);
                    else applyPhaseStatusEverywhere(id, state.confirmed);
                    showTerminalError(GENERIC_STATUS_ERROR);
                }
                settleIfPending(type, id, state);
            })
            .catch(function () {
                state.inFlight = false;
                if (type === 'task') applyTaskStatusEverywhere(id, state.confirmed);
                else applyPhaseStatusEverywhere(id, state.confirmed);
                showTerminalError(GENERIC_STATUS_ERROR);
                settleIfPending(type, id, state);
            });
    }

    function settleIfPending(type, id, state) {
        if (state.pendingSend === null) return;
        var value = state.pendingSend;
        state.pendingSend = null;
        if (value !== state.confirmed) {
            sendStatus(type, id, value, state);
        }
    }

    function scheduleStatusSend(type, id, state) {
        if (state.timer) clearTimeout(state.timer);
        state.timer = setTimeout(function () {
            state.timer = null;
            var value = currentDisplayedStatus(type, id);
            if (value === null || value === state.confirmed) return;
            if (state.inFlight) {
                state.pendingSend = value;
                return;
            }
            sendStatus(type, id, value, state);
        }, STATUS_SETTLE_DELAY_MS);
    }

    // Capturing-phase delegated listener: it must run, and call
    // stopPropagation(), before the event reaches the `.task` row's own
    // click listener (bound above, which opens the task panel) or a
    // `<summary>`'s native <details> toggle — both bubble-phase/default
    // behaviour on elements below `document` in the propagation path.
    // Listening on the bubble phase instead would fire this handler AFTER
    // those, too late to stop them (requirements 161/162).
    document.addEventListener('click', function (e) {
        var cycleEl = e.target.closest('.status-cycle');
        if (!cycleEl) return;
        e.preventDefault();
        e.stopPropagation();

        var isPhase = cycleEl.classList.contains('phase-status');
        var type = isPhase ? 'phase' : 'task';
        var container = isPhase
            ? cycleEl.closest('details.phase[data-phase-id]')
            : cycleEl.closest('.task[data-task-id]');
        if (!container) return;
        var id = isPhase ? container.dataset.phaseId : container.dataset.taskId;
        var cycle = isPhase ? PHASE_STATUS_CYCLE : TASK_STATUS_CYCLE;

        var key = type + ':' + id;
        var state = statusCycleState[key];
        if (!state) {
            state = statusCycleState[key] = {
                confirmed: container.dataset.status,
                timer: null,
                inFlight: false,
                pendingSend: null,
            };
        }

        var next = statusCycleNext(container.dataset.status, cycle);
        if (type === 'task') applyTaskStatusEverywhere(id, next);
        else applyPhaseStatusEverywhere(id, next);

        scheduleStatusSend(type, id, state);
    }, true);

});
