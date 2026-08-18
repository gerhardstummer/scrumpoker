<?php
/**
 * Scrum Poker – shared backend logic (file-locked rooms, roles, votes).
 */

define('SCRUM_ONLINE_TIMEOUT', 8);
define('SCRUM_MAX_ROOM', 64);
define('SCRUM_MAX_NAME', 32);
define('SCRUM_MAX_STORY', 500);
define('SCRUM_MAX_CARD', 16);
define('SCRUM_MAX_CARDS', 40);

function scrumRoomsFile() {
    return $GLOBALS['SCRUM_ROOMS_FILE'] ?? (__DIR__ . '/rooms.json');
}

function scrumCardsFile() {
    return $GLOBALS['SCRUM_CARDS_FILE'] ?? (__DIR__ . '/cards.json');
}

function scrumStrLen($s) {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function scrumStrCut($s, $n) {
    return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
}

function scrumStrLower($s) {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function scrumSanitize($value, $maxLen) {
    $s = trim((string)$value);
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
    if ($s === null) {
        $s = '';
    }
    if (scrumStrLen($s) > $maxLen) {
        $s = scrumStrCut($s, $maxLen);
    }
    return $s;
}

function scrumDefaultDecks() {
    $path = scrumCardsFile();
    $cards = [];
    if (is_file($path)) {
        $cards = json_decode((string)file_get_contents($path), true) ?: [];
    }
    return [
        'fibonacci' => isset($cards['fibonacci']) && is_array($cards['fibonacci'])
            ? array_values($cards['fibonacci'])
            : ['0', '1', '2', '3', '5', '8', '13', '20', '40', '100', '?'],
        'tshirt' => isset($cards['tshirt']) && is_array($cards['tshirt'])
            ? array_values($cards['tshirt'])
            : ['XS', 'S', 'M', 'L', 'X', 'XL', 'XXL', '?'],
        'days' => isset($cards['days']) && is_array($cards['days'])
            ? array_values($cards['days'])
            : ['1', '5', '10', '15', '20', '25', '30', '35', '50', '75', '100', '125', '150', '200'],
    ];
}

function scrumDefaultRoom() {
    return [
        'storyUrl' => '',
        'revealed' => false,
        'timerTarget' => null,
        'timerDuration' => 5,
        'activeDecks' => [
            'fibonacci' => true,
            'tshirt' => true,
            'days' => false,
        ],
        'decks' => scrumDefaultDecks(),
        'participants' => [],
    ];
}

function scrumNormalizeRoom($room) {
    $base = scrumDefaultRoom();
    if (!is_array($room)) {
        return $base;
    }
    $out = array_merge($base, $room);
    $out['storyUrl'] = isset($out['storyUrl']) ? (string)$out['storyUrl'] : '';
    $out['revealed'] = !empty($out['revealed']);
    $out['timerTarget'] = isset($out['timerTarget']) && $out['timerTarget'] !== null ? (int)$out['timerTarget'] : null;
    $out['timerDuration'] = max(1, (int)($out['timerDuration'] ?? 5));
    $out['activeDecks'] = array_merge($base['activeDecks'], is_array($out['activeDecks'] ?? null) ? $out['activeDecks'] : []);
    $out['decks'] = array_merge($base['decks'], is_array($out['decks'] ?? null) ? $out['decks'] : []);
    $out['participants'] = is_array($out['participants'] ?? null) ? $out['participants'] : [];
    foreach ($out['participants'] as $id => $p) {
        if (!is_array($p)) {
            unset($out['participants'][$id]);
            continue;
        }
        if (isset($p['vote']) && is_string($p['vote'])) {
            $p['vote'] = ['deck' => 'fibonacci', 'value' => $p['vote']];
        }
        $p['id'] = $p['id'] ?? $id;
        $p['banned'] = !empty($p['banned']);
        $p['online'] = !empty($p['online']);
        $out['participants'][$id] = $p;
    }
    return $out;
}

function scrumParseDeckValues($raw) {
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = explode(',', (string)$raw);
    }
    $out = [];
    foreach ($parts as $part) {
        $v = scrumSanitize($part, SCRUM_MAX_CARD);
        if ($v === '') {
            continue;
        }
        $out[] = $v;
        if (count($out) >= SCRUM_MAX_CARDS) {
            break;
        }
    }
    return $out;
}

function scrumEnsureUid() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['uid']) || !is_string($_SESSION['uid'])) {
        $_SESSION['uid'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['uid'];
}

function scrumIsPrivileged($participant) {
    if (!is_array($participant)) {
        return false;
    }
    $role = $participant['role'] ?? '';
    return $role === 'admin' || $role === 'moderator';
}

function scrumIsAdmin($participant) {
    return is_array($participant) && ($participant['role'] ?? '') === 'admin';
}

function scrumFindParticipant($room, $uid) {
    return $room['participants'][$uid] ?? null;
}

function scrumUniqueName($participants, $name, $selfId) {
    $taken = [];
    foreach ($participants as $id => $p) {
        if ($id === $selfId || !isset($p['name'])) {
            continue;
        }
        $taken[scrumStrLower($p['name'])] = true;
    }
    $base = $name !== '' ? $name : 'User';
    $candidate = $base;
    $i = 0;
    while (isset($taken[scrumStrLower($candidate)])) {
        $i++;
        $candidate = $base . '_' . $i;
    }
    return $candidate;
}

function scrumResetVotes(&$room) {
    foreach ($room['participants'] as $id => $p) {
        $room['participants'][$id]['vote'] = null;
    }
}

function scrumExpireTimer(&$room) {
    if (!empty($room['timerTarget']) && time() >= (int)$room['timerTarget']) {
        $room['revealed'] = true;
        $room['timerTarget'] = null;
        return true;
    }
    return false;
}

function scrumMarkPresence(&$room, $uid) {
    $now = time();
    if ($uid && isset($room['participants'][$uid])) {
        $room['participants'][$uid]['lastSeen'] = $now;
        $room['participants'][$uid]['online'] = true;
    }
    foreach ($room['participants'] as $id => $p) {
        $last = (int)($p['lastSeen'] ?? 0);
        $room['participants'][$id]['online'] = ($now - $last) <= SCRUM_ONLINE_TIMEOUT;
    }
}

function scrumLeaveOtherRooms(&$rooms, $uid, $keepRoom) {
    foreach ($rooms as $roomName => $room) {
        if ($roomName === $keepRoom) {
            continue;
        }
        if (!isset($rooms[$roomName]['participants'][$uid])) {
            continue;
        }
        $banned = !empty($rooms[$roomName]['participants'][$uid]['banned']);
        if ($banned) {
            $rooms[$roomName]['participants'][$uid]['online'] = false;
        } else {
            unset($rooms[$roomName]['participants'][$uid]);
        }
    }
}

function scrumPublicRoom($room) {
    $room = scrumNormalizeRoom($room);
    scrumExpireTimer($room);
    return $room;
}

/**
 * Exclusive lock, mutate $rooms by reference via callback, persist.
 * Callback signature: function (&$rooms) { return $resultArray; }
 */
function scrumWithRooms($callback) {
    $file = scrumRoomsFile();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        return ['success' => false, 'message' => 'storage_unavailable'];
    }
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return ['success' => false, 'message' => 'storage_unavailable'];
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return ['success' => false, 'message' => 'storage_unavailable'];
    }
    $stat = fstat($fp);
    $size = (int)($stat['size'] ?? 0);
    $raw = '';
    if ($size > 0) {
        $raw = fread($fp, $size);
        if ($raw === false) {
            $raw = '';
        }
    }
    $rooms = json_decode($raw, true);
    if (!is_array($rooms)) {
        $rooms = [];
    }

    try {
        $result = $callback($rooms);
        if (!is_array($result)) {
            $result = ['success' => false, 'message' => 'internal'];
        }
    } catch (Exception $e) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return ['success' => false, 'message' => 'internal'];
    }

    if (!empty($result['success'])) {
        $json = json_encode($rooms, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['success' => false, 'message' => 'storage_unavailable'];
        }
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, $json);
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function scrumJoinRoom(&$rooms, $uid, $roomName, $name, $requestedRole) {
    $roomName = scrumSanitize($roomName, SCRUM_MAX_ROOM);
    $name = scrumSanitize($name, SCRUM_MAX_NAME);
    if ($roomName === '' || $name === '' || $uid === '') {
        return ['success' => false, 'message' => 'missing_fields'];
    }
    $allowedRoles = ['user', 'moderator', 'admin'];
    if (!in_array($requestedRole, $allowedRoles, true)) {
        $requestedRole = 'user';
    }

    $isNew = !isset($rooms[$roomName]);
    if ($isNew) {
        $rooms[$roomName] = scrumDefaultRoom();
        $role = $requestedRole === 'user' ? 'moderator' : $requestedRole;
    } else {
        $rooms[$roomName] = scrumNormalizeRoom($rooms[$roomName]);
        $role = 'user';
    }

    scrumLeaveOtherRooms($rooms, $uid, $roomName);

    if (isset($rooms[$roomName]['participants'][$uid])) {
        $existing = $rooms[$roomName]['participants'][$uid];
        $role = $existing['role'] ?? $role;
        $name = scrumUniqueName($rooms[$roomName]['participants'], $name, $uid);
        $rooms[$roomName]['participants'][$uid]['name'] = $name;
        $rooms[$roomName]['participants'][$uid]['lastSeen'] = time();
        $rooms[$roomName]['participants'][$uid]['online'] = true;
        if (empty($rooms[$roomName]['participants'][$uid]['banned'])) {
            $rooms[$roomName]['participants'][$uid]['banned'] = false;
        }
    } else {
        $name = scrumUniqueName($rooms[$roomName]['participants'], $name, $uid);
        $rooms[$roomName]['participants'][$uid] = [
            'id' => $uid,
            'name' => $name,
            'role' => $role,
            'vote' => null,
            'lastSeen' => time(),
            'banned' => false,
            'online' => true,
        ];
    }

    scrumExpireTimer($rooms[$roomName]);
    scrumMarkPresence($rooms[$roomName], $uid);

    return [
        'success' => true,
        'room' => $roomName,
        'name' => $rooms[$roomName]['participants'][$uid]['name'],
        'role' => $rooms[$roomName]['participants'][$uid]['role'],
        'banned' => !empty($rooms[$roomName]['participants'][$uid]['banned']),
        'data' => scrumPublicRoom($rooms[$roomName]),
    ];
}

