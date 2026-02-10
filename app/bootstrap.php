<?php

declare(strict_types=1);

session_start();

date_default_timezone_set('UTC');

const DB_PATH = __DIR__ . '/../storage/microtasker.sqlite';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(dirname(DB_PATH))) {
        mkdir(dirname(DB_PATH), 0777, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    migrate($pdo);

    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS teams (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        created_by INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS team_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        role TEXT NOT NULL CHECK(role IN ("admin","member")),
        created_at TEXT NOT NULL,
        UNIQUE(team_id, user_id),
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS team_invites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        email TEXT,
        token TEXT NOT NULL UNIQUE,
        invited_by INTEGER NOT NULL,
        accepted_by INTEGER,
        expires_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(invited_by) REFERENCES users(id),
        FOREIGN KEY(accepted_by) REFERENCES users(id)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT,
        assigned_to INTEGER,
        status TEXT NOT NULL CHECK(status IN ("todo","doing","done")),
        priority TEXT NOT NULL CHECK(priority IN ("low","med","high")),
        due_date TEXT NOT NULL,
        completed_at TEXT,
        created_by INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(assigned_to) REFERENCES users(id),
        FOREIGN KEY(created_by) REFERENCES users(id)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS checkins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        day TEXT NOT NULL,
        yesterday TEXT,
        today TEXT,
        blockers TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(team_id, user_id, day),
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )');

    seedDemo($pdo);
}

function nowIso(): string
{
    return date('c');
}

function todayDate(): string
{
    return date('Y-m-d');
}

function seedDemo(PDO $pdo): void
{
    $exists = (int) $pdo->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
    if ($exists > 0) {
        return;
    }

    $now = nowIso();
    $password = password_hash('password', PASSWORD_DEFAULT);

    $pdo->prepare('INSERT INTO users(name, email, password, created_at, updated_at) VALUES (?,?,?,?,?)')->execute(['Demo Admin', 'admin@demo.test', $password, $now, $now]);
    $pdo->prepare('INSERT INTO users(name, email, password, created_at, updated_at) VALUES (?,?,?,?,?)')->execute(['Demo Member', 'member@demo.test', $password, $now, $now]);

    $adminId = (int) $pdo->lastInsertId() - 1;
    $memberId = $adminId + 1;

    $pdo->prepare('INSERT INTO teams(name, created_by, created_at) VALUES (?,?,?)')->execute(['Acme Delivery Team', $adminId, $now]);
    $teamId = (int) $pdo->lastInsertId();

    $addMember = $pdo->prepare('INSERT INTO team_members(team_id, user_id, role, created_at) VALUES (?,?,?,?)');
    $addMember->execute([$teamId, $adminId, 'admin', $now]);
    $addMember->execute([$teamId, $memberId, 'member', $now]);

    $addTask = $pdo->prepare('INSERT INTO tasks(team_id,title,description,assigned_to,status,priority,due_date,completed_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $today = todayDate();
    $addTask->execute([$teamId, 'Client standup notes', 'Share yesterday impact and today priorities in Slack.', $adminId, 'todo', 'high', $today, null, $adminId, $now, $now]);
    $addTask->execute([$teamId, 'Production bug triage', 'Review and assign overnight issues.', $memberId, 'doing', 'med', $today, null, $adminId, $now, $now]);
    $addTask->execute([$teamId, 'Deploy login patch', 'Release auth hotfix and verify monitoring.', $adminId, 'done', 'high', $today, $now, $adminId, $now, $now]);

    $addCheckin = $pdo->prepare('INSERT INTO checkins(team_id,user_id,day,yesterday,today,blockers,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)');
    $addCheckin->execute([$teamId, $adminId, $today, 'Finished regression checks', 'Prepare sprint summary', 'Waiting for PM sign-off', $now, $now]);
    $addCheckin->execute([$teamId, $memberId, $today, 'Refined API responses', 'Finish board UI polish', '', $now, $now]);
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function ensureCsrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $token = (string) ($_POST['_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
        http_response_code(419);
        exit('Invalid CSRF token.');
    }
}

function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);

    return $stmt->fetch() ?: null;
}

function requireAuth(): array
{
    $user = currentUser();
    if (!$user) {
        header('Location: /?page=login');
        exit;
    }

    return $user;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function takeFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $value = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $value;
}

function activeTeamId(array $user): ?int
{
    if (isset($_SESSION['active_team_id'])) {
        return (int) $_SESSION['active_team_id'];
    }

    $stmt = db()->prepare('SELECT team_id FROM team_members WHERE user_id = ? ORDER BY id LIMIT 1');
    $stmt->execute([(int) $user['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $_SESSION['active_team_id'] = (int) $row['team_id'];

    return (int) $row['team_id'];
}

function userTeams(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT t.*, tm.role
         FROM teams t
         JOIN team_members tm ON tm.team_id = t.id
         WHERE tm.user_id = ?
         ORDER BY t.name ASC'
    );
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

function requireTeamMember(int $teamId, int $userId): array
{
    $stmt = db()->prepare('SELECT * FROM team_members WHERE team_id = ? AND user_id = ?');
    $stmt->execute([$teamId, $userId]);
    $member = $stmt->fetch();

    if (!$member) {
        http_response_code(403);
        exit('Team access denied.');
    }

    return $member;
}

function validateEnum(string $value, array $allowed, string $fallback): string
{
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
