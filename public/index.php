<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

ensureCsrf();
$db = db();
$page = (string) ($_GET['page'] ?? 'today');
$user = currentUser();
$flash = takeFlash();

function render(string $title, string $content, ?array $user, ?array $flash): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($title) ?> · MicroTasker</title>
  <link rel="stylesheet" href="/assets/app.css" />
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">MicroTasker</div>
    <?php if ($user): ?>
      <div style="display:flex;gap:8px;align-items:center;">
        <span class="badge"><?= h($user['name']) ?></span>
        <form method="post" action="/?page=logout">
          <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" />
          <button class="btn secondary" type="submit">Logout</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</header>
<div class="wrap">
  <?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <?= $content ?>
</div>
<?php if ($user): ?>
<nav class="nav-bottom">
  <?php $tabs = ['today' => 'Today', 'checkin' => 'Check-in', 'team' => 'Team', 'settings' => 'Settings']; ?>
  <?php foreach ($tabs as $slug => $label): ?>
    <a class="<?= $slug === (string) ($_GET['page'] ?? 'today') ? 'active' : '' ?>" href="/?page=<?= $slug ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<script src="/assets/app.js"></script>
</body>
</html>
<?php
}

function isPost(string $route): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_GET['page'] ?? '') === $route;
}

if (isPost('register')) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        flash('error', 'Use a valid name/email and password with at least 6 characters.');
        redirect('/?page=register');
    }

    try {
        $db->prepare('INSERT INTO users(name,email,password,created_at,updated_at) VALUES (?,?,?,?,?)')->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), nowIso(), nowIso()]);
    } catch (Throwable) {
        flash('error', 'Email is already registered.');
        redirect('/?page=register');
    }

    $_SESSION['user_id'] = (int) $db->lastInsertId();
    flash('success', 'Welcome! Create your first team.');
    redirect('/?page=team');
}

if (isPost('login')) {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $account = $stmt->fetch();

    if (!$account || !password_verify($password, (string) $account['password'])) {
        flash('error', 'Invalid credentials.');
        redirect('/?page=login');
    }

    $_SESSION['user_id'] = (int) $account['id'];
    flash('success', 'Logged in successfully.');
    redirect('/?page=today');
}

if (isPost('logout')) {
    session_destroy();
    session_start();
    flash('success', 'Logged out.');
    redirect('/?page=login');
}

if (!$user && !in_array($page, ['login', 'register'], true)) {
    redirect('/?page=login');
}

if (!$user) {
    if ($page === 'register') {
        render('Register', '<div class="card"><h2>Create account</h2><form method="post"><input type="hidden" name="_token" value="' . h(csrfToken()) . '" /><div class="grid"><label>Name<input class="input" name="name" required></label><label>Email<input class="input" type="email" name="email" required></label><label>Password<input class="input" type="password" name="password" minlength="6" required></label><button class="btn">Create account</button><small class="muted">Already have an account? <a href="/?page=login">Login</a></small></div></form></div>', null, $flash);
        exit;
    }

    render('Login', '<div class="card"><h2>Sign in</h2><p><small class="muted">Demo: admin@demo.test / password</small></p><form method="post"><input type="hidden" name="_token" value="' . h(csrfToken()) . '" /><div class="grid"><label>Email<input class="input" type="email" name="email" required></label><label>Password<input class="input" type="password" name="password" required></label><button class="btn">Login</button><small class="muted">New here? <a href="/?page=register">Create account</a></small></div></form></div>', null, $flash);
    exit;
}

$user = requireAuth();
$teamId = activeTeamId($user);

if (isPost('team_create')) {
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) {
        flash('error', 'Team name is required and must be under 80 chars.');
        redirect('/?page=team');
    }

    $db->prepare('INSERT INTO teams(name,created_by,created_at) VALUES (?,?,?)')->execute([$name, $user['id'], nowIso()]);
    $newTeamId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO team_members(team_id,user_id,role,created_at) VALUES (?,?,?,?)')->execute([$newTeamId, $user['id'], 'admin', nowIso()]);
    $_SESSION['active_team_id'] = $newTeamId;

    flash('success', 'Team created.');
    redirect('/?page=team');
}

if (isPost('team_switch')) {
    $switchTo = (int) ($_POST['team_id'] ?? 0);
    requireTeamMember($switchTo, (int) $user['id']);
    $_SESSION['active_team_id'] = $switchTo;
    flash('success', 'Switched team.');
    redirect('/?page=today');
}

