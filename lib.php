<?php
/**
 * Scrum Poker – shared backend logic (file-locked rooms, roles, votes).
 */

define('SCRUM_ONLINE_TIMEOUT', 8);
define('SCRUM_PRESENCE_TOUCH', 4);
define('SCRUM_MAX_ROOM', 64);
define('SCRUM_MAX_NAME', 32);
define('SCRUM_MAX_STORY', 500);
define('SCRUM_MAX_CARD', 16);
define('SCRUM_MAX_CARDS', 40);
define('SCRUM_DEFAULT_ADMIN_PASSWORD', 'geheim');
define('SCRUM_MIN_PASSWORD', 4);
define('SCRUM_MAX_PASSWORD', 64);

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
        'revealedBy' => '',
        'resetBy' => '',
        'timerTarget' => null,
        'timerDuration' => 5,
        'activeDecks' => [
            'fibonacci' => true,
            'tshirt' => true,
            'days' => false,
        ],
        'decks' => scrumDefaultDecks(),
        'allowVoteChangeAfterReveal' => false,
        'allowUserModeration' => false,
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
    $out['revealedBy'] = isset($out['revealedBy']) ? scrumSanitize($out['revealedBy'], SCRUM_MAX_NAME) : '';
    $out['resetBy'] = isset($out['resetBy']) ? scrumSanitize($out['resetBy'], SCRUM_MAX_NAME) : '';
    $out['timerTarget'] = isset($out['timerTarget']) && $out['timerTarget'] !== null ? (int)$out['timerTarget'] : null;
    $out['timerDuration'] = max(1, (int)($out['timerDuration'] ?? 5));
    $out['allowVoteChangeAfterReveal'] = !empty($out['allowVoteChangeAfterReveal']);
    $out['allowUserModeration'] = !empty($out['allowUserModeration']);
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
        if (isset($p['passwordHash']) && (!is_string($p['passwordHash']) || $p['passwordHash'] === '')) {
            unset($p['passwordHash']);
        }
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
        $last = (int)($room['participants'][$uid]['lastSeen'] ?? 0);
        if ($now - $last >= SCRUM_PRESENCE_TOUCH) {
            $room['participants'][$uid]['lastSeen'] = $now;
        }
        $room['participants'][$uid]['online'] = true;
    }
    foreach ($room['participants'] as $id => $p) {
        $online = ($now - (int)($p['lastSeen'] ?? 0)) <= SCRUM_ONLINE_TIMEOUT;
        if (!empty($p['online']) !== $online) {
            $room['participants'][$id]['online'] = $online;
        }
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
        if (scrumShouldRetainParticipant($rooms[$roomName]['participants'][$uid])) {
            $rooms[$roomName]['participants'][$uid]['online'] = false;
        } else {
            unset($rooms[$roomName]['participants'][$uid]);
        }
    }
}

function scrumShouldRetainParticipant($p) {
    return is_array($p) && (!empty($p['banned']) || ($p['role'] ?? '') === 'admin');
}

function scrumHashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function scrumVerifyParticipantPassword($participant, $password) {
    if (!is_array($participant) || !is_string($password) || $password === '') {
        return false;
    }
    $hash = $participant['passwordHash'] ?? '';
    if (!is_string($hash) || $hash === '') {
        return hash_equals(SCRUM_DEFAULT_ADMIN_PASSWORD, $password);
    }
    return password_verify($password, $hash);
}

function scrumEnsureAdminPassword(&$participant) {
    if (!is_array($participant)) {
        return;
    }
    if (empty($participant['passwordHash']) || !is_string($participant['passwordHash'])) {
        $participant['passwordHash'] = scrumHashPassword(SCRUM_DEFAULT_ADMIN_PASSWORD);
    }
}

function scrumFindAdminByName($room, $name) {
    $want = scrumStrLower($name);
    if ($want === '') {
        return null;
    }
    foreach ($room['participants'] ?? [] as $id => $p) {
        if (($p['role'] ?? '') !== 'admin') {
            continue;
        }
        if (scrumStrLower($p['name'] ?? '') === $want) {
            return $id;
        }
    }
    return null;
}

