<?php
/** Messages, reminders, notices and the email log.
 *  Expects: $members, $teams, $outbox, $notices, $counts, $preview, $reminders,
 *           $tokens, $mail_ready, $roles */
$p = $preview;
?>
<?php if (!$mail_ready): ?>
  <div class="tutorial"><b>No email transport configured.</b> Messages will queue but never send.
     Set <code>smtp_host</code>, <code>smtp_port</code>, <code>smtp_secure</code>,
     <code>smtp_user</code>, <code>smtp_pass</code>, <code>mail_from</code> and
     <code>mail_enabled = true</code> in <code>server/config.php</code>
     (see <code>config.example.php</code>), then use <b>Send test to myself</b> below.</div>
<?php endif; ?>

<div class="stat-row">
  <div class="stat"><div class="lbl">Queued</div><div><?= (int) ($counts['queued'] ?? 0) ?></div></div>
  <div class="stat"><div class="lbl">Sent</div><div><?= (int) ($counts['sent'] ?? 0) ?></div></div>
  <div class="stat <?= !empty($counts['failed']) ? 'alert' : '' ?>">
    <div class="lbl">Failed</div><div><?= (int) ($counts['failed'] ?? 0) ?></div></div>
  <div class="stat"><div class="lbl">Recipients available</div><div><?= count($members) ?></div>
    <div class="sub">client logins excluded</div></div>
</div>

<?php if ($p): ?>
<div class="panel">
  <h3>Preview — nothing sent yet</h3>
  <p class="muted">This is how the first recipient's copy will read.
     <b><?= (int) $p['count'] ?></b> message(s) will be queued.
     <?php if ($p['skipped']): ?><br><b>Skipped:</b> <?= e(implode('; ', array_slice($p['skipped'], 0, 10))) ?>.<?php endif; ?></p>
  <div class="panel" style="background:var(--card-2)">
    <div class="muted small">To: <?= e($p['to']) ?></div>
    <h4 style="margin:.3rem 0"><?= e($p['subject']) ?></h4>
    <div style="white-space:pre-wrap"><?= e($p['body']) ?></div>
  </div>
  <form method="post" action="<?= e(url('/app/messages')) ?>" style="margin-top:.8rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="send">
    <input type="hidden" name="subject" value="<?= e($p['raw_subject']) ?>">
    <textarea name="body" hidden><?= e($p['raw_body']) ?></textarea>
    <input type="hidden" name="audience" value="<?= e($p['audience']) ?>">
    <input type="hidden" name="reminder_type" value="<?= e($p['reminder_type']) ?>">
    <?php if ($p['audience'] === 'users'): foreach ((array) $p['audience_ref'] as $uid): ?>
      <input type="hidden" name="user_ids[]" value="<?= (int) $uid ?>">
    <?php endforeach; else: ?>
      <input type="hidden" name="audience_ref" value="<?= e((string) $p['audience_ref']) ?>">
    <?php endif; ?>
    <button class="btn" type="submit">Queue <?= (int) $p['count'] ?> message(s)</button>
    <a class="lnk" href="<?= e(url('/app/messages')) ?>" style="margin-left:.6rem">start over</a>
  </form>
</div>
<?php endif; ?>

