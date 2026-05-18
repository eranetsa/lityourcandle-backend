<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\DB;
use App\Core\Settings;
use App\Services\CandleAiService;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
App::boot(dirname(__DIR__, 2));

session_name('LITYC_ADMIN');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/admin',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$action = $_GET['action'] ?? 'dashboard';

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '_')) {
        http_response_code(419); exit('CSRF mismatch');
    }
}
function require_admin(): void {
    if (empty($_SESSION['admin_id'])) { header('Location: /admin/?action=login'); exit; }
}
/** Validate a `#RRGGBB` color string; returns null if blank/invalid. */
function normalize_hex(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if ($s[0] !== '#') $s = '#' . $s;
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $s) ? strtoupper($s) : null;
}

function audit(string $action, string $entity, ?int $entityId = null, array $detail = []): void {
    DB::insert('audit_log', [
        'admin_user_id' => $_SESSION['admin_id'] ?? null,
        'action'        => $action,
        'entity'        => $entity,
        'entity_id'     => $entityId,
        'detail_json'   => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
        'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

// ----- LOGIN / LOGOUT --------------------------------------------------------
if ($action === 'login') {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $u = DB::one('SELECT * FROM admin_users WHERE username = :u AND is_active = 1', [':u' => $_POST['username'] ?? '']);
        if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
            $_SESSION['admin_id']   = (int)$u['id'];
            $_SESSION['admin_name'] = $u['full_name'];
            $_SESSION['admin_role'] = $u['role'];
            DB::run('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id', [':id' => $u['id']]);
            audit('login', 'admin_user', (int)$u['id']);
            header('Location: /admin/'); exit;
        }
        $err = 'بيانات الدخول غير صحيحة';
    }
    render('login', ['err' => $err]);
    exit;
}
if ($action === 'logout') {
    audit('logout', 'admin_user', $_SESSION['admin_id'] ?? null);
    session_destroy();
    header('Location: /admin/?action=login'); exit;
}

require_admin();

// ----- ROUTES ---------------------------------------------------------------
switch ($action) {
    case 'dashboard':     dashboard(); break;
    case 'users':         users_index(); break;
    case 'user':          user_detail(); break;
    case 'session_transcript': session_transcript(); break;
    case 'consultants':   consultants_index(); break;
    case 'sessions':      sessions_index(); break;
    case 'subscriptions': subscriptions_index(); break;
    case 'transactions':  transactions_index(); break;
    case 'mood':          mood_analytics(); break;
    case 'ai':            ai_analytics(); break;
    case 'ai_users':      ai_users(); break;
    case 'ai_user':       ai_user(); break;
    case 'ai_session':    ai_session(); break;
    case 'daily':         daily_messages(); break;
    case 'programs':      programs_index(); break;
    default: http_response_code(404); echo 'Not found';
}

// ----- VIEW HELPERS ---------------------------------------------------------
function render(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require __DIR__ . "/views/_layout_top.php";
    require __DIR__ . "/views/$view.php";
    require __DIR__ . "/views/_layout_bottom.php";
}

// ----- PAGES ---------------------------------------------------------------
function dashboard(): void
{
    $stats = [
        'users'         => (int)(DB::one('SELECT COUNT(*) AS n FROM users')['n'] ?? 0),
        'consultants'   => (int)(DB::one('SELECT COUNT(*) AS n FROM consultants')['n'] ?? 0),
        'sessions_30d'  => (int)(DB::one("SELECT COUNT(*) AS n FROM sessions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")['n'] ?? 0),
        'paid_subs'     => (int)(DB::one("SELECT COUNT(*) AS n FROM subscriptions WHERE status IN ('active','trial') AND plan != 'free'")['n'] ?? 0),
        'ai_today'      => (int)(DB::one("SELECT COUNT(*) AS n FROM ai_logs WHERE DATE(created_at) = CURDATE()")['n'] ?? 0),
        'mood_today'    => (int)(DB::one("SELECT COUNT(*) AS n FROM mood_logs WHERE logged_on = CURDATE()")['n'] ?? 0),
    ];
    render('dashboard', compact('stats'));
}

function users_index(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'toggle') {
        csrf_check();
        $id = (int)$_POST['id'];
        DB::run('UPDATE users SET is_active = 1 - is_active WHERE id = :id', [':id' => $id]);
        audit('toggle_active', 'user', $id);
        // Preserve filters when bouncing back so the admin doesn't lose
        // their place after toggling a user.
        $back = '/admin/?action=users';
        $keep = array_filter(['tab','q','plan','sub_status','role'], fn($k) => isset($_GET[$k]) && $_GET[$k] !== '');
        if ($keep) $back .= '&' . http_build_query(array_intersect_key($_GET, array_flip($keep)));
        header('Location: ' . $back); exit;
    }

    // ─── Filters via URL state ─────────────────────────────────────────
    // tab        : all (default) | subscribed | free | inactive | guest
    // q          : name / email / phone substring
    // plan       : exact plan slug (free|weekly|monthly|yearly|lifetime)
    // sub_status : subscription status (active|trial|expired|canceled|grace|none)
    // role       : user|consultant|admin|guest
    $tab        = (string)($_GET['tab']        ?? 'all');
    $q          = trim((string)($_GET['q']     ?? ''));
    $plan       = (string)($_GET['plan']       ?? '');
    $subStatus  = (string)($_GET['sub_status'] ?? '');
    $role       = (string)($_GET['role']       ?? '');
    // sort: last_active (default) | id   ;  dir: desc (default) | asc
    $sort       = (string)($_GET['sort']       ?? 'last_active');
    $dir        = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    if (!in_array($sort, ['last_active', 'id'], true)) $sort = 'last_active';
    $orderBy = $sort === 'id'
        ? "u.id $dir"
        : "last_active_at $dir, u.id DESC";

    $where  = ['1=1'];
    $params = [];
    if ($tab === 'subscribed') {
        // Paying users with an active subscription right now.
        $where[] = "s.status = 'active' AND s.plan IN ('weekly','monthly','yearly','lifetime')";
    } elseif ($tab === 'free') {
        $where[] = "(s.id IS NULL OR s.plan = 'free' OR s.status NOT IN ('active','trial'))";
    } elseif ($tab === 'inactive') {
        $where[] = 'u.is_active = 0';
    } elseif ($tab === 'guest') {
        $where[] = "u.role = 'guest'";
    }
    if ($q !== '') {
        $where[] = '(u.email LIKE :q OR u.name LIKE :q OR u.phone LIKE :q)';
        $params[':q'] = "%$q%";
    }
    if (in_array($plan, ['free','weekly','monthly','yearly','lifetime'], true)) {
        $where[] = 's.plan = :plan';
        $params[':plan'] = $plan;
    }
    if ($subStatus === 'none') {
        $where[] = 's.id IS NULL';
    } elseif (in_array($subStatus, ['active','trial','expired','canceled','grace'], true)) {
        $where[] = 's.status = :sub_status';
        $params[':sub_status'] = $subStatus;
    }
    if (in_array($role, ['user','consultant','admin','guest'], true)) {
        $where[] = 'u.role = :role';
        $params[':role'] = $role;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // Counts per tab — global (ignore current filters) so labels are
    // stable while the admin narrows down.
    $counts = DB::one(
        "SELECT
            COUNT(*) AS total,
            SUM(s.status = 'active' AND s.plan IN ('weekly','monthly','yearly','lifetime')) AS subscribed,
            SUM(s.id IS NULL OR s.plan = 'free' OR s.status NOT IN ('active','trial')) AS free,
            SUM(u.is_active = 0) AS inactive,
            SUM(u.role = 'guest') AS guest
         FROM users u
         LEFT JOIN subscriptions s ON s.id = (SELECT MAX(id) FROM subscriptions WHERE user_id = u.id)"
    ) ?: ['total' => 0, 'subscribed' => 0, 'free' => 0, 'inactive' => 0, 'guest' => 0];

    // last_active_at = the latest of users.last_login_at and the most
    // recent push-token heartbeat. push_tokens.last_seen_at is bumped on
    // every token-refresh API call, so it's a reasonable proxy for "the
    // app was open recently" even when the user hasn't re-logged in.
    $rows = DB::all(
        "SELECT u.*, s.plan, s.status AS sub_status, s.expires_at,
                GREATEST(
                    COALESCE(u.last_login_at,                                   '1970-01-01'),
                    COALESCE((SELECT MAX(pt.last_seen_at) FROM push_tokens pt
                              WHERE pt.user_id = u.id),                         '1970-01-01')
                ) AS last_active_at
         FROM users u
         LEFT JOIN subscriptions s ON s.id = (SELECT MAX(id) FROM subscriptions WHERE user_id = u.id)
         $whereSql
         ORDER BY $orderBy LIMIT 200",
        $params
    );

    render('users', [
        'rows'              => $rows,
        'q'                 => $q,
        'tab'               => $tab,
        'filterPlan'        => $plan,
        'filterSubStatus'   => $subStatus,
        'filterRole'        => $role,
        'counts'            => $counts,
        'sort'              => $sort,
        'dir'               => $dir,
    ]);
}

/**
 * Customer detail — single user's profile plus their AI chat log
 * (grouped by day) and every human consultation session. Each row
 * exposes a "view full chat" link into ai_session / session_transcript.
 */
function user_detail(): void
{
    $uid = (int)($_GET['id'] ?? 0);
    if ($uid <= 0) { http_response_code(400); exit('bad id'); }
    $user = DB::one(
        'SELECT id, name, email, phone, role, language, avatar_url,
                is_active, created_at, trial_started_at, trial_ends_at
           FROM users WHERE id = :id',
        [':id' => $uid]
    );
    if (!$user) { http_response_code(404); exit('user not found'); }

    // Active / most-recent subscription
    $subscription = DB::one(
        'SELECT id, plan, status, store, expires_at,
                sessions_remaining, sessions_total, auto_renew, created_at
           FROM subscriptions
          WHERE user_id = :uid
          ORDER BY id DESC LIMIT 1',
        [':uid' => $uid]
    );

    // AI chats grouped by day (same shape as ai_user so we can reuse
    // ai_session for the drill-down view).
    $aiDays = DB::all(
        "SELECT DATE(created_at) AS d,
                COUNT(*)         AS msgs,
                SUM(escalated)   AS escalated,
                MIN(created_at)  AS started_at,
                MAX(created_at)  AS ended_at,
                SUBSTRING_INDEX(
                  GROUP_CONCAT(user_message ORDER BY id ASC SEPARATOR '\n---\n'),
                  '\n---\n', 1
                ) AS opener
           FROM ai_logs
          WHERE user_id = :uid
          GROUP BY DATE(created_at)
          ORDER BY DATE(created_at) DESC
          LIMIT 60",
        [':uid' => $uid]
    );

    // Human consultation sessions, with message counts + the consultant
    // they spoke to. Pulls 60 most recent — fine for the admin context.
    $sessions = DB::all(
        "SELECT s.id, s.type, s.status, s.scheduled_at, s.started_at,
                s.ended_at, s.created_at, s.post_rating,
                c.name AS consultant_name,
                (SELECT COUNT(*) FROM messages m WHERE m.session_id = s.id) AS msg_count
           FROM sessions s
           LEFT JOIN consultants c ON c.id = s.consultant_id
          WHERE s.user_id = :uid
          ORDER BY s.id DESC LIMIT 60",
        [':uid' => $uid]
    );

    // Mood history — latest 60 daily entries + per-mood counts over the
    // last 30 days for the summary chips at the top of the section.
    $moodEntries = DB::all(
        'SELECT mood, note, logged_on, created_at
           FROM mood_logs
          WHERE user_id = :uid
          ORDER BY logged_on DESC LIMIT 60',
        [':uid' => $uid]
    );
    $moodCounts = DB::all(
        "SELECT mood, COUNT(*) AS n
           FROM mood_logs
          WHERE user_id = :uid AND logged_on >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          GROUP BY mood",
        [':uid' => $uid]
    );

    render('user_detail', [
        'user'         => $user,
        'subscription' => $subscription,
        'aiDays'       => $aiDays,
        'sessions'     => $sessions,
        'moodEntries'  => $moodEntries,
        'moodCounts'   => $moodCounts,
    ]);
}

/**
 * Full transcript of one human consultation session — every message
 * between the client and the consultant in chronological order. Used
 * from user_detail's "عرض المحادثة" link.
 */
function session_transcript(): void
{
    $sid = (int)($_GET['id'] ?? 0);
    if ($sid <= 0) { http_response_code(400); exit('bad id'); }
    $session = DB::one(
        "SELECT s.*, u.name AS user_name, u.email AS user_email,
                c.name AS consultant_name
           FROM sessions s
           JOIN users u ON u.id = s.user_id
           LEFT JOIN consultants c ON c.id = s.consultant_id
          WHERE s.id = :sid",
        [':sid' => $sid]
    );
    if (!$session) { http_response_code(404); exit('session not found'); }

    $messages = DB::all(
        'SELECT id, sender_id, sender_role, body, attachment_url,
                read_at, created_at
           FROM messages
          WHERE session_id = :sid
          ORDER BY id ASC',
        [':sid' => $sid]
    );
    render('session_transcript', ['session' => $session, 'messages' => $messages]);
}

function consultants_index(): void
{
    $error = null;
    $editingId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $op = $_POST['op'] ?? '';
        if ($op === 'create' || $op === 'update') {
            $photoUrl = trim($_POST['photo_url'] ?? '') ?: null;
            if (!empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                try {
                    $photoUrl = save_consultant_photo($_FILES['photo']);
                } catch (\RuntimeException $e) {
                    $error = $e->getMessage();
                }
            }
            if (!$error) {
                $data = [
                    'name'              => trim($_POST['name']),
                    'specialty'         => trim($_POST['specialty']),
                    'bio'               => trim($_POST['bio'] ?? ''),
                    'price_per_session' => (float)$_POST['price'],
                    'session_types'     => implode(',', $_POST['types'] ?? ['chat']),
                    'languages'         => trim($_POST['languages'] ?? 'ar'),
                    'is_available'      => isset($_POST['is_available']) ? 1 : 0,
                ];
                if ($photoUrl !== null) $data['photo_url'] = $photoUrl;
                if ($op === 'create') {
                    $id = DB::insert('consultants', $data + ['photo_url' => $photoUrl]);
                    audit('create', 'consultant', $id);
                } else {
                    $id = (int)$_POST['id'];
                    DB::update('consultants', $data, 'id = :id', [':id' => $id]);
                    audit('update', 'consultant', $id);
                }

                // Optional credentials: lets the consultant log into the
                // mobile app's "منصة المستشارين". Email + password create or
                // update the linked users row (role='consultant').
                $loginEmail = strtolower(trim($_POST['login_email'] ?? ''));
                $loginPwd   = (string)($_POST['login_password'] ?? '');
                if ($loginEmail !== '' || $loginPwd !== '') {
                    try {
                        save_consultant_credentials(
                            (int)$id,
                            $data['name'],
                            $loginEmail,
                            $loginPwd
                        );
                    } catch (\RuntimeException $e) {
                        $error = $e->getMessage();
                    }
                }
                if (!$error) {
                    $back = $op === 'update' ? "/admin/?action=consultants&edit=$id" : '/admin/?action=consultants';
                    header("Location: $back"); exit;
                }
            }
        } elseif ($op === 'delete') {
            $id = (int)$_POST['id'];
            $row = DB::one('SELECT photo_url FROM consultants WHERE id = :id', [':id' => $id]);
            DB::run('DELETE FROM consultants WHERE id = :id', [':id' => $id]);
            audit('delete', 'consultant', $id);
            // Best-effort delete of the local upload (skip remote URLs)
            if ($row && !empty($row['photo_url']) && str_starts_with($row['photo_url'], '/uploads/consultants/')) {
                @unlink(dirname(__DIR__, 2) . '/public' . $row['photo_url']);
            }
            header('Location: /admin/?action=consultants'); exit;
        }
    }
    if ($editingId > 0) {
        $consultant = DB::one(
            'SELECT c.*, u.email AS login_email
             FROM consultants c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.id = :id',
            [':id' => $editingId]
        );
        if (!$consultant) { header('Location: /admin/?action=consultants'); exit; }
        render('consultant_edit', ['consultant' => $consultant, 'error' => $error]);
        return;
    }

    $rows = DB::all(
        'SELECT c.*, u.email AS login_email
         FROM consultants c LEFT JOIN users u ON u.id = c.user_id
         ORDER BY c.id DESC LIMIT 200'
    );
    render('consultants', ['rows' => $rows, 'error' => $error]);
}

