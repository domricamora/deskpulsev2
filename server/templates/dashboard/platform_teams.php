<?php
/** Render a compact agent list. */
$agentRows = function (array $agents): void { ?>
  <table class="data agent-list"><tbody>
  <?php foreach ($agents as $a): ?>
    <tr>
      <td><b><?= e($a['name']) ?></b></td>
      <td><span class="tag"><?= e(role_label($a['role'])) ?></span></td>
      <td class="muted"><?= e($a['job_title'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
<?php }; ?>

<p class="muted">All teams across every customer organization. Expand a team to view the agents under it.</p>

<?php if (!$orgs): ?>
  <div class="panel"><div class="empty-state">No customer organizations yet.</div></div>
<?php endif; ?>

<?php foreach ($orgs as $g): $o = $g['org']; ?>
  <div class="panel">
    <div class="panel-head">
      <h3><?= e($o['name']) ?></h3>
      <span class="muted small"><?= (int) $g['agent_count'] ?> agent<?= $g['agent_count'] == 1 ? '' : 's' ?>
        · <?= count($g['teams']) ?> team<?= count($g['teams']) == 1 ? '' : 's' ?></span>
    </div>
    <?php if (!$g['teams'] && !$g['unassigned']): ?>
      <div class="empty-state">No teams or agents yet.</div>
    <?php endif; ?>
    <?php foreach ($g['teams'] as $t): $mem = $g['by_team'][$t['id']] ?? []; ?>
      <details class="org-group">
        <summary><b><?= e($t['name']) ?></b>
          <span class="muted">· <?= count($mem) ?> agent<?= count($mem) == 1 ? '' : 's' ?></span></summary>
        <?php if ($mem): $agentRows($mem); else: ?>
          <p class="muted">No agents on this team.</p>
        <?php endif; ?>
      </details>
    <?php endforeach; ?>
    <?php if ($g['unassigned']): ?>
      <details class="org-group">
        <summary><b>Unassigned</b>
          <span class="muted">· <?= count($g['unassigned']) ?> agent<?= count($g['unassigned']) == 1 ? '' : 's' ?> not on a team</span></summary>
        <?php $agentRows($g['unassigned']); ?>
      </details>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
