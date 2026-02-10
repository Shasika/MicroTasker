<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

@unlink(__DIR__ . '/../storage/microtasker.sqlite');
$db = db();

$results = [];

$users = (int) $db->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
$results[] = ['Seed users created', $users >= 2];

$admin = $db->query("SELECT * FROM users WHERE email='admin@demo.test' LIMIT 1")->fetch();
$results[] = ['Demo admin seeded', (bool) $admin];

$team = $db->query('SELECT * FROM teams LIMIT 1')->fetch();
$results[] = ['Demo team seeded', (bool) $team];

if ($admin && $team) {
    $membership = requireTeamMember((int) $team['id'], (int) $admin['id']);
    $results[] = ['Admin can access own team', $membership['role'] === 'admin'];
}

$stmt = $db->prepare('INSERT INTO users(name,email,password,created_at,updated_at) VALUES (?,?,?,?,?)');
$stmt->execute(['Alice', 'alice@test.local', password_hash('secret123', PASSWORD_DEFAULT), nowIso(), nowIso()]);
$aliceId = (int) $db->lastInsertId();
$results[] = ['Create user', $aliceId > 0];

$db->prepare('INSERT INTO teams(name,created_by,created_at) VALUES (?,?,?)')->execute(['Alpha', $aliceId, nowIso()]);
$alphaId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO team_members(team_id,user_id,role,created_at) VALUES (?,?,?,?)')->execute([$alphaId, $aliceId, 'admin', nowIso()]);
$results[] = ['Create team + membership', $alphaId > 0];

$today = todayDate();
$db->prepare('INSERT INTO tasks(team_id,title,description,assigned_to,status,priority,due_date,completed_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
   ->execute([$alphaId,'Task A','', $aliceId, 'todo', 'high', $today, null, $aliceId, nowIso(), nowIso()]);
$db->prepare('INSERT INTO tasks(team_id,title,description,assigned_to,status,priority,due_date,completed_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
   ->execute([$alphaId,'Task B','', null, 'doing', 'med', $today, null, $aliceId, nowIso(), nowIso()]);
$taskCount = (int) $db->query('SELECT COUNT(*) AS c FROM tasks WHERE team_id=' . $alphaId)->fetch()['c'];
$results[] = ['Create tasks', $taskCount === 2];

$filtered = (int) $db->query("SELECT COUNT(*) AS c FROM tasks WHERE team_id={$alphaId} AND status='doing'")->fetch()['c'];
$results[] = ['Task filtering by status works', $filtered === 1];

$db->prepare('UPDATE tasks SET status = ?, completed_at = ?, updated_at = ? WHERE team_id = ? AND title = ?')
    ->execute(['done', nowIso(), nowIso(), $alphaId, 'Task A']);
$done = (int) $db->query("SELECT COUNT(*) AS c FROM tasks WHERE team_id={$alphaId} AND status='done'")->fetch()['c'];
$results[] = ['Task status update works', $done >= 1];

$db->prepare('INSERT INTO checkins(team_id,user_id,day,yesterday,today,blockers,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')
   ->execute([$alphaId,$aliceId,$today,'x','y','z',nowIso(),nowIso()]);
$dupBlocked = false;
try {
    $db->prepare('INSERT INTO checkins(team_id,user_id,day,yesterday,today,blockers,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([$alphaId,$aliceId,$today,'x2','y2','',nowIso(),nowIso()]);
} catch (Throwable) {
    $dupBlocked = true;
}
$results[] = ['Prevent duplicate check-in per day', $dupBlocked];

$blockers = (int) $db->query("SELECT COUNT(*) AS c FROM checkins WHERE team_id={$alphaId} AND day='{$today}' AND blockers <> ''")->fetch()['c'];
$results[] = ['Blockers query available for summary', $blockers === 1];

$failed = 0;
foreach ($results as [$name, $ok]) {
    echo ($ok ? "[PASS] " : "[FAIL] ") . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
