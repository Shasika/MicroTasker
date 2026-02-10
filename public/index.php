<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

ensureCsrf();
$db = db();
$page = $_GET['page'] ?? 'today';
$user = currentUser();
$flash = takeFlash();

function render(string $title, string $content, ?array $user, ?array $flash): void
{
    $teams = $user ? userTeams((int) $user['id']) : [];
    $activeTeam = $user ? activeTeamId($user) : null;
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= h($title) ?> · MicroTasker</title>
<style>
:root{--bg:#f4f6fb;--card:#fff;--text:#1f2937;--muted:#6b7280;--accent:#4f46e5;--ok:#059669;--warn:#d97706;--danger:#dc2626}
*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--text)}
.container{max-width:1100px;margin:0 auto;padding:16px 16px 92px}
.card{background:var(--card);border-radius:16px;padding:14px;box-shadow:0 4px 20px rgba(15,23,42,.06)}
.btn{border:none;background:var(--accent);color:#fff;padding:10px 14px;border-radius:10px;cursor:pointer;text-decoration:none;display:inline-block}
.btn.secondary{background:#e5e7eb;color:#111827}.btn.danger{background:var(--danger)}
.input,select,textarea{width:100%;padding:10px;border-radius:10px;border:1px solid #d1d5db;background:#fff}
.grid{display:grid;gap:12px}.grid.cols-3{grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
.badge{padding:4px 8px;border-radius:999px;font-size:12px;background:#eef2ff;color:#3730a3}
header{position:sticky;top:0;background:#fff9;padding:10px 16px;backdrop-filter:blur(10px);border-bottom:1px solid #e5e7eb}
.nav{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid #e5e7eb;display:flex;justify-content:space-around;padding:10px 0}
.nav a{text-decoration:none;color:#4b5563;font-size:12px}.nav a.active{color:var(--accent);font-weight:700}
.fab{position:fixed;right:18px;bottom:74px;border-radius:999px;width:52px;height:52px;font-size:28px;line-height:1}
.flash{padding:10px;border-radius:10px;margin-bottom:12px}.flash.success{background:#dcfce7;color:#166534}.flash.error{background:#fee2e2;color:#991b1b}
small{color:var(--muted)}
</style>
</head>
<body>
<header>
  <div style="max-width:1100px;margin:0 auto;display:flex;gap:10px;align-items:center;justify-content:space-between">
    <strong>MicroTasker</strong>
    <?php if ($user): ?>
      <div style="display:flex;gap:8px;align-items:center">
        <span class="badge"><?= h($user['name']) ?></span>
        <form method="post" action="/?page=logout"><input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" /><button class="btn secondary">Logout</button></form>
      </div>
    <?php endif; ?>
  </div>
</header>
<div class="container">
<?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?= $content ?>
</div>
<?php if ($user): ?>
<nav class="nav">
  <?php $tabs=['today'=>'Today','checkin'=>'Check-in','team'=>'Team','settings'=>'Settings']; foreach($tabs as $k=>$label): ?>
  <a class="<?= $k===($_GET['page']??'today')?'active':'' ?>" href="/?page=<?= $k ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
</body>
</html>
<?php
}

if ($page === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        flash('error', 'Please provide valid name, email, and password (min 6 chars).');
        header('Location: /?page=register');exit;
    }
    $stmt = $db->prepare('INSERT INTO users(name,email,password,created_at) VALUES (?,?,?,?)');
    try {
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), date('c')]);
        $_SESSION['user_id'] = (int) $db->lastInsertId();
        flash('success', 'Account created. Create your first team.');
        header('Location: /?page=team');exit;
    } catch (Throwable $e) {
        flash('error', 'Email already exists.');
        header('Location: /?page=register');exit;
    }
}
if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');$stmt->execute([$email]);$row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password'])) {
        flash('error', 'Invalid credentials.');header('Location: /?page=login');exit;
    }
    $_SESSION['user_id'] = (int)$row['id'];
    flash('success', 'Welcome back!');header('Location: /?page=today');exit;
}
if ($page === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    session_destroy(); session_start(); flash('success', 'Logged out.'); header('Location: /?page=login'); exit;
}

