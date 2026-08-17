/**
 * Professional Scrum Poker - Core Client Engine
 * Asynchronous Multi-Role Integration & Frontend Core
 */

let currentRoom = '';
let currentUser = '';
let currentRole = '';
let currentLang = 'de';
let i18nDict = {};
let availableDecks = {};
let activePolling = null;
let clientStateHash = '';

document.addEventListener('DOMContentLoaded', async () => {
    await loadDecks();
    await changeLanguage(document.getElementById('lang-select').value);
    setupEventListeners();
});

async function loadDecks() {
    try {
        const res = await fetch('index.php?action=get_decks');
        availableDecks = await res.json();
    } catch (e) { console.error("Failed loading card configuration mappings", e); }
}

async function changeLanguage(lang) {
    currentLang = lang;
    try {
        const res = await fetch(`index.php?action=get_language&lang=${lang}`);
        i18nDict = await res.json();
        
        // DOM Translation Sweep
        document.querySelectorAll('[data-i18n]').forEach(el => {
            const key = el.getAttribute('data-i18n');
            if (i18nDict[key]) el.textContent = i18nDict[key];
        });
        
        document.getElementById('lang-select').value = lang;
        document.getElementById('workspace-lang-select').value = lang;
    } catch(e) { console.error("Error context handling languages translation parsing", e); }
}

function setupEventListeners() {
    document.getElementById('lang-select').addEventListener('change', (e) => changeLanguage(e.target.value));
    document.getElementById('workspace-lang-select').addEventListener('change', (e) => changeLanguage(e.target.value));
    
    document.getElementById('btn-theme-toggle').addEventListener('click', () => {
        const root = document.documentElement;
        const currentTheme = root.getAttribute('data-theme') || 'light';
        root.setAttribute('data-theme', currentTheme === 'light' ? 'dark' : 'light');
    });

    document.getElementById('btn-join').addEventListener('click', joinRoom);

    // Administrative Input Hooks
    document.getElementById('select-deck-type').addEventListener('change', (e) => updateRoomConfig({ deckType: e.target.value }));
    document.getElementById('input-story-url').addEventListener('change', (e) => updateRoomConfig({ storyUrl: e.target.value }));
    
    document.getElementById('btn-reveal').addEventListener('click', () => updateRoomConfig({ revealed: true }));
    document.getElementById('btn-reset').addEventListener('click', () => updateRoomConfig({ reset: true }));
    document.getElementById('btn-start-timer').addEventListener('click', () => {
        const dur = document.getElementById('input-timer-duration').value;
        updateRoomConfig({ startTimer: true, timerDuration: parseInt(dur) || 30 });
    });
}

async function joinRoom() {
    const room = document.getElementById('room-id-input').value.trim();
    const name = document.getElementById('username-input').value.trim();
    const role = document.getElementById('role-select').value;

    if (!room || !name) return;

    const res = await fetch('index.php?action=join_room', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ room, name, role })
    });
    
    const data = await res.json();
    if (data.success) {
        currentRoom = data.room;
        currentUser = data.name;
        currentRole = data.role;

        document.getElementById('login-container').style.display = 'none';
        document.getElementById('main-layout').style.display = 'grid';
        
        document.getElementById('display-room-name').textContent = `#${currentRoom}`;
        document.getElementById('display-user-info').textContent = `${currentUser} (${i18nDict['role-' + currentRole] || currentRole})`;

        if (currentRole === 'admin' || currentRole === 'moderator') {
            document.getElementById('panel-admin').style.display = 'block';
        }

        startPolling();
    }
}

function startPolling() {
    pollState();
    activePolling = setInterval(pollState, 1200);
}

async function pollState() {
    if (!currentRoom) return;
    try {
        const res = await fetch(`index.php?action=get_state&room=${encodeURIComponent(currentRoom)}&name=${encodeURIComponent(currentUser)}`);
        const result = await res.json();
        if (result.success) {
            renderWorkspace(result.data);
        }
    } catch(e) { console.error("Sync tracking state iteration dropped.", e); }
}