if (isPost('invite_create')) {
    if (!$teamId) {
        flash('error', 'Create a team first.');
        redirect('/?page=team');
    }

    $member = requireTeamMember((int) $teamId, (int) $user['id']);
    if ($member['role'] !== 'admin') {
        flash('error', 'Only admins can create invites.');
        redirect('/?page=team');
    }

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Invite email is invalid.');
        redirect('/?page=team');
    }

    $token = bin2hex(random_bytes(20));
    $db->prepare('INSERT INTO team_invites(team_id,email,token,invited_by,expires_at,created_at) VALUES (?,?,?,?,?,?)')->execute([(int) $teamId, $email !== '' ? $email : null, $token, $user['id'], date('c', strtotime('+7 day')), nowIso()]);
    $_SESSION['last_invite'] = $token;
    flash('success', 'Invite link created.');
    redirect('/?page=team');
}

if ($page === 'invite_accept') {
    $token = (string) ($_GET['token'] ?? '');
    $stmt = $db->prepare('SELECT * FROM team_invites WHERE token = ?');
    $stmt->execute([$token]);
    $invite = $stmt->fetch();

    if (!$invite || $invite['accepted_by'] || strtotime((string) $invite['expires_at']) < time()) {
        flash('error', 'Invite is invalid or expired.');
        redirect('/?page=team');
    }

    if ($invite['email'] && strtolower((string) $invite['email']) !== strtolower((string) $user['email'])) {
        flash('error', 'This invite is bound to a different email.');
        redirect('/?page=team');
    }

    $db->prepare('INSERT OR IGNORE INTO team_members(team_id,user_id,role,created_at) VALUES (?,?,?,?)')->execute([(int) $invite['team_id'], $user['id'], 'member', nowIso()]);
    $db->prepare('UPDATE team_invites SET accepted_by = ? WHERE id = ?')->execute([$user['id'], $invite['id']]);
    $_SESSION['active_team_id'] = (int) $invite['team_id'];
    flash('success', 'You joined the team.');
    redirect('/?page=today');
}

