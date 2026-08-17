<?php
/**
 * Professional Scrum Poker - Core Backend Engine
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

$roomsFile = __DIR__ . '/rooms.json';
$cardsFile = __DIR__ . '/cards.json';

function loadJson($file) {
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function saveJson($data, $file) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

$action = $_GET['action'] ?? '';

// API Endpunkte abfangen
if ($action) {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $rooms = loadJson($roomsFile);

    if ($action === 'get_decks') {
        echo file_get_contents($cardsFile);
        exit;
    }

    if ($action === 'get_language') {
        $lang = preg_replace('/[^a-z]/', '', $_GET['lang'] ?? 'de');
        $langPath = __DIR__ . "/lang/{$lang}.json";
        if (!file_exists($langPath)) {
            $langPath = __DIR__ . '/lang/de.json';
        }
        echo file_get_contents($langPath);
        exit;
    }

    if ($action === 'join_room') {
        $room = trim($input['room'] ?? '');
        $name = trim($input['name'] ?? '');
        $role = trim($input['role'] ?? 'user');

        if (!$room || !$name) {
            echo json_encode(['success' => false, 'message' => 'Missing fields']);
            exit;
        }

        if (!isset($rooms[$room])) {
            $rooms[$room] = [
                'deckType' => 'fibonacci',
                'storyUrl' => '',
                'revealed' => false,
                'timerTarget' => null,
                'timerDuration' => 30,
                'participants' => []
            ];
        }

        if (isset($rooms[$room]['participants'][$name]) && $rooms[$room]['participants'][$name]['role'] !== $role) {
            $name = $name . '_' . rand(10, 99);
        }

        $rooms[$room]['participants'][$name] = [
            'name' => $name,
            'role' => $role,
            'vote' => null,
            'lastSeen' => time()
        ];

        saveJson($rooms, $roomsFile);
        echo json_encode(['success' => true, 'room' => $room, 'name' => $name, 'role' => $role]);
        exit;
    }

    if ($action === 'get_state') {
        $room = $_GET['room'] ?? '';
        $name = $_GET['name'] ?? '';

        if (!isset($rooms[$room])) {
            echo json_encode(['success' => false, 'message' => 'Room not found']);
            exit;
        }

        if (isset($rooms[$room]['participants'][$name])) {
            $rooms[$room]['participants'][$name]['lastSeen'] = time();
        }
        
        // Timeout inaktiver User (15 Sek)
        foreach ($rooms[$room]['participants'] as $pName => $pData) {
            if (time() - $pData['lastSeen'] > 15) {
                unset($rooms[$room]['participants'][$pName]);
            }
        }
        saveJson($rooms, $roomsFile);

        echo json_encode(['success' => true, 'data' => $rooms[$room]]);
        exit;
    }

    if ($action === 'submit_vote') {
        $room = $input['room'] ?? '';
        $name = $input['name'] ?? '';
        $vote = $input['vote'] ?? null;

        if (isset($rooms[$room]['participants'][$name])) {
            $rooms[$room]['participants'][$name]['vote'] = $vote;
            saveJson($rooms, $roomsFile);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Session lost']);
        }
        exit;
    }

    if ($action === 'update_room') {
        $room = $input['room'] ?? '';
        $name = $input['name'] ?? '';
        
        if (!isset($rooms[$room]['participants'][$name]) || 
           ($rooms[$room]['participants'][$name]['role'] !== 'admin' && $rooms[$room]['participants'][$name]['role'] !== 'moderator')) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        if (isset($input['deckType'])) $rooms[$room]['deckType'] = $input['deckType'];
        if (isset($input['storyUrl'])) $rooms[$room]['storyUrl'] = trim($input['storyUrl']);
        if (isset($input['revealed'])) $rooms[$room]['revealed'] = (bool)$input['revealed'];
        
        if (isset($input['startTimer']) && $input['startTimer']) {
            $duration = intval($input['timerDuration'] ?? 30);
            $rooms[$room]['timerDuration'] = $duration;
            $rooms[$room]['timerTarget'] = time() + $duration;
            $rooms[$room]['revealed'] = false; 
        }
        if (isset($input['stopTimer']) && $input['stopTimer']) {
            $rooms[$room]['timerTarget'] = null;
        }
        
        if (isset($input['reset']) && $input['reset']) {
            $rooms[$room]['revealed'] = false;
            $rooms[$room]['timerTarget'] = null;
            foreach ($rooms[$room]['participants'] as $pName => $pData) {
                $rooms[$room]['participants'][$pName]['vote'] = null;
            }
        }
        
        saveJson($rooms, $roomsFile);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'kick_user') {
        $room = $input['room'] ?? '';
        $name = $input['name'] ?? '';
        $target = $input['target'] ?? '';

        if (isset($rooms[$room]['participants'][$name]) && 
           ($rooms[$room]['participants'][$name]['role'] === 'admin' || $rooms[$room]['participants'][$name]['role'] === 'moderator')) {
            unset($rooms[$room]['participants'][$target]);
            saveJson($rooms, $roomsFile);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
        }
        exit;
    }
    exit;
}

// HTML Viewport
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scrum Poker Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <!-- UI BLOCK 1: SIGN-IN HUB -->
    <div id="login-container" class="login-container">
        <div class="login-card glass">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2 data-i18n="login-title">Scrum Poker Pro</h2>
                <select id="lang-select" style="width: auto; padding: 0.4rem 0.8rem; font-size: 0.85rem;">
                    <option value="de">DE</option>
                    <option value="en">EN</option>
                </select>
            </div>
            <div class="form-group">
                <label id="lbl-room" data-i18n="lbl-room" for="room-id-input">Raum-ID</label>
                <input type="text" id="room-id-input" value="Sprint-Wechsel">
            </div>
            <div class="form-group">
                <label id="lbl-name" data-i18n="lbl-name" for="username-input">Dein Name</label>
                <input type="text" id="username-input" placeholder="Vorname...">
            </div>
            <div class="form-group">
                <label id="lbl-role" data-i18n="lbl-role" for="role-select">Deine Rolle</label>
                <select id="role-select">
                    <option value="user" data-i18n="role-user">User</option>
                    <option value="moderator" data-i18n="role-moderator">Moderator</option>
                    <option value="admin" data-i18n="role-admin">Admin</option>
                </select>
            </div>
            <button id="btn-join" data-i18n="btn-join" class="btn-primary" style="width:100%;">Raum betreten</button>
        </div>
    </div>

    <!-- UI BLOCK 2: APPLICATION WORKSPACE -->
    <div id="main-layout" class="main-layout" style="display: none;">
        <div class="left-column">
            <header class="glass">
                <div>
                    <h1 id="display-room-name">#Raum</h1>
                    <p id="display-user-info" style="font-size:0.9rem; color:var(--text-muted); font-weight:600;"></p>
                </div>
                <div style="display:flex; gap:0.75rem; align-items:center;">
                    <select id="workspace-lang-select" style="width: auto; padding: 0.5rem 1rem;">
                        <option value="de">DE</option>
                        <option value="en">EN</option>
                    </select>
                    <button id="btn-theme-toggle" class="btn-outline" style="padding: 0.6rem 1rem;">🌓</button>
                </div>
            </header>

            <div class="panel glass">
                <span class="panel-label" data-i18n="focus-target">Active Ticket / Focus Target</span>
                <div id="story-display">-</div>
            </div>

            <div id="decks-wrapper"></div>
        </div>

        <div class="right-column">
            <div class="panel glass" id="panel-timer-hub" style="text-align:center;">
                <span class="panel-label" data-i18n="timeboxing-title">Timeboxing & Sync</span>
                <div class="countdown-container">
                    <svg class="countdown-svg">
                        <circle r="40" cx="50" cy="50" class="countdown-circle-bg"/>
                        <circle r="40" cx="50" cy="50" id="countdown-progress" class="countdown-circle-progress" stroke-dasharray="251.2" stroke-dashoffset="0"/>
                    </svg>
                    <div id="countdown-number">--</div>
                </div>
            </div>

            <div class="panel glass" id="panel-admin" style="display: none;">
                <span class="panel-label" data-i18n="admin-panel-title">Steuerungskonsole</span>
                <div class="form-group">
                    <label data-i18n="lbl-deck-type">Kartendeck wechseln</label>
                    <select id="select-deck-type">
                        <option value="fibonacci">Fibonacci (0, 1, 2, 3, 5, 8...)</option>
                        <option value="tshirt">T-Shirt Sizes (XS, S, M, L, XL)</option>
                        <option value="risk">Risk Matrix</option>
                    </select>
                </div>
                <div class="form-group">
                    <label data-i18n="lbl-story-url">Story-Link / Ticket-ID</label>
                    <input type="text" id="input-story-url">
                </div>
                <div class="form-group" style="display:grid; grid-template-columns: 1fr 80px; gap:0.5rem; align-items:end;">
                    <div>
                        <label data-i18n="lbl-timer-duration">Dauer (Sek.)</label>
                        <input type="number" id="input-timer-duration" value="30" min="5">
                    </div>
                    <button id="btn-start-timer" data-i18n="btn-start-timer" class="btn-secondary" style="padding:0.9rem 0.5rem; font-size:0.85rem;">Start</button>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-top: 1.5rem;">
                    <button id="btn-reveal" data-i18n="btn-reveal" class="btn-primary">Aufdecken 👁️</button>
                    <button id="btn-reset" data-i18n="btn-reset" class="btn-outline">Reset 🔄</button>
                </div>
            </div>

            <div class="panel glass">
                <span class="panel-label" data-i18n="participants-title">Team-Übersicht</span>
                <div id="participants-list"></div>
            </div>

            <div class="panel glass" id="panel-stats" style="display: none;">
                <span class="panel-label" data-i18n="stats-title">Statistische Auswertung</span>
                <div class="stats-poker-grid">
                    <div class="mini-card-stat"><small data-i18n="lbl-average">Schnitt</small><strong id="stat-average">-</strong></div>
                    <div class="mini-card-stat"><small data-i18n="lbl-median">Median</small><strong id="stat-median">-</strong></div>
                    <div class="mini-card-stat"><small data-i18n="lbl-modus">Modus</small><strong id="stat-modus">-</strong></div>
                </div>

                <div class="recommendation-box">
                    <div class="rec-icon">🤖</div>
                    <div class="rec-details">
                        <small data-i18n="ai-rec-title">AI Recommendation</small>
                        <div class="rec-value" id="rec-value">-</div>
                        <div class="rec-reason" id="rec-reason">-</div>
                    </div>
                </div>

                <div class="distribution-title" data-i18n="distribution-title">Distribution Mapping</div>
                <div id="distribution-bars"></div>
            </div>
        </div>
    </div>

    <script src="index.js"></script>
</body>
</html>