<div class="card">
  <h2>المستخدمون</h2>
  <form method="get" style="display:flex; gap:10px; margin-bottom:18px; max-width:500px;">
    <input type="hidden" name="action" value="users">
    <input type="text" name="q" placeholder="بحث بالاسم أو البريد أو الجوال" value="<?= htmlspecialchars($q) ?>">
    <button type="submit" class="btn-ghost">بحث</button>
  </form>
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
</div>
