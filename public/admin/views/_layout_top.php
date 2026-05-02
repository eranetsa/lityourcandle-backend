<?php /** @var string $action */ $action = $_GET['action'] ?? 'dashboard'; ?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>لوحة الإدارة - أشعل شمعتك</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, "Segoe UI", Tahoma, Arial, sans-serif; margin:0; background:#f5f5f7; color:#222; }
  header { background:#1f2937; color:#fff; padding:14px 20px; display:flex; justify-content:space-between; align-items:center; }
  header h1 { margin:0; font-size:18px; }
  nav { background:#111827; padding:8px 16px; }
  nav a { color:#cbd5e1; text-decoration:none; margin-left:16px; font-size:14px; }
  nav a.active { color:#fbbf24; font-weight:bold; }
  main { padding: 20px; max-width: 1200px; margin: 0 auto; }
  .card { background:#fff; border-radius:8px; padding:16px; margin-bottom:16px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  th, td { padding:8px 10px; border-bottom:1px solid #eee; text-align:right; }
  th { background:#f9fafb; }
  input[type=text], input[type=password], input[type=number], input[type=email], input[type=date], select, textarea {
    padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:14px; width:100%;
  }
  textarea { min-height: 80px; }
  button, .btn { background:#fbbf24; color:#1f2937; border:0; padding:8px 14px; border-radius:6px; cursor:pointer; font-weight:bold; }
  .btn-danger { background:#ef4444; color:#fff; }
  .stat { display:inline-block; min-width:140px; background:#fff; border-radius:8px; padding:12px 16px; margin-left:8px; }
  .stat .n { font-size:28px; font-weight:bold; color:#1f2937; }
  .stat .l { font-size:12px; color:#6b7280; }
  form.inline { display:inline; }
  .badge { display:inline-block; padding:2px 8px; border-radius:9999px; font-size:12px; }
  .b-active { background:#dcfce7; color:#166534; }
  .b-inactive { background:#fee2e2; color:#991b1b; }
  .b-trial { background:#fef3c7; color:#92400e; }
</style>
</head>
<body>
<?php if (!empty($_SESSION['admin_id'])): ?>
<header>
  <h1>أشعل شمعتك — لوحة الإدارة</h1>
  <div>مرحبًا <?= htmlspecialchars($_SESSION['admin_name'] ?? '') ?> · <a href="?action=logout" style="color:#fbbf24;">خروج</a></div>
</header>
<nav>
  <a href="?action=dashboard"     class="<?= $action==='dashboard'?'active':'' ?>">الرئيسية</a>
  <a href="?action=users"         class="<?= $action==='users'?'active':'' ?>">المستخدمون</a>
  <a href="?action=consultants"   class="<?= $action==='consultants'?'active':'' ?>">المستشارون</a>
  <a href="?action=sessions"      class="<?= $action==='sessions'?'active':'' ?>">الجلسات</a>
  <a href="?action=subscriptions" class="<?= $action==='subscriptions'?'active':'' ?>">الاشتراكات</a>
  <a href="?action=transactions"  class="<?= $action==='transactions'?'active':'' ?>">المعاملات</a>
  <a href="?action=mood"          class="<?= $action==='mood'?'active':'' ?>">المزاج</a>
  <a href="?action=ai"            class="<?= $action==='ai'?'active':'' ?>">شمعة AI</a>
  <a href="?action=daily"         class="<?= $action==='daily'?'active':'' ?>">رسائل اليوم</a>
  <a href="?action=programs"      class="<?= $action==='programs'?'active':'' ?>">البرامج</a>
</nav>
<?php endif; ?>
<main>