<div class="two-col">
  <div class="panel">
    <h3>Compose a message or reminder</h3>
    <p class="muted">Pick a ready-made reminder — which works out its own recipients — or write a
       custom message and choose who gets it.</p>
    <form method="post" action="<?= e(url('/app/messages')) ?>" class="stack">
      <?= csrf_field() ?>
      <label>Reminder type
        <select name="reminder_type" onchange="dpRem(this)">
          <option value="">— Custom message —</option>
          <?php foreach ($reminders as $k => $r): ?>
            <option value="<?= e($k) ?>" data-subject="<?= e($r['subject']) ?>"
                    data-body="<?= e($r['body']) ?>"><?= e($r['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="muted" id="dp-rem-desc">A reminder picks its own recipients automatically.</small>
      </label>
      <div id="dp-aud">
        <div class="inline">
          <label>Send to <select name="audience" onchange="dpAud(this.value)">
            <option value="all">Everyone in the organization</option>
            <option value="role">A role</option>
            <option value="team">A team</option>
            <option value="users">Selected people</option>
          </select></label>
          <label id="dp-aud-role" hidden>Role <select name="audience_ref">
            <?php foreach ($roles as $rk => $rl): ?>
              <option value="<?= e($rk) ?>"><?= e($rl) ?></option>
            <?php endforeach; ?>
          </select></label>
          <label id="dp-aud-team" hidden>Team <select name="audience_ref">
            <?php foreach ($teams as $t): ?>
              <option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option>
            <?php endforeach; ?>
          </select></label>
        </div>
        <label id="dp-aud-users" hidden>People
          <select name="user_ids[]" multiple size="7">
            <?php foreach ($members as $m): ?>
              <option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?> — <?= e(role_label($m['role'])) ?></option>
            <?php endforeach; ?>
          </select></label>
      </div>
      <label>Subject <input type="text" name="subject" maxlength="200" required></label>
      <label>Message <textarea name="body" rows="8" required></textarea></label>
      <div class="actions-row">
        <button class="btn" type="submit" name="action" value="preview">Preview</button>
        <button class="btn ghost" type="submit" name="action" value="test">Send test to myself</button>
      </div>
    </form>
    <p class="muted small" style="margin-top:.6rem">Merge tokens:
      <?php foreach ($tokens as $tk => $desc): ?>
        <code><?= e($tk) ?></code> <span class="muted"><?= e($desc) ?></span>&nbsp;
      <?php endforeach; ?>
    </p>
  </div>

  <div class="panel">
    <h3>Publish a notice</h3>
    <p class="muted">Notices appear as a dismissible banner at the top of DeskPulse for the people
       you choose, and can optionally be emailed at the same time.</p>
    <form method="post" action="<?= e(url('/app/messages')) ?>" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="notice_create">
      <label>Title <input type="text" name="title" maxlength="160" required></label>
      <label>Body <textarea name="notice_body" rows="4" required></textarea></label>
      <div class="inline">
        <label>Importance <select name="level">
          <option value="info">Info</option><option value="success">Good news</option>
          <option value="warn">Warning</option><option value="urgent">Urgent</option>
        </select></label>
        <label>Show until <input type="date" name="ends_at"></label>
      </div>
      <div class="inline">
        <label>Audience <select name="n_audience" onchange="dpNAud(this.value)">
          <option value="all">Everyone</option><option value="role">A role</option>
          <option value="team">A team</option><option value="users">Selected people</option>
        </select></label>
        <label id="dp-naud-role" hidden>Role <select name="n_audience_ref">
          <?php foreach ($roles as $rk => $rl): ?>
            <option value="<?= e($rk) ?>"><?= e($rl) ?></option>
          <?php endforeach; ?>
        </select></label>
        <label id="dp-naud-team" hidden>Team <select name="n_audience_ref">
          <?php foreach ($teams as $t): ?>
            <option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select></label>
      </div>
      <label id="dp-naud-users" hidden>People <select name="n_user_ids[]" multiple size="6">
        <?php foreach ($members as $m): ?>
          <option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?></option>
        <?php endforeach; ?>
      </select></label>
      <label class="check"><input type="checkbox" name="also_email"> Also email this notice</label>
      <button class="btn" type="submit">Publish notice</button>
    </form>
  </div>
</div>

<?php if ($notices): ?>
<div class="panel">
  <h3>Published notices</h3>
  <table class="data">
    <thead><tr><th>Title</th><th>Level</th><th>Audience</th><th>Shown until</th><th>By</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($notices as $n): ?>
      <tr>
        <td><?= e($n['title']) ?><br><small class="muted"><?= e(mb_strimwidth($n['body'], 0, 90, '…')) ?></small></td>
        <td><span class="tag"><?= e($n['level']) ?></span></td>
        <td><?= e($n['audience']) ?><?= $n['audience_ref'] ? ' · ' . e($n['audience_ref']) : '' ?></td>
        <td><?= $n['ends_at'] ? tlocal($n['ends_at'], 'date') : 'no end date' ?></td>
        <td class="small muted"><?= e($n['author'] ?? '—') ?></td>
        <td>
          <form method="post" action="<?= e(url('/app/messages')) ?>" class="inline-form"
                onsubmit="return confirm('Remove this notice?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="notice_delete">
            <input type="hidden" name="notice_id" value="<?= (int) $n['id'] ?>">
            <button class="lnk danger" type="submit">remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <h3>Email log</h3>
    <form method="post" action="<?= e(url('/app/messages')) ?>" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="flush">
      <button class="btn sm ghost" type="submit">Send queued now</button>
    </form>
  </div>
  <?php if (!$outbox): ?>
    <div class="empty-state">Nothing sent yet.</div>
  <?php else: ?>
  <table class="data">
    <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Kind</th><th>Status</th>
      <th>Tries</th><th>Error</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($outbox as $o): ?>
      <tr>
        <td data-sort="<?= e($o['created_at']) ?>"><?= tlocal($o['created_at']) ?></td>
        <td class="small"><?= e($o['to_name'] ?: $o['to_email']) ?><br>
          <small class="muted"><?= e($o['to_email']) ?></small></td>
        <td class="trunc"><?= e($o['subject']) ?></td>
        <td><span class="tag"><?= e($o['kind']) ?></span></td>
        <td><span class="status <?= $o['status'] === 'sent' ? 'approved'
              : ($o['status'] === 'failed' ? 'rejected' : 'pending') ?>"><?= e($o['status']) ?></span></td>
        <td><?= (int) $o['attempts'] ?></td>
        <td class="small muted trunc"><?= e($o['last_error'] ?? '') ?: '—' ?></td>
        <td>
          <?php if ($o['status'] === 'failed'): ?>
            <form method="post" action="<?= e(url('/app/messages')) ?>" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="retry">
              <input type="hidden" name="email_id" value="<?= (int) $o['id'] ?>">
              <button class="lnk" type="submit">retry</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<script>
function dpAud(v){
  document.getElementById('dp-aud-role').hidden  = v !== 'role';
  document.getElementById('dp-aud-team').hidden  = v !== 'team';
  document.getElementById('dp-aud-users').hidden = v !== 'users';
}
function dpNAud(v){
  document.getElementById('dp-naud-role').hidden  = v !== 'role';
  document.getElementById('dp-naud-team').hidden  = v !== 'team';
  document.getElementById('dp-naud-users').hidden = v !== 'users';
}
function dpRem(sel){
  var opt = sel.options[sel.selectedIndex];
  var form = sel.form;
  var isReminder = sel.value !== '';
  document.getElementById('dp-aud').hidden = isReminder;
  document.getElementById('dp-rem-desc').textContent = isReminder
    ? 'Recipients are worked out automatically for this reminder.'
    : 'A reminder picks its own recipients automatically.';
  if (isReminder) {
    if (!form.subject.value) form.subject.value = opt.dataset.subject || '';
    if (!form.body.value)    form.body.value    = opt.dataset.body || '';
  }
}
</script>
