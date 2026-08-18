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
assertTrue($b['success'] && $b['role'] === 'user', 'second user cannot self-assign admin');

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

$during = scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'fibonacci', '3');
assertTrue(!empty($during['success']), 'votes allowed during countdown');

$rooms['Sprint']['timerTarget'] = time() - 1;
$state = scrumGetState($rooms, 'uid-a', 'Sprint');
assertTrue(!empty($state['data']['revealed']), 'expired timer reveals on get_state');
assertTrue($rooms['Sprint']['timerTarget'] === null, 'expired timer is cleared');

$locked = scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'fibonacci', '13');
assertTrue(empty($locked['success']), 'votes blocked after reveal');

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

$rooms['Sprint']['storyUrl'] = 'keep-me';
scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'fibonacci', '2');
$reset = scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'reset' => true]);
assertTrue($rooms['Sprint']['storyUrl'] === '', 'reset clears story');
assertTrue($rooms['Sprint']['participants']['uid-b']['vote'] === null, 'reset clears votes');
assertTrue($rooms['Sprint']['revealed'] === false, 'reset unreveals');

scrumSubmitVote($rooms, 'uid-b', 'Sprint', 'fibonacci', '8');
$decks = scrumUpdateRoom($rooms, 'uid-a', ['room' => 'Sprint', 'activeDecks' => ['fibonacci' => true, 'tshirt' => false, 'days' => true]]);
assertTrue($rooms['Sprint']['participants']['uid-b']['vote'] === null, 'changing decks resets votes');
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

$lang = scrumLanguagePayload('hu');
assertTrue($lang['ok'] === true && strpos($lang['json'], 'Szoba') !== false, 'hungarian language file loads');
$missing = scrumLanguagePayload('xx');
assertTrue($missing['ok'] === false, 'missing language reports failure');

@unlink($tmp);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
