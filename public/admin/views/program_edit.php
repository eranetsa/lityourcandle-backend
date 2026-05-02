<?php /** @var array $program */ /** @var array $days */ ?>

<div style="margin-bottom: 18px;">
  <a href="?action=programs" class="btn btn-ghost btn-sm">← العودة لقائمة البرامج</a>
</div>

<div class="card">
  <h2 style="margin-top:0;">تعديل البرنامج <small style="color:var(--text-muted); font-weight:500;">— <?= htmlspecialchars($program['slug']) ?></small></h2>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="op" value="update_program">
    <input type="hidden" name="id" value="<?= $program['id'] ?>">

    <p><label>المعرّف (slug)</label><input type="text" name="slug" value="<?= htmlspecialchars($program['slug']) ?>" required></p>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
      <p>
        <label>الفئة</label>
        <select name="category" required>
          <?php foreach (['breathing'=>'تنفّس','self_awareness'=>'وعي ذاتي','lifestyle'=>'نمط الحياة','anxiety'=>'القلق','relationships'=>'العلاقات','self_development'=>'تطوير الذات','candle'=>'اشعل شمعتك'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= $program['category'] === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </p>
      <p>
        <label>الأيقونة</label>
        <select name="icon">
          <option value="">— بدون —</option>
          <?php foreach (['flame'=>'🔥 شعلة','spark'=>'✨ شرارة','sun'=>'☀ شمس','moon'=>'🌙 قمر','leaf'=>'🌿 ورقة'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= $program['icon'] === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </p>
    </div>

    <p><label>العنوان بالعربية</label><input type="text" name="title_ar" value="<?= htmlspecialchars($program['title_ar']) ?>" required></p>
    <p><label>العنوان بالإنجليزية</label><input type="text" name="title_en" value="<?= htmlspecialchars($program['title_en'] ?? '') ?>"></p>
    <p><label>الوصف</label><textarea name="description_ar" rows="3"><?= htmlspecialchars($program['description_ar'] ?? '') ?></textarea></p>

    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px;">
      <p><label>تدرّج (بداية)</label><input type="text" name="palette_start" value="<?= htmlspecialchars($program['palette_start'] ?? '') ?>" maxlength="7" placeholder="#FBEFD0"></p>
      <p><label>تدرّج (نهاية)</label><input type="text" name="palette_end" value="<?= htmlspecialchars($program['palette_end'] ?? '') ?>" maxlength="7" placeholder="#F2D88E"></p>
      <p><label>ترتيب العرض</label><input type="number" name="sort_order" value="<?= (int)$program['sort_order'] ?>"></p>
    </div>

    <?php if (!empty($program['palette_start']) && !empty($program['palette_end'])): ?>
      <p>
        <label>معاينة التدرّج</label>
        <span style="display:block; width:100%; height:32px; border-radius:8px; border:1px solid var(--border-soft); background:linear-gradient(90deg, <?= htmlspecialchars($program['palette_start']) ?>, <?= htmlspecialchars($program['palette_end']) ?>);"></span>
      </p>
    <?php endif; ?>

    <div class="checks" style="margin-top:8px;">
      <label><input type="checkbox" name="is_premium" <?= $program['is_premium'] ? 'checked' : '' ?>> مدفوع (بريميوم)</label>
      <label><input type="checkbox" name="is_active" <?= $program['is_active'] ? 'checked' : '' ?>> نشط</label>
    </div>

    <button type="submit" style="margin-top: 14px;">💾 حفظ التعديلات</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;">أيام البرنامج <small style="color:var(--text-muted); font-weight:500;">(<?= count($days) ?>)</small></h2>

  <?php foreach ($days as $d): ?>
    <div id="day-<?= $d['id'] ?>" style="background: var(--surface); border:1px solid var(--border-soft); border-radius: 12px; padding: 14px 16px; margin-bottom: 12px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="op" value="update_day">
        <input type="hidden" name="id" value="<?= $d['id'] ?>">
        <input type="hidden" name="program_id" value="<?= $program['id'] ?>">

        <div style="display:grid; grid-template-columns: 80px 1fr 100px; gap: 12px; align-items: end;">
          <p><label>اليوم</label><input type="number" name="day_number" value="<?= (int)$d['day_number'] ?>" required></p>
          <p style="margin:0;"><label>عنوان اليوم</label><input type="text" name="title_ar" value="<?= htmlspecialchars($d['title_ar']) ?>" required></p>
          <p><label>دقائق</label><input type="number" name="duration_min" value="<?= (int)$d['duration_min'] ?>"></p>
        </div>
        <p><label>محتوى اليوم</label><textarea name="body_ar" rows="2"><?= htmlspecialchars($d['body_ar'] ?? '') ?></textarea></p>
        <div style="display:flex; justify-content: space-between; align-items: center; gap: 12px;">
          <label><input type="checkbox" name="is_locked" <?= $d['is_locked'] ? 'checked' : '' ?>> مغلق (للمشتركين)</label>
          <button type="submit" class="btn-sm">💾 حفظ</button>
        </div>
      </form>

      <form method="post" onsubmit="return confirm('حذف اليوم <?= (int)$d['day_number'] ?>؟')" style="margin-top: 8px; text-align: end;">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="op" value="delete_day">
        <input type="hidden" name="id" value="<?= $d['id'] ?>">
        <input type="hidden" name="program_id" value="<?= $program['id'] ?>">
        <button type="submit" class="btn-danger btn-sm">🗑 حذف اليوم</button>
      </form>
    </div>
  <?php endforeach; ?>

  <h3 style="margin-top:24px;">إضافة يوم جديد</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="op" value="create_day">
    <input type="hidden" name="program_id" value="<?= $program['id'] ?>">

    <div style="display:grid; grid-template-columns: 80px 1fr 100px; gap: 12px; align-items: end;">
      <p>
        <label>اليوم</label>
        <input type="number" name="day_number" value="<?= count($days) + 1 ?>" required>
      </p>
      <p style="margin:0;"><label>عنوان اليوم</label><input type="text" name="title_ar" required></p>
      <p><label>دقائق</label><input type="number" name="duration_min" value="3"></p>
    </div>
    <p><label>محتوى اليوم</label><textarea name="body_ar" rows="2"></textarea></p>
    <div style="display:flex; justify-content: space-between; align-items:center;">
      <label><input type="checkbox" name="is_locked"> مغلق (للمشتركين)</label>
      <button type="submit">➕ إضافة اليوم</button>
    </div>
  </form>
</div>
