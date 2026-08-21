/**
 * Scrum Poker – client: polling, i18n, URL login, rendering.
 */

var currentRoom = '';
var currentUser = '';
var currentRole = '';
var currentUid = '';
var currentBanned = false;
var currentLang = 'de';
var prevRevealed = false;
var i18nDict = {};
var activePolling = null;
var clientStateHash = '';
var lastState = null;
var countdownStartedAt = 0;
var isScrollingParticipants = false;
var scrollResetTimer = null;
var participantsPanelLocked = false;
var participantsUnlockTimer = null;
var participantsRenderPending = false;
var autoJoining = false;
var joinPassword = '';

var FLIP_DURATION_MS = 500;
var activeFlipCount = 0;
var voteToken = 0;
var pendingVote = null;
var CIRCUMFERENCE = 251.2;
var POLL_MS = 1000;

document.addEventListener('DOMContentLoaded', function () {
    applyStoredTheme();
    setupEventListeners();
    hydrateFromUrl();
    updateUrlParams();
    changeLanguage(document.getElementById('lang-select').value).then(function () {
        if (canAutoJoin()) {
            joinRoom(true);
        }
    });
});

function normalizeTheme(value) {
    return String(value || '').toLowerCase() === 'dark' ? 'dark' : 'light';
}

function getCurrentTheme() {
    return normalizeTheme(document.documentElement.getAttribute('data-theme'));
}

function setAppTheme(theme, persist) {
    theme = normalizeTheme(theme);
    document.documentElement.setAttribute('data-theme', theme);
    if (persist !== false) {
        try { localStorage.setItem('scrumpoker-theme', theme); } catch (e) { /* ignore */ }
    }
}

function applyStoredTheme() {
    try {
        var stored = localStorage.getItem('scrumpoker-theme');
        if (stored === 'dark' || stored === 'light') {
            setAppTheme(stored, false);
        }
    } catch (e) { /* ignore */ }
}

function t(key, fallback) {
    if (i18nDict[key]) {
        return i18nDict[key];
    }
    return fallback || key;
}

function isHttpUrl(value) {
    try {
        var u = new URL(String(value).trim());
        return u.protocol === 'http:' || u.protocol === 'https:';
    } catch (e) {
        return false;
    }
}

function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
        Object.keys(attrs).forEach(function (key) {
            var val = attrs[key];
            if (val === null || val === undefined || val === false) {
                return;
            }
            if (key === 'className') {
                node.className = val;
            } else if (key === 'dataset') {
                Object.keys(val).forEach(function (d) {
                    node.dataset[d] = val[d];
                });
            } else if (key.indexOf('on') === 0 && typeof val === 'function') {
                node.addEventListener(key.slice(2).toLowerCase(), val);
            } else if (key === 'text') {
                node.textContent = val;
            } else if (val === true) {
                node.setAttribute(key, key);
            } else {
                node.setAttribute(key, String(val));
            }
        });
    }
    (children || []).forEach(function (child) {
        if (child === null || child === undefined) {
            return;
        }
        if (typeof child === 'string') {
            node.appendChild(document.createTextNode(child));
        } else {
            node.appendChild(child);
        }
    });
    return node;
}

function clearNode(node) {
    while (node.firstChild) {
        node.removeChild(node.firstChild);
    }
}

function applyI18n() {
    document.querySelectorAll('[data-i18n]').forEach(function (node) {
        var key = node.getAttribute('data-i18n');
        if (i18nDict[key]) {
            node.textContent = i18nDict[key];
        }
    });
    document.querySelectorAll('[data-i18n-title]').forEach(function (node) {
        var key = node.getAttribute('data-i18n-title');
        if (i18nDict[key]) {
            node.setAttribute('title', i18nDict[key]);
        }
    });
}

async function changeLanguage(lang) {
    currentLang = lang || 'de';
    try {
        var res = await fetch('index.php?action=get_language&lang=' + encodeURIComponent(currentLang));
        if (!res.ok) {
            console.error('Language file could not be loaded', currentLang, res.status);
        }
        i18nDict = await res.json();
        if (res.headers.get('X-Lang-Fallback') === '1') {
            console.error('Language file not found, falling back to German', currentLang);
        }
        applyI18n();
        document.getElementById('lang-select').value = currentLang;
        document.getElementById('workspace-lang-select').value = currentLang;
        document.documentElement.setAttribute('lang', currentLang);
        if (currentRoom) {
            updateUrlParams();
            if (lastState) {
                clientStateHash = '';
                renderWorkspace(lastState);
            }
        } else {
            updateUrlParams();
        }
    } catch (e) {
        console.error('Error loading language file', e);
    }
}

