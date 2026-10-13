<?php
// Buddies: friends who see each other's training.
// GET  -> {ok, me, buddies, feed, received}
// POST {action, ...} -> same as GET, after the change:
//   name {name} · add {code} · remove {buddy_id} · share {buddy_id, level} · hide {hidden}
//   react {buddy_id, day, emoji} (toggles) · new_code
require __DIR__ . '/lib.php';
start(['GET', 'POST']);

const EMOJIS = ['💪', '🔥', '👏'];
const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const MAX_BUDDIES = 50;
const FEED_DAYS = 14;

$user = require_user();
$me = (int)$user['id'];
$pdo = db();

function new_buddy_code(PDO $pdo, int $userId): string {
    for ($i = 0; $i < 20; $i++) {
        $code = '';
        for ($j = 0; $j < 6; $j++) {
            $code .= CODE_CHARS[random_int(0, strlen(CODE_CHARS) - 1)];
        }
        try {
            $pdo->prepare('UPDATE users SET buddy_code = ? WHERE id = ?')->execute([$code, $userId]);
            return $code;
        } catch (PDOException $e) {
            // Code already taken by someone else: try another one.
        }
    }
    throw new RuntimeException('Could not create a buddy code');
}

function is_buddy(PDO $pdo, int $me, int $other): bool {
    $q = $pdo->prepare('SELECT 1 FROM buddies WHERE user_id = ? AND buddy_id = ?');
    $q->execute([$me, $other]);
    return (bool)$q->fetchColumn();
}

function buddy_id_from(array $in, PDO $pdo, int $me): int {
    $id = (int)($in['buddy_id'] ?? 0);
    if ($id <= 0 || !is_buddy($pdo, $me, $id)) {
        fail(404, 'not_buddy', 'This person is not one of your buddies.');
    }
    return $id;
}

function num($v): float {
    return is_numeric($v) ? (float)$v : 0.0;
}

// Turns one person's training data into what a buddy may see.
function summarize(?string $json, int $level, string $today): array {
    $data = $json !== null ? json_decode($json, true) : null;
    $days = [];      // date => [exercise => top set]
    $prDays = [];    // date => [exercise names with a new heaviest weight]
    $records = [];   // exercise => best set
    if (is_array($data) && is_array($data['sessions'] ?? null)) {
        foreach ($data['sessions'] as $name => $list) {
            if (!is_array($list)) continue;
            usort($list, fn($a, $b) => strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));
            $best = 0.0;
            foreach ($list as $s) {
                $date = substr((string)($s['date'] ?? ''), 0, 10);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today) continue;
                $top = null;
                foreach ((array)($s['sets'] ?? []) as $set) {
                    if (!is_array($set)) continue;
                    $cand = ['weight' => num($set['weight'] ?? ''), 'reps' => (int)num($set['reps'] ?? ''), 'duration' => (int)num($set['duration'] ?? '')];
                    if ($top === null || [$cand['weight'], $cand['reps'], $cand['duration']] > [$top['weight'], $top['reps'], $top['duration']]) {
                        $top = $cand;
                    }
                }
                if ($top === null) continue;
                $days[$date][(string)$name] = $top;
                if ($top['weight'] > 0) {
                    if ($best > 0 && $top['weight'] > $best) {
                        $prDays[$date][] = (string)$name;
                    }
                    if ($top['weight'] > $best) {
                        $best = $top['weight'];
                        $records[(string)$name] = $top + ['date' => $date];
                    }
                }
            }
        }
    }
    krsort($days);

    // This week (Monday to Sunday) and how many weeks in a row had a workout.
    $t = new DateTimeImmutable($today);
    $monday = $t->modify('-' . ((int)$t->format('N') - 1) . ' days');
    $week = [];
    for ($i = 0; $i < 7; $i++) {
        $week[] = isset($days[$monday->modify("+$i days")->format('Y-m-d')]);
    }
    $weeksWithWorkout = [];
    foreach (array_keys($days) as $d) {
        $weeksWithWorkout[(new DateTimeImmutable($d))->format('o-W')] = true;
    }
    $streak = 0;
    $w = $monday;
    if (!isset($weeksWithWorkout[$w->format('o-W')])) $w = $w->modify('-7 days');
    while (isset($weeksWithWorkout[$w->format('o-W')])) {
        $streak++;
        $w = $w->modify('-7 days');
    }

    $from = $t->modify('-' . (FEED_DAYS - 1) . ' days')->format('Y-m-d');
    $workouts = [];
    foreach ($days as $date => $exercises) {
        if ($date < $from) break;
        $item = ['day' => $date, 'count' => count($exercises)];
        if ($level >= 1) $item['exercises'] = array_keys($exercises);
        if ($level >= 2) {
            $item['sets'] = [];
            foreach ($exercises as $name => $top) {
                $item['sets'][] = ['name' => $name] + $top + ['pr' => in_array($name, $prDays[$date] ?? [], true)];
            }
        }
        $workouts[] = $item;
    }

    $out = [
        'week' => $week,
        'this_week' => count(array_filter($week)),
        'weeks_in_row' => $streak,
        'last_day' => $days ? array_key_first($days) : null,
        'workouts' => $workouts,
    ];
    if ($level >= 2) {
        $month = substr($today, 0, 7);
        $out['prs_this_month'] = array_sum(array_map(
            fn($d) => str_starts_with($d, $month) ? count($prDays[$d]) : 0,
            array_keys($prDays)
        ));
        $recs = [];
        foreach ($records as $name => $r) $recs[] = ['name' => $name] + $r;
        usort($recs, fn($a, $b) => strcmp($b['date'], $a['date']));
        $out['records'] = $recs;
    }
    return $out;
}