/**
 * Create or update the user account linked to a consultant so they can log
 * into the mobile app's "منصة المستشارين". Both email and password are
 * required when setting up the first time. On subsequent edits, an empty
 * password leaves the existing one untouched; an empty email is rejected.
 */
function save_consultant_credentials(int $consultantId, string $name, string $email, string $password): void
{
    if ($email === '') {
        throw new \RuntimeException('البريد الإلكتروني مطلوب لتفعيل دخول المستشار');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new \RuntimeException('صيغة البريد الإلكتروني غير صحيحة');
    }
    $existing = \App\Core\DB::one(
        'SELECT u.id, u.email FROM users u
         JOIN consultants c ON c.user_id = u.id
         WHERE c.id = :cid',
        [':cid' => $consultantId]
    );

    if ($existing) {
        // Reject if the new email is taken by someone else
        $other = \App\Core\DB::one(
            'SELECT id FROM users WHERE email = :e AND id != :id',
            [':e' => $email, ':id' => $existing['id']]
        );
        if ($other) {
            throw new \RuntimeException('البريد الإلكتروني مستخدم بالفعل');
        }
        $update = ['email' => $email, 'name' => $name, 'role' => 'consultant'];
        if ($password !== '') {
            if (strlen($password) < 8) {
                throw new \RuntimeException('كلمة المرور يجب أن تكون 8 أحرف على الأقل');
            }
            $update['password_hash'] = \App\Core\Auth::hashPassword($password);
        }
        \App\Core\DB::update('users', $update, 'id = :id', [':id' => $existing['id']]);
    } else {
        if ($password === '' || strlen($password) < 8) {
            throw new \RuntimeException('كلمة المرور يجب أن تكون 8 أحرف على الأقل');
        }
        $owner = \App\Core\DB::one('SELECT id FROM users WHERE email = :e', [':e' => $email]);
        if ($owner) {
            throw new \RuntimeException('البريد الإلكتروني مستخدم بالفعل');
        }
        $userId = \App\Core\DB::insert('users', [
            'name'          => $name,
            'email'         => $email,
            'password_hash' => \App\Core\Auth::hashPassword($password),
            'role'          => 'consultant',
            'language'      => 'ar',
        ]);
        \App\Core\DB::run(
            'UPDATE consultants SET user_id = :uid WHERE id = :cid',
            [':uid' => $userId, ':cid' => $consultantId]
        );
    }
    audit('credentials', 'consultant', $consultantId);
}

