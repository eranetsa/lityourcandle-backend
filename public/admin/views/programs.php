<div class="card">
  <h2 style="margin-top:0;">إنشاء برنامج</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="op" value="create_program">
    <p><input type="text" name="slug" placeholder="معرف فريد بالإنجليزية مثل breathing-101 أو candle-light-7" required></p>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <p>
        <label>الفئة</label>
        <select name="category" required>
          <option value="breathing">تنفّس</option>
          <option value="self_awareness">وعي ذاتي</option>
          <option value="lifestyle">نمط الحياة</option>
          <option value="anxiety">القلق</option>
          <option value="relationships">العلاقات</option>
          <option value="self_development">تطوير الذات</option>
          <option value="candle">اشعل شمعتك</option>
        </select>
      </p>
      <p>
        <label>الأيقونة (لبرامج اشعل شمعتك)</label>
        <select name="icon">
          <option value="">— بدون —</option>
          <option value="flame">🔥 شعلة</option>
          <option value="spark">✨ شرارة</option>
          <option value="sun">☀ شمس</option>
          <option value="moon">🌙 قمر</option>
          <option value="leaf">🌿 ورقة</option>
        </select>
      </p>
    </div>
    <p><input type="text" name="title_ar" placeholder="العنوان بالعربية" required></p>
    <p><input type="text" name="title_en" placeholder="English title (optional)"></p>
    <p><textarea name="description_ar" placeholder="الوصف"></textarea></p>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <p>
        <label>لون التدرّج (بداية)</label>
        <input type="text" name="palette_start" placeholder="#FBEFD0" maxlength="7">
      </p>
      <p>
        <label>لون التدرّج (نهاية)</label>
        <input type="text" name="palette_end" placeholder="#F2D88E" maxlength="7">
      </p>
    </div>
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
      <label>البرنامج</label>
      <select name="program_id" required>
        <?php foreach ($programs as $p): ?>
          <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['title_ar']) ?> <?= $p['category'] === 'candle' ? '🕯️' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </p>
    <div style="display:grid; grid-template-columns: 1fr 2fr; gap: 14px;">
      <p><label>رقم اليوم</label><input type="number" name="day_number" required></p>
      <p><label>عنوان اليوم</label><input type="text" name="title_ar" required></p>
    </div>
    <p><textarea name="body_ar" placeholder="محتوى اليوم"></textarea></p>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <p><label>المدة بالدقائق</label><input type="number" name="duration_min" value="3"></p>
      <p style="align-self:center;"><label><input type="checkbox" name="is_locked"> مغلق (للمشتركين)</label></p>
    </div>
    <button type="submit">إضافة اليوم</button>
  </form>
</div>

<div class="card">
  <h3>البرامج</h3>
  <table>
    <thead><tr><th>#</th><th>المعرف</th><th>الفئة</th><th>العنوان</th><th>بريميوم؟</th><th>تدرّج</th><th>أيام</th></tr></thead>
    <tbody>
      <?php foreach ($programs as $p):
        $pdays = array_filter($days, fn($d) => $d['program_id'] === $p['id']); ?>
      <tr>
        <td><?= $p['id'] ?></td>
        <td><?= htmlspecialchars($p['slug']) ?></td>
        <td>
          <?= htmlspecialchars($p['category']) ?>
          <?php if (!empty($p['icon'])): ?>
            <small style="color:var(--text-muted);">· <?= htmlspecialchars($p['icon']) ?></small>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($p['title_ar']) ?></td>
        <td>
          <?= $p['is_premium']
            ? '<span class="badge b-paid">بريميوم</span>'
            : '<span class="badge b-mute">مجاني</span>' ?>
        </td>
        <td>
          <?php if (!empty($p['palette_start']) && !empty($p['palette_end'])): ?>
            <span style="display:inline-block; width:48px; height:18px; border-radius:4px; background:linear-gradient(90deg, <?= htmlspecialchars($p['palette_start']) ?>, <?= htmlspecialchars($p['palette_end']) ?>);"></span>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td><?= count($pdays) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
