  <div class="panel">
    <h3>Desktop agent</h3>
    <p class="muted">Install the DeskPulse desktop app and sign in with your DeskPulse
       email &amp; password. It registers a device automatically and starts tracking when
       you press <b>Start</b>.</p>
    <p><b>Server URL to enter in the app:</b><br>
      <code><?= e($public_base) ?></code></p>
    <h4>Registered devices</h4>
    <ul class="plain">
      <?php foreach ($devices as $d): ?>
        <li class="dev-row">
          <span><?= e($d['name']) ?> · <small class="muted">last seen
            <?= $d['last_seen'] ? tlocal($d['last_seen'], 'datetime') : 'never' ?></small></span>
          <form method="post" action="<?= e(url('/app/settings')) ?>" style="display:inline"
                onsubmit="return confirm('Remove this device? The desktop app on it will have to sign in again.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="device_delete">
            <input type="hidden" name="device_id" value="<?= (int) $d['id'] ?>">
            <button class="lnk danger" type="submit">remove</button>
          </form>
        </li>
      <?php endforeach; ?>
      <?php if (!$devices): ?><li class="muted">No devices yet.</li><?php endif; ?>
    </ul>
  </div>

<?php if (!empty($is_admin)): ?>
<div class="panel">
  <h3>Company branding <span class="tag">admin</span></h3>
  <p class="muted">Upload your company logo. It's shown across the dashboard for everyone
     in your organization — admins, managers, agents and client logins — and on the
     desktop agent. Images are converted to WebP and resized automatically (PNG, JPG,
     GIF or WebP; max 8&nbsp;MB).</p>
  <?php if (!empty($org_logo)): ?>
    <div class="logo-preview"><img src="<?= e($org_logo) ?>" alt="Current company logo"></div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/app/settings')) ?>" class="row-form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_logo">
    <label class="grow">Logo image <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" required></label>
    <button class="btn" type="submit"><?= !empty($org_logo) ? 'Replace logo' : 'Upload logo' ?></button>
  </form>
  <?php if (!empty($org_logo)): ?>
    <form method="post" action="<?= e(url('/app/settings')) ?>" style="margin-top:.5rem"
          onsubmit="return confirm('Remove the company logo?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="remove_logo">
      <button class="lnk danger" type="submit">remove logo</button>
    </form>
  <?php endif; ?>
</div>