/**
 * Validate and persist a consultant photo to public/uploads/consultants/.
 * Returns the public path (e.g. /uploads/consultants/abc123.jpg).
 */
function save_consultant_photo(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new \RuntimeException('فشل رفع الملف');
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new \RuntimeException('الحد الأقصى للحجم 5 ميغابايت');
    }
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new \RuntimeException('نوع الصورة غير مدعوم — استخدم JPG أو PNG أو WebP');
    }
    $ext  = $allowed[$mime];
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $dir  = dirname(__DIR__, 2) . '/public/uploads/consultants';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new \RuntimeException('تعذّر إنشاء مجلد التحميل');
    }
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new \RuntimeException('تعذّر حفظ الصورة');
    }
    @chmod($dest, 0644);
    return '/uploads/consultants/' . $name;
}

function sessions_index(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $op = $_POST['op'] ?? '';
        if ($op === 'cancel') {
            $id = (int)$_POST['id'];
            $sess = DB::one(
                "SELECT id, user_id, consultant_id, status, paid_with FROM sessions WHERE id = :id",
                [':id' => $id]
            );
            if ($sess && in_array($sess['status'], ['pending','confirmed','in_progress'], true)) {
                DB::transaction(function () use ($sess) {
                    DB::run("UPDATE sessions SET status = 'canceled' WHERE id = :id", [':id' => $sess['id']]);
                    if ($sess['paid_with'] !== 'free') {
                        DB::run(
                            'UPDATE subscriptions SET sessions_remaining = sessions_remaining + 1
                             WHERE user_id = :uid ORDER BY id DESC LIMIT 1',
                            [':uid' => $sess['user_id']]
                        );
                    }
                });
                DB::insert('notifications', [
                    'user_id' => (int)$sess['user_id'],
                    'kind'    => 'session_reminder',
                    'title'   => 'تم إلغاء جلستك',
                    'body'    => 'قام مدير النظام بإلغاء الجلسة، يمكنك إرسال طلب جديد.',
                    'status'  => 'queued',
                ]);
                $cons = DB::one('SELECT user_id FROM consultants WHERE id = :id', [':id' => (int)$sess['consultant_id']]);
                if ($cons && !empty($cons['user_id'])) {
                    DB::insert('notifications', [
                        'user_id' => (int)$cons['user_id'],
                        'kind'    => 'session_reminder',
                        'title'   => 'ألغى المدير الجلسة',
                        'body'    => "تم إلغاء الجلسة #{$sess['id']} من قِبَل الإدارة.",
                        'status'  => 'queued',
                    ]);
                }
                audit('cancel', 'session', $id);
            }
        } elseif ($op === 'delete') {
            $id = (int)$_POST['id'];
            DB::run('DELETE FROM sessions WHERE id = :id', [':id' => $id]);
            audit('delete', 'session', $id);
        }
        header('Location: /admin/?action=sessions'); exit;
    }
    $rows = DB::all(
        "SELECT s.*, u.name AS user_name, c.name AS consultant_name
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         JOIN consultants c ON c.id = s.consultant_id
         ORDER BY s.id DESC LIMIT 200"
    );
    render('sessions', ['rows' => $rows]);
}