function scrumGetState(&$rooms, $uid, $roomName) {
    $roomName = scrumSanitize($roomName, SCRUM_MAX_ROOM);
    if ($roomName === '' || !isset($rooms[$roomName])) {
        return ['success' => false, 'message' => 'room_not_found'];
    }
    $rooms[$roomName] = scrumNormalizeRoom($rooms[$roomName]);
    if (!isset($rooms[$roomName]['participants'][$uid])) {
        return ['success' => false, 'message' => 'session_lost'];
    }
    scrumExpireTimer($rooms[$roomName]);
    scrumMarkPresence($rooms[$roomName], $uid);
    $me = $rooms[$roomName]['participants'][$uid];
    return [
        'success' => true,
        'data' => scrumPublicRoom($rooms[$roomName]),
        'role' => $me['role'],
        'name' => $me['name'],
        'banned' => !empty($me['banned']),
    ];
}

function scrumSubmitVote(&$rooms, $uid, $roomName, $deck, $value) {
    $roomName = scrumSanitize($roomName, SCRUM_MAX_ROOM);
    if (!isset($rooms[$roomName])) {
        return ['success' => false, 'message' => 'room_not_found'];
    }
    $rooms[$roomName] = scrumNormalizeRoom($rooms[$roomName]);
    scrumExpireTimer($rooms[$roomName]);
    if (!isset($rooms[$roomName]['participants'][$uid])) {
        return ['success' => false, 'message' => 'session_lost'];
    }
    $me = $rooms[$roomName]['participants'][$uid];
    if (!empty($me['banned'])) {
        return ['success' => false, 'message' => 'banned'];
    }
    if (!empty($rooms[$roomName]['revealed'])) {
        return ['success' => false, 'message' => 'locked'];
    }

    if ($deck === null && $value === null) {
        $rooms[$roomName]['participants'][$uid]['vote'] = null;
        $rooms[$roomName]['participants'][$uid]['lastSeen'] = time();
        return ['success' => true];
    }

    $deck = scrumSanitize((string)$deck, 20);
    $value = scrumSanitize((string)$value, SCRUM_MAX_CARD);
    $active = !empty($rooms[$roomName]['activeDecks'][$deck]);
    $cards = $rooms[$roomName]['decks'][$deck] ?? [];
    if (!$active || !in_array($value, $cards, true)) {
        return ['success' => false, 'message' => 'invalid_vote'];
    }

    $current = $rooms[$roomName]['participants'][$uid]['vote'];
    if (is_array($current) && ($current['deck'] ?? '') === $deck && (string)($current['value'] ?? '') === $value) {
        $rooms[$roomName]['participants'][$uid]['vote'] = null;
    } else {
        $rooms[$roomName]['participants'][$uid]['vote'] = ['deck' => $deck, 'value' => $value];
    }
    $rooms[$roomName]['participants'][$uid]['lastSeen'] = time();
    return ['success' => true];
}

