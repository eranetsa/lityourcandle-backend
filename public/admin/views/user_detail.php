<?php
/** @var array $user */
/** @var ?array $subscription */
/** @var array $aiDays */
/** @var array $sessions */
/** @var ?array $rcFlash */

$rcFlash     = $rcFlash     ?? null;
$moodEntries = $moodEntries ?? [];
$moodCounts  = $moodCounts  ?? [];
$moodLabel = [
  'happy'    => 'سعيد',
  'calm'     => 'هادئ',
  'neutral'  => 'عادي',
  'anxious'  => 'قلِق',
  'sad'      => 'حزين',
];
$moodEmoji = [
  'happy'    => '😊',
  'calm'     => '😌',
  'neutral'  => '🙂',
  'anxious'  => '😟',
  'sad'      => '😢',
];
$moodColor = [
  'happy'    => 'rgba(34,197,94,0.15)',
  'calm'     => 'rgba(56,189,248,0.15)',
  'neutral'  => 'rgba(255,255,255,0.06)',
  'anxious'  => 'rgba(255,184,0,0.15)',
  'sad'      => 'rgba(239,68,68,0.15)',
];
// Latest mood = first row (sorted DESC).
$latestMood = $moodEntries[0]['mood'] ?? null;

$planBadge = static function (?string $plan): string {
  if ($plan === 'yearly' || $plan === 'lifetime') return 'b-paid';
  if ($plan === 'monthly' || $plan === 'weekly') return 'b-trial';
  return 'b-mute';
};
$statusBadge = static function (string $status): string {
  return match ($status) {
    'active'   => 'b-active',
    'trial'    => 'b-trial',
    'in_progress' => 'b-active',
    'completed'   => 'b-paid',
    'pending', 'confirmed' => 'b-mute',
    default => 'b-inactive',
  };
};
?>

<?php if ($rcFlash !== null): ?>
  <?php
    $isError = !empty($rcFlash['error']);
    $badge   = $isError ? 'b-inactive' : (!empty($rcFlash['found']) ? 'b-active' : 'b-mute');
    if ($isError) {
        $summary = 'فشل المزامنة: ' . htmlspecialchars((string)$rcFlash['error']);
    } else {
        $summary = sprintf(
            'مزامنة RevenueCat — مطابق: %s، اشتراكات نشطة: %d، صفوف محدّثة: %d، أرصدة إضافية: %d%s',
            htmlspecialchars((string)($rcFlash['matched_id'] ?? '—')),
            (int)($rcFlash['active_subs_seen'] ?? 0),
            (int)($rcFlash['sub_rows_written'] ?? 0),
            (int)($rcFlash['non_sub_credits']  ?? 0),
            !empty($rcFlash['notes'])
                ? ' — ' . htmlspecialchars(implode(' / ', (array)$rcFlash['notes']))
                : ''
        );
    }
  ?>
  <div class="card" style="padding:10px 14px;">
    <span class="badge <?= $badge ?>">RC</span>
    <span style="margin-inline-start:8px;"><?= $summary ?></span>
  </div>
<?php endif; ?>