function subscriptions_index(): void
{
    $saved = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (($_POST['op'] ?? '') === 'save_plan_limits') {
            $free    = max(0, (int)($_POST['plan_free_sessions_per_month'] ?? 0));
            $weekly  = max(0, (int)($_POST['plan_weekly_sessions'] ?? 0));
            $monthly = max(0, (int)($_POST['plan_monthly_sessions'] ?? 0));
            $yearly  = max(0, (int)($_POST['plan_yearly_sessions_per_month'] ?? 0));
            Settings::set('plan_free_sessions_per_month', (string)$free);
            Settings::set('plan_weekly_sessions',         (string)$weekly);
            Settings::set('plan_monthly_sessions',        (string)$monthly);
            Settings::set('plan_yearly_sessions_per_month',(string)$yearly);
            $saved = true;
        }
    }
    // Read current overrides (or fall back to config defaults so the form is
    // always populated with the *effective* numbers).
    $cfg = \App\Core\App::config('plans');
    $planFreePerMonth   = (int)(Settings::get('plan_free_sessions_per_month',   '0') ?? 0);
    $planWeekly         = (int)(Settings::get('plan_weekly_sessions',
        (string)($cfg['weekly']['sessions'] ?? 0)) ?? 0);
    $planMonthly        = (int)(Settings::get('plan_monthly_sessions',
        (string)($cfg['monthly']['sessions'] ?? 1)) ?? 1);
    $planYearlyPerMonth = (int)(Settings::get('plan_yearly_sessions_per_month',
        (string)($cfg['yearly']['sessions_per_month'] ?? 2)) ?? 2);

    // ─── List filtering ────────────────────────────────────────────────
    // tab : all (default) | paid | free
    // plan: exact plan slug (weekly/monthly/yearly/free/lifetime)
    // status: active | trial | expired | canceled | grace
    // q   : substring of user name OR email
    $tab    = (string)($_GET['tab']    ?? 'all');
    $plan   = (string)($_GET['plan']   ?? '');
    $status = (string)($_GET['status'] ?? '');
    $q      = trim((string)($_GET['q'] ?? ''));

    $where  = [];
    $params = [];
    if ($tab === 'paid') {
        $where[] = "s.plan IN ('weekly','monthly','yearly','lifetime')";
    } elseif ($tab === 'free') {
        $where[] = "s.plan = 'free'";
    }
    if (in_array($plan, ['weekly','monthly','yearly','free','lifetime'], true)) {
        $where[] = "s.plan = :plan";
        $params[':plan'] = $plan;
    }
    if (in_array($status, ['active','trial','expired','canceled','grace'], true)) {
        $where[] = "s.status = :status";
        $params[':status'] = $status;
    }
    if ($q !== '') {
        $where[] = "(u.name LIKE :q OR u.email LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Counts per tab (ignores plan/status/q filters so the tab labels
    // always reflect the global totals — feels more natural for an admin
    // table than tabs that flicker as you narrow the search).
    $counts = DB::one(
        "SELECT
            SUM(s.plan IN ('weekly','monthly','yearly','lifetime')) AS paid,
            SUM(s.plan = 'free') AS free,
            COUNT(*) AS total
         FROM subscriptions s"
    ) ?: ['paid' => 0, 'free' => 0, 'total' => 0];

    $rows = DB::all(
        "SELECT s.*, u.name AS user_name, u.email
         FROM subscriptions s JOIN users u ON u.id = s.user_id
         $whereSql
         ORDER BY s.id DESC LIMIT 200",
        $params
    );
    render('subscriptions', [
        'rows'                => $rows,
        'saved'               => $saved,
        'planFreePerMonth'    => $planFreePerMonth,
        'planWeekly'          => $planWeekly,
        'planMonthly'         => $planMonthly,
        'planYearlyPerMonth'  => $planYearlyPerMonth,
        'cfgWeekly'           => (int)($cfg['weekly']['sessions'] ?? 0),
        'cfgMonthly'          => (int)($cfg['monthly']['sessions'] ?? 1),
        'cfgYearlyPerMonth'   => (int)($cfg['yearly']['sessions_per_month'] ?? 2),
        'tab'                 => $tab,
        'filterPlan'          => $plan,
        'filterStatus'        => $status,
        'filterQ'             => $q,
        'counts'              => $counts,
    ]);
}

