<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

@unlink(__DIR__ . '/../storage/microtasker.sqlite');
$db = db();

$results = [];

$users = (int) $db->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
$results[] = ['Seed users created', $users >= 2];

$stmt = $db->prepare('INSERT INTO users(name,email,password,created_at) VALUES (?,?,?,?)');
$stmt->execute(['Alice', 'alice@test.local', password_hash('secret123', PASSWORD_DEFAULT), date('c')]);
$aliceId = (int) $db->lastInsertId();
$results[] = ['Create user', $aliceId > 0];

$db->prepare('INSERT INTO teams(name,created_by,created_at) VALUES (?,?,?)')->execute(['Alpha', $aliceId, date('c')]);
$teamId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO team_members(team_id,user_id,role,created_at) VALUES (?,?,?,?)')->execute([$teamId, $aliceId, 'admin', date('c')]);
$results[] = ['Create team + membership', $teamId > 0];

$today = date('Y-m-d');
$db->prepare('INSERT INTO tasks(team_id,title,description,assigned_to,status,priority,due_date,completed_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
   ->execute([$teamId,'Task A','', $aliceId, 'todo', 'high', $today, null, $aliceId, date('c'), date('c')]);
$taskCount = (int) $db->query('SELECT COUNT(*) AS c FROM tasks WHERE team_id=' . $teamId)->fetch()['c'];
$results[] = ['Create task', $taskCount === 1];

$db->prepare('INSERT INTO checkins(team_id,user_id,day,yesterday,today,blockers,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')
   ->execute([$teamId,$aliceId,$today,'x','y','z',date('c'),date('c')]);
$dupBlocked = false;
try {
    $db->prepare('INSERT INTO checkins(team_id,user_id,day,yesterday,today,blockers,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([$teamId,$aliceId,$today,'x2','y2','',date('c'),date('c')]);
} catch (Throwable $e) {
    $dupBlocked = true;
}
$results[] = ['Prevent duplicate check-in per day', $dupBlocked];

$failed = 0;
foreach ($results as [$name, $ok]) {
    echo ($ok ? "[PASS] " : "[FAIL] ") . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
