<?php
$agentRows = function (array $agents): void { ?>
  <table class="data agent-list"><tbody>
  <?php foreach ($agents as $a): ?>
    <tr>
      <td><b><?= e($a['name']) ?></b></td>
      <td><span class="tag"><?= e(role_label($a['role'])) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
<?php }; ?>

<p class="muted">All clients across every customer organization. Expand a client to view the
   agents who have logged time under it.</p>

<?php if (!$orgs): ?>
  <div class="panel"><div class="empty-state">No customer organizations yet.</div></div>
<?php endif; ?>

<?php foreach ($orgs as $g): $o = $g['org']; ?>
  <div class="panel">
    <div class="panel-head">
      <h3><?= e($o['name']) ?></h3>
      <span class="muted small"><?= count($g['clients']) ?> client<?= count($g['clients']) == 1 ? '' : 's' ?></span>
    </div>
    <?php if (!$g['clients']): ?>
      <div class="empty-state">No clients yet.</div>
    <?php endif; ?>
    <?php foreach ($g['clients'] as $c): $agents = $g['by_client'][$c['id']] ?? []; ?>
      <details class="org-group">
        <summary>
          <b><?= e($c['name']) ?></b>
          <?php if ($c['archived']): ?><span class="status rejected">archived</span><?php endif; ?>
          <span class="muted">· <?= count($agents) ?> agent<?= count($agents) == 1 ? '' : 's' ?></span>
        </summary>
        <?php if ($agents): $agentRows($agents); else: ?>
          <p class="muted">No agents have logged time under this client yet.</p>
        <?php endif; ?>
      </details>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