function scrumPublicRoom($room) {
    $room = scrumNormalizeRoom($room);
    scrumExpireTimer($room);
    foreach ($room['participants'] as $id => $p) {
        unset($p['passwordHash']);
        $room['participants'][$id] = $p;
    }
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
    $before = json_encode($rooms, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

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
        if ($json !== $before) {
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $json);
            fflush($fp);
        }
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function scrumChangeAdminPassword(&$rooms, $uid, $roomName, $current, $next) {
    $roomName = scrumSanitize($roomName, SCRUM_MAX_ROOM);
    if ($roomName === '' || !isset($rooms[$roomName])) {
        return ['success' => false, 'message' => 'room_not_found'];
    }
    $rooms[$roomName] = scrumNormalizeRoom($rooms[$roomName]);
    $me = scrumFindParticipant($rooms[$roomName], $uid);
    if (!scrumIsAdmin($me) || !empty($me['banned'])) {
        return ['success' => false, 'message' => 'unauthorized'];
    }
    if (!scrumVerifyParticipantPassword($me, is_string($current) ? $current : '')) {
        return ['success' => false, 'message' => 'bad_password'];
    }
    if (!is_string($next) || scrumStrLen($next) < SCRUM_MIN_PASSWORD || scrumStrLen($next) > SCRUM_MAX_PASSWORD) {
        return ['success' => false, 'message' => 'password_invalid'];
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $next)) {
        return ['success' => false, 'message' => 'password_invalid'];
    }
    $hash = scrumHashPassword($next);
    if (!is_string($hash) || $hash === '') {
        return ['success' => false, 'message' => 'storage_unavailable'];
    }
    $rooms[$roomName]['participants'][$uid]['passwordHash'] = $hash;
    return ['success' => true];
}

function scrumResolveJoinRole($isNew, $requestedRole, $existingRole = null) {
    if (!in_array($requestedRole, ['user', 'moderator', 'admin'], true)) {
        $requestedRole = 'user';
    }

    if ($existingRole !== null) {
        if ($requestedRole === 'moderator') {
            return 'moderator';
        }
        if ($requestedRole === 'user') {
            return 'user';
        }
        return $existingRole === 'admin' ? 'admin' : $existingRole;
    }

    if ($isNew) {
        return $requestedRole === 'user' ? 'moderator' : $requestedRole;
    }

    if ($requestedRole === 'moderator') {
        return 'moderator';
    }
    return 'user';
}

