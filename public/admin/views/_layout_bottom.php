<?php $loggedIn = !empty($_SESSION['admin_id']); ?>
<?php if ($loggedIn): ?>
  </main>
</div>
<?php else: ?>
</div>
<?php endif; ?>
</body></html>
