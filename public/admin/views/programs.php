<div class="card">
  <h2 style="margin-top:0;">إنشاء برنامج</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="op" value="create_program">
    <p><input type="text" name="slug" placeholder="معرف فريد بالإنجليزية مثل breathing-101" required></p>
    <p>
      <select name="category" required>
        <option value="breathing">تنفس</option>
        <option value="self_awareness">وعي ذاتي</option>
        <option value="lifestyle">نمط الحياة</option>
        <option value="anxiety">القلق</option>
        <option value="relationships">العلاقات</option>
        <option value="self_development">تطوير الذات</option>
      </select>
    </p>
    <p><input type="text" name="title_ar" placeholder="العنوان بالعربية" required></p>
    <p><input type="text" name="title_en" placeholder="English title (optional)"></p>
    <p><textarea name="description_ar" placeholder="الوصف"></textarea></p>
    <p><label><input type="checkbox" name="is_premium"> مدفوع (بريميوم)</label></p>
    <button type="submit">إنشاء البرنامج</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;">إضافة يوم لبرنامج</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="op" value="create_day">
    <p>
      <select name="program_id" required>
        <?php foreach ($programs as $p): ?>
          <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['title_ar']) ?></option>
        <?php endforeach; ?>
      </select>
    </p>
    <p><input type="number" name="day_number" placeholder="رقم اليوم" required></p>
    <p><input type="text" name="title_ar" placeholder="عنوان اليوم" required></p>
    <p><textarea name="body_ar" placeholder="محتوى اليوم"></textarea></p>
    <p><input type="number" name="duration_min" placeholder="المدة بالدقائق" value="3"></p>
    <p><label><input type="checkbox" name="is_locked"> مغلق (للمشتركين)</label></p>
    <button type="submit">إضافة اليوم</button>
  </form>
</div>

<div class="card">
  <h3>البرامج</h3>
  <table>
    <thead><tr><th>#</th><th>المعرف</th><th>الفئة</th><th>العنوان</th><th>بريميوم؟</th><th>أيام</th></tr></thead>
    <tbody>
      <?php foreach ($programs as $p):
        $pdays = array_filter($days, fn($d) => $d['program_id'] === $p['id']); ?>
      <tr>
        <td><?= $p['id'] ?></td>
        <td><?= htmlspecialchars($p['slug']) ?></td>
        <td><?= htmlspecialchars($p['category']) ?></td>
        <td><?= htmlspecialchars($p['title_ar']) ?></td>
        <td><?= $p['is_premium'] ? 'نعم' : 'لا' ?></td>
        <td><?= count($pdays) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
