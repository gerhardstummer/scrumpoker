<?php
/**
 * CLI tests for Scrum Poker backend logic.
 * Run: php tests.php
 */

require_once __DIR__ . '/lib.php';

$passed = 0;
$failed = 0;

function assertTrue($cond, $msg) {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "OK  $msg\n";
    } else {
        $failed++;
        echo "FAIL  $msg\n";
    }
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scrumpoker-test-' . bin2hex(random_bytes(4)) . '.json';
$GLOBALS['SCRUM_ROOMS_FILE'] = $tmp;
file_put_contents($tmp, '{}');

$rooms = [];
$a = scrumJoinRoom($rooms, 'uid-a', 'Sprint', 'Anna', 'user');
assertTrue(!empty($a['success']), 'creator can join');
assertTrue($a['role'] === 'moderator', 'creator requesting user becomes moderator');
assertTrue(isset($rooms['Sprint']), 'room is created');

$b = scrumJoinRoom($rooms, 'uid-b', 'Sprint', 'Ben', 'admin');
assertTrue($b['success'] && $b['role'] === 'user', 'second user cannot self-assign admin without password');

$lead = scrumJoinRoom($rooms, 'uid-e', 'AdminLab', 'Eve', 'admin');
assertTrue($lead['success'] && $lead['role'] === 'admin', 'creator requesting admin becomes admin');
assertTrue(!empty($rooms['AdminLab']['participants']['uid-e']['passwordHash']), 'new admin gets a password hash');
$public = scrumPublicRoom($rooms['AdminLab']);
assertTrue(!isset($public['participants']['uid-e']['passwordHash']), 'public state strips password hashes');

$steal = scrumJoinRoom($rooms, 'uid-x', 'AdminLab', 'Xena', 'admin', 'geheim');
assertTrue($steal['success'] && $steal['role'] === 'user', 'another name cannot use an admin default password');

$unauthPw = scrumChangeAdminPassword($rooms, 'uid-x', 'AdminLab', 'geheim', 'neuespw');
assertTrue(empty($unauthPw['success']), 'non-admin cannot change admin password');
$badOld = scrumChangeAdminPassword($rooms, 'uid-e', 'AdminLab', 'wrong', 'evepw');
assertTrue(empty($badOld['success']) && ($badOld['message'] ?? '') === 'bad_password', 'wrong current password is rejected');
$shortPw = scrumChangeAdminPassword($rooms, 'uid-e', 'AdminLab', 'geheim', 'ab');
assertTrue(empty($shortPw['success']) && ($shortPw['message'] ?? '') === 'password_invalid', 'too short password is rejected');
$changedPw = scrumChangeAdminPassword($rooms, 'uid-e', 'AdminLab', 'geheim', 'evepw');
assertTrue(!empty($changedPw['success']), 'admin can change own password');
assertTrue(scrumVerifyParticipantPassword($rooms['AdminLab']['participants']['uid-e'], 'evepw'), 'new personal password is accepted');
assertTrue(!scrumVerifyParticipantPassword($rooms['AdminLab']['participants']['uid-e'], 'geheim'), 'old default no longer works for that admin');

$otherPw = scrumJoinRoom($rooms, 'uid-f', 'AdminLab', 'Fay', 'admin', 'evepw');
assertTrue($otherPw['success'] && $otherPw['role'] === 'user', 'another name cannot use Eve password');
scrumUpdateRoom($rooms, 'uid-e', ['room' => 'AdminLab', 'changeRole' => 'admin', 'target' => 'uid-f']);
assertTrue(($rooms['AdminLab']['participants']['uid-f']['role'] ?? '') === 'admin', 'admin can promote another user');
assertTrue(scrumVerifyParticipantPassword($rooms['AdminLab']['participants']['uid-f'], 'geheim'), 'promoted admin starts with default password');
$fayPw = scrumChangeAdminPassword($rooms, 'uid-f', 'AdminLab', 'geheim', 'faypw');
assertTrue(!empty($fayPw['success']), 'second admin can set a different password');
assertTrue(scrumVerifyParticipantPassword($rooms['AdminLab']['participants']['uid-e'], 'evepw'), 'first admin password stays unchanged');