function renderWorkspace(state) {
    const currentHash = JSON.stringify(state);
    if (clientStateHash === currentHash) {
        handleTimerExecution(state.timerTarget);
        return; 
    }
    clientStateHash = currentHash;

    // Ticket & Input Updates
    const displayStory = document.getElementById('story-display');
    if (state.storyUrl) {
        if (state.storyUrl.startsWith('http')) {
            displayStory.innerHTML = `<a href="${state.storyUrl}" target="_blank">${state.storyUrl}</a>`;
        } else {
            displayStory.textContent = state.storyUrl;
        }
    } else {
        displayStory.textContent = '-';
    }

    if (currentRole !== 'admin' && currentRole !== 'moderator') {
        document.getElementById('select-deck-type').value = state.deckType;
        document.getElementById('input-story-url').value = state.storyUrl;
    }

    renderCardsMatrix(state.deckType, state.revealed, state.participants[currentUser]?.vote);
    renderParticipants(state.participants, state.revealed);
    handleTimerExecution(state.timerTarget);

    if (state.revealed) {
        calculateStatistics(state.participants, state.deckType);
        document.getElementById('panel-stats').style.display = 'block';
    } else {
        document.getElementById('panel-stats').style.display = 'none';
    }
}

function renderCardsMatrix(deckType, isRevealed, activeVote) {
    const wrapper = document.getElementById('decks-wrapper');
    wrapper.innerHTML = '';

    const grid = document.createElement('div');
    grid.className = 'cards-grid';

    const cards = availableDecks[deckType] || availableDecks['fibonacci'] || [];
    cards.forEach(cardValue => {
        const container = document.createElement('div');
        container.className = 'poker-card';
        if (activeVote === cardValue) container.classList.add('selected');
        if (activeVote && activeVote !== cardValue && !isRevealed) container.classList.add('dimmed');
        if (isRevealed) container.classList.add('locked');

        container.innerHTML = `
            <div class="card-inner">
                <span class="c-val">${cardValue}</span>
                <strong>${cardValue}</strong>
                <span class="c-val-rev">${cardValue}</span>
            </div>
        `;

        if (!isRevealed) {
            container.addEventListener('click', () => castVote(cardValue));
        }
        grid.appendChild(container);
    });
    wrapper.appendChild(grid);
}

async function castVote(value) {
    await fetch('index.php?action=submit_vote', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ room: currentRoom, name: currentUser, vote: value })
    });
}

async function updateRoomConfig(payload) {
    payload.room = currentRoom;
    payload.name = currentUser;
    await fetch('index.php?action=update_room', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
}

function renderParticipants(list, isRevealed) {
    const container = document.getElementById('participants-list');
    container.innerHTML = '';

    Object.values(list).forEach(p => {
        const item = document.createElement('div');
        item.className = 'participant-item';

        let badgeStyle = p.role === 'admin' ? 'admin-badge' : (p.role === 'moderator' ? 'moderator-badge' : 'user-badge');
        let badgeTranslated = i18nDict[badgeStyle] || p.role;

        let statusIndicator = '';
        if (p.vote !== null) {
            if (isRevealed) {
                statusIndicator = `<div class="vote-revealed-inline">${p.vote}</div>`;
            } else {
                statusIndicator = `<div class="mini-card-back">✓</div>`;
            }
        } else {
            statusIndicator = `<span style="font-size:0.85rem; color:var(--text-muted); font-weight:700;">...</span>`;
        }

        let kickAction = '';
        if ((currentRole === 'admin' || currentRole === 'moderator') && p.name !== currentUser) {
            kickAction = `<button class="btn-tiny-subtle" title="${i18nDict['kick'] || 'Kick'}" onclick="kickTarget('${p.name}')">×</button>`;
        }

        item.innerHTML = `
            <div class="p-info">
                ${statusIndicator}
                <div>
                    <strong style="display:block; font-size:0.95rem;">${p.name}</strong>
                    <span style="font-size:0.7rem; font-weight:800; text-transform:uppercase; color:var(--text-muted);">${badgeTranslated}</span>
                </div>
            </div>
            ${kickAction}
        `;
        container.appendChild(item);
    });
}

async function kickTarget(targetName) {
    await fetch('index.php?action=kick_user', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ room: currentRoom, name: currentUser, target: targetName })
    });
}