if ($teamId && isPost('task_create')) {
    requireTeamMember((int) $teamId, (int) $user['id']);

    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $status = validateEnum((string) ($_POST['status'] ?? 'todo'), ['todo', 'doing', 'done'], 'todo');
    $priority = validateEnum((string) ($_POST['priority'] ?? 'med'), ['low', 'med', 'high'], 'med');
    $dueDate = (string) ($_POST['due_date'] ?? todayDate());
    $assigned = (string) ($_POST['assigned_to'] ?? '');
    $assignedTo = $assigned !== '' ? (int) $assigned : null;

    if ($title === '' || mb_strlen($title) > 160) {
        flash('error', 'Task title is required and must be <= 160 chars.');
        redirect('/?page=today');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        $dueDate = todayDate();
    }

    $completedAt = $status === 'done' ? nowIso() : null;

    $db->prepare('INSERT INTO tasks(team_id,title,description,assigned_to,status,priority,due_date,completed_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$teamId, $title, $description, $assignedTo, $status, $priority, $dueDate, $completedAt, $user['id'], nowIso(), nowIso()]);

    flash('success', 'Task created.');
    redirect('/?page=today');
}

if ($teamId && isPost('task_update')) {
    requireTeamMember((int) $teamId, (int) $user['id']);

    $taskId = (int) ($_POST['task_id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM tasks WHERE id = ? AND team_id = ?');
    $stmt->execute([$taskId, $teamId]);
    $task = $stmt->fetch();

    if (!$task) {
        flash('error', 'Task not found.');
        redirect('/?page=today');
    }

    $status = validateEnum((string) ($_POST['status'] ?? $task['status']), ['todo', 'doing', 'done'], (string) $task['status']);
    $priority = validateEnum((string) ($_POST['priority'] ?? $task['priority']), ['low', 'med', 'high'], (string) $task['priority']);
    $title = trim((string) ($_POST['title'] ?? $task['title']));
    $description = trim((string) ($_POST['description'] ?? $task['description']));
    $dueDate = (string) ($_POST['due_date'] ?? $task['due_date']);
    $assignedTo = (string) ($_POST['assigned_to'] ?? '') !== '' ? (int) $_POST['assigned_to'] : null;

    if ($title === '') {
        flash('error', 'Task title is required.');
        redirect('/?page=today');
    }

    $completedAt = $status === 'done' ? ((string) $task['completed_at'] !== '' ? $task['completed_at'] : nowIso()) : null;

    $db->prepare('UPDATE tasks SET title = ?, description = ?, assigned_to = ?, status = ?, priority = ?, due_date = ?, completed_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$title, $description, $assignedTo, $status, $priority, $dueDate, $completedAt, nowIso(), $taskId]);

    flash('success', 'Task updated.');
    redirect('/?page=today');
}

if ($teamId && isPost('task_quick')) {
    requireTeamMember((int) $teamId, (int) $user['id']);
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $field = (string) ($_POST['field'] ?? '');
    $value = (string) ($_POST['value'] ?? '');

    $stmt = $db->prepare('SELECT * FROM tasks WHERE id = ? AND team_id = ?');
    $stmt->execute([$taskId, $teamId]);
    $task = $stmt->fetch();

    if (!$task) {
        flash('error', 'Task not found.');
        redirect('/?page=today');
    }

    if ($field === 'status') {
        $status = validateEnum($value, ['todo', 'doing', 'done'], (string) $task['status']);
        $completedAt = $status === 'done' ? nowIso() : null;
        $db->prepare('UPDATE tasks SET status = ?, completed_at = ?, updated_at = ? WHERE id = ?')->execute([$status, $completedAt, nowIso(), $taskId]);
    }

    if ($field === 'priority') {
        $priority = validateEnum($value, ['low', 'med', 'high'], (string) $task['priority']);
        $db->prepare('UPDATE tasks SET priority = ?, updated_at = ? WHERE id = ?')->execute([$priority, nowIso(), $taskId]);
    }

    if ($field === 'assigned_to') {
        $assigned = $value === '' ? null : (int) $value;
        $db->prepare('UPDATE tasks SET assigned_to = ?, updated_at = ? WHERE id = ?')->execute([$assigned, nowIso(), $taskId]);
    }

    flash('success', 'Task quick action applied.');
    redirect('/?page=today');
}

if ($teamId && isPost('checkin_save')) {
    requireTeamMember((int) $teamId, (int) $user['id']);

    $day = todayDate();
    $yesterday = trim((string) ($_POST['yesterday'] ?? ''));
    $todayPlan = trim((string) ($_POST['today'] ?? ''));
    $blockers = trim((string) ($_POST['blockers'] ?? ''));

    if ($todayPlan === '' && $yesterday === '') {
        flash('error', 'Please fill at least yesterday or today update.');
        redirect('/?page=checkin');
    }

    $stmt = $db->prepare('SELECT id FROM checkins WHERE team_id = ? AND user_id = ? AND day = ?');
    $stmt->execute([$teamId, $user['id'], $day]);
    $existing = $stmt->fetch();

    if ($existing) {
        $db->prepare('UPDATE checkins SET yesterday = ?, today = ?, blockers = ?, updated_at = ? WHERE id = ?')->execute([$yesterday, $todayPlan, $blockers, nowIso(), $existing['id']]);
        flash('success', 'Check-in updated.');
    } else {
        $db->prepare('INSERT INTO checkins(team_id,user_id,day,yesterday,today,blockers,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$teamId, $user['id'], $day, $yesterday, $todayPlan, $blockers, nowIso(), nowIso()]);
        flash('success', 'Check-in submitted.');
    }

    redirect('/?page=checkin');
}

if (isPost('profile_update')) {
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        flash('error', 'Name cannot be empty.');
        redirect('/?page=settings');
    }

    $db->prepare('UPDATE users SET name = ?, updated_at = ? WHERE id = ?')->execute([$name, nowIso(), $user['id']]);
    flash('success', 'Profile updated.');
    redirect('/?page=settings');
}

if (isPost('password_update')) {
    $current = (string) ($_POST['current_password'] ?? '');
    $next = (string) ($_POST['new_password'] ?? '');

    $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, (string) $row['password'])) {
        flash('error', 'Current password is incorrect.');
        redirect('/?page=settings');
    }

    if (strlen($next) < 6) {
        flash('error', 'New password must be at least 6 characters.');
        redirect('/?page=settings');
    }

    $db->prepare('UPDATE users SET password = ?, updated_at = ? WHERE id = ?')->execute([password_hash($next, PASSWORD_DEFAULT), nowIso(), $user['id']]);
    flash('success', 'Password updated.');
    redirect('/?page=settings');
}

$teams = userTeams((int) $user['id']);
$teamMembership = $teamId ? requireTeamMember((int) $teamId, (int) $user['id']) : null;

if ($page === 'team') {
    $members = [];
    $invites = [];

    if ($teamId) {
        $stmt = $db->prepare('SELECT u.name, u.email, tm.role FROM team_members tm JOIN users u ON u.id = tm.user_id WHERE tm.team_id = ? ORDER BY tm.role DESC, u.name ASC');
        $stmt->execute([$teamId]);
        $members = $stmt->fetchAll();

        $stmt = $db->prepare('SELECT * FROM team_invites WHERE team_id = ? ORDER BY id DESC LIMIT 8');
        $stmt->execute([$teamId]);
        $invites = $stmt->fetchAll();
    }

    ob_start();
    ?>
    <div class="grid cols-3">
      <section class="card">
        <h3>Create team</h3>
        <form method="post" action="/?page=team_create" class="grid">
          <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" />
          <input class="input" name="name" placeholder="Team name" maxlength="80" required />
          <button class="btn" type="submit">Create team</button>
        </form>
      </section>

      <section class="card">
        <h3>Switch team</h3>
        <?php if (!$teams): ?>
          <div class="empty">No teams yet. Create one to get started.</div>
        <?php endif; ?>
        <?php foreach ($teams as $team): ?>
          <form method="post" action="/?page=team_switch" style="margin-bottom:8px;">
            <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" />
            <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>" />
            <button class="btn secondary" type="submit" style="width:100%; <?= (int) $team['id'] === (int) $teamId ? 'font-weight:800;' : '' ?>">
              <?= h($team['name']) ?> · <?= h($team['role']) ?>
            </button>
          </form>
        <?php endforeach; ?>
      </section>

      <section class="card">
        <h3>Invite members</h3>
        <?php if (!$teamId): ?>
          <div class="empty">Select or create a team first.</div>
        <?php elseif (($teamMembership['role'] ?? 'member') !== 'admin'): ?>
          <div class="empty">Only admins can create invite links.</div>
        <?php else: ?>
          <form method="post" action="/?page=invite_create" class="grid">
            <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" />
            <input class="input" type="email" name="email" placeholder="Email (optional, binds invite)" />
            <button class="btn" type="submit">Create invite link</button>
            <?php if (!empty($_SESSION['last_invite'])): ?>
              <small class="muted">Latest link: <a href="/?page=invite_accept&token=<?= h($_SESSION['last_invite']) ?>">join team</a></small>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </section>
    </div>

    <div class="grid cols-2" style="margin-top:12px;">
      <section class="card">
        <h3>Members</h3>
        <?php if (!$members): ?><div class="empty">No members yet.</div><?php endif; ?>
        <?php foreach ($members as $member): ?>
          <p><strong><?= h($member['name']) ?></strong> <small class="muted"><?= h($member['email']) ?></small> <span class="badge"><?= h($member['role']) ?></span></p>
        <?php endforeach; ?>
      </section>

      <section class="card">
        <h3>Recent invite links</h3>
        <?php if (!$invites): ?><div class="empty">No invites created yet.</div><?php endif; ?>
        <?php foreach ($invites as $invite): ?>
          <div class="task-card">
            <small class="muted">Target: <?= h($invite['email'] ?: 'Shareable link') ?></small><br />
            <a href="/?page=invite_accept&token=<?= h($invite['token']) ?>">Accept invite link</a>
          </div>
        <?php endforeach; ?>
      </section>
    </div>
    <?php
    render('Team', ob_get_clean(), $user, $flash);
    exit;
}

if (!$teamId) {
    render('No Team', '<section class="card"><h3>No team selected</h3><div class="empty">Create or join a team to continue.</div><p><a class="btn" href="/?page=team">Go to Team</a></p></section>', $user, $flash);
    exit;
}

if ($page === 'checkin') {
    $today = todayDate();

    $stmt = $db->prepare('SELECT * FROM checkins WHERE team_id = ? AND user_id = ? AND day = ?');
    $stmt->execute([$teamId, $user['id'], $today]);
    $mine = $stmt->fetch();

    $stmt = $db->prepare('SELECT c.*, u.name FROM checkins c JOIN users u ON u.id = c.user_id WHERE c.team_id = ? AND c.day = ? ORDER BY u.name');
    $stmt->execute([$teamId, $today]);
    $feed = $stmt->fetchAll();

    ob_start();
    ?>
    <div class="grid cols-2">
      <section class="card">
        <h3>Daily Check-in · <?= h($today) ?></h3>
        <form method="post" action="/?page=checkin_save" class="grid">
          <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" />
          <label>Yesterday<textarea name="yesterday" placeholder="What did you finish yesterday?"><?= h($mine['yesterday'] ?? '') ?></textarea></label>
          <label>Today<textarea name="today" placeholder="What are you focusing on today?"><?= h($mine['today'] ?? '') ?></textarea></label>
          <label>Blockers<textarea name="blockers" placeholder="Any blockers needing help?"><?= h($mine['blockers'] ?? '') ?></textarea></label>
          <button class="btn" type="submit"><?= $mine ? 'Update check-in' : 'Submit check-in' ?></button>
        </form>
      </section>

      <section class="card">
        <h3>Team feed</h3>
        <?php if (!$feed): ?><div class="empty">No check-ins yet today.</div><?php endif; ?>
        <?php foreach ($feed as $entry): ?>
          <article class="task-card">
            <strong><?= h($entry['name']) ?></strong>
            <p><small class="muted">Yesterday:</small> <?= h($entry['yesterday']) ?></p>
            <p><small class="muted">Today:</small> <?= h($entry['today']) ?></p>
            <?php if (trim((string) $entry['blockers']) !== ''): ?>
              <p><small class="muted">Blockers:</small> <span class="badge danger"><?= h($entry['blockers']) ?></span></p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </section>
    </div>
    <?php
    render('Check-in', ob_get_clean(), $user, $flash);
    exit;
}

if ($page === 'summary') {
    $today = todayDate();

    $completed = $db->prepare('SELECT t.title, u.name AS assignee FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to WHERE t.team_id = ? AND t.completed_at IS NOT NULL AND date(t.completed_at) = ? ORDER BY t.completed_at DESC');
    $completed->execute([$teamId, $today]);
    $completedRows = $completed->fetchAll();

    $inProgress = $db->prepare('SELECT title, priority FROM tasks WHERE team_id = ? AND due_date = ? AND status = "doing" ORDER BY CASE priority WHEN "high" THEN 1 WHEN "med" THEN 2 ELSE 3 END');
    $inProgress->execute([$teamId, $today]);
    $progressRows = $inProgress->fetchAll();

    $blockers = $db->prepare('SELECT u.name, c.blockers FROM checkins c JOIN users u ON u.id = c.user_id WHERE c.team_id = ? AND c.day = ? AND trim(c.blockers) <> ""');
    $blockers->execute([$teamId, $today]);
    $blockRows = $blockers->fetchAll();

    $health = count($progressRows) + count($blockRows);

    ob_start();
    ?>
    <div class="toolbar">
      <h2>Team Summary · Today</h2>
      <a class="btn secondary" href="/?page=today">Back to Board</a>
    </div>
    <div class="grid cols-3">
      <section class="card"><h3>Team health</h3><div class="kpi"><?= $health ?></div><small class="muted">Doing tasks + blockers</small></section>
      <section class="card"><h3>Completed today</h3><?php if (!$completedRows): ?><div class="empty">No completed tasks yet.</div><?php endif; ?><?php foreach ($completedRows as $row): ?><p>✅ <?= h($row['title']) ?> <small class="muted"><?= h($row['assignee'] ?: 'Unassigned') ?></small></p><?php endforeach; ?></section>
      <section class="card"><h3>Still in progress</h3><?php if (!$progressRows): ?><div class="empty">All clear 🎉</div><?php endif; ?><?php foreach ($progressRows as $row): ?><p>⏳ <?= h($row['title']) ?> <span class="badge warn"><?= h($row['priority']) ?></span></p><?php endforeach; ?></section>
    </div>
    <section class="card" style="margin-top:12px;">
      <h3>Blockers</h3>
      <?php if (!$blockRows): ?><div class="empty">No blockers reported.</div><?php endif; ?>
      <?php foreach ($blockRows as $row): ?><p><strong><?= h($row['name']) ?>:</strong> <?= h($row['blockers']) ?></p><?php endforeach; ?>
    </section>
    <?php
    render('Summary', ob_get_clean(), $user, $flash);
    exit;
}

if ($page === 'settings') {
    ob_start();
    ?>
    <div class="grid cols-2">
      <section class="card">
        <h3>Profile</h3>
        <form method="post" action="/?page=profile_update" class="grid">
          <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" />
          <label>Name<input class="input" name="name" maxlength="80" value="<?= h($user['name']) ?>" required /></label>
          <label>Email<input class="input" value="<?= h($user['email']) ?>" disabled /></label>
          <button class="btn" type="submit">Save profile</button>
        </form>
      </section>
      <section class="card">
        <h3>Security</h3>
        <form method="post" action="/?page=password_update" class="grid">
          <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" />
          <label>Current password<input class="input" type="password" name="current_password" required /></label>
          <label>New password<input class="input" type="password" name="new_password" minlength="6" required /></label>
          <button class="btn" type="submit">Update password</button>
        </form>
      </section>
    </div>
    <?php
    render('Settings', ob_get_clean(), $user, $flash);
    exit;
}

$today = todayDate();
$mine = (string) ($_GET['mine'] ?? '') === '1';
$statusFilter = (string) ($_GET['status'] ?? '');
$priorityFilter = (string) ($_GET['priority'] ?? '');

$params = [$teamId, $today];
$sql = 'SELECT t.*, u.name AS assignee FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to WHERE t.team_id = ? AND t.due_date = ?';

if ($mine) {
    $sql .= ' AND t.assigned_to = ?';
    $params[] = $user['id'];
}
if (in_array($statusFilter, ['todo', 'doing', 'done'], true)) {
    $sql .= ' AND t.status = ?';
    $params[] = $statusFilter;
}
if (in_array($priorityFilter, ['low', 'med', 'high'], true)) {
    $sql .= ' AND t.priority = ?';
    $params[] = $priorityFilter;
}

$sql .= ' ORDER BY CASE t.priority WHEN "high" THEN 1 WHEN "med" THEN 2 ELSE 3 END, t.id DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

$grouped = ['todo' => [], 'doing' => [], 'done' => []];
foreach ($tasks as $task) {
    $grouped[(string) $task['status']][] = $task;
}

$membersStmt = $db->prepare('SELECT u.id, u.name FROM team_members tm JOIN users u ON u.id = tm.user_id WHERE tm.team_id = ? ORDER BY u.name ASC');
$membersStmt->execute([$teamId]);
$members = $membersStmt->fetchAll();

ob_start();
?>
<div class="toolbar">
  <div>
    <h2 style="margin:0">Today Board</h2>
    <small class="muted">Due today · fast updates · team-focused</small>
  </div>
  <a class="btn secondary" href="/?page=summary">Team Summary</a>
</div>

<section class="card" style="margin:10px 0 12px;">
  <form method="get" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));align-items:end;">
    <input type="hidden" name="page" value="today" />
    <label><small class="muted">Scope</small><select name="mine"><option value="">All tasks</option><option value="1" <?= $mine ? 'selected' : '' ?>>My tasks</option></select></label>
    <label><small class="muted">Status</small><select name="status"><option value="">Any</option><?php foreach (['todo', 'doing', 'done'] as $status): ?><option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></label>
    <label><small class="muted">Priority</small><select name="priority"><option value="">Any</option><?php foreach (['low', 'med', 'high'] as $priority): ?><option value="<?= $priority ?>" <?= $priorityFilter === $priority ? 'selected' : '' ?>><?= $priority ?></option><?php endforeach; ?></select></label>
    <button class="btn secondary" type="submit">Apply</button>
  </form>