function scrumJoinRoom(&$rooms, $uid, $roomName, $name, $requestedRole, $password = null) {
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
    } else {
        $rooms[$roomName] = scrumNormalizeRoom($rooms[$roomName]);
    }

    scrumLeaveOtherRooms($rooms, $uid, $roomName);

    $claimed = false;
    if ($requestedRole === 'admin' && !$isNew && !isset($rooms[$roomName]['participants'][$uid])) {
        $claimId = scrumFindAdminByName($rooms[$roomName], $name);
        if ($claimId && scrumVerifyParticipantPassword($rooms[$roomName]['participants'][$claimId], is_string($password) ? $password : '')) {
            $slot = $rooms[$roomName]['participants'][$claimId];
            unset($rooms[$roomName]['participants'][$claimId]);
            $slot['id'] = $uid;
            $slot['lastSeen'] = time();
            $slot['online'] = true;
            $rooms[$roomName]['participants'][$uid] = $slot;
            $claimed = true;
        }
    }

    if (!$claimed) {
        if (isset($rooms[$roomName]['participants'][$uid])) {
            $existing = $rooms[$roomName]['participants'][$uid];
            $role = scrumResolveJoinRole($isNew, $requestedRole, $existing['role'] ?? 'user');
            $name = scrumUniqueName($rooms[$roomName]['participants'], $name, $uid);
            $rooms[$roomName]['participants'][$uid]['name'] = $name;
            $rooms[$roomName]['participants'][$uid]['role'] = $role;
            $rooms[$roomName]['participants'][$uid]['lastSeen'] = time();
            $rooms[$roomName]['participants'][$uid]['online'] = true;
            if (empty($rooms[$roomName]['participants'][$uid]['banned'])) {
                $rooms[$roomName]['participants'][$uid]['banned'] = false;
            }
        } else {
            $role = scrumResolveJoinRole($isNew, $requestedRole, null);
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
    }

    if (($rooms[$roomName]['participants'][$uid]['role'] ?? '') === 'admin') {
        scrumEnsureAdminPassword($rooms[$roomName]['participants'][$uid]);
    } else {
        unset($rooms[$roomName]['participants'][$uid]['passwordHash']);
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
    if (!empty($rooms[$roomName]['revealed']) && empty($rooms[$roomName]['allowVoteChangeAfterReveal'])) {
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

function scrumCanModerateSession($room, $participant) {
    if (!is_array($participant) || !empty($participant['banned'])) {
        return false;
    }
    if (scrumIsPrivileged($participant)) {
        return true;
    }
    return ($participant['role'] ?? '') === 'user' && !empty($room['allowUserModeration']);
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

    $needsPrivilege = isset($input['activeDecks']) || isset($input['ban']) || isset($input['unban'])
        || isset($input['changeRole']) || isset($input['allowVoteChangeAfterReveal'])
        || isset($input['allowUserModeration']);
    $needsSessionModeration = isset($input['reveal']) || isset($input['reset']) || isset($input['clearAbsent']);
    $needsAdmin = isset($input['timerDuration']) || isset($input['decks']);

    if ($needsPrivilege && !scrumRequirePrivilege($rooms[$roomName], $uid)) {
        return ['success' => false, 'message' => 'unauthorized'];
    }
    if ($needsSessionModeration && !scrumCanModerateSession($rooms[$roomName], $me)) {
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
        $rooms[$roomName]['revealedBy'] = scrumSanitize($me['name'] ?? '', SCRUM_MAX_NAME);
        $rooms[$roomName]['resetBy'] = '';
    }

    if (!empty($input['reset'])) {
        $rooms[$roomName]['revealed'] = false;
        $rooms[$roomName]['revealedBy'] = '';
        $rooms[$roomName]['resetBy'] = scrumSanitize($me['name'] ?? '', SCRUM_MAX_NAME);
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
    }

    if (isset($input['allowVoteChangeAfterReveal'])) {
        $rooms[$roomName]['allowVoteChangeAfterReveal'] = !empty($input['allowVoteChangeAfterReveal']);
    }

    if (isset($input['allowUserModeration'])) {
        $rooms[$roomName]['allowUserModeration'] = !empty($input['allowUserModeration']);
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
    }

    if (!empty($input['clearAbsent'])) {
        foreach ($rooms[$roomName]['participants'] as $id => $p) {
            $online = !empty($p['online']) && (time() - (int)($p['lastSeen'] ?? 0)) <= SCRUM_ONLINE_TIMEOUT;
            if (!$online && !scrumShouldRetainParticipant($p)) {
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
            if ($newRole === 'admin') {
                scrumEnsureAdminPassword($rooms[$roomName]['participants'][$target]);
            } else {
                unset($rooms[$roomName]['participants'][$target]['passwordHash']);
            }
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
        if (scrumShouldRetainParticipant($rooms[$roomName]['participants'][$uid])) {
            $rooms[$roomName]['participants'][$uid]['online'] = false;
        } else {
            unset($rooms[$roomName]['participants'][$uid]);
        }
    }
    return ['success' => true];
}

function scrumIsLanIpv4($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    $long = ip2long($ip);
    if ($long === false) {
        return false;
    }
    if (($long & 0xFF000000) === 0x0A000000) {
        return true;
    }
    if (($long & 0xFFF00000) === 0xAC100000) {
        return true;
    }
    if (($long & 0xFFFF0000) === 0xC0A80000) {
        return true;
    }
    return false;
}

function scrumGuessLanHost() {
    $candidates = [];
    $hn = @gethostname();
    if (is_string($hn) && $hn !== '') {
        $resolved = @gethostbyname($hn);
        if (is_string($resolved) && $resolved !== $hn) {
            $candidates[] = $resolved;
        }
    }
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $out = @shell_exec('ipconfig');
        if (is_string($out) && preg_match_all('/IPv4[^\d]{0,80}([\d.]+)/', $out, $m)) {
            foreach ($m[1] as $ip) {
                $candidates[] = $ip;
            }
        }
    }
    foreach ($candidates as $ip) {
        if (scrumIsLanIpv4($ip)) {
            return $ip;
        }
    }
    return '';
}

function scrumRequestScheme() {
    $fwd = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if (strpos($fwd, 'https') !== false) {
        return 'https';
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return $https ? 'https' : 'http';
}

function scrumHostWithoutPort($host) {
    $host = trim((string)$host);
    if ($host === '') {
        return '';
    }
    if ($host[0] === '[') {
        $end = strpos($host, ']');
        return $end !== false ? substr($host, 1, $end - 1) : $host;
    }
    if (substr_count($host, ':') === 1) {
        return explode(':', $host)[0];
    }
    return $host;
}

function scrumHostIsPrivate($host) {
    $name = strtolower(scrumHostWithoutPort($host));
    if ($name === '' || $name === 'localhost' || $name === '127.0.0.1' || $name === '::1' || $name === 'ip6-localhost') {
        return true;
    }
    if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && scrumIsLanIpv4($name)) {
        return true;
    }
    return false;
}

function scrumNormalizeOrigin($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $raw)) {
        $raw = 'https://' . ltrim($raw, '/');
    }
    $parts = parse_url($raw);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $host = strtolower($parts['host']);
    $scheme = strtolower($parts['scheme'] ?? 'https');
    if (preg_match('/(^|\.)(trycloudflare\.com|ngrok-free\.app|ngrok\.io|loca\.lt|pinggy\.link)$/i', $host)) {
        $scheme = 'https';
    }
    $port = isset($parts['port']) ? (int)$parts['port'] : 0;
    $suffix = ($port && (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443))) ? (':' . $port) : '';
    return $scheme . '://' . $host . $suffix;
}

function scrumConfiguredPublicOrigin() {
    $env = getenv('SCRUM_PUBLIC_ORIGIN');
    if (is_string($env) && $env !== '') {
        return scrumNormalizeOrigin($env);
    }
    if (!empty($GLOBALS['SCRUM_PUBLIC_ORIGIN']) && is_string($GLOBALS['SCRUM_PUBLIC_ORIGIN'])) {
        return scrumNormalizeOrigin($GLOBALS['SCRUM_PUBLIC_ORIGIN']);
    }
    return '';
}

function scrumPublicRequestOrigin() {
    $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($hostHeader === '' || scrumHostIsPrivate($hostHeader)) {
        return '';
    }
    $origin = scrumNormalizeOrigin(scrumRequestScheme() . '://' . $hostHeader);
    return $origin;
}

function scrumLanJoinOrigin() {
    $scheme = scrumRequestScheme();
    $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($hostHeader !== '' && !preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $hostHeader)) {
        return $scheme . '://' . $hostHeader;
    }
    $lan = scrumGuessLanHost();
    if ($lan === '') {
        return $scheme . '://' . ($hostHeader !== '' ? $hostHeader : 'localhost');
    }
    $port = isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : 80;
    $suffix = ($port && $port !== 80 && $port !== 443) ? (':' . $port) : '';
    return $scheme . '://' . $lan . $suffix;
}

