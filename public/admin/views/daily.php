<div class="card">
  <h2 style="margin-top:0;">رسالة اليوم - إضافة</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="op" value="create">
    <p><textarea name="text_ar" placeholder="النص بالعربية" required></textarea></p>
    <p><textarea name="text_en" placeholder="English (optional)"></textarea></p>
    <p><label>تاريخ ثابت (اختياري): <input type="date" name="show_on" style="width:auto;"></label></p>
    <p><label><input type="checkbox" name="is_active" checked> نشطة</label></p>
    <button type="submit">إضافة</button>
  </form>
</div>
<div class="card">
  <h3>القائمة</h3>
  <table>
    <thead><tr><th>#</th><th>النص</th><th>تاريخ</th><th>نشطة؟</th><th>إجراء</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= htmlspecialchars($r['text_ar']) ?></td>
        <td><?= htmlspecialchars($r['show_on'] ?? '-') ?></td>
        <td><?= $r['is_active'] ? 'نعم' : 'لا' ?></td>
        <td>
          <form method="post" class="inline" onsubmit="return confirm('حذف؟')">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="op" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button type="submit" class="btn-danger">حذف</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