<div class="card">
  <div style="display:flex; align-items:center; gap:14px; margin-bottom:6px; flex-wrap:wrap;">
    <?php if (!empty($user['avatar_url'])): ?>
      <img src="<?= htmlspecialchars($user['avatar_url']) ?>" class="table-photo" alt="">
    <?php else: ?>
      <span class="table-photo-empty"><?= htmlspecialchars(mb_substr((string)$user['name'], 0, 1)) ?></span>
    <?php endif; ?>
    <div style="flex:1; min-width:0;">
      <h2 style="margin:0; font-size:20px;"><?= htmlspecialchars((string)$user['name']) ?></h2>
      <div style="color:var(--text-muted); font-size:13px;"><?= htmlspecialchars((string)$user['email']) ?></div>
    </div>
    <form method="POST"
          action="/admin/?action=sync_user&amp;id=<?= (int)$user['id'] ?>"
          style="display:inline;">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <button type="submit" class="btn-ghost btn-sm" title="جلب آخر حالة من RevenueCat">
        ⟳ مزامنة RevenueCat
      </button>
    </form>
    <a href="?action=users" class="btn-ghost btn-sm">← قائمة المستخدمين</a>
  </div>

  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-top:14px;">
    <div>
      <div style="color:var(--text-muted); font-size:12px;">رقم الجوال</div>
      <div><?= htmlspecialchars($user['phone'] ?: '—') ?></div>
    </div>
    <div>
      <div style="color:var(--text-muted); font-size:12px;">الدور</div>
      <div><span class="badge b-mute"><?= htmlspecialchars($user['role']) ?></span></div>
    </div>
    <div>
      <div style="color:var(--text-muted); font-size:12px;">اللغة</div>
      <div><?= htmlspecialchars($user['language'] ?: '—') ?></div>
    </div>
    <div>
      <div style="color:var(--text-muted); font-size:12px;">الحالة</div>
      <div>
        <span class="badge <?= $user['is_active'] ? 'b-active' : 'b-inactive' ?>">
          <?= $user['is_active'] ? 'نشط' : 'موقوف' ?>
        </span>
      </div>
    </div>
    <div>
      <div style="color:var(--text-muted); font-size:12px;">تاريخ التسجيل</div>
      <div><?= htmlspecialchars($user['created_at']) ?></div>
    </div>
  </div>
</div>