function transactions_index(): void
{
    $rows = DB::all(
        "SELECT t.*, u.email FROM transactions t
         JOIN users u ON u.id = t.user_id
         ORDER BY t.id DESC LIMIT 200"
    );
    render('transactions', ['rows' => $rows]);
}

function mood_analytics(): void
{
    $byDay = DB::all(
        "SELECT logged_on, mood, COUNT(*) AS n FROM mood_logs
         WHERE logged_on >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         GROUP BY logged_on, mood ORDER BY logged_on"
    );
    render('mood', ['byDay' => $byDay]);
}

function ai_analytics(): void
{
    $saved = false;
    $refError = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $op = $_POST['op'] ?? '';
        if ($op === 'save_prompt') {
            Settings::set('candle_ai_prompt', (string)($_POST['prompt'] ?? ''));
            $saved = true;
        } elseif ($op === 'reset_prompt') {
            Settings::set('candle_ai_prompt', '');
            $saved = true;
        } elseif ($op === 'save_free_limit') {
            $n = max(0, (int)($_POST['ai_free_daily_limit'] ?? 0));
            Settings::set('ai_free_daily_limit', (string)$n);
            $saved = true;
        } elseif ($op === 'save_memory_turns') {
            $n = max(0, min(50, (int)($_POST['ai_memory_turns'] ?? 10)));
            Settings::set('ai_memory_turns', (string)$n);
            $saved = true;
        } elseif ($op === 'save_provider') {
            // Allow flipping provider + per-provider keys + models without
            // a redeploy. We persist the keys to the settings table so
            // .env stays untouched; the service reads Settings first, then
            // falls back to .env on miss.
            $provider = (string)($_POST['ai_provider'] ?? 'anthropic');
            if (!in_array($provider, ['anthropic', 'openrouter'], true)) {
                $provider = 'anthropic';
            }
            Settings::set('ai_provider', $provider);
            // Only overwrite a key when the admin actually typed something —
            // empty inputs in either box must NOT wipe out the existing
            // value so the form is safe to save with the keys hidden.
            $anthropicKey = trim((string)($_POST['ai_anthropic_key'] ?? ''));
            if ($anthropicKey !== '') Settings::set('ai_anthropic_key', $anthropicKey);
            $orKey = trim((string)($_POST['ai_openrouter_key'] ?? ''));
            if ($orKey !== '') Settings::set('ai_openrouter_key', $orKey);
            $anthropicModel = trim((string)($_POST['ai_anthropic_model'] ?? ''));
            if ($anthropicModel !== '') Settings::set('ai_anthropic_model', $anthropicModel);
            $orModel = trim((string)($_POST['ai_openrouter_model'] ?? ''));
            if ($orModel !== '') Settings::set('ai_openrouter_model', $orModel);
            $saved = true;
        } elseif ($op === 'clear_anthropic_key') {
            Settings::set('ai_anthropic_key', '');
            $saved = true;
        } elseif ($op === 'clear_openrouter_key') {
            Settings::set('ai_openrouter_key', '');
            $saved = true;
        } elseif ($op === 'upload_reference') {
            // Accept .txt and .md only — anything else would need a parser
            // (PDF/Word) that isn't installed on the server today.
            $f = $_FILES['ref'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $refError = 'لم يتم رفع الملف. تأكد من اختياره أولاً.';
            } else {
                $name = (string)$f['name'];
                $ext  = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
                $size = (int)($f['size'] ?? 0);
                if (!in_array($ext, ['txt', 'md'], true)) {
                    $refError = 'النوع غير مدعوم. ارفع ملف ‎.txt أو ‎.md فقط.';
                } elseif ($size > 2 * 1024 * 1024) {
                    $refError = 'الحجم الأقصى ٢ ميجابايت.';
                } else {
                    $text = (string)@file_get_contents($f['tmp_name']);
                    if ($text === '') {
                        $refError = 'تعذّر قراءة محتوى الملف.';
                    } else {
                        $dir = dirname(__DIR__, 2) . '/storage/ai-references';
                        if (!is_dir($dir)) @mkdir($dir, 0750, true);
                        $stored = bin2hex(random_bytes(8)) . '.' . $ext;
                        $path = $dir . '/' . $stored;
                        @file_put_contents($path, $text);
                        @chmod($path, 0640);
                        DB::insert('ai_references', [
                            'original_name'  => $name,
                            'storage_path'   => 'storage/ai-references/' . $stored,
                            'mime'           => $ext === 'md' ? 'text/markdown' : 'text/plain',
                            'size_bytes'     => $size,
                            'extracted_text' => $text,
                            'is_active'      => 1,
                            'sort_order'     => 0,
                        ]);
                        $saved = true;
                    }
                }
            }
        } elseif ($op === 'toggle_reference') {
            $id = (int)($_POST['id'] ?? 0);
            DB::run('UPDATE ai_references SET is_active = 1 - is_active WHERE id = :id', [':id' => $id]);
            $saved = true;
        } elseif ($op === 'delete_reference') {
            $id = (int)($_POST['id'] ?? 0);
            $row = DB::one('SELECT storage_path FROM ai_references WHERE id = :id', [':id' => $id]);
            if ($row) {
                $abs = dirname(__DIR__, 2) . '/' . (string)$row['storage_path'];
                @unlink($abs);
                DB::run('DELETE FROM ai_references WHERE id = :id', [':id' => $id]);
            }
            $saved = true;
        }
    }
    $aiFreeLimit  = (int)(Settings::get('ai_free_daily_limit', '3')  ?? 3);
    $aiMemoryTurns = (int)(Settings::get('ai_memory_turns',    '10') ?? 10);

    // Effective provider config (Settings overrides .env). The keys
    // themselves are NOT passed to the view — only a boolean "is set"
    // flag — so we don't leak them into HTML even via view caching.
    $effectiveProvider = (string)(Settings::get('ai_provider') ?: \App\Core\App::config('ai.provider'));
    $effectiveAnthKey  = (string)(Settings::get('ai_anthropic_key') ?: \App\Core\App::config('ai.anthropic_key'));
    $effectiveOrKey    = (string)(Settings::get('ai_openrouter_key') ?: \App\Core\App::config('ai.openrouter_key'));
    $effectiveAnthModel = (string)(Settings::get('ai_anthropic_model') ?: \App\Core\App::config('ai.anthropic_model'));
    $effectiveOrModel   = (string)(Settings::get('ai_openrouter_model') ?: \App\Core\App::config('ai.openrouter_model'));

    $stats = DB::one(
        "SELECT COUNT(*) AS total,
                SUM(escalated) AS escalated,
                SUM(IF(DATE(created_at) = CURDATE(), 1, 0)) AS today,
                COALESCE(SUM(tokens_in), 0) AS tokens_in,
                COALESCE(SUM(tokens_out), 0) AS tokens_out
         FROM ai_logs"
    );
    $latest = DB::all('SELECT * FROM ai_logs ORDER BY id DESC LIMIT 50');
    $currentPrompt = Settings::get('candle_ai_prompt', '');
    $defaultPrompt = CandleAiService::defaultSystemPrompt();

    // AI provider readiness — when this is "ready=false" every reply is
    // the static fallback regardless of the prompt above, which is what
    // surfaced as "AI keeps repeating itself" in production.
    $providerKey = $effectiveProvider === 'openrouter' ? $effectiveOrKey : $effectiveAnthKey;
    $providerModel = $effectiveProvider === 'openrouter' ? $effectiveOrModel : $effectiveAnthModel;
    $aiCfg = [
        'provider' => $effectiveProvider,
        'model'    => $providerModel,
        'has_key'  => $providerKey !== '',
        'ready'    => $providerKey !== '' && in_array($effectiveProvider, ['anthropic','openrouter'], true),
    ];

    $references = [];
    try {
        $references = DB::all(
            'SELECT id, original_name, mime, size_bytes, is_active, created_at
               FROM ai_references
              ORDER BY sort_order ASC, id DESC'
        );
    } catch (\Throwable $e) {
        // Migration not run yet — show empty list, the view will hint.
    }

    render('ai', [
        'stats'         => $stats,
        'latest'        => $latest,
        'currentPrompt' => $currentPrompt,
        'defaultPrompt' => $defaultPrompt,
        'saved'         => $saved,
        'aiCfg'         => $aiCfg,
        'aiFreeLimit'   => $aiFreeLimit,
        'aiMemoryTurns' => $aiMemoryTurns,
        'references'    => $references,
        'refError'      => $refError,
        // Provider settings (keys are surfaced as booleans only — the
        // actual secret never lands in the rendered HTML).
        'effectiveProvider'    => $effectiveProvider,
        'hasAnthropicKey'      => $effectiveAnthKey  !== '',
        'hasOpenRouterKey'     => $effectiveOrKey    !== '',
        'effectiveAnthModel'   => $effectiveAnthModel,
        'effectiveOrModel'     => $effectiveOrModel,
    ]);
}

