<div class="card">
  <h2 style="margin-top:0;">عدد الاستشارات المجانية في كل باقة</h2>
  <p style="color:var(--text-muted); margin:0 0 12px;">
    العدد الذي يُمنح للمستخدم تلقائيًا عند تفعيل الاشتراك (يُكتب في
    <code>sessions_total</code> و <code>sessions_remaining</code> عند تنشيط
    الاشتراك). تعديله هنا يطبّق على كل اشتراك جديد بعد الحفظ — الاشتراكات
    الحالية تحتفظ بأرصدتها الحالية.
  </p>
  <?php if (!empty($saved)): ?>
    <div style="background:rgba(34,197,94,0.10); border:1px solid rgba(34,197,94,0.35); color:#0a8a3a; padding:10px 14px; border-radius:8px; margin-bottom:14px;">
      ✓ تم حفظ الإعدادات.
    </div>
  <?php endif; ?>
  <form method="post" action="?action=subscriptions">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf()) ?>">
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:16px;">
      <div>
        <label style="display:block; color:var(--text-muted); font-size:13px; margin-bottom:6px;">
          الباقة المجانية — جلسات / شهر
        </label>
        <input
          type="number" name="plan_free_sessions_per_month" min="0"
          value="<?= (int)($planFreePerMonth ?? 0) ?>"
          style="width:100%; font-size:18px; font-weight:700; text-align:center;"
        >
        <small style="color:var(--text-muted);">٠ = لا يستطيع الحساب المجاني الحجز</small>
      </div>
      <div>
        <label style="display:block; color:var(--text-muted); font-size:13px; margin-bottom:6px;">
          الباقة الأسبوعية — جلسات / اشتراك
        </label>
        <input
          type="number" name="plan_weekly_sessions" min="0"
          value="<?= (int)($planWeekly ?? 0) ?>"
          style="width:100%; font-size:18px; font-weight:700; text-align:center;"
        >
        <small style="color:var(--text-muted);">الافتراضي في config: <?= (int)($cfgWeekly ?? 0) ?></small>
      </div>
      <div>
        <label style="display:block; color:var(--text-muted); font-size:13px; margin-bottom:6px;">
          الباقة الشهرية — جلسات / اشتراك
        </label>
        <input
          type="number" name="plan_monthly_sessions" min="0"
          value="<?= (int)($planMonthly ?? 1) ?>"
          style="width:100%; font-size:18px; font-weight:700; text-align:center;"
        >
        <small style="color:var(--text-muted);">الافتراضي في config: <?= (int)($cfgMonthly ?? 1) ?></small>
      </div>
      <div>
        <label style="display:block; color:var(--text-muted); font-size:13px; margin-bottom:6px;">
          الباقة السنوية — جلسات / شهر (×١٢)
        </label>
        <input
          type="number" name="plan_yearly_sessions_per_month" min="0"
          value="<?= (int)($planYearlyPerMonth ?? 2) ?>"
          style="width:100%; font-size:18px; font-weight:700; text-align:center;"
        >
        <small style="color:var(--text-muted);">
          الإجمالي السنوي: <strong><?= (int)($planYearlyPerMonth ?? 2) * 12 ?></strong> جلسة
          · الافتراضي: <?= (int)($cfgYearlyPerMonth ?? 2) ?>
        </small>
      </div>
    </div>
    <div style="margin-top:14px;">
      <button type="submit" name="op" value="save_plan_limits" class="btn">حفظ الإعدادات</button>
    </div>
    <p style="color:var(--text-muted); font-size:12px; margin-top:14px; line-height:1.7;">
      • <strong>المجانية</strong> تُحتسب شهريًا حسب التقويم على جلسات تحمل
      <code>paid_with='free'</code> (لا يحتاج اشتراكًا).<br>
      • <strong>الأسبوعية / الشهرية / السنوية</strong> تُمنح كرصيد ثابت في
      <code>sessions_total</code> عند تفعيل الاشتراك. تعديل القيمة يطبّق
      على التفعيلات الجديدة فقط — الرصيد الحالي للمشتركين الحاليين لا يتغيّر.
    </p>
  </form>
