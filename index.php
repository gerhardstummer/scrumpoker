<?php
/**
 * Scrum Poker – page shell and JSON API.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/lib.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}
$uid = scrumEnsureUid();

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    if ($action === 'get_decks') {
        $path = scrumCardsFile();
        echo is_file($path) ? file_get_contents($path) : json_encode(scrumDefaultDecks());
        exit;
    }

    if ($action === 'get_language') {
        $payload = scrumLanguagePayload($_GET['lang'] ?? 'de');
        if (!$payload['ok']) {
            header('X-Lang-Fallback: 1');
        }
        echo $payload['json'];
        exit;
    }

    if ($action === 'get_session') {
        echo json_encode(['success' => true, 'csrf' => scrumCsrfToken()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mutating = ['join_room', 'submit_vote', 'update_room', 'delete_room', 'logout'];
    if (in_array($action, $mutating, true) && !scrumCsrfCheck()) {
        echo json_encode(['success' => false, 'message' => 'csrf']);
        exit;
    }

    $result = scrumWithRooms(function (&$rooms) use ($action, $input, $uid) {
        if ($action === 'join_room') {
            return scrumJoinRoom(
                $rooms,
                $uid,
                $input['room'] ?? '',
                $input['name'] ?? '',
                $input['role'] ?? 'user',
                $input['password'] ?? null
            );
        }
        if ($action === 'get_state') {
            $room = $_GET['room'] ?? ($input['room'] ?? '');
            return scrumGetState($rooms, $uid, $room);
        }
        if ($action === 'submit_vote') {
            $deck = array_key_exists('deck', $input) ? $input['deck'] : null;
            $vote = array_key_exists('vote', $input) ? $input['vote'] : null;
            if (is_array($vote)) {
                $deck = $vote['deck'] ?? $deck;
                $vote = $vote['value'] ?? null;
            }
            return scrumSubmitVote($rooms, $uid, $input['room'] ?? '', $deck, $vote);
        }
        if ($action === 'update_room') {
            return scrumUpdateRoom($rooms, $uid, $input);
        }
        if ($action === 'list_rooms') {
            return scrumListRooms($rooms, $uid, $input['room'] ?? ($_GET['room'] ?? ''));
        }
        if ($action === 'delete_room') {
            return scrumDeleteRoom($rooms, $uid, $input['room'] ?? '', $input['target'] ?? '');
        }
        if ($action === 'logout') {
            $out = scrumLogout($rooms, $uid);
            return $out;
        }
        return ['success' => false, 'message' => 'unknown_action'];
    });

    if ($action === 'logout' && !empty($result['success'])) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scrum Poker</title>
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body data-csrf="<?php echo htmlspecialchars(scrumCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <div id="login-container" class="login-container">
        <div class="login-card glass">
            <div class="login-card-head">
                <h2 data-i18n="login-title">Scrum Poker Pro</h2>
                <select id="lang-select" aria-label="Language">
                    <option value="de">Deutsch</option>
                    <option value="en">English</option>
                    <option value="hu">Magyar</option>
                </select>
            </div>
            <div class="form-group">
                <label data-i18n="lbl-room" for="room-id-input">Raum-ID</label>
                <input type="text" id="room-id-input" maxlength="64" autocomplete="off">
            </div>
            <div class="form-group">
                <label data-i18n="lbl-name" for="username-input">Dein Name</label>
                <input type="text" id="username-input" maxlength="32" autocomplete="nickname">
            </div>
            <div class="form-group">
                <label data-i18n="lbl-role" for="role-select">Deine Rolle</label>
                <select id="role-select">
                    <option value="user" data-i18n="role-user">User</option>
                    <option value="moderator" data-i18n="role-moderator">Moderator</option>
                    <option value="admin" data-i18n="role-admin">Admin</option>
                </select>
            </div>
            <div class="form-group" id="admin-password-group" hidden>
                <label data-i18n="lbl-password" for="admin-password-input">Passwort</label>
                <input type="password" id="admin-password-input" maxlength="64" autocomplete="current-password">
            </div>
            <p id="login-error" class="form-error" hidden></p>
            <button type="button" id="btn-join" class="btn-primary" data-i18n="btn-join" style="width:100%;">Raum betreten</button>
        </div>
    </div>

    <div id="main-layout" class="main-layout" hidden>
        <div class="left-column">
            <header class="glass">
                <h1 id="display-room-title">Scrumpoker für den Raum</h1>
                <div class="header-actions">
                    <p id="display-user-info"></p>
                    <select id="workspace-lang-select" aria-label="Language">
                        <option value="de">Deutsch</option>
                        <option value="en">English</option>
                        <option value="hu">Magyar</option>
                    </select>
                    <button type="button" id="btn-theme-toggle" class="btn-outline" data-i18n-title="btn-theme">🌓</button>
                    <button type="button" id="btn-logout" class="btn-outline" data-i18n="btn-logout">Abmelden</button>
                </div>
            </header>

            <div class="panel glass" id="panel-moderation" hidden>
                <span class="panel-label" data-i18n="moderation-title">Moderation</span>
                <div class="button-row">
                    <button type="button" id="btn-reveal" class="btn-primary" data-i18n="btn-reveal">Aufdecken</button>
                    <button type="button" id="btn-reset" class="btn-outline" data-i18n="btn-reset">Zurücksetzen</button>
                    <button type="button" id="btn-clear" class="btn-secondary" data-i18n="btn-clear">Clear</button>
                </div>
            </div>

            <div class="panel glass" id="panel-cards">
                <span class="panel-label" data-i18n="cards-title">Karten</span>
                <div id="decks-wrapper"></div>
            </div>

            <div class="panel glass" id="panel-story">
                <span class="panel-label" data-i18n="story-title">Story</span>
                <div id="story-display">-</div>
                <div class="form-group story-edit">
                    <label data-i18n="lbl-story-url" for="input-story-url">Story-Link / Ticket-ID</label>
                    <input type="text" id="input-story-url" maxlength="500">
                </div>
            </div>
        </div>

        <div class="right-column">
            <div class="panel glass" id="panel-timer-hub" hidden>
                <span class="panel-label" data-i18n="countdown-title">Countdown</span>
                <div class="countdown-container">
                    <svg class="countdown-svg" viewBox="0 0 100 100" aria-hidden="true">
                        <circle r="40" cx="50" cy="50" class="countdown-circle-bg"/>
                        <circle r="40" cx="50" cy="50" id="countdown-progress" class="countdown-circle-progress" stroke-dasharray="251.2" stroke-dashoffset="0"/>
                    </svg>
                    <div id="countdown-number">--</div>
                </div>
            </div>

            <div class="panel glass">
                <span class="panel-label" data-i18n="participants-title">Team-Übersicht</span>
                <div id="participants-list"></div>
            </div>

            <div class="panel glass" id="panel-stats" hidden>
                <span class="panel-label" data-i18n="stats-title">Statistische Auswertung</span>
                <div class="stats-poker-grid">
                    <div class="mini-card-stat"><small data-i18n="lbl-count">Anzahl</small><strong id="stat-count">-</strong></div>
                    <div class="mini-card-stat"><small data-i18n="lbl-average">Schnitt</small><strong id="stat-average">-</strong></div>
                    <div class="mini-card-stat"><small data-i18n="lbl-median">Median</small><strong id="stat-median">-</strong></div>
                    <div class="mini-card-stat"><small data-i18n="lbl-modus">Modus</small><strong id="stat-modus">-</strong></div>
                </div>
                <div class="recommendation-box">
                    <div class="rec-icon" aria-hidden="true">★</div>
                    <div class="rec-details">
                        <small data-i18n="rec-title">Empfehlung</small>
                        <div class="rec-value" id="rec-value">-</div>
                        <div class="rec-reason" id="rec-reason">-</div>
                    </div>
                </div>
                <div class="distribution-title" data-i18n="distribution-title">Verteilung</div>
                <div id="distribution-bars"></div>
            </div>

            <div class="panel glass" id="panel-deck-config" hidden>
                <span class="panel-label" data-i18n="deck-config-title">Deck-Konfiguration</span>
                <div class="form-group">
                    <label data-i18n="lbl-timer-duration" for="input-timer-duration">Countdown (Sek.)</label>
                    <input type="number" id="input-timer-duration" value="5" min="1" max="600">
                </div>
                <div class="form-group">
                    <label data-i18n="lbl-edit-fibonacci" for="input-deck-fibonacci">Fibonacci</label>
                    <input type="text" id="input-deck-fibonacci">
                </div>
                <div class="form-group">
                    <label data-i18n="lbl-edit-tshirt" for="input-deck-tshirt">T-Shirt</label>
                    <input type="text" id="input-deck-tshirt">
                </div>
                <div class="form-group">
                    <label data-i18n="lbl-edit-days" for="input-deck-days">Personentage</label>
                    <input type="text" id="input-deck-days">
                </div>
                <button type="button" id="btn-save-config" class="btn-primary" data-i18n="btn-save-config">Speichern</button>
            </div>

            <div class="panel glass" id="panel-rooms" hidden>
                <span class="panel-label" data-i18n="rooms-title">Raumliste</span>
                <div id="rooms-list"></div>
            </div>
        </div>
    </div>

    <script src="index.js"></script>
</body>
</html>