function scrumTunnelFile() {
    return $GLOBALS['SCRUM_TUNNEL_FILE'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scrumpoker-tunnel-' . md5(__DIR__) . '.json');
}

function scrumTunnelLogFile() {
    return $GLOBALS['SCRUM_TUNNEL_LOG'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scrumpoker-tunnel-' . md5(__DIR__) . '.log');
}

function scrumReadTunnelState() {
    $path = scrumTunnelFile();
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function scrumWriteTunnelState(array $state) {
    $path = scrumTunnelFile();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($path, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function scrumPidAlive($pid) {
    $pid = (int)$pid;
    if ($pid <= 0) {
        return false;
    }
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $out = @shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH');
        return is_string($out) && preg_match('/\b' . $pid . '\b/', $out) && stripos($out, 'INFO:') === false && stripos($out, 'INFORMATION:') === false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return is_dir('/proc/' . $pid);
}

function scrumActiveTunnelOrigin() {
    $state = scrumReadTunnelState();
    $origin = scrumNormalizeOrigin($state['origin'] ?? '');
    if ($origin === '') {
        return '';
    }
    $pid = (int)($state['pid'] ?? 0);
    $workerPid = (int)($state['workerPid'] ?? 0);
    $pidDead = $pid > 0 && !scrumPidAlive($pid);
    $workerDead = $workerPid > 0 && !scrumPidAlive($workerPid);
    if ($pidDead && ($workerPid === 0 || $workerDead)) {
        return '';
    }
    return $origin;
}

function scrumParseTunnelOriginFromText($text) {
    if (!is_string($text) || $text === '') {
        return '';
    }
    if (preg_match('#https://[a-z0-9.-]+\.trycloudflare\.com#i', $text, $m)) {
        return scrumNormalizeOrigin($m[0]);
    }
    if (preg_match('#https://[a-z0-9.-]+\.(ngrok-free\.app|ngrok\.io|loca\.lt|pinggy\.link)#i', $text, $m)) {
        return scrumNormalizeOrigin($m[0]);
    }
    return '';
}

function scrumJoinOrigin() {
    $configured = scrumConfiguredPublicOrigin();
    if ($configured !== '') {
        return $configured;
    }
    $tunnel = scrumActiveTunnelOrigin();
    if ($tunnel !== '') {
        return $tunnel;
    }
    $public = scrumPublicRequestOrigin();
    if ($public !== '') {
        return $public;
    }
    return scrumLanJoinOrigin();
}

function scrumRemoteJoinActive() {
    return scrumConfiguredPublicOrigin() !== '' || scrumActiveTunnelOrigin() !== '' || scrumPublicRequestOrigin() !== '';
}

function scrumLocalListenPort($fallback = 8000) {
    $port = isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : 0;
    if ($port > 0 && $port < 65536) {
        return $port;
    }
    return (int)$fallback;
}

function scrumPowershellQuote($value) {
    return "'" . str_replace("'", "''", (string)$value) . "'";
}

function scrumSpawnDetached($exe, array $args) {
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $argList = [];
        foreach ($args as $arg) {
            $argList[] = scrumPowershellQuote($arg);
        }
        $ps = 'Start-Process -FilePath ' . scrumPowershellQuote($exe)
            . ' -ArgumentList @(' . implode(',', $argList) . ') -WindowStyle Hidden';
        @shell_exec('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command ' . escapeshellarg($ps));
        return true;
    }
    $cmd = escapeshellarg($exe);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    @exec($cmd . ' > /dev/null 2>&1 &');
    return true;
}

function scrumCloudflaredDownloadUrl() {
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        return 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe';
    }
    $uname = strtolower(php_uname('m'));
    if (stripos(PHP_OS, 'Darwin') !== false) {
        return strpos($uname, 'arm') !== false
            ? 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-darwin-arm64'
            : 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-darwin-amd64';
    }
    return (strpos($uname, 'aarch') !== false || strpos($uname, 'arm64') !== false)
        ? 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm64'
        : 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64';
}

function scrumCloudflaredBinPath() {
    if (!empty($GLOBALS['SCRUM_CLOUDFLARED_BIN']) && is_string($GLOBALS['SCRUM_CLOUDFLARED_BIN'])) {
        return $GLOBALS['SCRUM_CLOUDFLARED_BIN'];
    }
    $base = getenv('LOCALAPPDATA');
    if (!is_string($base) || $base === '') {
        $base = getenv('HOME');
    }
    if (!is_string($base) || $base === '') {
        $base = sys_get_temp_dir();
    }
    $dir = rtrim($base, '\\/') . DIRECTORY_SEPARATOR . 'scrumpoker';
    $name = strncasecmp(PHP_OS, 'WIN', 3) === 0 ? 'cloudflared.exe' : 'cloudflared';
    return $dir . DIRECTORY_SEPARATOR . $name;
}

function scrumFindCloudflared() {
    $cached = scrumCloudflaredBinPath();
    if (is_file($cached)) {
        return $cached;
    }
    $out = @shell_exec(strncasecmp(PHP_OS, 'WIN', 3) === 0 ? 'where cloudflared 2>NUL' : 'command -v cloudflared 2>/dev/null');
    $line = is_string($out) ? trim(strtok($out, "\r\n")) : '';
    if ($line !== '' && is_file($line)) {
        return $line;
    }
    return '';
}

function scrumDownloadFile($url, $destTmp) {
    @unlink($destTmp);
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $cmd = 'curl.exe -L --fail --retry 2 --max-time 180 -o ' . escapeshellarg($destTmp) . ' ' . escapeshellarg($url);
        @exec($cmd, $out, $code);
        if ((int)$code === 0 && is_file($destTmp) && filesize($destTmp) > 1000000) {
            return true;
        }
        @unlink($destTmp);
        $ps = 'Invoke-WebRequest -Uri ' . scrumPowershellQuote($url) . ' -OutFile ' . scrumPowershellQuote($destTmp) . ' -UseBasicParsing';
        @shell_exec('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command ' . escapeshellarg($ps));
        if (is_file($destTmp) && filesize($destTmp) > 1000000) {
            return true;
        }
        @unlink($destTmp);
    }
    if (function_exists('curl_init')) {
        $fp = @fopen($destTmp, 'wb');
        if ($fp !== false) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_USERAGENT => 'scrumpoker-tunnel',
            ]);
            $ok = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            fclose($fp);
            if ($ok && $code === 200 && is_file($destTmp) && filesize($destTmp) > 1000000) {
                return true;
            }
        }
        @unlink($destTmp);
    }
    return false;
}

