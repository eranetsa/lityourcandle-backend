<?php
$tab             = $tab             ?? 'all';
$filterPlan      = $filterPlan      ?? '';
$filterSubStatus = $filterSubStatus ?? '';
$filterRole      = $filterRole      ?? '';
$counts          = $counts          ?? ['total' => 0, 'subscribed' => 0, 'free' => 0, 'inactive' => 0, 'guest' => 0];

$tabHref = function (string $key) use ($q, $filterPlan, $filterSubStatus, $filterRole): string {
  $qs = ['action' => 'users', 'tab' => $key];
  if ($q                !== '') $qs['q']          = $q;
  if ($filterPlan       !== '') $qs['plan']       = $filterPlan;
  if ($filterSubStatus  !== '') $qs['sub_status'] = $filterSubStatus;
  if ($filterRole       !== '') $qs['role']       = $filterRole;
  return '?' . http_build_query($qs);
};
?>
<div class="card">
  <h2>المستخدمون</h2>

  <!-- Tabs -->
  <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px;">
    <a href="<?= htmlspecialchars($tabHref('all')) ?>"
       class="<?= $tab === 'all' ? 'btn' : 'btn-ghost' ?> btn-sm">
      الكل <small style="opacity:.7;">(<?= (int)$counts['total'] ?>)</small>
    </a>
    <a href="<?= htmlspecialchars($tabHref('subscribed')) ?>"
       class="<?= $tab === 'subscribed' ? 'btn' : 'btn-ghost' ?> btn-sm">
      مشتركون نشطون <small style="opacity:.7;">(<?= (int)$counts['subscribed'] ?>)</small>
    </a>
    <a href="<?= htmlspecialchars($tabHref('free')) ?>"
       class="<?= $tab === 'free' ? 'btn' : 'btn-ghost' ?> btn-sm">
      مجاني / بلا اشتراك <small style="opacity:.7;">(<?= (int)$counts['free'] ?>)</small>
    </a>
    <a href="<?= htmlspecialchars($tabHref('guest')) ?>"
       class="<?= $tab === 'guest' ? 'btn' : 'btn-ghost' ?> btn-sm">
      ضيوف <small style="opacity:.7;">(<?= (int)$counts['guest'] ?>)</small>
    </a>
    <a href="<?= htmlspecialchars($tabHref('inactive')) ?>"
       class="<?= $tab === 'inactive' ? 'btn' : 'btn-ghost' ?> btn-sm">
      موقوفون <small style="opacity:.7;">(<?= (int)$counts['inactive'] ?>)</small>
    </a>
  </div>

  <!-- Filter row -->
  <form method="get" action="?action=users"
        style="display:grid; grid-template-columns: 1fr 160px 180px 140px auto; gap:8px; margin-bottom:18px;">
    <input type="hidden" name="action" value="users">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
    <input type="text" name="q" placeholder="بحث بالاسم أو البريد أو الجوال"
           value="<?= htmlspecialchars($q) ?>">
    <select name="plan">
      <option value="">كل الباقات</option>
      <?php foreach (['free','weekly','monthly','yearly','lifetime'] as $p): ?>
        <option value="<?= $p ?>" <?= $filterPlan === $p ? 'selected' : '' ?>>
          <?= htmlspecialchars($p) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="sub_status">
      <option value="">كل حالات الاشتراك</option>
      <option value="active"   <?= $filterSubStatus === 'active'   ? 'selected' : '' ?>>active</option>
      <option value="trial"    <?= $filterSubStatus === 'trial'    ? 'selected' : '' ?>>trial</option>
      <option value="expired"  <?= $filterSubStatus === 'expired'  ? 'selected' : '' ?>>expired</option>
      <option value="canceled" <?= $filterSubStatus === 'canceled' ? 'selected' : '' ?>>canceled</option>
      <option value="grace"    <?= $filterSubStatus === 'grace'    ? 'selected' : '' ?>>grace</option>
      <option value="none"     <?= $filterSubStatus === 'none'     ? 'selected' : '' ?>>بلا اشتراك</option>
    </select>
    <select name="role">
      <option value="">كل الأدوار</option>
      <?php foreach (['user','consultant','admin','guest'] as $rl): ?>
        <option value="<?= $rl ?>" <?= $filterRole === $rl ? 'selected' : '' ?>>
          <?= htmlspecialchars($rl) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-ghost btn-sm">تصفية</button>
  </form>

  <?php if (empty($rows)): ?>
    <p style="color:var(--text-muted); text-align:center; padding:18px 0;">
      لا يوجد مستخدمون يطابقون الفلتر الحالي.
    </p>
  <?php else: ?>
  <table>
    <thead><tr><th>#</th><th>الاسم</th><th>البريد</th><th>الجوال</th><th>الباقة</th><th>الحالة</th><th>إجراء</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r):
        $statusBadge = $r['sub_status'] === 'trial' ? 'b-trial'
                     : ($r['sub_status'] === 'active' ? ($r['plan'] === 'free' ? 'b-mute' : 'b-paid')
                     : 'b-inactive');
      ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><?= htmlspecialchars($r['email']) ?></td>
        <td><?= htmlspecialchars($r['phone'] ?? '—') ?></td>
        <td>
          <span class="badge <?= $statusBadge ?>"><?= htmlspecialchars($r['plan'] ?? 'free') ?></span>
          <?php if (!empty($r['sub_status']) && $r['sub_status'] !== 'active'): ?>
            <small style="color:var(--text-muted);"><?= htmlspecialchars($r['sub_status']) ?></small>
          <?php endif; ?>
        </td>
        <td><span class="badge <?= $r['is_active'] ? 'b-active' : 'b-inactive' ?>"><?= $r['is_active'] ? 'نشط' : 'موقوف' ?></span></td>
        <td style="display:flex; gap:6px;">
          <a href="?action=user&amp;id=<?= (int)$r['id'] ?>" class="btn-ghost btn-sm">عرض</a>
          <form method="post" class="inline">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="op" value="toggle">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button type="submit" class="<?= $r['is_active'] ? 'btn-danger' : '' ?> btn-sm"><?= $r['is_active'] ? 'إيقاف' : 'تفعيل' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