<div class="panel">
  <h3>Reporting &amp; payroll period <span class="tag">admin</span></h3>
  <p class="muted">Work is recorded in UTC and shown to each person in their own local time.
     These settings decide the <b>clock every report window is cut in</b> — which sessions
     count as "today", where a week starts, and how your pay periods are chopped up.</p>
  <form method="post" action="<?= e(url('/app/settings')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="period_policy">
    <div class="inline">
      <label>Reporting timezone
        <select name="report_tz">
          <?php foreach (timezone_identifiers_list() as $tzid): ?>
            <option value="<?= e($tzid) ?>" <?= ($period_cfg['tz'] === $tzid) ? 'selected' : '' ?>><?= e($tzid) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="muted">Day/week/month boundaries are calculated here.</small></label>
      <label>Week starts on
        <select name="week_start">
          <?php foreach ([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as $n => $lbl): ?>
            <option value="<?= $n ?>" <?= ((int) $period_cfg['week_start'] === $n) ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select></label>
    </div>
    <div class="inline">
      <label>Payroll cycle
        <select name="pay_cycle">
          <?php foreach ([
              'semimonthly' => 'Semi-monthly — 1st–15th and 16th–end of month',
              'rolling15'   => 'Every 15 days (fixed blocks from an anchor date)',
              'weekly'      => 'Weekly',
              'biweekly'    => 'Every 14 days (fixed blocks from an anchor date)',
              'monthly'     => 'Monthly',
          ] as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= ($period_cfg['pay_cycle'] === $k) ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="muted">Drives the <b>Pay period</b> pill on Payroll, Payslip and the salary run.</small></label>
      <label>Cycle anchor date
        <input type="date" name="pay_cycle_anchor" value="<?= e((string) ($period_cfg['pay_cycle_anchor'] ?? '')) ?>">
        <small class="muted">Only used by the 15-day / 14-day cycles — the first day of any period.</small></label>
      <label>Payroll currency
        <input type="text" name="pay_currency" maxlength="8" value="<?= e($period_cfg['pay_currency']) ?>"></label>
    </div>
    <button class="btn" type="submit">Save period settings</button>
  </form>
  <p class="muted small">Current pay period: <b><?= e($period_cfg['current_label']) ?></b></p>
</div>

<div class="panel">
  <h3>Monitoring policy <span class="tag">admin</span></h3>
  <p class="muted">Set centrally for everyone in your organization. The desktop agent
     fetches this and applies it on each new tracking session, overriding local app settings.</p>
  <form method="post" action="<?= e(url('/app/settings')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="policy">
    <div class="inline">
      <label>Screenshot interval
        <input type="number" name="screenshot_interval_min" min="1" max="120"
               value="<?= (int) $policy['screenshot_interval_min'] ?>"> <small class="muted">minutes</small></label>
      <label>Idle threshold
        <input type="number" name="idle_threshold_min" min="1" max="120"
               value="<?= (int) $policy['idle_threshold_min'] ?>"> <small class="muted">minutes</small></label>
      <label>Sync interval
        <input type="number" name="sync_interval_s" min="15" max="600"
               value="<?= (int) $policy['sync_interval_s'] ?>"> <small class="muted">seconds</small></label>
    </div>
    <label class="check"><input type="checkbox" name="track_screenshots" <?= $policy['track_screenshots'] ? 'checked' : '' ?>> Capture screenshots</label>
    <label class="check"><input type="checkbox" name="screenshot_blur" <?= $policy['screenshot_blur'] ? 'checked' : '' ?>> Blur screenshots</label>
    <label class="check"><input type="checkbox" name="track_windows" <?= $policy['track_windows'] ? 'checked' : '' ?>> Track active window / apps</label>
    <label class="check"><input type="checkbox" name="track_processes" <?= $policy['track_processes'] ? 'checked' : '' ?>> Record running tasks</label>
    <button class="btn" type="submit">Save policy</button>
  </form>
</div>
<?php endif; ?>

<?php
// ── Single sign-on (per organization) ──────────────────────────────────────────
// OIDC only. SAML is deliberately unsupported — see the note at the top of
// src/oauth.php for why hand-rolling XML-DSig verification is not worth the risk.
$ssoSecretSet = !empty($sso['sso_client_secret']);
?>
<?php if ($is_admin): ?>
<div class="panel">
  <h3>Single sign-on</h3>
  <p class="muted">Let your team sign in with your own identity provider — Microsoft Entra
     ID, Google Workspace, Okta, Auth0, JumpCloud or anything else that speaks
     <b>OpenID Connect</b>. Add this redirect URI to the application you create there:</p>
  <p><code><?= e(rtrim($public_base, '/')) ?>/auth/sso/callback</code></p>

  <form method="post" action="<?= e(url('/app/settings')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="sso">
    <div class="price-fields">
      <label>Issuer URL
        <input type="url" name="sso_issuer" style="width:320px"
               placeholder="https://login.microsoftonline.com/&lt;tenant&gt;/v2.0"
               value="<?= e($sso['sso_issuer'] ?? '') ?>">
        <small class="muted">We read its <code>/.well-known/openid-configuration</code>.</small>
      </label>
      <label>Client ID
        <input type="text" name="sso_client_id" style="width:320px"
               value="<?= e($sso['sso_client_id'] ?? '') ?>">
      </label>
      <label>Client secret
        <input type="password" name="sso_client_secret" style="width:260px"
               placeholder="<?= $ssoSecretSet ? '•••••••• (stored)' : '' ?>" autocomplete="new-password">
        <small class="muted">Encrypted at rest. Leave blank to keep the current one.</small>
      </label>
      <label>Email domains
        <input type="text" name="sso_domains" style="width:260px" placeholder="acme.com, acme.co.uk"
               value="<?= e($sso['sso_domains'] ?? '') ?>">
        <small class="muted">Comma separated. People with these domains are offered SSO,
          and may be provisioned on first sign-in.</small>
      </label>
    </div>
    <label class="chk"><input type="checkbox" name="sso_enabled" value="1"
      <?= !empty($sso['sso_enabled']) ? 'checked' : '' ?>> Enable single sign-on</label>
    <label class="chk"><input type="checkbox" name="sso_enforce" value="1"
      <?= !empty($sso['sso_enforce']) ? 'checked' : '' ?>> <b>Require</b> it — block password
      sign-in for everyone in this organization</label>
    <p class="muted small"><b>Test enabling before you enforce.</b> Open a private window and
      sign in through SSO first. Enforcing a provider that is not working locks out every
      account in this workspace, including yours.</p>
    <div><button class="btn" type="submit">Save single sign-on</button></div>
  </form>
</div>
<?php endif; ?>

<?php if (!empty($oauth_providers) || !empty($identities)): ?>
<div class="panel">
  <h3>Connected sign-in accounts</h3>
  <?php if ($identities): ?>
    <table class="data" data-nofilter>
      <thead><tr><th>Provider</th><th>Account</th><th>Linked</th><th>Last used</th></tr></thead>
      <tbody>
        <?php foreach ($identities as $i): ?>
          <tr>
            <td><?= e(ucfirst($i['provider'])) ?></td>
            <td><?= e($i['email'] ?: '—') ?></td>
            <td><?= tlocal($i['created_at'], 'date') ?></td>
            <td><?= $i['last_login_at'] ? tlocal($i['last_login_at'], 'date') : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted">You sign in with a password. Link a provider to sign in with one click.</p>
  <?php endif; ?>
  <?php $linked = array_column($identities, 'provider'); ?>
  <div class="actions-row">
    <?php foreach ($oauth_providers as $k => $p): ?>
      <?php if (!in_array($k, $linked, true)): ?>
        <a class="btn sm ghost" href="<?= e(url('/auth/' . $k)) ?>">Link <?= e($p['label']) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