/**
 * Level 1 — every user who has ever talked to شمعة AI, ordered by most
 * recent activity. We also surface conversation counts and an at-a-glance
 * snippet of the latest user message so admins can scan for a specific
 * person quickly.
 */
function ai_users(): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $params = [];
    $where = '';
    if ($q !== '') {
        $where = 'AND (u.name LIKE :q OR u.email LIKE :q)';
        $params[':q'] = "%$q%";
    }
    $rows = DB::all(
        "SELECT u.id, u.name, u.email, u.role,
                COUNT(a.id) AS msgs,
                COUNT(DISTINCT DATE(a.created_at)) AS days,
                SUM(a.escalated) AS escalated,
                MAX(a.created_at) AS last_at,
                SUBSTRING_INDEX(GROUP_CONCAT(a.user_message ORDER BY a.id DESC SEPARATOR '\n---\n'), '\n---\n', 1) AS last_msg
           FROM ai_logs a
           JOIN users u ON u.id = a.user_id
          WHERE 1=1 $where
          GROUP BY u.id, u.name, u.email, u.role
          ORDER BY MAX(a.id) DESC
          LIMIT 200",
        $params
    );
    render('ai_users', ['rows' => $rows, 'q' => $q]);
}

/**
 * Level 2 — for one user, show their AI activity grouped by calendar
 * day. Each "session" is one date; clicking opens the full transcript.
 */