function getJoinPassword() {
    var roleSelect = document.getElementById('role-select');
    if (roleSelect && roleSelect.value === 'admin') {
        var fromInput = document.getElementById('admin-password-input');
        if (fromInput && fromInput.value.trim() !== '') {
            return fromInput.value.trim();
        }
    }
    var fromUrl = new URLSearchParams(window.location.search).get('password');
    return (fromUrl !== null && fromUrl !== '') ? fromUrl : joinPassword;
}

function syncAdminPasswordField() {
    var roleSelect = document.getElementById('role-select');
    var group = document.getElementById('admin-password-group');
    var input = document.getElementById('admin-password-input');
    if (!roleSelect || !group || !input) {
        return;
    }
    var isAdmin = roleSelect.value === 'admin';
    group.hidden = !isAdmin;
    if (isAdmin) {
        if (!input.value && joinPassword) {
            input.value = joinPassword;
        }
    } else {
        input.value = '';
    }
}

function loginFields() {
    return {
        room: document.getElementById('room-id-input').value.trim(),
        user: document.getElementById('username-input').value.trim(),
        role: document.getElementById('role-select').value,
        lang: document.getElementById('lang-select').value
    };
}

function updateUrlParams() {
    var params = new URLSearchParams();
    if (currentRoom) {
        params.set('room', currentRoom);
        params.set('user', currentUser);
        params.set('role', currentRole || 'user');
        params.set('lang', currentLang);
    } else {
        var fields = loginFields();
        if (fields.room) params.set('room', fields.room);
        if (fields.user) params.set('user', fields.user);
        if (fields.role) params.set('role', fields.role);
        params.set('lang', fields.lang || currentLang || 'de');
    }
    params.set('theme', getCurrentTheme());
    var qs = params.toString();
    var next = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
    history.replaceState(null, '', next);
}

function hydrateFromUrl() {
    var params = new URLSearchParams(window.location.search);
    var room = params.get('room') || '';
    var user = params.get('user') || '';
    var role = params.get('role') || 'user';
    var lang = params.get('lang') || 'de';
    if (['de', 'en', 'hu'].indexOf(lang) === -1) {
        lang = 'de';
    }
    if (['user', 'moderator', 'admin'].indexOf(role) === -1) {
        role = 'user';
    }
    document.getElementById('room-id-input').value = room;
    document.getElementById('username-input').value = user;
    document.getElementById('role-select').value = role;
    document.getElementById('lang-select').value = lang;
    document.getElementById('workspace-lang-select').value = lang;
    joinPassword = params.get('password') || '';
    if (params.has('theme')) {
        setAppTheme(params.get('theme'));
    }
    syncAdminPasswordField();
}

function canAutoJoin() {
    var params = new URLSearchParams(window.location.search);
    return !!(params.get('user') && params.get('room'));
}

function setupEventListeners() {
    document.getElementById('lang-select').addEventListener('change', function (e) {
        changeLanguage(e.target.value);
    });
    document.getElementById('workspace-lang-select').addEventListener('change', function (e) {
        changeLanguage(e.target.value);
    });

    document.getElementById('role-select').addEventListener('change', function () {
        syncAdminPasswordField();
        updateUrlParams();
    });

    ['room-id-input', 'username-input', 'lang-select'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', updateUrlParams);
        document.getElementById(id).addEventListener('change', updateUrlParams);
    });

    document.getElementById('btn-theme-toggle').addEventListener('click', function () {
        var next = getCurrentTheme() === 'light' ? 'dark' : 'light';
        setAppTheme(next);
        updateUrlParams();
    });

    document.getElementById('btn-join').addEventListener('click', function () { joinRoom(false); });
    document.getElementById('username-input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') joinRoom(false);
    });
    document.getElementById('room-id-input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') joinRoom(false);
    });
    document.getElementById('admin-password-input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') joinRoom(false);
    });

    document.getElementById('btn-logout').addEventListener('click', logout);
    document.getElementById('btn-reveal').addEventListener('click', function () {
        updateRoomConfig({ reveal: true });
    });
    document.getElementById('btn-reset').addEventListener('click', function () {
        updateRoomConfig({ reset: true });
    });
    document.getElementById('btn-clear').addEventListener('click', function () {
        updateRoomConfig({ clearAbsent: true });
    });
    document.getElementById('chk-allow-vote-change').addEventListener('change', function (e) {
        updateRoomConfig({ allowVoteChangeAfterReveal: e.target.checked });
    });
    document.getElementById('input-story-url').addEventListener('change', function (e) {
        var value = e.target.value.trim();
        if (currentRole === 'user' && value === '') {
            e.target.value = (lastState && lastState.storyUrl) ? lastState.storyUrl : '';
            return;
        }
        updateRoomConfig({ storyUrl: value });
    });
    document.getElementById('btn-save-config').addEventListener('click', saveDeckConfig);

    var list = document.getElementById('participants-list');
    list.addEventListener('scroll', function () {
        isScrollingParticipants = true;
        clearTimeout(scrollResetTimer);
        scrollResetTimer = setTimeout(function () {
            isScrollingParticipants = false;
        }, 600);
    });
    list.addEventListener('mousedown', function (e) {
        if (e.target.classList && e.target.classList.contains('role-select-mini')) {
            lockParticipantsPanel();
        }
    });
    list.addEventListener('focusin', function (e) {
        if (e.target.classList && e.target.classList.contains('role-select-mini')) {
            lockParticipantsPanel();
        }
    });
    list.addEventListener('focusout', function (e) {
        if (e.target.classList && e.target.classList.contains('role-select-mini')) {
            scheduleUnlockParticipantsPanel();
        }
    });
}

