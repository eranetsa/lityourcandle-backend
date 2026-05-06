<?php
/** @var string $action */
$action = $_GET['action'] ?? 'dashboard';
$loggedIn = !empty($_SESSION['admin_id']);

$NAV = [
  'dashboard'     => ['label' => 'الرئيسية',     'icon' => '<path d="M3 12L12 3l9 9"/><path d="M5 10v10h14V10"/>'],
  'users'         => ['label' => 'المستخدمون',   'icon' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
  'consultants'   => ['label' => 'المستشارون',   'icon' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'],
  'sessions'      => ['label' => 'الجلسات',       'icon' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
  'subscriptions' => ['label' => 'الاشتراكات',   'icon' => '<path d="M21 4H3a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h18a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/><path d="M1 10h22"/>'],
  'transactions'  => ['label' => 'المعاملات',     'icon' => '<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>'],
  'mood'          => ['label' => 'المزاج',        'icon' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>'],
  'ai'            => ['label' => 'شمعة AI',       'icon' => '<path d="M12 2v6"/><path d="M12 22v-6"/><path d="M4.93 4.93l4.24 4.24"/><path d="M14.83 14.83l4.24 4.24"/><path d="M2 12h6"/><path d="M16 12h6"/><path d="M4.93 19.07l4.24-4.24"/><path d="M14.83 9.17l4.24-4.24"/>'],
  'daily'         => ['label' => 'رسائل اليوم',   'icon' => '<path d="M21 15a2 2 0 0 1-2 2H5l-4 4V3a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z"/>'],
  'programs'      => ['label' => 'البرامج',       'icon' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>'],
];
$pageTitle = $NAV[$action]['label'] ?? 'لوحة الإدارة';
$initials  = mb_strtoupper(mb_substr((string)($_SESSION['admin_name'] ?? 'A'), 0, 1));
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>لوحة الإدارة — أشعل شمعتك</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --purple-deep: #5B2C8E; --purple: #7B2CBF; --magenta: #C724B1;
    --blue: #3949AB; --blue-deep: #1E2A5E; --gold: #FFB800; --gold-soft: #FFD166;
    --bg: #0E1220; --bg-2: #15182B;
    --surface: rgba(255,255,255,0.04); --surface-2: rgba(255,255,255,0.07);
    --border: rgba(199,36,177,0.18); --border-soft: rgba(255,255,255,0.08);
    --text: #F5F5F7; --text-2: #B5B7C7; --text-muted: #7A7E94;
    --grad-brand:   linear-gradient(135deg, #C724B1 0%, #7B2CBF 45%, #3949AB 100%);
    --grad-brand-r: linear-gradient(315deg, #C724B1 0%, #7B2CBF 45%, #3949AB 100%);
    --grad-gold:    linear-gradient(135deg, #FFB800, #FF7A00);
    --grad-card:    linear-gradient(160deg, rgba(123,44,191,0.15), rgba(57,73,171,0.08) 60%, rgba(255,255,255,0.02));
    --grad-bg:      radial-gradient(1200px 800px at 90% -10%, rgba(199,36,177,0.18), transparent 60%),
                    radial-gradient(900px 700px at -10% 110%, rgba(57,73,171,0.22), transparent 60%),
                    linear-gradient(180deg, #0B0E1A 0%, #0E1220 100%);
    --shadow-1: 0 1px 2px rgba(0,0,0,0.4), 0 8px 24px rgba(0,0,0,0.35);
    --shadow-glow: 0 0 0 1px rgba(199,36,177,0.25), 0 12px 40px rgba(123,44,191,0.25);
    --radius: 14px; --radius-sm: 10px;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    font-family: "Cairo", -apple-system, "Segoe UI", Tahoma, Arial, sans-serif;
    color: var(--text); background: var(--grad-bg); background-attachment: fixed;
    min-height: 100vh; -webkit-font-smoothing: antialiased;
  }
  a { color: inherit; text-decoration: none; }
  button { font-family: inherit; }

  .shell { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }
  @media (max-width: 900px) { .shell { grid-template-columns: 1fr; } }

  .sidebar {
    background: linear-gradient(180deg, rgba(21,24,43,0.85), rgba(11,14,26,0.95));
    backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
    border-inline-end: 1px solid var(--border-soft);
    padding: 22px 18px; display: flex; flex-direction: column; gap: 24px;
    position: sticky; top: 0; height: 100vh; overflow-y: auto;
  }
  .brand { display: flex; align-items: center; gap: 12px; padding: 4px 6px 18px; border-bottom: 1px solid var(--border-soft); }
  .brand .logo { width: 44px; height: 44px; flex-shrink: 0; filter: drop-shadow(0 6px 14px rgba(199,36,177,0.45)); }
  .brand .name { font-size: 15px; font-weight: 800; letter-spacing: 0.2px; line-height: 1.2; }
  .brand .name small { display: block; color: var(--text-muted); font-weight: 500; font-size: 11px; margin-top: 2px; letter-spacing: 1px; }

  .nav { display: flex; flex-direction: column; gap: 4px; }
  .nav a {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 12px; border-radius: var(--radius-sm);
    color: var(--text-2); font-weight: 500; font-size: 14px;
    transition: background .18s ease, color .18s ease, transform .18s ease;
  }
  .nav a:hover { background: var(--surface); color: var(--text); }
  .nav a.active { background: var(--grad-brand-r); color: #fff; box-shadow: var(--shadow-glow); }
  .nav svg { width: 18px; height: 18px; opacity: .9; flex-shrink: 0; }

  .side-foot { margin-top: auto; padding-top: 14px; border-top: 1px solid var(--border-soft); display: flex; flex-direction: column; gap: 10px; }
  .side-foot .who { display: flex; align-items: center; gap: 10px; padding: 6px 4px; }
  .side-foot .avatar {
    width: 36px; height: 36px; border-radius: 50%; background: var(--grad-brand);
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 14px;
  }
  .side-foot .who .meta { line-height: 1.25; }
  .side-foot .who .meta b { display: block; font-size: 13px; }
  .side-foot .who .meta small { color: var(--text-muted); font-size: 11px; }
  .logout {
    text-align: center; padding: 9px 12px; border-radius: var(--radius-sm);
    background: var(--surface); color: var(--text-2); font-size: 13px;
  }
  .logout:hover { background: var(--surface-2); color: #fff; }

  .main { padding: 28px 32px 60px; max-width: 1200px; }
  @media (max-width: 900px) { .main { padding: 20px 16px 40px; } }

  .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; gap: 12px; flex-wrap: wrap; }
  .topbar h1 { margin: 0; font-size: 22px; font-weight: 800; }
  .topbar h1 small { color: var(--text-muted); font-size: 12px; font-weight: 500; display: block; margin-top: 2px; letter-spacing: .5px; }

  .card {
    background: var(--grad-card); border: 1px solid var(--border-soft);
    border-radius: var(--radius); padding: 20px 22px; margin-bottom: 18px;
    box-shadow: var(--shadow-1);
    backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
  }
  .card h2 { margin: 0 0 14px; font-size: 16px; font-weight: 700; }
  .card h3 { margin: 0 0 12px; font-size: 14px; font-weight: 700; color: var(--text-2); }

  .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 14px; }
  .stat {
    position: relative; overflow: hidden;
    background: var(--grad-card); border: 1px solid var(--border-soft);
    border-radius: var(--radius); padding: 18px 20px; box-shadow: var(--shadow-1);
  }
  .stat::before {
    content: ""; position: absolute; inset: 0 auto auto 0;
    width: 80px; height: 80px; background: var(--grad-brand);
    filter: blur(36px); opacity: .55; border-radius: 50%;
    transform: translate(-30%, -30%);
  }
  .stat .ico {
    position: relative; width: 38px; height: 38px; border-radius: 10px;
    background: var(--surface-2); display: flex; align-items: center; justify-content: center;
    margin-bottom: 12px; border: 1px solid var(--border-soft); color: #f0a8e1;
  }
  .stat .ico svg { width: 18px; height: 18px; }
  .stat .n { position: relative; font-size: 28px; font-weight: 800; line-height: 1; }
  .stat .l { position: relative; color: var(--text-muted); font-size: 12px; margin-top: 6px; letter-spacing: .3px; }

  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { padding: 11px 12px; text-align: right; }
  thead th {
    color: var(--text-muted); font-weight: 600; font-size: 11px; letter-spacing: 1px;
    border-bottom: 1px solid var(--border-soft); text-transform: uppercase;
  }
  tbody tr { border-bottom: 1px solid rgba(255,255,255,0.04); transition: background .15s ease; }
  tbody tr:hover { background: rgba(255,255,255,0.025); }
  tbody td { color: var(--text); }
  .table-photo { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-soft); }
  .table-photo-empty { width: 36px; height: 36px; border-radius: 50%; background: var(--surface-2); border: 1px solid var(--border-soft); display:inline-flex; align-items:center; justify-content:center; color: var(--text-muted); font-size: 12px; }

  input[type=text], input[type=password], input[type=number], input[type=email], input[type=date], select, textarea {
    width: 100%; padding: 10px 12px; background: var(--surface);
    border: 1px solid var(--border-soft); border-radius: var(--radius-sm);
    color: var(--text); font-size: 14px; font-family: inherit;
    transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
  }
  input:focus, select:focus, textarea:focus {
    outline: none; border-color: var(--magenta);
    box-shadow: 0 0 0 3px rgba(199,36,177,0.18); background: var(--surface-2);
  }
  input[type=file] { padding: 8px 10px; cursor: pointer; }
  input[type=file]::-webkit-file-upload-button {
    background: var(--surface-2); color: var(--text); border: 0;
    padding: 6px 12px; border-radius: 6px; cursor: pointer; font-family: inherit; margin-inline-end: 10px;
  }
  textarea { min-height: 90px; resize: vertical; }
  label { font-size: 13px; color: var(--text-2); }
  form p { margin: 0 0 12px; }
  .checks { display: flex; flex-wrap: wrap; gap: 14px; }
  .checks label { display: inline-flex; align-items: center; gap: 6px; }

  button, .btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 18px; border-radius: 10px; border: 0; cursor: pointer;
    background: var(--grad-brand); color: #fff; font-weight: 700; font-size: 14px;
    box-shadow: 0 8px 22px rgba(123,44,191,0.35);
    transition: transform .15s ease, box-shadow .15s ease, filter .15s ease;
  }
  button:hover, .btn:hover { transform: translateY(-1px); filter: brightness(1.08); }
  button:active { transform: translateY(0); }
  .btn-ghost { background: var(--surface); color: var(--text); box-shadow: none; border: 1px solid var(--border-soft); }
  .btn-ghost:hover { background: var(--surface-2); }
  .btn-danger { background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); box-shadow: 0 8px 22px rgba(239,68,68,0.3); }
  .btn-gold { background: var(--grad-gold); color: #1a1410; box-shadow: 0 8px 22px rgba(255,184,0,0.3); }
  .btn-sm { padding: 6px 12px; font-size: 12px; }
  form.inline { display: inline; }

  .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: .3px; }
  .b-active   { background: rgba(34,197,94,0.15);  color: #4ade80; border: 1px solid rgba(34,197,94,0.3); }
  .b-inactive { background: rgba(239,68,68,0.15);  color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
  .b-trial    { background: rgba(255,184,0,0.15);  color: #ffd166; border: 1px solid rgba(255,184,0,0.3); }
  .b-paid     { background: rgba(199,36,177,0.15); color: #f0a8e1; border: 1px solid rgba(199,36,177,0.3); }
  .b-mute     { background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border-soft); }
  .flash { padding: 12px 14px; border-radius: 12px; margin-bottom: 14px; font-size: 14px; }
  .flash-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }

  .auth-bg {
    min-height: 100vh; display: grid; place-items: center; padding: 24px;
    background:
      radial-gradient(800px 500px at 80% 10%, rgba(199,36,177,0.25), transparent 60%),
      radial-gradient(700px 400px at 10% 90%, rgba(57,73,171,0.3), transparent 60%),
      var(--bg);
  }
  .auth-card {
    width: 100%; max-width: 420px;
    background: var(--grad-card); border: 1px solid var(--border-soft);
    border-radius: 20px; padding: 32px 28px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 0 0 1px rgba(199,36,177,0.15);
    backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
  }
  .auth-card .brand-row { display: flex; flex-direction: column; align-items: center; gap: 12px; margin-bottom: 22px; }
  .auth-card .brand-row .logo { width: 80px; height: 80px; filter: drop-shadow(0 10px 24px rgba(199,36,177,0.5)); }
  .auth-card h2 { margin: 0; font-size: 22px; font-weight: 800; }
  .auth-card .sub { color: var(--text-muted); font-size: 13px; text-align: center; margin-bottom: 22px; }
  .auth-card .err { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; padding: 10px 12px; border-radius: 10px; font-size: 13px; margin-bottom: 14px; }
  .auth-card button { width: 100%; justify-content: center; padding: 12px; margin-top: 6px; }

  /* Mobile (≤900px): collapse the side rail into a horizontal scrolling
     icon strip at the top and let the main column take full width. The
     active item keeps its label so the user can tell where they are; the
     rest become icon-only pills that scroll horizontally. */
  @media (max-width: 900px) {
    .sidebar {
      position: static;
      height: auto;
      flex-direction: row;
      align-items: center;
      gap: 10px;
      padding: 10px 14px;
      overflow-x: auto;
      overflow-y: hidden;
      border-inline-end: 0;
      border-bottom: 1px solid var(--border-soft);
      scrollbar-width: none;
    }
    .sidebar::-webkit-scrollbar { display: none; }
    .sidebar .brand {
      border-bottom: 0;
      border-inline-end: 1px solid var(--border-soft);
      padding: 0 10px 0 0;
      flex-shrink: 0;
    }
    .sidebar .brand .logo { width: 32px; height: 32px; }
    .sidebar .brand .name { font-size: 13px; }
    .sidebar .brand .name small { display: none; }
    .nav { flex-direction: row; gap: 4px; flex-shrink: 0; }
    .nav a { padding: 8px 10px; font-size: 13px; white-space: nowrap; }
    .nav a span { display: none; }
    .nav a.active span { display: inline; }
    .nav a.active { padding: 8px 12px; }
    .side-foot {
      margin-top: 0;
      margin-inline-start: auto;
      flex-direction: row;
      align-items: center;
      gap: 8px;
      border-top: 0;
      padding-top: 0;
      flex-shrink: 0;
    }
    .side-foot .who { display: none; }
    .side-foot .logout { padding: 6px 10px; font-size: 12px; white-space: nowrap; }
  }

  /* Phone (≤700px): wide tables would otherwise blow the card's width.
     Make the card itself scroll horizontally and pin a sane minimum
     table width so the columns don't squash to unreadable mush. */
  @media (max-width: 700px) {
    .main { padding: 14px 12px 32px; }
    .card { padding: 14px 12px; overflow-x: auto; }
    .card > table { min-width: 640px; }
    .card h2 { font-size: 15px; }
    th, td { padding: 8px 8px; font-size: 13px; }
    thead th { font-size: 10px; letter-spacing: .8px; }
    .topbar h1 { font-size: 19px; }
    .topbar { gap: 8px; }
    .topbar > * { min-width: 0; }
    .topbar form,
    .topbar .actions { width: 100%; }
    .stat .n { font-size: 22px; }
    .stat { padding: 14px 14px; }
    .btn-sm { padding: 5px 10px; font-size: 11px; }

    /* Many forms use inline `style="display:grid; grid-template-columns:
       1fr 1fr"` to lay out two-column field rows. Inline styles outrank
       normal CSS, so use !important to flatten them to a single column
       on small screens. The selector is broad on purpose — any inline
       grid-template-columns inside the main column collapses. */
    .main [style*="grid-template-columns"] {
      grid-template-columns: 1fr !important;
    }

    /* Inline-styled flex rows that hold buttons or filters: let them
       wrap so action buttons don't overflow the card. */
    .main [style*="display:flex"],
    .main [style*="display: flex"] {
      flex-wrap: wrap;
    }

    /* The "actions" cell on session/user/consultant rows already uses
        inline flex; once it wraps the gap matters more. */
    td [style*="display:flex"] { gap: 6px; row-gap: 6px; }

    /* Search forms with a max-width: 500px lose nothing by stretching. */
    form[style*="max-width:500px"] { max-width: 100% !important; }

    /* Allow long tokens / emails / urls inside cells to wrap rather
        than push the table wider than the screen. */
    td { overflow-wrap: anywhere; word-break: break-word; }

    /* Buttons should be a comfortable tap target on phones. */
    button, .btn { min-height: 38px; }
    .btn-sm { min-height: 30px; }

    /* Auth (login) card scales down on tiny phones too. */
    .auth-card { padding: 22px 18px; }
    .auth-card h2 { font-size: 19px; }

    /* AI chat bubbles: full width on phones so text is readable */
    .main [style*="max-width:85%"] { max-width: 100% !important; }

    /* Tighten stats grid on phones */
    .stats { grid-template-columns: repeat(2, 1fr) !important; gap: 10px; }

    /* Ensure any table inside a card scrolls horizontally */
    .card table { display: block; overflow-x: auto; white-space: nowrap; }
    .card thead, .card tbody, .card th, .card td { white-space: nowrap; }
    .card td[style*="max-width"] { white-space: nowrap !important; }
  }

  /* Tiny phones (≤400px): one more notch tighter so iPhone SE-class
     viewports stop horizontal-scrolling the topbar. */
  @media (max-width: 400px) {
    .sidebar { padding: 8px 10px; gap: 6px; }
    .sidebar .brand .name { display: none; }
    .nav a { padding: 7px 8px; }
    .topbar h1 { font-size: 17px; }
    .topbar h1 small { font-size: 11px; }
    .stats { grid-template-columns: 1fr !important; }
  }
</style>

<svg width="0" height="0" style="position: absolute" aria-hidden="true">
  <defs>
    <linearGradient id="lytcOuter" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%"  stop-color="#C724B1"/>
      <stop offset="50%" stop-color="#7B2CBF"/>
      <stop offset="100%" stop-color="#3949AB"/>
    </linearGradient>
    <linearGradient id="lytcGold" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%"   stop-color="#FFD166"/>
      <stop offset="100%" stop-color="#FF7A00"/>
    </linearGradient>
    <linearGradient id="lytcRim" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%"   stop-color="#FFD166"/>
      <stop offset="100%" stop-color="#FFB800"/>
    </linearGradient>
    <symbol id="lytcLogo" viewBox="0 0 64 80">
      <path d="M32 2 C50 22 60 36 60 52 C60 68 48 78 32 78 C16 78 4 68 4 52 C4 36 14 22 32 2 Z"
            fill="url(#lytcOuter)" stroke="url(#lytcRim)" stroke-width="1.5"/>
      <path d="M22 56 Q32 64 42 56 L42 64 Q32 72 22 64 Z" fill="#3D1F6E" opacity="0.85"/>
      <path d="M32 22 C26 32 24 42 28 50 C30 54 34 54 36 50 C40 42 38 32 32 22 Z" fill="#FFFFFF"/>
      <path d="M32 30 C29 36 28 42 30 47 C31 50 33 50 34 47 C36 42 35 36 32 30 Z" fill="url(#lytcGold)"/>
      <circle cx="14" cy="28" r="1.4" fill="#FFD166" opacity="0.9"/>
      <circle cx="50" cy="44" r="1.6" fill="#FFFFFF" opacity="0.85"/>
      <circle cx="48" cy="20" r="1" fill="#FFD166" opacity="0.7"/>
    </symbol>
  </defs>
</svg>

</head>
<body>

<?php if ($loggedIn): ?>
<div class="shell">
  <aside class="sidebar">
    <div class="brand">
      <svg class="logo"><use href="#lytcLogo"/></svg>
      <div class="name">أشعل شمعتك<small>ADMIN</small></div>
    </div>
    <nav class="nav">
      <?php foreach ($NAV as $key => $item): ?>
        <a href="?action=<?= $key ?>" class="<?= $action === $key ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $item['icon'] ?></svg>
          <span><?= htmlspecialchars($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <div class="who">
        <div class="avatar"><?= htmlspecialchars($initials) ?></div>
        <div class="meta">
          <b><?= htmlspecialchars($_SESSION['admin_name'] ?? '') ?></b>
          <small><?= htmlspecialchars($_SESSION['admin_role'] ?? 'admin') ?></small>
        </div>
      </div>
      <a class="logout" href="?action=logout">تسجيل الخروج</a>
    </div>
  </aside>
  <main class="main">
    <div class="topbar">
      <h1>
        <?= htmlspecialchars($pageTitle) ?>
        <small>أشعل شمعتك · لوحة التحكم</small>
      </h1>
    </div>
<?php else: ?>
<div class="auth-bg">
<?php endif; ?>
