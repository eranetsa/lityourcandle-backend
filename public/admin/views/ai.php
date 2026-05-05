<div class="card">
  <h2>شمعة AI — الاستخدام</h2>
  <div class="stats" style="grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));">
    <div class="stat"><div class="n"><?= number_format((int)($stats['total'] ?? 0)) ?></div><div class="l">إجمالي المحادثات</div></div>
    <div class="stat"><div class="n"><?= number_format((int)($stats['today'] ?? 0)) ?></div><div class="l">اليوم</div></div>
    <div class="stat"><div class="n"><?= number_format((int)($stats['escalated'] ?? 0)) ?></div><div class="l">تصعيد لمستشار</div></div>
    <div class="stat"><div class="n"><?= number_format((int)($stats['tokens_in'] ?? 0)) ?></div><div class="l">tokens in</div></div>
    <div class="stat"><div class="n"><?= number_format((int)($stats['tokens_out'] ?? 0)) ?></div><div class="l">tokens out</div></div>
  </div>
</div>

<div class="card">
  <h3>برومبت شمعة AI (System Prompt)</h3>
  <p style="color:var(--text-muted); margin:0 0 12px;">
    النص الذي يُرسل لـ Claude كـ <code>system</code> في كل طلب AI داخل التطبيق.
    اتركه فارغًا لاستخدام البرومبت الافتراضي (المعروض كـ placeholder).
  </p>
  <?php if (!empty($saved)): ?>
    <div style="background:#E8F5E9; border:1px solid #1B5E20; color:#1B5E20; padding:10px 14px; border-radius:8px; margin-bottom:14px;">
      ✓ تم حفظ البرومبت. سيستخدمه التطبيق فورًا في الطلب التالي.
    </div>
  <?php endif; ?>
  <form method="post" action="?action=ai">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf()) ?>">
    <textarea
      name="prompt"
      dir="rtl"
      style="width:100%; min-height:340px; padding:14px; font-family:'SF Mono', monospace; font-size:13px; line-height:1.6; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text); resize:vertical;"
      placeholder="<?= htmlspecialchars($defaultPrompt ?? '') ?>"><?= htmlspecialchars($currentPrompt ?? '') ?></textarea>
    <div style="display:flex; gap:10px; margin-top:14px; justify-content:flex-start;">
      <button type="submit" name="op" value="save_prompt" class="btn">حفظ البرومبت</button>
      <button type="submit" name="op" value="reset_prompt" class="btn btn-ghost"
        onclick="return confirm('استعادة البرومبت الافتراضي؟ سيُحذف النص الحالي.')">
        استعادة الافتراضي
      </button>
    </div>
  </form>
  <details style="margin-top:18px;">
    <summary style="cursor:pointer; color:var(--text-muted);">عرض البرومبت الافتراضي</summary>
    <pre style="margin-top:10px; padding:14px; background:var(--bg); border:1px solid var(--border); border-radius:8px; white-space:pre-wrap; direction:rtl; font-size:12px; line-height:1.7;"><?= htmlspecialchars($defaultPrompt ?? '') ?></pre>
  </details>
</div>

<div class="card">
  <h3>أحدث 50 محادثة</h3>
  <table>
    <thead><tr><th>#</th><th>المستخدم</th><th>المزاج</th><th>الرسالة</th><th>تصعيد؟</th><th>التاريخ</th></tr></thead>
    <tbody>
      <?php foreach ($latest as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= $r['user_id'] ?></td>
        <td><?= htmlspecialchars($r['mood'] ?? '—') ?></td>
        <td style="max-width:480px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars(mb_substr((string)$r['user_message'], 0, 140)) ?></td>
        <td><?= $r['escalated'] ? '<span class="badge b-trial">نعم</span>' : '<span class="badge b-mute">لا</span>' ?></td>
        <td style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars($r['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