function ai_user(): void
{
    $uid = (int)($_GET['id'] ?? 0);
    if ($uid <= 0) { http_response_code(400); exit('bad id'); }
    $user = DB::one('SELECT id, name, email, role FROM users WHERE id = :id', [':id' => $uid]);
    if (!$user) { http_response_code(404); exit('user not found'); }

    $days = DB::all(
        "SELECT DATE(created_at) AS d,
                COUNT(*)         AS msgs,
                SUM(escalated)   AS escalated,
                MIN(created_at)  AS started_at,
                MAX(created_at)  AS ended_at,
                SUBSTRING_INDEX(GROUP_CONCAT(user_message ORDER BY id ASC SEPARATOR '\n---\n'), '\n---\n', 1) AS opener
           FROM ai_logs
          WHERE user_id = :uid
          GROUP BY DATE(created_at)
          ORDER BY DATE(created_at) DESC
          LIMIT 60",
        [':uid' => $uid]
    );
    render('ai_user', ['user' => $user, 'days' => $days]);
}

/**
 * Level 3 — the full back-and-forth between this user and شمعة on a
 * single calendar day. Renders the user's message and the structured
 * AI response (empathy / reflection / suggestion / exercise / dua) so
 * admins can audit the actual content delivered.
 */
function ai_session(): void
{
    $uid  = (int)($_GET['id'] ?? 0);
    $date = trim((string)($_GET['date'] ?? ''));
    if ($uid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400); exit('bad params');
    }
    $user = DB::one('SELECT id, name, email, role FROM users WHERE id = :id', [':id' => $uid]);
    if (!$user) { http_response_code(404); exit('user not found'); }

    $turns = DB::all(
        "SELECT id, mood, user_message, response_json, escalated,
                tokens_in, tokens_out, created_at
           FROM ai_logs
          WHERE user_id = :uid AND DATE(created_at) = :d
          ORDER BY id ASC",
        [':uid' => $uid, ':d' => $date]
    );
    render('ai_session', ['user' => $user, 'date' => $date, 'turns' => $turns]);
}

