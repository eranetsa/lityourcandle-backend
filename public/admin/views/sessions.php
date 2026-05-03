<div class="card">
  <h2 style="margin-top:0;">الجلسات</h2>
  <table>
    <thead><tr>
      <th>#</th><th>المستخدم</th><th>المستشار</th>
      <th>النوع</th><th>الحالة</th><th>المجدول</th>
      <th>المدة (د)</th><th>التقييم</th><th>إجراءات</th>
    </tr></thead>
    <tbody>
      <?php foreach ($rows as $r):
        $statusBadge = match ($r['status']) {
            'pending'     => 'b-trial',
            'confirmed'   => 'b-active',
            'in_progress' => 'b-paid',
            'completed'   => 'b-mute',
            default       => 'b-inactive',
        };
        $isOpen = in_array($r['status'], ['pending','confirmed','in_progress'], true);
      ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= htmlspecialchars($r['user_name']) ?></td>
        <td><?= htmlspecialchars($r['consultant_name']) ?></td>
        <td><?= $r['type'] ?> / <?= $r['mode'] ?></td>
        <td><span class="badge <?= $statusBadge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
        <td><?= htmlspecialchars($r['scheduled_at'] ?? '—') ?></td>
        <td><?= $r['duration_min'] ?? '—' ?></td>
        <td><?= $r['post_rating'] ?? '—' ?></td>
        <td style="display:flex; gap:6px;">
          <?php if ($isOpen): ?>
            <form method="post" class="inline" onsubmit="return confirm('إلغاء الجلسة #<?= $r['id'] ?>؟')">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="op"   value="cancel">
              <input type="hidden" name="id"   value="<?= $r['id'] ?>">
              <button type="submit" class="btn-sm btn-ghost">إلغاء</button>
            </form>
          <?php endif; ?>
          <form method="post" class="inline" onsubmit="return confirm('حذف الجلسة نهائياً؟')">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="op"   value="delete">
            <input type="hidden" name="id"   value="<?= $r['id'] ?>">
            <button type="submit" class="btn-danger btn-sm">حذف</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