<?php if ($subscription): ?>
<div class="card">
  <h3 style="margin-top:0;">الاشتراك</h3>
  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
    <div>
      <div style="color:var(--text-muted); font-size:12px;">الباقة</div>
      <div><span class="badge <?= $planBadge($subscription['plan']) ?>"><?= htmlspecialchars((string)$subscription['plan']) ?></span></div>
    </div>
    <div>
      <div style="color:var(--text-muted); font-size:12px;">الحالة</div>
      <div><span class="badge <?= $statusBadge((string)$subscription['status']) ?>"><?= htmlspecialchars((string)$subscription['status']) ?></span></div>
    </div>
    <div>
      <div style="color:var(--text-muted); font-size:12px;">المتجر</div>
      <div><?= htmlspecialchars((string)$subscription['store']) ?></div>
    </div>
    <div>
      <div style="color:var(--text-muted); font-size:12px;">ينتهي</div>
      <div><?= htmlspecialchars($subscription['expires_at'] ?? '—') ?></div>
    </div>
    <div>
      <div style="color:var(--text-muted); font-size:12px;">الجلسات المتبقية</div>
      <div><?= (int)$subscription['sessions_remaining'] ?> / <?= (int)$subscription['sessions_total'] ?></div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h3 style="margin-top:0;">المزاج</h3>
  <?php if (empty($moodEntries)): ?>
    <p style="color:var(--text-muted); text-align:center; padding:14px 0;">
      لم يسجّل هذا المستخدم أي حالة مزاج بعد.
    </p>
  <?php else: ?>
    <!-- Header row: current mood + 30-day count chips -->
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
      <?php if ($latestMood): ?>
        <div style="
          display:inline-flex; align-items:center; gap:8px;
          padding:8px 14px; border-radius:12px;
          background:<?= $moodColor[$latestMood] ?? 'var(--surface-2)' ?>;
          border:1px solid var(--border-soft);">
          <span style="font-size:22px;"><?= $moodEmoji[$latestMood] ?? '🙂' ?></span>
          <div>
            <div style="font-size:11px; color:var(--text-muted);">آخر حالة</div>
            <strong><?= $moodLabel[$latestMood] ?? $latestMood ?></strong>
          </div>
        </div>
      <?php endif; ?>
      <div style="color:var(--text-muted); font-size:12px; align-self:center;">آخر ٣٠ يوماً:</div>
      <?php foreach ($moodCounts as $c): ?>
        <span class="badge b-mute" style="background:<?= $moodColor[$c['mood']] ?? 'var(--surface-2)' ?>;">
          <?= $moodEmoji[$c['mood']] ?? '·' ?>
          <?= $moodLabel[$c['mood']] ?? $c['mood'] ?>
          <strong style="margin-inline-start:4px;"><?= (int)$c['n'] ?></strong>
        </span>
      <?php endforeach; ?>
    </div>

    <!-- Recent entries -->
    <table>
      <thead><tr><th>اليوم</th><th>الحالة</th><th>ملاحظة</th><th>وقت التسجيل</th></tr></thead>
      <tbody>
        <?php foreach ($moodEntries as $m): ?>
        <tr>
          <td><?= htmlspecialchars((string)$m['logged_on']) ?></td>
          <td>
            <span style="font-size:16px;"><?= $moodEmoji[$m['mood']] ?? '·' ?></span>
            <span style="margin-inline-start:6px;"><?= $moodLabel[$m['mood']] ?? $m['mood'] ?></span>
          </td>
          <td style="max-width:420px; color:var(--text-2);"><?= htmlspecialchars((string)($m['note'] ?? '—')) ?></td>
          <td style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars((string)$m['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin-top:0;">محادثات شمعة AI (<?= count($aiDays) ?>)</h3>
  <?php if (empty($aiDays)): ?>
    <p style="color:var(--text-muted); text-align:center; padding:14px 0;">
      لا توجد محادثات مع الذكاء الاصطناعي لهذا المستخدم.
    </p>
  <?php else: ?>
    <table>
      <thead><tr><th>اليوم</th><th>عدد الرسائل</th><th>تصعيد</th><th>أول رسالة</th><th>إجراء</th></tr></thead>
      <tbody>
        <?php foreach ($aiDays as $d): ?>
        <tr>
          <td><?= htmlspecialchars((string)$d['d']) ?></td>
          <td><?= (int)$d['msgs'] ?></td>
          <td>
            <?php if ((int)$d['escalated']): ?>
              <span class="badge b-trial"><?= (int)$d['escalated'] ?></span>
            <?php else: ?>
              <span class="badge b-mute">—</span>
            <?php endif; ?>
          </td>
          <td style="max-width:380px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
            <?= htmlspecialchars(mb_substr((string)$d['opener'], 0, 120)) ?>
          </td>
          <td>
            <a href="?action=ai_session&amp;id=<?= (int)$user['id'] ?>&amp;date=<?= urlencode((string)$d['d']) ?>"
               class="btn-ghost btn-sm">عرض المحادثة</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin-top:0;">جلسات الاستشارة البشرية (<?= count($sessions) ?>)</h3>
  <?php if (empty($sessions)): ?>
    <p style="color:var(--text-muted); text-align:center; padding:14px 0;">
      لم يحجز هذا المستخدم أي جلسة استشارية بعد.
    </p>
  <?php else: ?>
    <table>
      <thead><tr><th>#</th><th>المستشار</th><th>النوع</th><th>الحالة</th><th>عدد الرسائل</th><th>التقييم</th><th>التاريخ</th><th>إجراء</th></tr></thead>
      <tbody>
        <?php foreach ($sessions as $s): ?>
        <tr>
          <td><?= (int)$s['id'] ?></td>
          <td><?= htmlspecialchars($s['consultant_name'] ?: '—') ?></td>
          <td><span class="badge b-mute"><?= htmlspecialchars((string)$s['type']) ?></span></td>
          <td><span class="badge <?= $statusBadge((string)$s['status']) ?>"><?= htmlspecialchars((string)$s['status']) ?></span></td>
          <td><?= (int)$s['msg_count'] ?></td>
          <td><?= $s['post_rating'] ? str_repeat('★', (int)$s['post_rating']) : '—' ?></td>
          <td style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars((string)$s['created_at']) ?></td>
          <td>
            <a href="?action=session_transcript&amp;id=<?= (int)$s['id'] ?>" class="btn-ghost btn-sm">
              عرض المحادثة
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