function csrfHeaders() {
    return {
        'Content-Type': 'application/json',
        'X-CSRF-Token': document.body.getAttribute('data-csrf') || ''
    };
}

async function refreshCsrf() {
    try {
        var res = await fetch('index.php?action=get_session');
        var data = await res.json();
        if (data && data.csrf) {
            document.body.setAttribute('data-csrf', data.csrf);
        }
    } catch (e) {
        console.error('csrf refresh failed', e);
    }
}

function showLoginError(message) {
    var box = document.getElementById('login-error');
    if (!message) {
        box.hidden = true;
        box.textContent = '';
        return;
    }
    box.hidden = false;
    box.textContent = message;
}

async function joinRoom(fromUrl) {
    if (autoJoining) return;
    var fields = loginFields();
    if (!fields.room || !fields.user) {
        if (!fromUrl) {
            showLoginError(t('err-missing-fields', 'Bitte Name und Raum angeben.'));
        }
        return;
    }
    autoJoining = true;
    showLoginError('');
    try {
        var res = await fetch('index.php?action=join_room', {
            method: 'POST',
            headers: csrfHeaders(),
            body: JSON.stringify({
                room: fields.room,
                name: fields.user,
                role: fields.role,
                password: getJoinPassword()
            })
        });
        var data = await res.json();
        if (!data.success) {
            if (data.message === 'csrf') {
                await refreshCsrf();
                showLoginError(t('err-csrf', 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden.'));
                return;
            }
            showLoginError(t('err-' + (data.message || 'join'), t('err-join', 'Beitritt fehlgeschlagen.')));
            return;
        }
        currentRoom = data.room;
        currentUser = data.name;
        currentRole = data.role;
        enterWorkspace();
        updateUrlParams();
        startPolling();
    } catch (e) {
        console.error('join failed', e);
        showLoginError(t('err-join', 'Beitritt fehlgeschlagen.'));
    } finally {
        autoJoining = false;
    }
}

function enterWorkspace() {
    document.body.classList.add('workspace-active');
    document.getElementById('login-container').hidden = true;
    document.getElementById('main-layout').hidden = false;
    refreshChrome();
}

function refreshChrome() {
    var title = document.getElementById('display-room-title');
    clearNode(title);
    title.appendChild(document.createTextNode('Scrum Poker '));
    title.appendChild(el('span', { className: 'header-room-id', text: currentRoom }));

    var initial = (currentUser || '?').charAt(0).toUpperCase();
    document.getElementById('user-avatar').textContent = initial;
    document.getElementById('user-name').textContent = currentUser || '';
    var rolePill = document.getElementById('user-role-pill');
    rolePill.textContent = t('role-' + currentRole, currentRole);
    rolePill.className = 'role-pill role-pill-' + (currentRole || 'user');

    var isPriv = currentRole === 'admin' || currentRole === 'moderator';
    document.getElementById('panel-moderation').hidden = !isPriv || currentBanned;
    document.getElementById('panel-deck-config').hidden = currentRole !== 'admin' || currentBanned;
    document.getElementById('panel-rooms').hidden = currentRole !== 'admin' || currentBanned;
    document.getElementById('input-story-url').disabled = currentBanned;
}

function leaveWorkspace() {
    document.body.classList.remove('workspace-active');
    document.getElementById('login-container').hidden = false;
    document.getElementById('main-layout').hidden = true;
    currentRoom = '';
    currentUser = '';
    currentRole = '';
    currentBanned = false;
    lastState = null;
    clientStateHash = '';
    prevRevealed = false;
    if (activePolling) {
        clearInterval(activePolling);
        activePolling = null;
    }
}

async function logout() {
    try {
        await fetch('index.php?action=logout', {
            method: 'POST',
            headers: csrfHeaders(),
            body: JSON.stringify({})
        });
    } catch (e) {
        console.error('logout failed', e);
    }
    leaveWorkspace();
    await refreshCsrf();
    updateUrlParams();
}

function startPolling() {
    if (activePolling) {
        clearInterval(activePolling);
    }
    pollState();
    activePolling = setInterval(pollState, POLL_MS);
}

async function pollState() {
    if (!currentRoom) return;
    try {
        var res = await fetch('index.php?action=get_state&room=' + encodeURIComponent(currentRoom));
        var result = await res.json();
        if (!result.success) {
            if (result.message === 'session_lost' || result.message === 'room_not_found') {
                leaveWorkspace();
                showLoginError(t('err-' + result.message, t('err-session', 'Sitzung beendet.')));
            }
            return;
        }
        if (result.role) currentRole = result.role;
        if (result.name) currentUser = result.name;
        currentBanned = !!result.banned;
        renderWorkspace(result.data);
        if (currentRole === 'admin') {
            refreshRoomList();
        }
    } catch (e) {
        console.error('Sync tracking state iteration dropped.', e);
    }
}

async function apiPost(action, payload) {
    var res = await fetch('index.php?action=' + encodeURIComponent(action), {
        method: 'POST',
        headers: csrfHeaders(),
        body: JSON.stringify(payload || {})
    });
    return res.json();
}

async function updateRoomConfig(payload) {
    payload.room = currentRoom;
    try {
        await apiPost('update_room', payload);
        pollState();
    } catch (e) {
        console.error('update failed', e);
    }
}

async function saveDeckConfig() {
    updateRoomConfig({
        timerDuration: parseInt(document.getElementById('input-timer-duration').value, 10) || 5,
        decks: {
            fibonacci: document.getElementById('input-deck-fibonacci').value,
            tshirt: document.getElementById('input-deck-tshirt').value,
            days: document.getElementById('input-deck-days').value
        }
    });
}

function shouldSkipParticipantsRender() {
    if (isScrollingParticipants || participantsPanelLocked) {
        return true;
    }
    var active = document.activeElement;
    return !!(active && active.classList && active.classList.contains('role-select-mini'));
}

function lockParticipantsPanel() {
    clearTimeout(participantsUnlockTimer);
    participantsPanelLocked = true;
}

function scheduleUnlockParticipantsPanel() {
    clearTimeout(participantsUnlockTimer);
    participantsUnlockTimer = setTimeout(function () {
        participantsPanelLocked = false;
        if (participantsRenderPending && lastState) {
            participantsRenderPending = false;
            clientStateHash = '';
            renderParticipants(lastState.participants || {}, !!lastState.revealed);
        }
    }, 400);
}

function fieldIsBusy(id) {
    var node = document.getElementById(id);
    return node && document.activeElement === node;
}

function renderStory(state) {
    var display = document.getElementById('story-display');
    clearNode(display);
    var story = (state.storyUrl || '').trim();
    if (!story) {
        display.className = 'story-empty';
        display.textContent = t('story-empty', 'Noch keine Story — Link oder Ticket-ID eintragen.');
    } else {
        display.className = '';
        if (isHttpUrl(story)) {
            display.appendChild(el('a', {
                href: story,
                target: '_blank',
                rel: 'noopener noreferrer',
                text: story
            }));
        } else {
            display.textContent = story;
        }
    }
    if (!fieldIsBusy('input-story-url')) {
        document.getElementById('input-story-url').value = story;
    }
}

function renderWorkspace(state) {
    state = withPendingVote(state);
    lastState = state;
    var hash = JSON.stringify(state);
    handleTimerExecution(state);
    if (clientStateHash === hash) {
        return;
    }
    clientStateHash = hash;
    refreshChrome();
    updateUrlParams();

    renderStory(state);
    renderCardsMatrix(state);
    if (!shouldSkipParticipantsRender()) {
        renderParticipants(state.participants || {}, !!state.revealed);
        participantsRenderPending = false;
    } else {
        participantsRenderPending = true;
    }

    var revealBtn = document.getElementById('btn-reveal');
    revealBtn.disabled = !!(state.revealed || state.timerTarget);

    var allowChangeBox = document.getElementById('chk-allow-vote-change');
    if (allowChangeBox && !fieldIsBusy('chk-allow-vote-change')) {
        allowChangeBox.checked = !!state.allowVoteChangeAfterReveal;
    }

    if (currentRole === 'admin') {
        if (!fieldIsBusy('input-timer-duration')) {
            document.getElementById('input-timer-duration').value = state.timerDuration || 5;
        }
        ['fibonacci', 'tshirt', 'days'].forEach(function (key) {
            var id = 'input-deck-' + key;
            if (!fieldIsBusy(id) && state.decks && state.decks[key]) {
                document.getElementById(id).value = state.decks[key].join(', ');
            }
        });
    }

    if (state.revealed) {
        calculateStatistics(state);
        document.getElementById('panel-stats').hidden = false;
    } else {
        document.getElementById('panel-stats').hidden = true;
        resetStatUi();
    }
}

function areVotesLocked(state) {
    if (currentBanned) return true;
    if (!state.revealed) return false;
    return !state.allowVoteChangeAfterReveal;
}

function myVote(state) {
    var participants = state.participants || {};
    var found = null;
    Object.keys(participants).forEach(function (id) {
        if (participants[id].name === currentUser) {
            found = participants[id];
        }
    });
    return found && found.vote ? found.vote : null;
}

function isCardsFlipLocked() {
    return activeFlipCount > 0;
}

function withPendingVote(state) {
    if (!pendingVote || !state || !state.participants) {
        return state;
    }
    var merged = JSON.parse(JSON.stringify(state));
    Object.keys(merged.participants).forEach(function (id) {
        var p = merged.participants[id];
        if (p.name !== currentUser) return;
        merged.participants[id] = Object.assign({}, p, {
            vote: pendingVote.selecting
                ? { deck: pendingVote.deck, value: String(pendingVote.value) }
                : null
        });
    });
    return merged;
}

function patchMyVoteInState(deck, value, selected) {
    if (!lastState || !lastState.participants) return;
    Object.keys(lastState.participants).forEach(function (id) {
        var p = lastState.participants[id];
        if (p.name !== currentUser) return;
        lastState.participants[id] = Object.assign({}, p, {
            vote: selected ? { deck: deck, value: String(value) } : null
        });
    });
    clientStateHash = '';
}

function syncCardsAfterFlip() {
    /* DOM bleibt optimistisch; Sync kommt über pollState */
}

function applyOptimisticCardVote(cardEl) {
    var wrapper = document.getElementById('decks-wrapper');
    if (!wrapper || !cardEl) return false;
    var wasSelected = cardEl.classList.contains('selected');
    wrapper.querySelectorAll('.poker-card').forEach(function (c) {
        c.classList.remove('selected', 'dimmed');
    });
    if (!wasSelected) {
        cardEl.classList.add('selected');
        wrapper.querySelectorAll('.poker-card').forEach(function (c) {
            if (c !== cardEl) c.classList.add('dimmed');
        });
    }
    return !wasSelected;
}

function triggerFlip(card) {
    activeFlipCount++;
    card.classList.remove('flip-anim');
    void card.offsetWidth;
    card.classList.add('flip-anim');
    setTimeout(function () {
        card.classList.remove('flip-anim');
        activeFlipCount = Math.max(0, activeFlipCount - 1);
        syncCardsAfterFlip();
    }, FLIP_DURATION_MS);
}

function renderCardsMatrix(state) {
    if (isCardsFlipLocked()) {
        return;
    }
    var wrapper = document.getElementById('decks-wrapper');
    clearNode(wrapper);
    var vote = myVote(state);
    var locked = areVotesLocked(state);
    var justRevealed = !!state.revealed && !prevRevealed && locked;
    prevRevealed = !!state.revealed;
    var isPriv = (currentRole === 'admin' || currentRole === 'moderator') && !currentBanned;
    var decks = state.decks || {};
    var active = state.activeDecks || {};
    var labels = {
        fibonacci: t('deck-fibonacci', 'Fibonacci'),
        tshirt: t('deck-tshirt', 'T-Shirt'),
        days: t('deck-days', 'Personentage')
    };

    ['fibonacci', 'tshirt', 'days'].forEach(function (deckKey) {
        var section = el('div', { className: 'deck-section' });
        var head = el('div', { className: 'deck-section-head' });
        if (isPriv) {
            var box = el('label', { className: 'deck-toggle' }, [
                el('input', {
                    type: 'checkbox',
                    checked: !!active[deckKey],
                    onChange: function (e) {
                        var next = {
                            fibonacci: !!active.fibonacci,
                            tshirt: !!active.tshirt,
                            days: !!active.days
                        };
                        next[deckKey] = e.target.checked;
                        updateRoomConfig({ activeDecks: next });
                    }
                }),
                el('span', { text: labels[deckKey] })
            ]);
            head.appendChild(box);
        } else {
            head.appendChild(el('span', { className: 'deck-name', text: labels[deckKey] }));
        }
        section.appendChild(head);

        if (!active[deckKey]) {
            if (isPriv) {
                wrapper.appendChild(section);
            }
            return;
        }

        var grid = el('div', { className: 'cards-grid' });
        (decks[deckKey] || []).forEach(function (cardValue) {
            var selected = vote && vote.deck === deckKey && String(vote.value) === String(cardValue);
            var card = el('div', { className: 'poker-card' });
            if (selected) card.classList.add('selected');
            if (vote && !selected && !locked) card.classList.add('dimmed');
            if (locked) card.classList.add('locked');
            card.appendChild(el('div', { className: 'card-inner' }, [
                el('div', { className: 'card-face card-front' }, [
                    el('span', { className: 'c-val', text: String(cardValue) }),
                    el('strong', { text: String(cardValue) }),
                    el('span', { className: 'c-val-rev', text: String(cardValue) })
                ]),
                el('div', { className: 'card-face card-back', 'aria-hidden': 'true' }, [
                    el('span', { className: 'card-back-corner card-back-corner-tl' }),
                    el('span', { className: 'card-back-corner card-back-corner-tr' }),
                    el('span', { className: 'card-back-corner card-back-corner-bl' }),
                    el('span', { className: 'card-back-corner card-back-corner-br' }),
                    el('span', { className: 'card-back-emblem' })
                ])
            ]));
            if (!locked) {
                card.addEventListener('click', function () {
                    if (card.classList.contains('flip-anim')) {
                        return;
                    }
                    var selecting = applyOptimisticCardVote(card);
                    voteToken += 1;
                    var token = voteToken;
                    pendingVote = {
                        deck: deckKey,
                        value: cardValue,
                        selecting: selecting,
                        token: token
                    };
                    patchMyVoteInState(deckKey, cardValue, selecting);
                    triggerFlip(card);
                    castVote(deckKey, cardValue, selecting, token);
                });
            } else if (justRevealed && selected) {
                triggerFlip(card);
            }
            grid.appendChild(card);
        });
        section.appendChild(grid);
        wrapper.appendChild(section);
    });
}

async function castVote(deck, value, selecting, token) {
    try {
        var result = await apiPost('submit_vote', { room: currentRoom, deck: deck, vote: value });
        if (token !== voteToken) {
            return;
        }
        if (!result || !result.success) {
            console.error('vote rejected', result);
            pendingVote = null;
            document.querySelectorAll('#decks-wrapper .flip-anim').forEach(function (c) {
                c.classList.remove('flip-anim');
            });
            activeFlipCount = 0;
            clientStateHash = '';
            pollState();
            return;
        }
        pendingVote = null;
        patchMyVoteInState(deck, value, selecting);
        clientStateHash = '';
        pollState();
    } catch (e) {
        console.error('vote failed', e);
        if (token !== voteToken) {
            return;
        }
        pendingVote = null;
        document.querySelectorAll('#decks-wrapper .flip-anim').forEach(function (c) {
            c.classList.remove('flip-anim');
        });
        activeFlipCount = 0;
        clientStateHash = '';
        pollState();
    }
}

function roleRank(role) {
    if (role === 'admin') return 0;
    if (role === 'moderator') return 1;
    return 2;
}

function renderParticipants(list, isRevealed) {
    var container = document.getElementById('participants-list');
    var scrollTop = container.scrollTop;
    clearNode(container);

    var rows = Object.keys(list).map(function (id) {
        var p = list[id];
        p.id = p.id || id;
        return p;
    });
    rows.sort(function (a, b) {
        var d = roleRank(a.role) - roleRank(b.role);
        if (d !== 0) return d;
        return String(a.name || '').localeCompare(String(b.name || ''), currentLang, { sensitivity: 'base' });
    });

    var isAdmin = currentRole === 'admin' && !currentBanned;

    rows.forEach(function (p) {
        var item = el('div', { className: 'participant-item' + (p.banned ? ' banned' : '') + (p.online ? '' : ' offline') });
        var voteNode;
        if (p.banned) {
            voteNode = el('span', { className: 'vote-empty', text: '—' });
        } else if (p.vote) {
            if (isRevealed) {
                voteNode = el('div', { className: 'vote-revealed-inline', text: String(p.vote.value) });
            } else {
                voteNode = el('div', { className: 'mini-card-back' }, [
                    el('span', { className: 'mini-back-emblem' })
                ]);
            }
        } else {
            voteNode = el('span', { className: 'vote-empty', text: '…' });
        }

        var nameChildren = [el('strong', { text: p.name || '' })];
        if (p.role === 'admin' || p.role === 'moderator') {
            nameChildren.push(el('span', {
                className: 'participant-role-pill role-pill-' + p.role,
                text: t('role-' + p.role, p.role)
            }));
        }
        if (p.banned) {
            nameChildren.push(el('span', { className: 'banned-flag', text: ' (' + t('banned', 'banned') + ')' }));
        } else if (!p.online) {
            nameChildren.push(el('span', { className: 'offline-flag', text: ' (' + t('offline', 'offline') + ')' }));
        }

        var info = el('div', { className: 'p-info' }, [
            el('span', {
                className: 'online-dot',
                title: p.online ? t('online', 'online') : t('offline', 'offline')
            }),
            el('div', { className: 'p-name-block' }, nameChildren)
        ]);

        var actions = el('div', { className: 'p-actions' });
        if (isAdmin && p.id && p.name !== currentUser) {
            if (p.banned) {
                actions.appendChild(el('button', {
                    type: 'button',
                    className: 'btn-tiny-subtle',
                    title: t('unban', 'Entbannen'),
                    text: t('unban', 'Entbannen'),
                    onClick: function () { updateRoomConfig({ unban: p.id }); }
                }));
            } else {
                actions.appendChild(el('button', {
                    type: 'button',
                    className: 'btn-tiny-subtle btn-ban',
                    title: t('ban', 'Bannen'),
                    text: t('ban', 'Bannen'),
                    onClick: function () { updateRoomConfig({ ban: p.id }); }
                }));
            }
            var select = el('select', { className: 'role-select-mini', title: t('lbl-role-change', 'Rolle ändern') });
            ['user', 'moderator', 'admin'].forEach(function (role) {
                var opt = el('option', { value: role, text: t('role-' + role, role) });
                if (role === p.role) opt.selected = true;
                select.appendChild(opt);
            });
            select.addEventListener('change', function () {
                updateRoomConfig({ changeRole: select.value, target: p.id });
            });
            actions.appendChild(select);
        }

        var right = el('div', { className: 'p-right' });
        if (actions.childNodes.length) {
            right.appendChild(actions);
        }
        right.appendChild(voteNode);

        item.appendChild(info);
        item.appendChild(right);
        container.appendChild(item);
    });
    container.scrollTop = scrollTop;
}

function handleTimerExecution(state) {
    var panel = document.getElementById('panel-timer-hub');
    var elNum = document.getElementById('countdown-number');
    var elProg = document.getElementById('countdown-progress');
    var targetTime = state && state.timerTarget ? parseInt(state.timerTarget, 10) : 0;
    var duration = Math.max(1, parseInt(state && state.timerDuration, 10) || 5);

    if (!targetTime || (state && state.revealed)) {
        panel.hidden = true;
        elNum.textContent = '--';
        elProg.style.strokeDashoffset = '0';
        countdownStartedAt = 0;
        return;
    }

    panel.hidden = false;
    var now = Math.floor(Date.now() / 1000);
    var left = targetTime - now;
    if (left < 0) left = 0;
    elNum.textContent = String(left);
    var ratio = left / duration;
    if (ratio > 1) ratio = 1;
    elProg.style.strokeDashoffset = String(CIRCUMFERENCE * (1 - ratio));
}

function votesFromParticipants(participants) {
    var votes = [];
    Object.keys(participants || {}).forEach(function (id) {
        var p = participants[id];
        if (!p || p.banned || !p.vote) return;
        var value = p.vote.value;
        if (value === null || value === undefined || value === '') return;
        votes.push({ deck: p.vote.deck, value: String(value) });
    });
    return votes;
}

function calculateStatistics(state) {
    var votes = votesFromParticipants(state.participants);
    document.getElementById('stat-count').textContent = String(votes.length);
    if (!votes.length) {
        resetStatUi();
        document.getElementById('stat-count').textContent = '0';
        return;
    }

    var countable = votes.filter(function (v) { return v.value !== '?'; });
    var numerical = countable.map(function (v) { return parseFloat(v.value); }).filter(function (n) { return !isNaN(n); });

    var avg = '-';
    var med = '-';
    var medNum = null;
    if (numerical.length > 0) {
        numerical.sort(function (a, b) { return a - b; });
        avg = (numerical.reduce(function (a, b) { return a + b; }, 0) / numerical.length).toFixed(1);
        var mid = Math.floor(numerical.length / 2);
        if (numerical.length % 2 !== 0) {
            medNum = numerical[mid];
            med = String(medNum);
        } else {
            medNum = (numerical[mid - 1] + numerical[mid]) / 2;
            med = (medNum % 1 === 0) ? String(medNum) : medNum.toFixed(1);
        }
    }

    var counts = {};
    var maxCount = 0;
    var modes = [];
    countable.forEach(function (v) {
        counts[v.value] = (counts[v.value] || 0) + 1;
        if (counts[v.value] > maxCount) {
            maxCount = counts[v.value];
            modes = [v.value];
        } else if (counts[v.value] === maxCount && modes.indexOf(v.value) === -1) {
            modes.push(v.value);
        }
    });

    document.getElementById('stat-average').textContent = avg;
    document.getElementById('stat-median').textContent = med;
    document.getElementById('stat-modus').textContent = modes.join(', ') || '-';

    var recValue = document.getElementById('rec-value');
    var recReason = document.getElementById('rec-reason');
    var allCards = [];
    Object.keys(state.decks || {}).forEach(function (k) {
        if (state.activeDecks && state.activeDecks[k]) {
            allCards = allCards.concat(state.decks[k] || []);
        }
    });
    var medianMatchesCard = medNum !== null && allCards.some(function (c) {
        return String(c) === String(medNum) || parseFloat(c) === medNum;
    });

    if (medianMatchesCard) {
        recValue.textContent = String(medNum);
        recReason.textContent = t('rec-exact', 'Median entspricht einer Karte.');
    } else if (modes.length === 1) {
        recValue.textContent = modes[0];
        recReason.textContent = t('rec-mode', 'Eindeutig häufigste Karte.');
    } else if (modes.length > 1) {
        recValue.textContent = modes.join(', ');
        recReason.textContent = t('rec-tied', 'Geteilte Häufigkeit, Diskussion empfohlen.');
    } else {
        recValue.textContent = countable[0] ? countable[0].value : '-';
        recReason.textContent = t('rec-exact', 'Konsens.');
    }

    var distContainer = document.getElementById('distribution-bars');
    clearNode(distContainer);
    var total = countable.length || 1;
    var seen = {};
    allCards.forEach(function (c) {
        if (!counts[c] || seen[c]) return;
        seen[c] = true;
        var count = counts[c];
        var pct = (count / total) * 100;
        var row = el('div', { className: 'distribution-row' + (modes.indexOf(String(c)) !== -1 ? ' highest-rank' : '') });
        row.appendChild(el('div', { className: 'dist-card-badge', text: String(c) }));
        var barWrap = el('div', { className: 'dist-bar-wrapper' });
        barWrap.appendChild(el('div', { className: 'dist-bar-fill', style: 'width: ' + pct + '%' }));
        row.appendChild(barWrap);
        row.appendChild(el('div', { className: 'dist-count-text', text: count + 'x (' + Math.round(pct) + '%)' }));
        distContainer.appendChild(row);
    });
}

function resetStatUi() {
    document.getElementById('stat-count').textContent = '-';
    document.getElementById('stat-average').textContent = '-';
    document.getElementById('stat-median').textContent = '-';
    document.getElementById('stat-modus').textContent = '-';
    document.getElementById('rec-value').textContent = '-';
    document.getElementById('rec-reason').textContent = t('rec-no-votes', 'Warte auf Stimmabgabe...');
    clearNode(document.getElementById('distribution-bars'));
}

var roomListBusy = false;
async function refreshRoomList() {
    if (roomListBusy || currentRole !== 'admin') return;
    roomListBusy = true;
    try {
        var result = await apiPost('list_rooms', { room: currentRoom });
        if (!result.success) return;
        var box = document.getElementById('rooms-list');
        clearNode(box);
        if (!result.rooms || !result.rooms.length) {
            box.appendChild(el('p', { className: 'empty-hint', text: t('empty-rooms', 'Keine Räume.') }));
            return;
        }
        result.rooms.forEach(function (room) {
            var row = el('div', { className: 'room-row' });
            row.appendChild(el('div', { className: 'room-meta' }, [
                el('strong', { text: room.name }),
                el('span', { text: ' · ' + room.online + '/' + room.participants })
            ]));
            row.appendChild(el('button', {
                type: 'button',
                className: 'btn-tiny-subtle',
                title: t('btn-delete-room', 'Raum löschen'),
                text: t('btn-delete-room', 'Löschen'),
                onClick: function () {
                    if (!confirm(t('confirm-delete-room', 'Raum wirklich löschen?'))) return;
                    apiPost('delete_room', { room: currentRoom, target: room.name }).then(function () {
                        if (room.name === currentRoom) {
                            leaveWorkspace();
                            showLoginError(t('err-room_not_found', 'Raum nicht gefunden.'));
                        } else {
                            refreshRoomList();
                        }
                    });
                }
            }));
            box.appendChild(row);
        });
    } catch (e) {
        console.error('room list failed', e);
    } finally {
        roomListBusy = false;
    }
}