</div>

<?php
$tab          = $tab          ?? 'all';
$filterPlan   = $filterPlan   ?? '';
$filterStatus = $filterStatus ?? '';
$filterQ      = $filterQ      ?? '';
$counts       = $counts       ?? ['paid' => 0, 'free' => 0, 'total' => 0];

$tabHref = function (string $key) use ($filterPlan, $filterStatus, $filterQ): string {
  $q = ['action' => 'subscriptions', 'tab' => $key];
  if ($filterPlan   !== '') $q['plan']   = $filterPlan;
  if ($filterStatus !== '') $q['status'] = $filterStatus;
  if ($filterQ      !== '') $q['q']      = $filterQ;
  return '?' . http_build_query($q);
};
?>
<div class="card">
  <h2 style="margin-top:0;">الاشتراكات</h2>

  <!-- Tabs ─ all / paid / free -->
  <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px;">
    <a href="<?= htmlspecialchars($tabHref('all')) ?>"
       class="<?= $tab === 'all' ? 'btn' : 'btn-ghost' ?> btn-sm">
      الكل <small style="opacity:.7;">(<?= (int)$counts['total'] ?>)</small>
    </a>
    <a href="<?= htmlspecialchars($tabHref('paid')) ?>"
       class="<?= $tab === 'paid' ? 'btn' : 'btn-ghost' ?> btn-sm">
      المدفوعة <small style="opacity:.7;">(<?= (int)$counts['paid'] ?>)</small>
    </a>
    <a href="<?= htmlspecialchars($tabHref('free')) ?>"
       class="<?= $tab === 'free' ? 'btn' : 'btn-ghost' ?> btn-sm">
      المجانية <small style="opacity:.7;">(<?= (int)$counts['free'] ?>)</small>
    </a>
  </div>

  <!-- Filter row -->
  <form method="get" action="?action=subscriptions"
        style="display:grid; grid-template-columns: 1fr 180px 180px auto; gap:8px; margin-bottom:14px;">
    <input type="hidden" name="action" value="subscriptions">
    <input type="hidden" name="tab"    value="<?= htmlspecialchars($tab) ?>">
    <input type="text" name="q" placeholder="ابحث بالاسم أو البريد"
           value="<?= htmlspecialchars($filterQ) ?>">
    <select name="plan">
      <option value="">كل الباقات</option>
      <?php foreach (['free','weekly','monthly','yearly','lifetime'] as $p): ?>
        <option value="<?= $p ?>" <?= $filterPlan === $p ? 'selected' : '' ?>>
          <?= htmlspecialchars($p) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="status">
      <option value="">كل الحالات</option>
      <?php foreach (['active','trial','expired','canceled','grace'] as $st): ?>
        <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>>
          <?= htmlspecialchars($st) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-ghost btn-sm">تصفية</button>
  </form>

  <?php if (empty($rows)): ?>
    <p style="color:var(--text-muted); text-align:center; padding:24px 0;">
      لا توجد اشتراكات تطابق الفلتر الحالي.
    </p>
  <?php else: ?>
  <table>
    <thead><tr><th>#</th><th>المستخدم</th><th>الباقة</th><th>الحالة</th><th>المتجر</th><th>الانتهاء</th><th>جلسات متبقية</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= htmlspecialchars($r['user_name']) ?> <small>(<?= htmlspecialchars($r['email']) ?>)</small></td>
        <td><?= htmlspecialchars($r['plan']) ?></td>
        <td><span class="badge b-<?= $r['status']==='trial'?'trial':($r['status']==='active'?'active':'inactive') ?>"><?= htmlspecialchars($r['status']) ?></span></td>
        <td><?= htmlspecialchars($r['store']) ?></td>
        <td><?= htmlspecialchars($r['expires_at'] ?? '-') ?></td>
        <td><?= $r['sessions_remaining'] ?> / <?= $r['sessions_total'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