scrumLogout($rooms, 'uid-e');
assertTrue(isset($rooms['AdminLab']['participants']['uid-e']) && empty($rooms['AdminLab']['participants']['uid-e']['online']), 'admin remains listed after logout');
$reclaimWrong = scrumJoinRoom($rooms, 'uid-e2', 'AdminLab', 'Eve', 'admin', 'geheim');
assertTrue($reclaimWrong['role'] === 'user', 'reclaim fails with old password');
$reclaim = scrumJoinRoom($rooms, 'uid-e3', 'AdminLab', 'Eve', 'admin', 'evepw');
assertTrue($reclaim['success'] && $reclaim['role'] === 'admin', 'admin can reclaim with name and own password');
assertTrue(!isset($rooms['AdminLab']['participants']['uid-e']), 'reclaim moves the admin slot to the new session');
assertTrue(isset($rooms['AdminLab']['participants']['uid-e3']), 'reclaimed admin uses the new uid');

$mod = scrumJoinRoom($rooms, 'uid-d', 'Sprint', 'Dan', 'moderator');
assertTrue($mod['success'] && $mod['role'] === 'moderator', 'anyone can join as moderator');

$c = scrumJoinRoom($rooms, 'uid-c', 'Sprint', 'Anna', 'user');
assertTrue($c['name'] !== 'Anna', 'duplicate display names are uniquified');

$vote = scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'fibonacci', '5');
assertTrue(!empty($vote['success']), 'user can vote before reveal');
assertTrue($rooms['Sprint']['participants']['uid-b']['vote']['value'] === '5', 'vote stored');

$toggle = scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'fibonacci', '5');
assertTrue($rooms['Sprint']['participants']['uid-b']['vote'] === null, 'same card deselects');

scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'fibonacci', '8');
$badDeck = scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'days', '5');
assertTrue(empty($badDeck['success']), 'inactive deck vote rejected');

$story = scrumUpdateRoom($rooms, 'uid-b', ['room' => 'Sprint', 'storyUrl' => 'https://example.com/TICKET-1']);
assertTrue($story['success'] && $rooms['Sprint']['storyUrl'] === 'https://example.com/TICKET-1', 'any user may set story');
$clearStory = scrumUpdateRoom($rooms, 'uid-b', ['room' => 'Sprint', 'storyUrl' => '']);
assertTrue(empty($clearStory['success']) && $rooms['Sprint']['storyUrl'] === 'https://example.com/TICKET-1', 'user cannot clear story');

$unauthReveal = scrumUpdateRoom($rooms, 'uid-b', ['room' => 'Sprint', 'reveal' => true]);
assertTrue(empty($unauthReveal['success']), 'plain user cannot reveal');

$reveal = scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'reveal' => true]);
assertTrue(!empty($reveal['success']) && !empty($rooms['Sprint']['timerTarget']), 'moderator reveal starts countdown');
assertTrue(empty($rooms['Sprint']['revealed']), 'cards stay hidden during countdown');
assertTrue(($rooms['Sprint']['revealedBy'] ?? '') === 'Anna', 'reveal stores the actor name');

$during = scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'fibonacci', '3');
assertTrue(!empty($during['success']), 'votes allowed during countdown');

$rooms['Sprint']['timerTarget'] = time() - 1;
$state = scrumGetState($rooms, 'uid-a', 'Sprint');
assertTrue(!empty($state['data']['revealed']), 'expired timer reveals on get_state');
assertTrue($rooms['Sprint']['timerTarget'] === null, 'expired timer is cleared');

$locked = scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'fibonacci', '13');
assertTrue(empty($locked['success']), 'votes blocked after reveal');

$enableChange = scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'allowVoteChangeAfterReveal' => true]);
assertTrue(!empty($enableChange['success']), 'moderator can enable post-reveal vote changes');
assertTrue(!empty($rooms['Sprint']['allowVoteChangeAfterReveal']), 'room flag persisted');

$open = scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'fibonacci', '13');
assertTrue(!empty($open['success']), 'votes allowed after reveal when room option enabled');

$disableChange = scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'allowVoteChangeAfterReveal' => false]);
assertTrue(empty($rooms['Sprint']['allowVoteChangeAfterReveal']), 'room flag can be disabled');
$lockedAgain = scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'fibonacci', '21');
assertTrue(empty($lockedAgain['success']), 'votes blocked again when option disabled');