function state(PDO $pdo, int $me): array {
    $q = $pdo->prepare('SELECT display_name, buddy_code, social_hidden FROM users WHERE id = ?');
    $q->execute([$me]);
    $u = $q->fetch();
    $code = $u['buddy_code'] ?: new_buddy_code($pdo, $me);
    $today = date('Y-m-d');
    $from = (new DateTimeImmutable($today))->modify('-' . (FEED_DAYS - 1) . ' days')->format('Y-m-d');

    // Reactions on the last weeks of training of me and my buddies.
    $q = $pdo->prepare(
        'SELECT r.from_user, r.to_user, r.day, r.emoji, u.display_name FROM reactions r JOIN users u ON u.id = r.from_user
         WHERE r.day >= ? AND (r.to_user = ? OR r.to_user IN (SELECT buddy_id FROM buddies WHERE user_id = ?))
         ORDER BY r.created_at'
    );
    $q->execute([$from, $me, $me]);
    $reactions = [];
    $received = [];
    foreach ($q->fetchAll() as $r) {
        $to = (int)$r['to_user'];
        $from_user = (int)$r['from_user'];
        if ($to === $me) {
            if (is_buddy($pdo, $me, $from_user)) {
                $received[] = ['day' => $r['day'], 'emoji' => $r['emoji'], 'name' => $r['display_name'] ?: 'A buddy'];
            }
            continue;
        }
        $key = $to . '|' . $r['day'];
        $reactions[$key][$r['emoji']]['count'] = ($reactions[$key][$r['emoji']]['count'] ?? 0) + 1;
        if ($from_user === $me) $reactions[$key][$r['emoji']]['mine'] = true;
    }

    $q = $pdo->prepare(
        'SELECT u.id, u.display_name, u.social_hidden, mine.share_level AS i_share, theirs.share_level AS they_share,
                d.data
         FROM buddies mine
         JOIN users u ON u.id = mine.buddy_id
         LEFT JOIN buddies theirs ON theirs.user_id = mine.buddy_id AND theirs.buddy_id = mine.user_id
         LEFT JOIN user_data d ON d.user_id = mine.buddy_id
         WHERE mine.user_id = ?
         ORDER BY mine.created_at'
    );
    $q->execute([$me]);
    $buddies = [];
    $feed = [];
    foreach ($q->fetchAll() as $b) {
        $id = (int)$b['id'];
        $name = $b['display_name'] ?: 'New buddy';
        $level = (int)($b['they_share'] ?? 0);
        $entry = ['id' => $id, 'name' => $name, 'i_share' => (int)$b['i_share'], 'they_share' => $level, 'hidden' => (bool)$b['social_hidden']];
        if (!$b['social_hidden']) {
            $sum = summarize($b['data'], $level, $today);
            foreach ($sum['workouts'] as &$w) {
                $w['reactions'] = [];
                foreach (EMOJIS as $e) {
                    $x = $reactions[$id . '|' . $w['day']][$e] ?? [];
                    $w['reactions'][] = ['emoji' => $e, 'count' => $x['count'] ?? 0, 'mine' => !empty($x['mine'])];
                }
                $feed[] = ['buddy_id' => $id, 'name' => $name] + $w;
            }
            unset($w);
            $entry += $sum;
        }
        $buddies[] = $entry;
    }
    usort($feed, fn($a, $b) => strcmp($b['day'], $a['day']));

    return [
        'ok' => true,
        'today' => $today,
        'me' => ['name' => $u['display_name'], 'code' => $code, 'hidden' => (bool)$u['social_hidden']],
        'buddies' => $buddies,
        'feed' => array_slice($feed, 0, 40),
        'received' => array_reverse($received),
        'emojis' => EMOJIS,
    ];
}