function handleTimerExecution(targetTime) {
    const elNum = document.getElementById('countdown-number');
    const elProg = document.getElementById('countdown-progress');
    
    if (!targetTime) {
        elNum.textContent = '--';
        elProg.style.strokeDashoffset = '0';
        return;
    }

    const now = Math.floor(Date.now() / 1000);
    const left = targetTime - now;

    if (left <= 0) {
        elNum.textContent = '0';
        elProg.style.strokeDashoffset = '251.2';
        if (currentRole === 'admin' || currentRole === 'moderator') {
            // Automatisches Reveal bei Ablauf ausführen
            updateRoomConfig({ revealed: true, stopTimer: true });
        }
    } else {
        elNum.textContent = left;
        let percentage = (left / 30) * 251.2; // Fallback Max-Bound Mapping Base
        elProg.style.strokeDashoffset = 251.2 - percentage;
    }
}

function calculateStatistics(participants, deckType) {
    let votes = Object.values(participants).map(p => p.vote).filter(v => v !== null && v !== '?' && v !== '☕');
    if (votes.length === 0) {
        resetStatUi();
        return;
    }

    let numericalVotes = votes.map(v => parseFloat(v)).filter(v => !isNaN(v));

    let avg = '-';
    let med = '-';
    if (numericalVotes.length > 0) {
        numericalVotes.sort((a, b) => a - b);
        avg = (numericalVotes.reduce((a, b) => a + b, 0) / numericalVotes.length).toFixed(1);
        
        let mid = Math.floor(numericalVotes.length / 2);
        med = numericalVotes.length % 2 !== 0 ? numericalVotes[mid] : ((numericalVotes[mid - 1] + numericalVotes[mid]) / 2).toFixed(1);
    }

    // Modus (Häufigster Wert)
    let counts = {};
    let maxCount = 0;
    let modes = [];
    votes.forEach(v => {
        counts[v] = (counts[v] || 0) + 1;
        if (counts[v] > maxCount) {
            maxCount = counts[v];
            modes = [v];
        } else if (counts[v] === maxCount && !modes.includes(v)) {
            modes.push(v);
        }
    });

    document.getElementById('stat-average').textContent = avg;
    document.getElementById('stat-median').textContent = med;
    document.getElementById('stat-modus').textContent = modes.join(', ');

    // AI Recommender Simulation Matrix
    const recValue = document.getElementById('rec-value');
    const recReason = document.getElementById('rec-reason');

    if (modes.length === 1) {
        recValue.textContent = modes[0];
        recReason.textContent = i18nDict['rec-mode'] || 'Suggested by statistical frequency.';
    } else if (modes.length > 1 && numericalVotes.length > 0) {
        recValue.textContent = Math.round(med);
        recReason.textContent = i18nDict['rec-tied'] || 'Split decision. Recommend discussion.';
    } else {
        recValue.textContent = votes[0] || '-';
        recReason.textContent = i18nDict['rec-exact'] || 'Consensus estimation dynamic.';
    }

    // Distribution Bars Renderer
    const distContainer = document.getElementById('distribution-bars');
    distContainer.innerHTML = '';
    
    let totalVotes = votes.length;
    let rawCards = availableDecks[deckType] || [];
    
    rawCards.forEach(c => {
        if (counts[c]) {
            const count = counts[c];
            const pct = (count / totalVotes) * 100;
            const isHighest = modes.includes(c);

            const row = document.createElement('div');
            row.className = `distribution-row ${isHighest ? 'highest-rank' : ''}`;
            row.innerHTML = `
                <div class="dist-card-badge">${c}</div>
                <div class="dist-bar-wrapper"><div class="dist-bar-fill" style="width: ${pct}%"></div></div>
                <div class="dist-count-text">${count}x (${Math.round(pct)}%)</div>
            `;
            distContainer.appendChild(row);
        }
    });
}

function resetStatUi() {
    document.getElementById('stat-average').textContent = '-';
    document.getElementById('stat-median').textContent = '-';
    document.getElementById('stat-modus').textContent = '-';
    document.getElementById('rec-value').textContent = '-';
    document.getElementById('rec-reason').textContent = i18nDict['rec-no-votes'] || 'Awaiting votes...';
    document.getElementById('distribution-bars').innerHTML = '';
}