$unauthFlag = scrumUpdateRoom($rooms, 'uid-b', ['room' => 'Sprint', 'allowUserModeration' => true]);
assertTrue(empty($unauthFlag['success']), 'plain user cannot enable user moderation');
$enableUserMod = scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'allowUserModeration' => true]);
assertTrue(!empty($enableUserMod['success']) && !empty($rooms['Sprint']['allowUserModeration']), 'moderator can allow user moderation');
scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'reset' => true]);
$userReveal = scrumUpdateRoom($rooms, 'uid-b', ['room' => 'Sprint', 'reveal' => true]);
assertTrue(!empty($userReveal['success']) && !empty($rooms['Sprint']['timerTarget']), 'user can reveal when room option enabled');
assertTrue(($rooms['Sprint']['revealedBy'] ?? '') === 'Ben', 'user reveal stores the actor name');
assertTrue(($rooms['Sprint']['resetBy'] ?? '') === '', 'reveal clears the reset actor');
$rooms['Sprint']['timerTarget'] = null;
$rooms['Sprint']['revealed'] = true;
$userReset = scrumUpdateRoom($rooms, 'uid-b', ['room' => 'Sprint', 'reset' => true]);
assertTrue(!empty($userReset['success']) && $rooms['Sprint']['revealed'] === false, 'user can reset when room option enabled');
assertTrue(($rooms['Sprint']['resetBy'] ?? '') === 'Ben', 'reset stores the actor name');
assertTrue(($rooms['Sprint']['revealedBy'] ?? '') === '', 'reset clears the reveal actor');
$userClear = scrumUpdateRoom($rooms, 'uid-b', ['room' => 'Sprint', 'clearAbsent' => true]);
assertTrue(!empty($userClear['success']), 'user can clear when room option enabled');
scrumUpdateRoom($rooms, 'uid-b', ['room' => 'Sprint', 'ban' => 'uid-a']);
assertTrue(empty($rooms['Sprint']['participants']['uid-a']['banned']), 'user still cannot ban');
$disableUserMod = scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'allowUserModeration' => false]);
assertTrue(empty($rooms['Sprint']['allowUserModeration']), 'moderator can disable user moderation');
$userRevealAgain = scrumUpdateRoom($rooms, 'uid-b', ['room' => 'Sprint', 'reveal' => true]);
assertTrue(empty($userRevealAgain['success']), 'user cannot reveal when option disabled');

$ban = scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'ban' => 'uid-b']);
assertTrue(!empty($rooms['Sprint']['participants']['uid-b']['banned']), 'banned user remains in the room');
$bannedVote = scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'fibonacci', '1');
assertTrue(empty($bannedVote['success']), 'banned user cannot vote');

$unban = scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'unban' => 'uid-b']);
assertTrue(empty($rooms['Sprint']['participants']['uid-b']['banned']), 'unban works');

$promote = scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'changeRole' => 'moderator', 'target' => 'uid-b']);
assertTrue(($rooms['Sprint']['participants']['uid-b']['role'] ?? '') === 'moderator', 'moderator can change roles');

$adminPromote = scrumUpdateRoom($rooms, 'uid-b', ['room' => 'Sprint', 'changeRole' => 'admin', 'target' => 'uid-c']);
assertTrue(($rooms['Sprint']['participants']['uid-c']['role'] ?? '') !== 'admin', 'moderator cannot promote to admin');

$rooms['Sprint']['participants']['uid-c']['online'] = false;
$rooms['Sprint']['participants']['uid-c']['lastSeen'] = time() - 60;
$clear = scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'clearAbsent' => true]);
assertTrue(!isset($rooms['Sprint']['participants']['uid-c']), 'clear removes offline users');
assertTrue(isset($rooms['Sprint']['participants']['uid-b']), 'clear keeps online users');

$reset = scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'reset' => true]);
assertTrue($rooms['Sprint']['storyUrl'] === '', 'reset clears story');
assertTrue($rooms['Sprint']['participants']['uid-b']['vote'] === null, 'reset clears votes');
assertTrue($rooms['Sprint']['revealed'] === false, 'reset unreveals');
assertTrue(($rooms['Sprint']['revealedBy'] ?? '') === '', 'reset clears reveal actor');
assertTrue(($rooms['Sprint']['resetBy'] ?? '') === 'Anna', 'later reset updates the actor name');

scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'fibonacci', '8');
$rooms['Sprint']['revealed'] = true;
$rooms['Sprint']['revealedBy'] = 'Anna';
$saveCfg = scrumUpdateRoom($rooms, 'uid-a', [
    'room' => 'Sprint',
    'timerDuration' => 7,
    'decks' => ['fibonacci' => '0, 1, 2, 3, 5, 8, 13'],
]);
assertTrue(!empty($saveCfg['success']), 'admin can save deck settings');
assertTrue((int)$rooms['Sprint']['timerDuration'] === 7, 'timer duration is saved');
assertTrue($rooms['Sprint']['participants']['uid-b']['vote']['value'] === '8', 'saving deck settings does not reset votes');
assertTrue($rooms['Sprint']['revealed'] === true, 'saving deck settings does not unreveal');
assertTrue(($rooms['Sprint']['revealedBy'] ?? '') === 'Anna', 'saving deck settings keeps reveal actor');

$decks = scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'activeDecks' => ['fibonacci' => true, 'tshirt' => false, 'days' => true]]);
assertTrue($rooms['Sprint']['participants']['uid-b']['vote']['value'] === '8', 'toggling active decks does not reset votes');
assertTrue($rooms['Sprint']['revealed'] === true, 'toggling active decks does not unreveal');
assertTrue(!empty($rooms['Sprint']['activeDecks']['days']), 'days deck can be enabled');

$parsed = scrumParseDeckValues(' 1, 2, xss<script>, ');
assertTrue($parsed[2] === 'xss<script>', 'deck parser keeps values as data, not HTML');
assertTrue(scrumSanitize("<img onerror=alert(1)>", 32) === '<img onerror=alert(1)>', 'sanitize strips controls but rendering must escape');

$second = scrumJoinRoom($rooms, 'uid-a', 'Other', 'Anna', 'admin');
assertTrue($second['role'] === 'moderator' || $second['role'] === 'admin', 'creating another room assigns privileged creator role');
assertTrue($second['role'] === 'admin', 'creator may become admin when requested');
assertTrue(!isset($rooms['Sprint']['participants']['uid-a']), 'leaving moves the session to the new room');

$adminJoin = scrumJoinRoom($rooms, 'uid-a', 'Other', 'Anna', 'admin');
$list = scrumListRooms($rooms, 'uid-a', 'Other');
assertTrue(!empty($list['success']) && count($list['rooms']) >= 1, 'admin can list rooms');

$del = scrumDeleteRoom($rooms, 'uid-a', 'Other', 'Sprint');
assertTrue(!isset($rooms['Sprint']), 'admin can delete another room');

scrumJoinRoom($rooms, 'uid-b', 'Other', 'Ben', 'user');
$logout = scrumLogout($rooms, 'uid-b');
assertTrue($logout['success'] && !isset($rooms['Other']['participants']['uid-b']), 'logout removes non-banned user');

scrumJoinRoom($rooms, 'uid-b', 'Other', 'Ben', 'user');
scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Other', 'ban' => 'uid-b']);
scrumLogout($rooms, 'uid-b');
assertTrue(!empty($rooms['Other']['participants']['uid-b']['banned']), 'banned user remains listed after logout');

file_put_contents($tmp, '{}');
$lockResult = scrumWithRooms(function (&$rooms) {
    $rooms['Locked'] = scrumDefaultRoom();
    $rooms['Locked']['participants']['x'] = [
        'id' => 'x', 'name' => 'X', 'role' => 'admin', 'vote' => null,
        'lastSeen' => time(), 'banned' => false, 'online' => true,
    ];
    return ['success' => true];
});
$disk = json_decode(file_get_contents($tmp), true);
assertTrue(!empty($lockResult['success']) && isset($disk['Locked']), 'withRooms persists under exclusive lock');

$onDisk = file_get_contents($tmp);
$noop = scrumWithRooms(function (&$rooms) {
    return ['success' => true];
});
assertTrue(!empty($noop['success']) && file_get_contents($tmp) === $onDisk, 'withRooms skips rewrite when rooms are unchanged');

