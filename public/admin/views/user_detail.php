<?php
/** @var array $user */
/** @var ?array $subscription */
/** @var array $aiDays */
/** @var array $sessions */

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
