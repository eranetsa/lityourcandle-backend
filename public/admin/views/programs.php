<div class="card">
  <h2 style="margin-top:0;">إنشاء برنامج جديد</h2>
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
    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px;">
      <p><label>تدرّج (بداية)</label><input type="text" name="palette_start" placeholder="#FBEFD0" maxlength="7"></p>
      <p><label>تدرّج (نهاية)</label><input type="text" name="palette_end" placeholder="#F2D88E" maxlength="7"></p>
      <p><label>ترتيب العرض</label><input type="number" name="sort_order" value="0"></p>
    </div>
    <p><label><input type="checkbox" name="is_premium"> مدفوع (بريميوم)</label></p>
    <button type="submit">➕ إنشاء البرنامج</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;">البرامج</h2>
  <table>
    <thead><tr>
      <th>#</th><th>المعرف</th><th>الفئة</th><th>العنوان</th>
      <th>أيام</th><th>تدرّج</th><th>الحالة</th><th>إجراءات</th>
    </tr></thead>
    <tbody>
      <?php foreach ($programs as $p):
        $pdays = array_filter($days, fn($d) => $d['program_id'] === $p['id']); ?>
      <tr>
        <td><?= $p['id'] ?></td>
        <td><code style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars($p['slug']) ?></code></td>
        <td>
          <?= htmlspecialchars($p['category']) ?>
          <?php if (!empty($p['icon'])): ?>
            <small style="color:var(--text-muted);">· <?= htmlspecialchars($p['icon']) ?></small>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($p['title_ar']) ?></td>
        <td><?= count($pdays) ?></td>
        <td>
          <?php if (!empty($p['palette_start']) && !empty($p['palette_end'])): ?>
            <span title="<?= htmlspecialchars($p['palette_start']) ?> → <?= htmlspecialchars($p['palette_end']) ?>"
                  style="display:inline-block; width:60px; height:20px; border-radius:6px; border:1px solid rgba(255,255,255,0.1); background:linear-gradient(90deg, <?= htmlspecialchars($p['palette_start']) ?>, <?= htmlspecialchars($p['palette_end']) ?>);"></span>
          <?php else: ?>
            <span style="color:var(--text-muted);">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($p['is_active']): ?>
            <span class="badge b-active">نشط</span>
          <?php else: ?>
            <span class="badge b-inactive">موقوف</span>
          <?php endif; ?>
          <?php if ($p['is_premium']): ?>
            <span class="badge b-paid">بريميوم</span>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <a href="?action=programs&edit=<?= $p['id'] ?>" class="btn btn-sm btn-ghost">تعديل</a>
            <form method="post" class="inline">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="op" value="toggle_program_active">
              <input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn-sm btn-ghost"><?= $p['is_active'] ? 'إيقاف' : 'تفعيل' ?></button>
            </form>
            <form method="post" class="inline" onsubmit="return confirm('حذف البرنامج وكل أيامه؟')">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="op" value="delete_program">
              <input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn-danger btn-sm">حذف</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