function daily_messages(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $op = $_POST['op'] ?? '';
        if ($op === 'create') {
            $id = DB::insert('daily_messages', [
                'text_ar'   => trim($_POST['text_ar']),
                'text_en'   => trim($_POST['text_en'] ?? '') ?: null,
                'show_on'   => trim($_POST['show_on'] ?? '') ?: null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ]);
            audit('create', 'daily_message', $id);
        } elseif ($op === 'delete') {
            $id = (int)$_POST['id'];
            DB::run('DELETE FROM daily_messages WHERE id = :id', [':id' => $id]);
            audit('delete', 'daily_message', $id);
        }
        header('Location: /admin/?action=daily'); exit;
    }
    $rows = DB::all('SELECT * FROM daily_messages ORDER BY id DESC LIMIT 200');
    render('daily', ['rows' => $rows]);
}

function programs_index(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $op = $_POST['op'] ?? '';
        $redirect = '/admin/?action=programs';

        if ($op === 'create_program') {
            $id = DB::insert('programs', [
                'slug'           => trim($_POST['slug']),
                'category'       => $_POST['category'],
                'title_ar'       => trim($_POST['title_ar']),
                'title_en'       => trim($_POST['title_en'] ?? '') ?: null,
                'description_ar' => trim($_POST['description_ar'] ?? '') ?: null,
                'icon'           => trim($_POST['icon'] ?? '') ?: null,
                'palette_start'  => normalize_hex($_POST['palette_start'] ?? ''),
                'palette_end'    => normalize_hex($_POST['palette_end'] ?? ''),
                'is_premium'     => isset($_POST['is_premium']) ? 1 : 0,
                'sort_order'     => (int)($_POST['sort_order'] ?? 0),
                'is_active'      => 1,
            ]);
            audit('create', 'program', $id);
            $redirect = "/admin/?action=programs&edit=$id";
        } elseif ($op === 'update_program') {
            $id = (int)$_POST['id'];
            DB::update('programs', [
                'slug'           => trim($_POST['slug']),
                'category'       => $_POST['category'],
                'title_ar'       => trim($_POST['title_ar']),
                'title_en'       => trim($_POST['title_en'] ?? '') ?: null,
                'description_ar' => trim($_POST['description_ar'] ?? '') ?: null,
                'icon'           => trim($_POST['icon'] ?? '') ?: null,
                'palette_start'  => normalize_hex($_POST['palette_start'] ?? ''),
                'palette_end'    => normalize_hex($_POST['palette_end'] ?? ''),
                'is_premium'     => isset($_POST['is_premium']) ? 1 : 0,
                'is_active'      => isset($_POST['is_active']) ? 1 : 0,
                'sort_order'     => (int)($_POST['sort_order'] ?? 0),
            ], 'id = :id', [':id' => $id]);
            audit('update', 'program', $id);
            $redirect = "/admin/?action=programs&edit=$id";
        } elseif ($op === 'delete_program') {
            $id = (int)$_POST['id'];
            DB::run('DELETE FROM programs WHERE id = :id', [':id' => $id]);
            audit('delete', 'program', $id);
        } elseif ($op === 'toggle_program_active') {
            $id = (int)$_POST['id'];
            DB::run('UPDATE programs SET is_active = 1 - is_active WHERE id = :id', [':id' => $id]);
            audit('toggle_active', 'program', $id);
        } elseif ($op === 'create_day') {
            $programId = (int)$_POST['program_id'];
            $id = DB::insert('program_days', [
                'program_id'   => $programId,
                'day_number'   => (int)$_POST['day_number'],
                'title_ar'     => trim($_POST['title_ar']),
                'body_ar'      => trim($_POST['body_ar'] ?? '') ?: null,
                'duration_min' => (int)($_POST['duration_min'] ?? 3),
                'is_locked'    => isset($_POST['is_locked']) ? 1 : 0,
            ]);
            audit('create', 'program_day', $id);
            $redirect = "/admin/?action=programs&edit=$programId#day-$id";
        } elseif ($op === 'update_day') {
            $id = (int)$_POST['id'];
            $programId = (int)$_POST['program_id'];
            DB::update('program_days', [
                'day_number'   => (int)$_POST['day_number'],
                'title_ar'     => trim($_POST['title_ar']),
                'body_ar'      => trim($_POST['body_ar'] ?? '') ?: null,
                'duration_min' => (int)($_POST['duration_min'] ?? 3),
                'is_locked'    => isset($_POST['is_locked']) ? 1 : 0,
            ], 'id = :id', [':id' => $id]);
            audit('update', 'program_day', $id);
            $redirect = "/admin/?action=programs&edit=$programId#day-$id";
        } elseif ($op === 'delete_day') {
            $id = (int)$_POST['id'];
            $programId = (int)$_POST['program_id'];
            DB::run('DELETE FROM program_days WHERE id = :id', [':id' => $id]);
            audit('delete', 'program_day', $id);
            $redirect = "/admin/?action=programs&edit=$programId";
        }
        header("Location: $redirect"); exit;
    }

    $editingId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
    if ($editingId > 0) {
        $program = DB::one('SELECT * FROM programs WHERE id = :id', [':id' => $editingId]);
        if (!$program) { header('Location: /admin/?action=programs'); exit; }
        $days = DB::all(
            'SELECT * FROM program_days WHERE program_id = :pid ORDER BY day_number',
            [':pid' => $editingId]
        );
        render('program_edit', ['program' => $program, 'days' => $days]);
        return;
    }

    $programs = DB::all('SELECT * FROM programs ORDER BY sort_order, id');
    $days     = DB::all('SELECT * FROM program_days ORDER BY program_id, day_number');
    render('programs', ['programs' => $programs, 'days' => $days]);
}
