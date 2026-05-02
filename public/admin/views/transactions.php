<div class="card">
  <h2 style="margin-top:0;">المعاملات</h2>
  <table>
    <thead><tr><th>#</th><th>البريد</th><th>النوع</th><th>المبلغ</th><th>المتجر</th><th>الحالة</th><th>التاريخ</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= htmlspecialchars($r['email']) ?></td>
        <td><?= htmlspecialchars($r['kind']) ?></td>
        <td><?= number_format((float)$r['amount'], 2) ?> <?= htmlspecialchars($r['currency']) ?></td>
        <td><?= htmlspecialchars($r['store']) ?></td>
        <td><?= htmlspecialchars($r['status']) ?></td>
        <td><?= htmlspecialchars($r['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