function scrumDownloadCloudflared($dest) {
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $dest . '.part';
    if (!scrumDownloadFile(scrumCloudflaredDownloadUrl(), $tmp)) {
        return false;
    }
    @unlink($dest);
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        return false;
    }
    if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
        @chmod($dest, 0755);
    }
    return is_file($dest);
}

function scrumEnsureCloudflared() {
    $found = scrumFindCloudflared();
    if ($found !== '') {
        return $found;
    }
    $dest = scrumCloudflaredBinPath();
    return scrumDownloadCloudflared($dest) ? $dest : '';
}

function scrumSpawnTunnelWorker($port) {
    $php = PHP_BINARY;
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'tunnel-worker.php';
    if (!is_file($script) || $php === '') {
        return false;
    }
    return scrumSpawnDetached($php, [$script, (string)((int)$port)]);
}

function scrumEnsureRemoteJoin() {
    $configured = scrumConfiguredPublicOrigin();
    if ($configured !== '') {
        return ['success' => true, 'origin' => $configured, 'remote' => true, 'pending' => false];
    }
    $public = scrumPublicRequestOrigin();
    if ($public !== '') {
        return ['success' => true, 'origin' => $public, 'remote' => true, 'pending' => false];
    }
    $tunnel = scrumActiveTunnelOrigin();
    if ($tunnel !== '') {
        return ['success' => true, 'origin' => $tunnel, 'remote' => true, 'pending' => false];
    }
    $lan = scrumLanJoinOrigin();
    if (!empty($GLOBALS['SCRUM_DISABLE_TUNNEL'])) {
        return ['success' => true, 'origin' => $lan, 'remote' => false, 'pending' => false];
    }
    $state = scrumReadTunnelState();
    $workerPid = (int)($state['workerPid'] ?? 0);
    $startedAt = (int)($state['startedAt'] ?? 0);
    $age = $startedAt > 0 ? time() - $startedAt : 9999;
    $workerAlive = $workerPid > 0 && scrumPidAlive($workerPid);
    $pending = !empty($state['pending']) && $age < 180 && ($workerAlive || $age < 30);
    if (!$pending) {
        $port = scrumLocalListenPort(8000);
        scrumWriteTunnelState([
            'pending' => true,
            'remote' => false,
            'origin' => '',
            'port' => $port,
            'startedAt' => time(),
            'workerPid' => 0,
            'error' => '',
        ]);
        scrumSpawnTunnelWorker($port);
        $pending = true;
    }
    return ['success' => true, 'origin' => $lan, 'remote' => false, 'pending' => $pending];
}

