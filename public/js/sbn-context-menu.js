/**
 * sbn-context-menu.js — Vanilla context menu singleton
 *
 * Loaded as a plain <script> (same pattern as chords.js).
 * Exposes showContextMenu / hideContextMenu on window so Alpine x-data
 * methods can call them directly. When the Vue tab editor eventually needs
 * the menu it can import from this same file as an ES module — the window
 * assignments don't interfere.
 *
 * Usage (from Alpine):
 *   showContextMenu(event, items, onAction);
 *   hideContextMenu();
 */

/**
 * @typedef {Object} MenuItem
 * @property {string}   id         — operation key, e.g. 'deleteBar'
 * @property {string}   label      — display text
 * @property {string}   [icon]     — optional emoji prefix
 * @property {boolean}  [disabled] — greyed out, non-interactive
 * @property {string}   [group]    — items with different adjacent groups get a <hr>
 * @property {string}   [shortcut] — keyboard hint shown on the right, e.g. 'Ctrl+C'
 * @property {boolean}  [danger]   — red styling for destructive actions
 */

(function () {
    var MENU_ID   = 'sbn-context-menu';
    var _menuEl   = null;
    var _onAction = null;
    var _cleanup  = null;

    function ensureMenu() {
        if (_menuEl) return _menuEl;
        _menuEl = document.createElement('div');
        _menuEl.id = MENU_ID;
        _menuEl.className = 'sbn-context-menu';
        _menuEl.style.display = 'none';
        document.body.appendChild(_menuEl);
        return _menuEl;
    }

    /**
     * Show context menu at the mouse cursor position.
     *
     * @param {MouseEvent}            event    — the contextmenu event
     * @param {MenuItem[]}            items    — menu items to render
     * @param {function(string):void} onAction — called with the action id when clicked
     */
    function showContextMenu(event, items, onAction) {
        event.preventDefault();
        event.stopPropagation();
        hideContextMenu();

        var menu = ensureMenu();
        _onAction = onAction;

        // Build DOM
        menu.innerHTML = '';
        var lastGroup = null;

        items.forEach(function (item) {
            // Separator when group boundary changes (skip before the first group)
            if (item.group !== undefined && item.group !== lastGroup && lastGroup !== null) {
                menu.appendChild(document.createElement('hr'));
            }
            lastGroup = item.group !== undefined ? item.group : lastGroup;

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'sbn-context-menu-item';
            if (item.disabled) btn.classList.add('disabled');
            if (item.danger)   btn.classList.add('danger');
            btn.setAttribute('data-action', item.id);

            var labelSpan = document.createElement('span');
            labelSpan.className = 'sbn-context-menu-label';
            labelSpan.textContent = (item.icon ? item.icon + ' ' : '') + item.label;
            btn.appendChild(labelSpan);

            if (item.shortcut) {
                var kbdSpan = document.createElement('span');
                kbdSpan.className = 'sbn-context-menu-kbd';
                kbdSpan.textContent = item.shortcut;
                btn.appendChild(kbdSpan);
            }

            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (!item.disabled && _onAction) _onAction(item.id);
                hideContextMenu();
            });

            menu.appendChild(btn);
        });

        // Position at cursor, clamp to viewport so menu never overflows
        menu.style.display = 'block';
        var rect = menu.getBoundingClientRect();
        var x = event.clientX;
        var y = event.clientY;
        if (x + rect.width  > window.innerWidth)  x = window.innerWidth  - rect.width  - 8;
        if (y + rect.height > window.innerHeight) y = window.innerHeight - rect.height - 8;
        // Clamp to viewport (clientX/Y are already viewport-relative).
        // No scrollX/Y offset needed — the menu uses position:fixed.
        menu.style.left = x + 'px';
        menu.style.top  = y + 'px';

        // Dismiss on click-away, second right-click, or Escape.
        // setTimeout so this contextmenu event doesn't immediately re-trigger dismiss.
        function onClickAway(e) {
            if (!menu.contains(e.target)) hideContextMenu();
        }
        function onKeyDown(e) {
            if (e.key === 'Escape') hideContextMenu();
        }
        setTimeout(function () {
            document.addEventListener('click',       onClickAway, true);
            document.addEventListener('contextmenu', onClickAway, true);
            document.addEventListener('keydown',     onKeyDown);
        }, 0);

        _cleanup = function () {
            document.removeEventListener('click',       onClickAway, true);
            document.removeEventListener('contextmenu', onClickAway, true);
            document.removeEventListener('keydown',     onKeyDown);
        };
    }

    function hideContextMenu() {
        if (_cleanup) { _cleanup(); _cleanup = null; }
        if (_menuEl)  _menuEl.style.display = 'none';
        _onAction = null;
    }

    // ── Expose globally (Alpine x-data calls these directly) ─────────────
    window.showContextMenu = showContextMenu;
    window.hideContextMenu = hideContextMenu;

}());
