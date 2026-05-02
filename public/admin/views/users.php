<div class="card">
  <form method="get" style="margin-bottom:12px;">
    <input type="hidden" name="action" value="users">
    <input type="text" name="q" placeholder="بحث بالاسم أو البريد أو الجوال" value="<?= htmlspecialchars($q) ?>" style="width:300px;">
    <button type="submit">بحث</button>
  </form>
  <table>
    <thead><tr><th>#</th><th>الاسم</th><th>البريد</th><th>الجوال</th><th>الباقة</th><th>الحالة</th><th>إجراء</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><?= htmlspecialchars($r['email']) ?></td>
        <td><?= htmlspecialchars($r['phone'] ?? '') ?></td>
        <td><?= htmlspecialchars($r['plan'] ?? 'free') ?> <span class="badge b-<?= $r['sub_status']==='trial'?'trial':($r['sub_status']==='active'?'active':'inactive') ?>"><?= htmlspecialchars($r['sub_status'] ?? '') ?></span></td>
        <td><span class="badge <?= $r['is_active']?'b-active':'b-inactive' ?>"><?= $r['is_active']?'نشط':'موقوف' ?></span></td>
        <td>
          <form method="post" class="inline">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="op" value="toggle">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button type="submit" class="btn-danger"><?= $r['is_active']?'إيقاف':'تفعيل' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