if (!$user && !in_array($page, ['login','register'], true)) { header('Location: /?page=login'); exit; }

if ($page === 'team_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireAuth();
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { flash('error','Team name required.'); header('Location: /?page=team'); exit; }
    $now = date('c');
    $db->prepare('INSERT INTO teams(name,created_by,created_at) VALUES (?,?,?)')->execute([$name,$user['id'],$now]);
    $teamId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO team_members(team_id,user_id,role,created_at) VALUES (?,?,?,?)')->execute([$teamId,$user['id'],'admin',$now]);
    $_SESSION['active_team_id'] = $teamId;
    flash('success','Team created.'); header('Location: /?page=team'); exit;
}
if ($page === 'team_switch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireAuth();
    $teamId = (int) ($_POST['team_id'] ?? 0);
    requireTeamMember($teamId, (int) $user['id']);
    $_SESSION['active_team_id'] = $teamId; flash('success','Switched team.'); header('Location: /?page=today'); exit;
}
if ($page === 'invite_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireAuth();
    $teamId = activeTeamId($user); if (!$teamId) { flash('error','No active team.'); header('Location: /?page=team'); exit; }
    $membership = requireTeamMember($teamId, (int)$user['id']);
    if ($membership['role'] !== 'admin') { flash('error','Only admins can invite.'); header('Location: /?page=team'); exit; }
    $email = trim($_POST['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('error', 'Invalid email.'); header('Location: /?page=team'); exit; }
    $token = bin2hex(random_bytes(18));
    $db->prepare('INSERT INTO team_invites(team_id,email,token,invited_by,expires_at,created_at) VALUES (?,?,?,?,?,?)')
      ->execute([$teamId,$email ?: null,$token,$user['id'],date('c', strtotime('+7 days')),date('c')]);
    flash('success','Invite created. Share link below.');
    $_SESSION['last_invite'] = $token;
    header('Location: /?page=team'); exit;
}
if ($page === 'invite_accept') {
    $user = requireAuth();
    $token = $_GET['token'] ?? '';
    $stmt = $db->prepare('SELECT * FROM team_invites WHERE token = ?');$stmt->execute([$token]);$invite=$stmt->fetch();
    if (!$invite || $invite['accepted_by'] || strtotime($invite['expires_at']) < time()) { flash('error','Invite invalid or expired.'); header('Location: /?page=team'); exit; }
    if ($invite['email'] && strtolower($invite['email']) !== strtolower($user['email'])) { flash('error', 'Invite is for a different email.'); header('Location: /?page=team'); exit; }
    $db->prepare('INSERT OR IGNORE INTO team_members(team_id,user_id,role,created_at) VALUES (?,?,?,?)')->execute([$invite['team_id'],$user['id'],'member',date('c')]);
    $db->prepare('UPDATE team_invites SET accepted_by = ? WHERE id = ?')->execute([$user['id'],$invite['id']]);
    $_SESSION['active_team_id'] = (int) $invite['team_id']; flash('success','Joined team.'); header('Location: /?page=today'); exit;
}

if (in_array($page, ['task_create','task_update','task_quick'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireAuth();
    $teamId = activeTeamId($user); requireTeamMember((int)$teamId, (int)$user['id']);
    if ($page === 'task_create') {
        $title = trim($_POST['title'] ?? ''); if ($title === '') { flash('error','Task title required.'); header('Location: /?page=today'); exit; }
        $db->prepare('INSERT INTO tasks(team_id,title,description,assigned_to,status,priority,due_date,completed_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$teamId,$title,trim($_POST['description'] ?? ''),($_POST['assigned_to']??'')!==''?(int)$_POST['assigned_to']:null,$_POST['status'] ?? 'todo',$_POST['priority'] ?? 'med',$_POST['due_date'] ?: date('Y-m-d'),null,$user['id'],date('c'),date('c')]);
        flash('success','Task added.');
    }
    if ($page === 'task_update') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM tasks WHERE id = ? AND team_id = ?');$stmt->execute([$taskId,$teamId]);$task=$stmt->fetch();
        if (!$task) { flash('error','Task not found.'); header('Location:/?page=today'); exit; }
        $status = $_POST['status'] ?? $task['status'];
        $completedAt = $status === 'done' ? date('c') : null;
        $db->prepare('UPDATE tasks SET title=?,description=?,assigned_to=?,status=?,priority=?,due_date=?,completed_at=?,updated_at=? WHERE id=?')
            ->execute([trim($_POST['title'] ?? $task['title']),trim($_POST['description'] ?? $task['description']),($_POST['assigned_to']??'')!==''?(int)$_POST['assigned_to']:null,$status,$_POST['priority'] ?? $task['priority'],$_POST['due_date'] ?? $task['due_date'],$completedAt,date('c'),$taskId]);
        flash('success','Task updated.');
    }
    if ($page === 'task_quick') {
        $taskId=(int)($_POST['task_id']??0);$field=$_POST['field']??'';$value=$_POST['value']??'';
        $stmt=$db->prepare('SELECT * FROM tasks WHERE id = ? AND team_id = ?');$stmt->execute([$taskId,$teamId]);$task=$stmt->fetch();
        if ($task && in_array($field,['status','priority'],true)) {
            $completedAt = $field==='status' && $value==='done' ? date('c') : ($field==='status'?null:$task['completed_at']);
            $db->prepare("UPDATE tasks SET {$field}=?, completed_at=?, updated_at=? WHERE id=?")->execute([$value,$completedAt,date('c'),$taskId]);
            flash('success','Task updated.');
        }
    }
    header('Location: /?page=today'); exit;
}

if ($page === 'checkin_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireAuth();
    $teamId = activeTeamId($user); requireTeamMember((int)$teamId, (int)$user['id']);
    $day = date('Y-m-d');
    $stmt = $db->prepare('SELECT id FROM checkins WHERE team_id=? AND user_id=? AND day=?');
    $stmt->execute([$teamId,$user['id'],$day]);
    $exists = $stmt->fetch();
    if ($exists) {
        $db->prepare('UPDATE checkins SET yesterday=?,today=?,blockers=?,updated_at=? WHERE id=?')->execute([
            trim($_POST['yesterday'] ?? ''), trim($_POST['today'] ?? ''), trim($_POST['blockers'] ?? ''), date('c'), $exists['id']
        ]);
        flash('success','Check-in updated.');
    } else {
        $db->prepare('INSERT INTO checkins(team_id,user_id,day,yesterday,today,blockers,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')->execute([
            $teamId,$user['id'],$day,trim($_POST['yesterday'] ?? ''),trim($_POST['today'] ?? ''),trim($_POST['blockers'] ?? ''),date('c'),date('c')
        ]);
        flash('success','Check-in submitted.');
    }
    header('Location: /?page=checkin'); exit;
}

if (!$user) {
    if ($page === 'register') {
        render('Register', '<div class="card"><h2>Create account</h2><form method="post"><input type="hidden" name="_token" value="'.h(csrfToken()).'" /><div class="grid"><input class="input" name="name" placeholder="Name" required /><input class="input" type="email" name="email" placeholder="Email" required /><input class="input" type="password" name="password" placeholder="Password" required minlength="6" /><button class="btn">Register</button><small>Have an account? <a href="/?page=login">Login</a></small></div></form></div>', null, $flash); exit;
    }
    render('Login', '<div class="card"><h2>Welcome back</h2><form method="post"><input type="hidden" name="_token" value="'.h(csrfToken()).'" /><div class="grid"><input class="input" type="email" name="email" placeholder="Email" required /><input class="input" type="password" name="password" placeholder="Password" required /><button class="btn">Login</button><small>New here? <a href="/?page=register">Create account</a></small></div></form><p><small>Demo login: admin@demo.test / password</small></p></div>', null, $flash); exit;
}

$teamId = activeTeamId($user);
$teams = userTeams((int)$user['id']);
$teamCtx = $teamId ? requireTeamMember((int)$teamId, (int)$user['id']) : null;

if ($page === 'team') {
    $members=[];$invites=[];
    if ($teamId) {
        $stmt = $db->prepare('SELECT u.name,u.email,tm.role FROM team_members tm JOIN users u ON u.id=tm.user_id WHERE tm.team_id=? ORDER BY tm.role DESC, u.name');$stmt->execute([$teamId]);$members=$stmt->fetchAll();
        $stmt=$db->prepare('SELECT * FROM team_invites WHERE team_id=? ORDER BY id DESC LIMIT 8');$stmt->execute([$teamId]);$invites=$stmt->fetchAll();
    }
    ob_start(); ?>
<div class="grid cols-3">
  <div class="card"><h3>Create team</h3><form method="post" action="/?page=team_create"><input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" /><input class="input" name="name" placeholder="Team name" required /><p style="margin-top:10px"><button class="btn">Create</button></p></form></div>
  <div class="card"><h3>Switch team</h3>
    <?php if (!$teams): ?><p>No teams yet.</p><?php endif; ?>
    <?php foreach($teams as $team): ?><form style="margin-bottom:8px" method="post" action="/?page=team_switch"><input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" /><input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>" /><button class="btn secondary" style="width:100%<?= (int)$team['id']===(int)$teamId?';font-weight:700':'' ?>"><?= h($team['name']) ?> (<?= h($team['role']) ?>)</button></form><?php endforeach; ?>
  </div>
  <div class="card"><h3>Invite member</h3>
    <?php if (!$teamId): ?><p>Create or join a team first.</p><?php elseif($teamCtx['role']!=='admin'): ?><p>Only admins can invite members.</p><?php else: ?>
      <form method="post" action="/?page=invite_create"><input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" /><input class="input" type="email" name="email" placeholder="Invite email (optional)" /><p style="margin-top:10px"><button class="btn">Create invite link</button></p></form>
      <?php if (!empty($_SESSION['last_invite'])): ?><small>Latest: <a href="/?page=invite_accept&token=<?= h($_SESSION['last_invite']) ?>">Accept link</a></small><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<div class="grid cols-3" style="margin-top:12px">
  <div class="card" style="grid-column:span 2"><h3>Members</h3><?php foreach($members as $m): ?><p><strong><?= h($m['name']) ?></strong> <small><?= h($m['email']) ?></small> <span class="badge"><?= h($m['role']) ?></span></p><?php endforeach; ?></div>
  <div class="card"><h3>Recent invites</h3><?php foreach($invites as $inv): ?><p><small><?= h($inv['email'] ?: 'Shareable') ?></small><br/><a href="/?page=invite_accept&token=<?= h($inv['token']) ?>">Join link</a></p><?php endforeach; ?></div>
</div>
<?php render('Team', ob_get_clean(), $user, $flash); exit; }

if (!$teamId) {
    render('No team', '<div class="card"><h3>No team selected</h3><p>Create a team to start tracking tasks.</p><a class="btn" href="/?page=team">Go to Team</a></div>', $user, $flash); exit;
}

if ($page === 'checkin') {
    $today = date('Y-m-d');
    $stmt=$db->prepare('SELECT * FROM checkins WHERE team_id=? AND user_id=? AND day=?');$stmt->execute([$teamId,$user['id'],$today]);$mine=$stmt->fetch();
    $stmt=$db->prepare('SELECT c.*,u.name FROM checkins c JOIN users u ON u.id=c.user_id WHERE c.team_id=? AND c.day=? ORDER BY u.name');$stmt->execute([$teamId,$today]);$feed=$stmt->fetchAll();
    ob_start(); ?>
<div class="grid cols-3">
  <div class="card"><h3>Daily check-in (<?= h($today) ?>)</h3><form method="post" action="/?page=checkin_save"><input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" /><label>Yesterday</label><textarea name="yesterday" rows="3"><?= h($mine['yesterday'] ?? '') ?></textarea><label>Today</label><textarea name="today" rows="3"><?= h($mine['today'] ?? '') ?></textarea><label>Blockers</label><textarea name="blockers" rows="3"><?= h($mine['blockers'] ?? '') ?></textarea><p style="margin-top:10px"><button class="btn"><?= $mine?'Update':'Submit' ?></button></p></form></div>
  <div class="card" style="grid-column:span 2"><h3>Team feed</h3><?php if(!$feed): ?><p>No check-ins yet.</p><?php endif; foreach($feed as $c): ?><div class="card" style="margin-bottom:8px;background:#fafafa"><strong><?= h($c['name']) ?></strong><p><small>Yesterday:</small> <?= h($c['yesterday']) ?></p><p><small>Today:</small> <?= h($c['today']) ?></p><?php if(trim((string)$c['blockers'])!==''): ?><p><small>Blockers:</small> <span style="color:var(--danger)"><?= h($c['blockers']) ?></span></p><?php endif; ?></div><?php endforeach; ?></div>
</div>
<?php render('Check-in', ob_get_clean(), $user, $flash); exit; }

if ($page === 'settings') {
    ob_start(); ?>
<div class="card"><h3>Profile</h3><p><strong><?= h($user['name']) ?></strong></p><p><small><?= h($user['email']) ?></small></p><p>This lightweight system is production-minded with SQLite local persistence and session-based auth.</p></div>
<?php render('Settings', ob_get_clean(), $user, $flash); exit; }

if ($page === 'summary') {
    $today = date('Y-m-d');
    $completed=$db->prepare('SELECT t.*,u.name AS assignee FROM tasks t LEFT JOIN users u ON u.id=t.assigned_to WHERE t.team_id=? AND t.status="done" AND date(t.updated_at)=? ORDER BY t.updated_at DESC');$completed->execute([$teamId,$today]);$completedRows=$completed->fetchAll();
    $doing=$db->prepare('SELECT * FROM tasks WHERE team_id=? AND status="doing" AND due_date=? ORDER BY priority DESC');$doing->execute([$teamId,$today]);$doingRows=$doing->fetchAll();
    $block=$db->prepare('SELECT u.name,c.blockers FROM checkins c JOIN users u ON u.id=c.user_id WHERE c.team_id=? AND c.day=? AND trim(c.blockers)<>""');$block->execute([$teamId,$today]);$blockRows=$block->fetchAll();
    $health=count($doingRows)+count($blockRows);
    ob_start(); ?>
<div class="grid cols-3">
  <div class="card"><h3>Team health</h3><p style="font-size:32px;margin:8px 0"><?= $health ?></p><small>Doing tasks + blockers count</small></div>
  <div class="card"><h3>Completed today</h3><?php foreach($completedRows as $r): ?><p>✅ <?= h($r['title']) ?></p><?php endforeach; if(!$completedRows): ?><p>None yet.</p><?php endif; ?></div>
  <div class="card"><h3>Still in progress</h3><?php foreach($doingRows as $r): ?><p>⏳ <?= h($r['title']) ?></p><?php endforeach; if(!$doingRows): ?><p>All clear.</p><?php endif; ?></div>
</div>
<div class="card" style="margin-top:12px"><h3>Blockers</h3><?php foreach($blockRows as $b): ?><p><strong><?= h($b['name']) ?>:</strong> <?= h($b['blockers']) ?></p><?php endforeach; if(!$blockRows): ?><p>No blockers reported.</p><?php endif; ?></div>
<?php render('Team Summary', ob_get_clean(), $user, $flash); exit; }

$today = date('Y-m-d');
$mine = ($_GET['mine'] ?? '') === '1';
$statusFilter = $_GET['status'] ?? '';
$priorityFilter = $_GET['priority'] ?? '';
$params = [$teamId, $today];
$sql = 'SELECT t.*,u.name AS assignee FROM tasks t LEFT JOIN users u ON u.id=t.assigned_to WHERE t.team_id=? AND t.due_date=?';
if ($mine) { $sql .= ' AND t.assigned_to=?'; $params[] = $user['id']; }
if (in_array($statusFilter,['todo','doing','done'], true)) { $sql .= ' AND t.status=?'; $params[] = $statusFilter; }
if (in_array($priorityFilter,['low','med','high'], true)) { $sql .= ' AND t.priority=?'; $params[] = $priorityFilter; }
$sql .= ' ORDER BY CASE t.priority WHEN "high" THEN 1 WHEN "med" THEN 2 ELSE 3 END, t.id DESC';
$stmt = $db->prepare($sql); $stmt->execute($params); $tasks = $stmt->fetchAll();
$byStatus = ['todo'=>[],'doing'=>[],'done'=>[]]; foreach($tasks as $task){$byStatus[$task['status']][]=$task;}
$membersStmt=$db->prepare('SELECT u.id,u.name FROM team_members tm JOIN users u ON u.id=tm.user_id WHERE tm.team_id=? ORDER BY u.name');$membersStmt->execute([$teamId]);$members=$membersStmt->fetchAll();

ob_start(); ?>
<div style="display:flex;justify-content:space-between;align-items:center"><h2>Today Board</h2><a class="btn secondary" href="/?page=summary">Team Summary</a></div>
<div class="card" style="margin-bottom:12px"><form method="get" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:8px"><input type="hidden" name="page" value="today" /><select name="mine"><option value="">All tasks</option><option value="1" <?= $mine?'selected':'' ?>>My tasks</option></select><select name="status"><option value="">Any status</option><?php foreach(['todo','doing','done'] as $s): ?><option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select><select name="priority"><option value="">Any priority</option><?php foreach(['low','med','high'] as $p): ?><option value="<?= $p ?>" <?= $priorityFilter===$p?'selected':'' ?>><?= $p ?></option><?php endforeach; ?></select><button class="btn secondary">Filter</button></form></div>
<div class="grid cols-3">
<?php foreach(['todo'=>'To do','doing'=>'Doing','done'=>'Done'] as $key=>$label): ?>
  <div class="card"><h3><?= h($label) ?> <small>(<?= count($byStatus[$key]) ?>)</small></h3>
  <?php if(!$byStatus[$key]): ?><p><small>Nothing here yet.</small></p><?php endif; ?>
  <?php foreach($byStatus[$key] as $t): ?>
    <div class="card" style="margin-bottom:8px;background:#fafafa">
      <strong><?= h($t['title']) ?></strong>
      <p><small><?= h($t['assignee'] ?? 'Unassigned') ?> · <?= h($t['priority']) ?></small></p>
      <form method="post" action="/?page=task_quick" style="display:flex;gap:6px;align-items:center"><input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" /><input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>" /><input type="hidden" name="field" value="status" /><select name="value"><?php foreach(['todo','doing','done'] as $s): ?><option value="<?= $s ?>" <?= $t['status']===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select><button class="btn secondary">Save</button></form>
    </div>
  <?php endforeach; ?>
  </div>
<?php endforeach; ?>
</div>
<button class="btn fab" onclick="document.getElementById('newTask').style.display='block'">+</button>
<div id="newTask" class="card" style="display:none;position:fixed;left:8px;right:8px;bottom:76px;max-height:80vh;overflow:auto">
<h3>Add task</h3>
<form method="post" action="/?page=task_create"><input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" />
<label>Title</label><input class="input" name="title" required />
<label>Description</label><textarea name="description"></textarea>
<label>Assignee</label><select name="assigned_to"><option value="">Unassigned</option><?php foreach($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= h($m['name']) ?></option><?php endforeach; ?></select>
<div class="grid" style="grid-template-columns:1fr 1fr 1fr"><div><label>Status</label><select name="status"><?php foreach(['todo','doing','done'] as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?></select></div><div><label>Priority</label><select name="priority"><?php foreach(['low','med','high'] as $p): ?><option value="<?= $p ?>"><?= $p ?></option><?php endforeach; ?></select></div><div><label>Due date</label><input type="date" name="due_date" value="<?= h($today) ?>" /></div></div>
<p style="margin-top:10px;display:flex;gap:8px"><button class="btn">Create task</button><button class="btn secondary" type="button" onclick="document.getElementById('newTask').style.display='none'">Close</button></p></form>
</div>
<?php
render('Today', ob_get_clean(), $user, $flash);
