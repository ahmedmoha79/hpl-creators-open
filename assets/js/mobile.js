(function () {
    function stackTables() {
        document.querySelectorAll('.app__content table.table').forEach(function (table) {
            var head = table.querySelector('thead');
            if (!head || table.getAttribute('data-no-stack') !== null) return;

            var labels = Array.prototype.map.call(head.querySelectorAll('th'), function (th) {
                return th.textContent.replace(/\s+/g, ' ').trim();
            });
            if (!labels.length) return;

            table.classList.add('table--stack');
            table.querySelectorAll('tbody tr').forEach(function (row) {
                Array.prototype.forEach.call(row.children, function (cell, index) {
                    if (cell.tagName !== 'TD') return;
                    var label = labels[index] || '';
                    if (label) cell.setAttribute('data-label', label);
                    // One box for the value, so label and value sit side by side even when the value has several parts.
                    if (label && index > 0 && !cell.__hplWrapped) {
                        cell.__hplWrapped = true;
                        var v = document.createElement('span'); v.className = 'td__v';
                        while (cell.firstChild) v.appendChild(cell.firstChild);
                        cell.appendChild(v);
                    }
                    // Cells with buttons become one row of buttons on phones (owner, 2026-11-13: admin too tall on a phone).
                    if (cell.querySelector('button, .btn, input[type="submit"]') && !cell.querySelector('input[type="text"], input[type="number"], textarea, select')) cell.classList.add('td--actions');
                });
            });
        });
    }

    document.addEventListener('hpl:swapped', stackTables); // admin pages swapped in place (admin-smooth.js)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', stackTables);
    } else {
        stackTables();
    }
})();