function scrumRequirePrivilege($room, $uid) {
    $me = scrumFindParticipant($room, $uid);
    if (!$me || !empty($me['banned']) || !scrumIsPrivileged($me)) {
        return false;
    }
    return $me;
}

function scrumUpdateRoom(&$rooms, $uid, $input) {
    $roomName = scrumSanitize($input['room'] ?? '', SCRUM_MAX_ROOM);
    if (!isset($rooms[$roomName])) {
        return ['success' => false, 'message' => 'room_not_found'];
    }
    $rooms[$roomName] = scrumNormalizeRoom($rooms[$roomName]);
    scrumExpireTimer($rooms[$roomName]);
    $me = scrumFindParticipant($rooms[$roomName], $uid);
    if (!$me) {
        return ['success' => false, 'message' => 'session_lost'];
    }

    if (array_key_exists('storyUrl', $input)) {
        if (!empty($me['banned'])) {
            return ['success' => false, 'message' => 'banned'];
        }
        $nextStory = scrumSanitize($input['storyUrl'], SCRUM_MAX_STORY);
        $isPlainUser = ($me['role'] ?? 'user') === 'user';
        if ($isPlainUser && $nextStory === '' && ($rooms[$roomName]['storyUrl'] ?? '') !== '') {
            return ['success' => false, 'message' => 'story_protected'];
        }
        $rooms[$roomName]['storyUrl'] = $nextStory;
    }

    $needsPrivilege = isset($input['reveal']) || isset($input['reset']) || isset($input['activeDecks'])
        || isset($input['clearAbsent']) || isset($input['ban']) || isset($input['unban'])
        || isset($input['changeRole']);
    $needsAdmin = isset($input['timerDuration']) || isset($input['decks']);

    if ($needsPrivilege && !scrumRequirePrivilege($rooms[$roomName], $uid)) {
        return ['success' => false, 'message' => 'unauthorized'];
    }
    if ($needsAdmin && (!scrumIsAdmin($me) || !empty($me['banned']))) {
        return ['success' => false, 'message' => 'unauthorized'];
    }

    if (!empty($input['reveal'])) {
        if (!empty($rooms[$roomName]['revealed']) || !empty($rooms[$roomName]['timerTarget'])) {
            return ['success' => false, 'message' => 'already_revealed'];
        }
        $duration = max(1, (int)$rooms[$roomName]['timerDuration']);
        $rooms[$roomName]['timerTarget'] = time() + $duration;
        $rooms[$roomName]['revealed'] = false;
    }

    if (!empty($input['reset'])) {
        $rooms[$roomName]['revealed'] = false;
        $rooms[$roomName]['timerTarget'] = null;
        $rooms[$roomName]['storyUrl'] = '';
        scrumResetVotes($rooms[$roomName]);
    }

    if (isset($input['activeDecks']) && is_array($input['activeDecks'])) {
        foreach (['fibonacci', 'tshirt', 'days'] as $key) {
            if (array_key_exists($key, $input['activeDecks'])) {
                $rooms[$roomName]['activeDecks'][$key] = !empty($input['activeDecks'][$key]);
            }
        }
        if (!in_array(true, $rooms[$roomName]['activeDecks'], true)) {
            $rooms[$roomName]['activeDecks']['fibonacci'] = true;
        }
        scrumResetVotes($rooms[$roomName]);
    }

    if (isset($input['timerDuration'])) {
        $rooms[$roomName]['timerDuration'] = max(1, min(600, (int)$input['timerDuration']));
    }

    if (isset($input['decks']) && is_array($input['decks'])) {
        foreach (['fibonacci', 'tshirt', 'days'] as $key) {
            if (isset($input['decks'][$key])) {
                $parsed = scrumParseDeckValues($input['decks'][$key]);
                if ($parsed) {
                    $rooms[$roomName]['decks'][$key] = $parsed;
                }
            }
        }
        scrumResetVotes($rooms[$roomName]);
    }

    if (!empty($input['clearAbsent'])) {
        foreach ($rooms[$roomName]['participants'] as $id => $p) {
            $online = !empty($p['online']) && (time() - (int)($p['lastSeen'] ?? 0)) <= SCRUM_ONLINE_TIMEOUT;
            if (!$online && empty($p['banned'])) {
                unset($rooms[$roomName]['participants'][$id]);
            }
        }
    }

    if (!empty($input['ban'])) {
        $target = (string)$input['ban'];
        if ($target !== $uid && isset($rooms[$roomName]['participants'][$target])) {
            $rooms[$roomName]['participants'][$target]['banned'] = true;
            $rooms[$roomName]['participants'][$target]['vote'] = null;
        }
    }

    if (!empty($input['unban'])) {
        $target = (string)$input['unban'];
        if (isset($rooms[$roomName]['participants'][$target])) {
            $rooms[$roomName]['participants'][$target]['banned'] = false;
        }
    }

    if (!empty($input['changeRole']) && isset($input['target'])) {
        $target = (string)$input['target'];
        $newRole = (string)$input['changeRole'];
        $actor = scrumFindParticipant($rooms[$roomName], $uid);
        $actorRole = $actor['role'] ?? '';
        if ($target !== $uid
            && isset($rooms[$roomName]['participants'][$target])
            && in_array($newRole, ['user', 'moderator', 'admin'], true)
            && ($actorRole === 'admin' || ($actorRole === 'moderator' && $newRole !== 'admin'))
        ) {
            $rooms[$roomName]['participants'][$target]['role'] = $newRole;
        }
    }

    return ['success' => true, 'data' => scrumPublicRoom($rooms[$roomName])];
}

