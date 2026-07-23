/* Elevate SJC — client-facing availability calendar + booking request form. */
(function () {
    'use strict';

    const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    const WEEKDAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function init(opts) {
        const state = {
            year: new Date().getFullYear(),
            month: new Date().getMonth() + 1,
            selectedDate: null,
        };

        function load() {
            fetch('/api/availability.php?year=' + state.year + '&month=' + state.month, {
                credentials: 'same-origin',
            })
                .then((r) => r.json())
                .then(render)
                .catch(() => {
                    opts.gridEl.innerHTML = '<p class="alert alert--error">Could not load the calendar. Please refresh.</p>';
                });
        }

        function render(data) {
            opts.titleEl.textContent = MONTH_NAMES[state.month - 1] + ' ' + state.year;
            opts.syncWarningEl.hidden = !!data.calendarSyncOk;

            const grid = opts.gridEl;
            grid.innerHTML = '';

            WEEKDAY_LABELS.forEach((label) => {
                const cell = document.createElement('div');
                cell.className = 'calendar-grid__weekday';
                cell.textContent = label;
                grid.appendChild(cell);
            });

            const firstOfMonth = new Date(state.year, state.month - 1, 1);
            const leadingBlanks = firstOfMonth.getDay();
            for (let i = 0; i < leadingBlanks; i++) {
                grid.appendChild(document.createElement('div'));
            }

            Object.keys(data.days).forEach((iso) => {
                const status = data.days[iso];
                const day = parseInt(iso.slice(8, 10), 10);
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'calendar-day calendar-day--' + status.replace('_', '-');
                btn.textContent = String(day);
                btn.setAttribute('data-date', iso);

                const selectable = (opts.selectableStatuses || ['open']).indexOf(status) !== -1;
                if (selectable) {
                    btn.addEventListener('click', () => selectDate(iso));
                } else {
                    btn.disabled = true;
                }
                grid.appendChild(btn);
            });
        }

        function selectDate(iso) {
            state.selectedDate = iso;
            opts.dateLabelEl.textContent = new Date(iso + 'T00:00:00').toLocaleDateString(undefined, {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
            });
            opts.errorEl.hidden = true;
            opts.formCardEl.hidden = false;
            opts.formCardEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        opts.prevBtn.addEventListener('click', () => {
            state.month -= 1;
            if (state.month < 1) { state.month = 12; state.year -= 1; }
            load();
        });
        opts.nextBtn.addEventListener('click', () => {
            state.month += 1;
            if (state.month > 12) { state.month = 1; state.year += 1; }
            load();
        });
        opts.cancelBtn.addEventListener('click', () => {
            opts.formCardEl.hidden = true;
        });

        opts.formEl.addEventListener('submit', (e) => {
            e.preventDefault();
            if (!state.selectedDate) return;

            const submitBtn = opts.formEl.querySelector('button[type="submit"]');
            submitBtn.disabled = true;

            fetch('/api/book.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    date: state.selectedDate,
                    type: opts.formEl.querySelector('[name="type"]').value,
                    notes: opts.formEl.querySelector('[name="notes"]').value,
                    csrf_token: csrfToken(),
                }),
            })
                .then((r) => r.json().then((body) => ({ ok: r.ok, body })))
                .then(({ ok, body }) => {
                    if (!ok) {
                        opts.errorEl.textContent = body.error || 'Something went wrong. Please try again.';
                        opts.errorEl.hidden = false;
                        submitBtn.disabled = false;
                        return;
                    }
                    opts.onBooked && opts.onBooked(body);
                })
                .catch(() => {
                    opts.errorEl.textContent = 'Network error. Please try again.';
                    opts.errorEl.hidden = false;
                    submitBtn.disabled = false;
                });
        });

        load();
    }

    window.ElevateCalendar = { init };
})();
