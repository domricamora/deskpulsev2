<?php
$intro = [
  'self' => 'Your personal read-only public link. Share it to show your stats &amp; graphs — no login, no salary, no screenshots.',
  'team' => 'Public read-only links for your team roster. Each shows that agent\'s stats &amp; graphs only.',
  'all'  => 'Every agent\'s public read-only link, grouped by team. Stats &amp; graphs only — no salary or screenshots.',
];
$linkCell = function (?string $url) {
    if (!$url) {
        return '<span class="muted">—</span>';
    }
    $e = e($url);
    return '<a href="' . $e . '" target="_blank">Open</a> '
         . '· <button type="button" class="lnk copy-link" data-link="' . $e . '">copy</button>';
};
?>
<p class="muted"><?= $intro[$mode] ?? $intro['self'] ?></p>

<div class="panel">
  <div class="panel-head"><h3>Your public link</h3>
    <span class="muted small">stats &amp; graphs only</span></div>
  <?php if ($self['url']): ?>
    <div class="ws-linkrow" style="align-items:center">
      <a class="btn sm ghost" href="<?= e($self['url']) ?>" target="_blank">Open my page</a>
      <button type="button" class="btn sm ghost copy-link" data-link="<?= e($self['url']) ?>">Copy link</button>
      <code class="share-url"><?= e($self['url']) ?></code>
    </div>
  <?php else: ?>
    <p class="muted">No personal link yet — it will be generated automatically.</p>
  <?php endif; ?>
</div>

<?php if ($mode !== 'self'): ?>
  <?php if (!$groups): ?>
    <div class="panel"><div class="empty-state">No agents in your scope yet.</div></div>
  <?php endif; ?>
  <?php foreach ($groups as $g): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= e($g['name']) ?></h3>
        <span class="muted small"><?= count($g['members']) ?> agent<?= count($g['members']) === 1 ? '' : 's' ?></span></div>
      <table class="data">
        <thead><tr><th>Agent</th><th>Role</th><th>Public link</th></tr></thead>
        <tbody>
          <?php foreach ($g['members'] as $m): ?>
            <tr>
              <td><b><?= e($m['name']) ?></b></td>
              <td><span class="tag"><?= e(role_label($m['role'])) ?></span></td>
              <td><?= $linkCell($m['url']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<p class="muted small">Need a custom team or org link, or want to revoke one? Manage those on the
   <a href="<?= e(url('/app/settings')) ?>">Settings</a> page.</p>