function scrumDeleteRoom(&$rooms, $uid, $currentRoom, $targetRoom) {
    $currentRoom = scrumSanitize($currentRoom, SCRUM_MAX_ROOM);
    $targetRoom = scrumSanitize($targetRoom, SCRUM_MAX_ROOM);
    if (!isset($rooms[$currentRoom])) {
        return ['success' => false, 'message' => 'room_not_found'];
    }
    $me = scrumFindParticipant(scrumNormalizeRoom($rooms[$currentRoom]), $uid);
    if (!scrumIsAdmin($me) || !empty($me['banned'])) {
        return ['success' => false, 'message' => 'unauthorized'];
    }
    if (isset($rooms[$targetRoom])) {
        unset($rooms[$targetRoom]);
    }
    return ['success' => true];
}

function scrumListRooms($rooms, $uid, $currentRoom) {
    $currentRoom = scrumSanitize($currentRoom, SCRUM_MAX_ROOM);
    if (!isset($rooms[$currentRoom])) {
        return ['success' => false, 'message' => 'room_not_found'];
    }
    $me = scrumFindParticipant(scrumNormalizeRoom($rooms[$currentRoom]), $uid);
    if (!scrumIsAdmin($me) || !empty($me['banned'])) {
        return ['success' => false, 'message' => 'unauthorized'];
    }
    $list = [];
    foreach ($rooms as $name => $room) {
        $room = scrumNormalizeRoom($room);
        $online = 0;
        foreach ($room['participants'] as $p) {
            if (!empty($p['online'])) {
                $online++;
            }
        }
        $list[] = [
            'name' => $name,
            'participants' => count($room['participants']),
            'online' => $online,
        ];
    }
    usort($list, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return ['success' => true, 'rooms' => $list];
}

function scrumLogout(&$rooms, $uid) {
    foreach ($rooms as $roomName => $room) {
        if (!isset($rooms[$roomName]['participants'][$uid])) {
            continue;
        }
        if (!empty($rooms[$roomName]['participants'][$uid]['banned'])) {
            $rooms[$roomName]['participants'][$uid]['online'] = false;
        } else {
            unset($rooms[$roomName]['participants'][$uid]);
        }
    }
    return ['success' => true];
}

function scrumCsrfToken() {
    scrumEnsureUid();
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function scrumCsrfCheck() {
    $hdr = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    return $hdr !== '' && hash_equals(scrumCsrfToken(), $hdr);
}

function scrumLanguagePayload($lang) {
    $lang = preg_replace('/[^a-z]/', '', strtolower((string)$lang));
    if ($lang === '') {
        $lang = 'de';
    }
    $path = __DIR__ . "/lang/{$lang}.json";
    $fallback = __DIR__ . '/lang/de.json';
    if (!is_file($path)) {
        return ['ok' => false, 'lang' => 'de', 'json' => is_file($fallback) ? (string)file_get_contents($fallback) : '{}'];
    }
    return ['ok' => true, 'lang' => $lang, 'json' => (string)file_get_contents($path)];
}