function require_name(PDO $pdo, int $me): void {
    $q = $pdo->prepare('SELECT display_name FROM users WHERE id = ?');
    $q->execute([$me]);
    if (!$q->fetchColumn()) {
        fail(400, 'no_name', 'Choose the name your buddies see first.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input();
    $action = (string)($in['action'] ?? '');

    if ($action === 'name') {
        $name = trim(preg_replace('/[\p{C}]+/u', '', (string)($in['name'] ?? '')) ?? '');
        $name = preg_replace('/\s+/u', ' ', $name);
        if (mb_strlen($name) < 1 || mb_strlen($name) > 30) {
            fail(400, 'bad_name', 'Your name needs 1 to 30 characters.');
        }
        $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$name, $me]);

    } elseif ($action === 'add') {
        require_name($pdo, $me);
        rate_limit('buddy', $user['email'], 30, 10);
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($in['code'] ?? '')));
        $q = $pdo->prepare('SELECT id FROM users WHERE buddy_code = ?');
        $q->execute([$code]);
        $other = (int)$q->fetchColumn();
        if (!$other) {
            record_attempt('buddy', $user['email']);
            fail(404, 'unknown_code', 'Nobody has that code. Check it and try again.');
        }
        if ($other === $me) {
            fail(400, 'own_code', 'That is your own code. Send it to a friend instead.');
        }
        if (!is_buddy($pdo, $me, $other)) {
            $q = $pdo->prepare('SELECT COUNT(*) FROM buddies WHERE user_id = ?');
            $q->execute([$me]);
            if ($q->fetchColumn() >= MAX_BUDDIES) {
                fail(400, 'too_many_buddies', 'You can have at most ' . MAX_BUDDIES . ' buddies.');
            }
            $add = $pdo->prepare('INSERT IGNORE INTO buddies (user_id, buddy_id) VALUES (?, ?)');
            $add->execute([$me, $other]);
            $add->execute([$other, $me]);
        }

    } elseif ($action === 'remove') {
        $other = buddy_id_from($in, $pdo, $me);
        $pdo->prepare('DELETE FROM buddies WHERE (user_id = ? AND buddy_id = ?) OR (user_id = ? AND buddy_id = ?)')
            ->execute([$me, $other, $other, $me]);
        $pdo->prepare('DELETE FROM reactions WHERE (from_user = ? AND to_user = ?) OR (from_user = ? AND to_user = ?)')
            ->execute([$me, $other, $other, $me]);

    } elseif ($action === 'share') {
        $other = buddy_id_from($in, $pdo, $me);
        $level = (int)($in['level'] ?? -1);
        if ($level < 0 || $level > 2) fail(400, 'bad_request', 'Invalid request.');
        $pdo->prepare('UPDATE buddies SET share_level = ? WHERE user_id = ? AND buddy_id = ?')->execute([$level, $me, $other]);

    } elseif ($action === 'hide') {
        $pdo->prepare('UPDATE users SET social_hidden = ? WHERE id = ?')->execute([empty($in['hidden']) ? 0 : 1, $me]);

    } elseif ($action === 'react') {
        $other = buddy_id_from($in, $pdo, $me);
        $day = (string)($in['day'] ?? '');
        $emoji = (string)($in['emoji'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !in_array($emoji, EMOJIS, true)) {
            fail(400, 'bad_request', 'Invalid request.');
        }
        $del = $pdo->prepare('DELETE FROM reactions WHERE from_user = ? AND to_user = ? AND day = ? AND emoji = ?');
        $del->execute([$me, $other, $day, $emoji]);
        if ($del->rowCount() === 0) {
            $pdo->prepare('INSERT INTO reactions (from_user, to_user, day, emoji) VALUES (?, ?, ?, ?)')
                ->execute([$me, $other, $day, $emoji]);
        }

    } elseif ($action === 'new_code') {
        new_buddy_code($pdo, $me);

    } else {
        fail(400, 'bad_request', 'Invalid request.');
    }
}

respond(state($pdo, $me));