function scrumRunTunnelWorker($port) {
    if (!empty($GLOBALS['SCRUM_DISABLE_TUNNEL'])) {
        return;
    }
    set_time_limit(0);
    ignore_user_abort(true);
    $port = (int)$port;
    if ($port <= 0 || $port >= 65536) {
        $port = 8000;
    }
    $state = scrumReadTunnelState();
    $state['pending'] = true;
    $state['workerPid'] = getmypid();
    $state['startedAt'] = time();
    $state['port'] = $port;
    $state['error'] = '';
    scrumWriteTunnelState($state);

    $existing = scrumActiveTunnelOrigin();
    if ($existing !== '') {
        $state['origin'] = $existing;
        $state['pending'] = false;
        $state['remote'] = true;
        scrumWriteTunnelState($state);
        return;
    }

    $bin = scrumEnsureCloudflared();
    if ($bin === '') {
        $state['pending'] = false;
        $state['error'] = 'tunnel_download_failed';
        scrumWriteTunnelState($state);
        return;
    }

    $log = scrumTunnelLogFile();
    @file_put_contents($log, '');
    $target = 'http://127.0.0.1:' . $port;
    $cmd = [$bin, 'tunnel', '--no-autoupdate', '--url', $target, '--logfile', $log];
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ];
    $proc = @proc_open($cmd, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        $state['pending'] = false;
        $state['error'] = 'tunnel_start_failed';
        scrumWriteTunnelState($state);
        return;
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $origin = '';
    $deadline = time() + 75;
    while (time() < $deadline) {
        $status = proc_get_status($proc);
        $origin = scrumParseTunnelOriginFromText(@file_get_contents($log) ?: '');
        if ($origin !== '') {
            $state['origin'] = $origin;
            $state['pid'] = (int)($status['pid'] ?? 0);
            $state['pending'] = false;
            $state['remote'] = true;
            $state['error'] = '';
            scrumWriteTunnelState($state);
            break;
        }
        if (empty($status['running'])) {
            break;
        }
        usleep(400000);
    }

    if ($origin === '') {
        $state['pending'] = false;
        $state['error'] = 'tunnel_timeout';
        $status = proc_get_status($proc);
        if (!empty($status['running'])) {
            @proc_terminate($proc);
        }
        proc_close($proc);
        scrumWriteTunnelState($state);
        return;
    }

    while (true) {
        $status = proc_get_status($proc);
        if (empty($status['running'])) {
            break;
        }
        sleep(2);
    }
    proc_close($proc);
    $latest = scrumReadTunnelState();
    if (($latest['origin'] ?? '') === $origin) {
        $latest['pid'] = 0;
        $latest['pending'] = false;
        $latest['remote'] = false;
        $latest['error'] = 'tunnel_stopped';
        scrumWriteTunnelState($latest);
    }
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
