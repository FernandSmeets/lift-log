<?php
// GET (X-Auth-Token of an admin) -> {ok, exercises:[...], templates:[...]}.
// Which exercises and templates people have and use, for the admin page (/beheer/).
// Names are grouped case-insensitively, so "bench press" and "Bench Press" count as one.
require __DIR__ . '/lib.php';
start(['GET']);

$user = require_user();
if (!is_admin($user)) {
    fail(403, 'not_admin', 'This account has no access to the admin page.');
}

// Templates that come with the app (DEFAULT_TEMPLATES, SUGGESTED_LIBRARY and the
// Home Rig program in index.html). Any other template name was made by the user.
const BUILTIN_TEMPLATES = [
    'Chest Day', 'Leg Day', 'Full Body', 'Biceps Day', 'Isometric & Tendon Health',
    'Calisthenics', 'Home Rig A', 'Home Rig B',
];
$builtinTemplates = array_map('strtolower', BUILTIN_TEMPLATES);

$rows = db()->query(
    'SELECT u.email, u.display_name, d.data FROM users u JOIN user_data d ON d.user_id = u.id'
)->fetchAll();

$exercises = [];
$templates = [];

function group_key(string $name): string {
    return strtolower(preg_replace('/\s+/', ' ', trim($name)));
}
function add_variant(array &$group, string $name): void {
    $group['variants'][$name] = ($group['variants'][$name] ?? 0) + 1;
}

foreach ($rows as $r) {
    $data = json_decode((string)$r['data'], true);
    if (!is_array($data)) continue;
    $who = ['email' => $r['email'], 'name' => $r['display_name']];

    // Exercises in the user's list, plus any name that still has logged workouts.
    $mine = [];
    foreach ((array)($data['exercises'] ?? []) as $ex) {
        if (!is_array($ex) || !isset($ex['name']) || trim((string)$ex['name']) === '') continue;
        $mine[(string)$ex['name']] = ['in_list' => true, 'hidden' => !empty($ex['hidden']), 'builtin' => !empty($ex['builtin'])];
    }
    $sessions = is_array($data['sessions'] ?? null) ? $data['sessions'] : [];
    foreach ($sessions as $name => $list) {
        if (!isset($mine[(string)$name]) && is_array($list) && $list) {
            $mine[(string)$name] = ['in_list' => false, 'hidden' => false, 'builtin' => false];
        }
    }
    foreach ($mine as $name => $info) {
        $workouts = 0; $sets = 0; $last = null;
        foreach ((array)($sessions[$name] ?? []) as $s) {
            if (!is_array($s)) continue;
            $n = is_array($s['sets'] ?? null) ? count($s['sets']) : 0;
            if (!$n) continue;
            $workouts++;
            $sets += $n;
            $d = substr((string)($s['date'] ?? ''), 0, 10);
            if ($d !== '' && ($last === null || $d > $last)) $last = $d;
        }
        $k = group_key($name);
        $exercises[$k] ??= ['key' => $k, 'variants' => [], 'builtin' => false, 'people' => []];
        add_variant($exercises[$k], $name);
        if ($info['builtin']) $exercises[$k]['builtin'] = true;
        $exercises[$k]['people'][] = $who + [
            'as' => $name, 'builtin' => $info['builtin'], 'hidden' => $info['hidden'], 'in_list' => $info['in_list'],
            'workouts' => $workouts, 'sets' => $sets, 'last' => $last,
        ];
    }

    $lastDone = is_array($data['templateLastDone'] ?? null) ? $data['templateLastDone'] : [];
    foreach ((array)($data['templates'] ?? []) as $t) {
        if (!is_array($t) || !isset($t['name']) || trim((string)$t['name']) === '') continue;
        $name = (string)$t['name'];
        $k = group_key($name);
        $templates[$k] ??= ['key' => $k, 'variants' => [], 'builtin' => in_array($k, $builtinTemplates, true), 'people' => []];
        add_variant($templates[$k], $name);
        $last = isset($lastDone[$name]) ? substr((string)$lastDone[$name], 0, 10) : null;
        $templates[$k]['people'][] = $who + [
            'as' => $name, 'hidden' => !empty($t['hidden']),
            'exercises' => array_values(array_map('strval', array_filter((array)($t['exercises'] ?? []), 'is_scalar'))),
            'last' => $last ?: null,
        ];
    }
}

// Most common spelling becomes the display name; totals per group.
function finish(array $groups, callable $extra): array {
    $out = [];
    foreach ($groups as $g) {
        arsort($g['variants']);
        $g['name'] = (string)array_key_first($g['variants']);
        $g['variants'] = array_map('strval', array_keys($g['variants']));
        $g['users'] = count($g['people']);
        $g['last'] = null;
        foreach ($g['people'] as $p) {
            if ($p['last'] !== null && ($g['last'] === null || $p['last'] > $g['last'])) $g['last'] = $p['last'];
        }
        $out[] = $extra($g);
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

respond([
    'ok' => true,
    'exercises' => finish($exercises, function ($g) {
        $g['trained_by'] = count(array_filter($g['people'], fn($p) => $p['workouts'] > 0));
        $g['workouts'] = array_sum(array_column($g['people'], 'workouts'));
        $g['sets'] = array_sum(array_column($g['people'], 'sets'));
        return $g;
    }),
    'templates' => finish($templates, function ($g) {
        $g['visible_for'] = count(array_filter($g['people'], fn($p) => !$p['hidden']));
        $g['used_by'] = count(array_filter($g['people'], fn($p) => $p['last'] !== null));
        return $g;
    }),
]);
