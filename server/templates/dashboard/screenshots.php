<div class="panel">
  <h3>Screenshots</h3>
  <form method="get" action="<?= e(url('/app/screenshots')) ?>" class="row-form" style="margin-bottom:1rem">
    <?php if (!empty($is_manager)): ?>
      <label>Member
        <select name="user_id">
          <option value="0">Everyone</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= (int) $m['id'] ?>" <?= $filter_user === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <label>Day (UTC) <input type="date" name="date" value="<?= e($filter_date) ?>"></label>
    <button class="btn" type="submit">Filter</button>
    <?php if ($filter_user || $filter_date): ?>
      <a class="ghost" href="<?= e(url('/app/screenshots')) ?>">Clear</a>
    <?php endif; ?>
  </form>

  <?php if ($shots): ?>
    <div class="shot-grid">
      <?php foreach ($shots as $sc): ?>
        <a href="<?= e(url('/uploads/' . $sc['file_path'])) ?>" target="_blank">
          <img src="<?= e(url('/uploads/' . $sc['file_path'])) ?>" loading="lazy" alt="screenshot">
          <span><?= e($sc['user_name']) ?> · <?= tlocal($sc['ts'], 'datetime') ?><?php
            if (!empty($sc['monitor'])): ?> · <span class="tag">Monitor <?= (int) $sc['monitor'] ?></span><?php endif; ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="muted">No screenshots
      <?= ($filter_user || $filter_date) ? 'match this filter.' : 'captured yet. They appear here once a desktop agent uploads them during a tracked session.' ?></p>
  <?php endif; ?>
</div>