</section>

<div class="grid cols-3">
  <?php foreach (['todo' => 'To do', 'doing' => 'Doing', 'done' => 'Done'] as $statusKey => $statusLabel): ?>
    <section class="card">
      <h3><?= h($statusLabel) ?> <small class="muted">(<?= count($grouped[$statusKey]) ?>)</small></h3>
      <?php if (!$grouped[$statusKey]): ?>
        <div class="empty">
          <div class="skeleton" style="margin-bottom:8px;"></div>
          <div class="skeleton" style="width:70%;margin:0 auto 8px;"></div>
          No tasks in this lane.
        </div>
      <?php endif; ?>

      <?php foreach ($grouped[$statusKey] as $task): ?>
        <article class="task-card">
          <div style="display:flex;justify-content:space-between;gap:8px;">
            <strong><?= h($task['title']) ?></strong>
            <span class="badge <?= $task['priority'] === 'high' ? 'danger' : ($task['priority'] === 'med' ? 'warn' : 'success') ?>"><?= h($task['priority']) ?></span>
          </div>
          <small class="muted">Assignee: <?= h($task['assignee'] ?: 'Unassigned') ?></small>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px;">
            <form method="post" action="/?page=task_quick">
              <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" />
              <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>" />
              <input type="hidden" name="field" value="status" />
              <select name="value"><?php foreach (['todo', 'doing', 'done'] as $value): ?><option value="<?= $value ?>" <?= $task['status'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select>
              <button class="btn ghost" style="width:100%;margin-top:5px;" type="submit">Status</button>
            </form>
            <form method="post" action="/?page=task_quick">
              <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" />
              <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>" />
              <input type="hidden" name="field" value="priority" />
              <select name="value"><?php foreach (['low', 'med', 'high'] as $value): ?><option value="<?= $value ?>" <?= $task['priority'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select>
              <button class="btn ghost" style="width:100%;margin-top:5px;" type="submit">Priority</button>
            </form>
          </div>
          <button class="btn secondary" data-open="task-<?= (int) $task['id'] ?>" style="width:100%;margin-top:8px;" type="button">Open details</button>
        </article>

        <section id="task-<?= (int) $task['id'] ?>" class="drawer card">
          <h3>Task details</h3>
          <form method="post" action="/?page=task_update" class="grid">
            <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" />
            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>" />
            <label>Title<input class="input" name="title" value="<?= h($task['title']) ?>" required /></label>
            <label>Description<textarea name="description"><?= h($task['description']) ?></textarea></label>
            <label>Assignee<select name="assigned_to"><option value="">Unassigned</option><?php foreach ($members as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) $task['assigned_to'] === (int) $member['id'] ? 'selected' : '' ?>><?= h($member['name']) ?></option><?php endforeach; ?></select></label>
            <div class="grid" style="grid-template-columns:1fr 1fr 1fr;">
              <label>Status<select name="status"><?php foreach (['todo', 'doing', 'done'] as $value): ?><option value="<?= $value ?>" <?= $task['status'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></label>
              <label>Priority<select name="priority"><?php foreach (['low', 'med', 'high'] as $value): ?><option value="<?= $value ?>" <?= $task['priority'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></label>
              <label>Due date<input class="input" type="date" name="due_date" value="<?= h($task['due_date']) ?>" /></label>
            </div>
            <div style="display:flex;gap:8px;">
              <button class="btn" type="submit">Save task</button>
              <button class="btn secondary" data-close="task-<?= (int) $task['id'] ?>" type="button">Close</button>
            </div>
          </form>
        </section>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
</div>

<button class="btn fab" data-open="newTask" type="button">+</button>
<section id="newTask" class="drawer card">
  <h3>Create task</h3>
  <form method="post" action="/?page=task_create" class="grid">
    <input type="hidden" name="_token" value="<?= h(csrfToken()) ?>" />
    <label>Title<input class="input" name="title" maxlength="160" required /></label>
    <label>Description<textarea name="description"></textarea></label>
    <label>Assignee<select name="assigned_to"><option value="">Unassigned</option><?php foreach ($members as $member): ?><option value="<?= (int) $member['id'] ?>"><?= h($member['name']) ?></option><?php endforeach; ?></select></label>
    <div class="grid" style="grid-template-columns:1fr 1fr 1fr;">
      <label>Status<select name="status"><?php foreach (['todo', 'doing', 'done'] as $value): ?><option value="<?= $value ?>"><?= $value ?></option><?php endforeach; ?></select></label>
      <label>Priority<select name="priority"><?php foreach (['low', 'med', 'high'] as $value): ?><option value="<?= $value ?>"><?= $value ?></option><?php endforeach; ?></select></label>
      <label>Due date<input class="input" type="date" name="due_date" value="<?= h($today) ?>" /></label>
    </div>
    <div style="display:flex;gap:8px;">
      <button class="btn" type="submit">Create task</button>
      <button class="btn secondary" data-close="newTask" type="button">Close</button>
    </div>
  </form>
</section>
<?php
render('Today', ob_get_clean(), $user, $flash);
