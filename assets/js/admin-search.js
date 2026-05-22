/**
 * Instant-filter for plugin admin list tables (Customers, Users,
 * Products, Orders). No AJAX — every list view already renders all rows
 * server-side; this just hides ones that don't match the typed query.
 *
 * Markup contract:
 *   <input class="mop-table-filter" data-target="…rows selector…"
 *          data-group="…optional group wrapper selector…">
 *   <span class="mop-table-filter__count"></span>
 *
 * - data-target: CSS selector for the rows to filter. Defaults to all
 *   .wp-list-table tbody tr on the page.
 * - data-group: optional selector for wrappers that should be hidden
 *   when they have no surviving rows (used on the Products screen where
 *   each category is its own table).
 *
 * Matching is case-insensitive against each row's full textContent —
 * good enough at our table sizes (~300 rows max).
 */
(function () {
    'use strict';

    function applyFilter(input) {
        var q        = input.value.trim().toLowerCase();
        var targetEl = input.getAttribute('data-target') || '.wp-list-table tbody tr';
        var groupSel = input.getAttribute('data-group');
        var countEl  = input.parentElement && input.parentElement.querySelector('.mop-table-filter__count');
        var clearEl  = input.parentElement && input.parentElement.querySelector('.mop-table-filter__clear');

        var rows = document.querySelectorAll(targetEl);
        var visible = 0;
        rows.forEach(function (row) {
            // Skip placeholder/empty-state rows (no data-row attribute and
            // colspan-ed cells span the whole table). Identified by their
            // first <td> having a colspan attribute — those are the
            // "No customers yet" / "No orders yet" empty messages.
            var firstCell = row.querySelector('td');
            var isEmptyState = firstCell && firstCell.hasAttribute('colspan');
            if (isEmptyState) {
                row.style.display = q === '' ? '' : 'none';
                return;
            }
            if (q === '' || row.textContent.toLowerCase().indexOf(q) !== -1) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });

        // Hide group wrappers whose visible-row count is zero.
        if (groupSel) {
            document.querySelectorAll(groupSel).forEach(function (group) {
                var groupRows  = group.querySelectorAll(targetEl);
                var groupShown = 0;
                groupRows.forEach(function (r) {
                    if (r.style.display !== 'none') {
                        var fc = r.querySelector('td');
                        if (!(fc && fc.hasAttribute('colspan'))) {
                            groupShown++;
                        }
                    }
                });
                group.style.display = (q === '' || groupShown > 0) ? '' : 'none';
            });
        }

        if (countEl) {
            if (q === '') {
                countEl.textContent = '';
            } else {
                countEl.textContent = visible + (visible === 1 ? ' match' : ' matches');
            }
        }
        if (clearEl) {
            clearEl.style.display = q === '' ? 'none' : '';
        }
    }

    function init() {
        var inputs = document.querySelectorAll('.mop-table-filter');
        inputs.forEach(function (input) {
            input.addEventListener('input', function () { applyFilter(input); });
            // Run once on load in case the browser restored a typed value.
            applyFilter(input);

            var clearEl = input.parentElement && input.parentElement.querySelector('.mop-table-filter__clear');
            if (clearEl) {
                clearEl.addEventListener('click', function (e) {
                    e.preventDefault();
                    input.value = '';
                    applyFilter(input);
                    input.focus();
                });
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