$fresh = time();
$room = scrumDefaultRoom();
$room['participants']['u1'] = [
    'id' => 'u1', 'name' => 'U', 'role' => 'user', 'vote' => null,
    'lastSeen' => $fresh, 'banned' => false, 'online' => true,
];
$room['participants']['u2'] = [
    'id' => 'u2', 'name' => 'V', 'role' => 'user', 'vote' => null,
    'lastSeen' => $fresh - (SCRUM_ONLINE_TIMEOUT + 1), 'banned' => false, 'online' => true,
];
scrumMarkPresence($room, 'u1');
assertTrue($room['participants']['u1']['lastSeen'] === $fresh, 'presence does not bump lastSeen inside touch window');
assertTrue($room['participants']['u2']['online'] === false, 'presence flips stale participants offline');

$room['participants']['u1']['lastSeen'] = $fresh - SCRUM_PRESENCE_TOUCH;
scrumMarkPresence($room, 'u1');
assertTrue($room['participants']['u1']['lastSeen'] >= $fresh, 'presence bumps lastSeen after touch interval');

$lang = scrumLanguagePayload('hu');
assertTrue($lang['ok'] === true && strpos($lang['json'], 'Szoba') !== false, 'hungarian language file loads');
$missing = scrumLanguagePayload('xx');
assertTrue($missing['ok'] === false, 'missing language reports failure');

$GLOBALS['SCRUM_DISABLE_TUNNEL'] = true;
$GLOBALS['SCRUM_PUBLIC_ORIGIN'] = '';
putenv('SCRUM_PUBLIC_ORIGIN');
$prevHost = $_SERVER['HTTP_HOST'] ?? null;
$prevHttps = $_SERVER['HTTPS'] ?? null;
$prevPort = $_SERVER['SERVER_PORT'] ?? null;
$prevFwd = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
$_SERVER['HTTPS'] = '';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = '';
$_SERVER['HTTP_HOST'] = 'localhost:8000';
$_SERVER['SERVER_PORT'] = '8000';

assertTrue(scrumNormalizeOrigin('https://abc.trycloudflare.com/') === 'https://abc.trycloudflare.com', 'origin trim and normalize');
assertTrue(scrumHostIsPrivate('localhost:8000') && scrumHostIsPrivate('192.168.0.167'), 'localhost and LAN hosts are private');
assertTrue(!scrumHostIsPrivate('poker.example.com') && !scrumHostIsPrivate('abc.trycloudflare.com'), 'public hosts are not private');

$tf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scrumpoker-tunnel-test-' . bin2hex(random_bytes(4)) . '.json';
$GLOBALS['SCRUM_TUNNEL_FILE'] = $tf;
file_put_contents($tf, json_encode(['origin' => 'https://join-test.trycloudflare.com', 'pid' => 0]));
assertTrue(scrumJoinOrigin() === 'https://join-test.trycloudflare.com', 'join origin prefers active tunnel url');

$GLOBALS['SCRUM_PUBLIC_ORIGIN'] = 'https://poker.example.com';
assertTrue(scrumJoinOrigin() === 'https://poker.example.com', 'configured public origin wins over tunnel');
$GLOBALS['SCRUM_PUBLIC_ORIGIN'] = '';

@unlink($tf);
unset($GLOBALS['SCRUM_TUNNEL_FILE']);
$_SERVER['HTTP_HOST'] = 'scrumpoker.example.com';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
assertTrue(scrumJoinOrigin() === 'https://scrumpoker.example.com', 'public request host is used without tunnel');

$_SERVER['HTTP_HOST'] = 'localhost:8000';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = '';
$ensured = scrumEnsureRemoteJoin();
assertTrue(!empty($ensured['success']) && empty($ensured['pending']) && empty($ensured['remote']), 'disabled tunnel stays on LAN origin');

if ($prevHost === null) { unset($_SERVER['HTTP_HOST']); } else { $_SERVER['HTTP_HOST'] = $prevHost; }
if ($prevHttps === null) { unset($_SERVER['HTTPS']); } else { $_SERVER['HTTPS'] = $prevHttps; }
if ($prevPort === null) { unset($_SERVER['SERVER_PORT']); } else { $_SERVER['SERVER_PORT'] = $prevPort; }
if ($prevFwd === null) { unset($_SERVER['HTTP_X_FORWARDED_PROTO']); } else { $_SERVER['HTTP_X_FORWARDED_PROTO'] = $prevFwd; }

@unlink($tmp);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
