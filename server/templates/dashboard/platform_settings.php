<?php include __DIR__ . '/_platform_tabs.php'; ?>

<?php
$num = fn($v) => number_format((float) $v, 2, '.', '');
$capSeats = plan_cap_seats($prices);
$floor = (float) $prices['per_seat'] * (int) $prices['seats_bill_min'];
?>
<div class="panel">
  <h3>Price ladder</h3>
  <p class="muted">The published prices, shown on the marketing site and used to bill tenants.
     Each tenant is charged its plan's price less its own percentage discount
     (set on the <a href="<?= e(url('/app/platform/billing')) ?>">Billing</a> page). Prices are in the platform currency.</p>
  <form method="post" action="<?= e(url('/app/platform/settings')) ?>" class="stack"><?= csrf_field() ?>
    <input type="hidden" name="action" value="set_prices">
    <div class="price-fields">
      <label>Solo price / mo
        <input type="number" name="price_solo" min="0" step="0.01"
               value="<?= e($num($prices['solo'])) ?>" style="width:120px">
        <small class="muted">0 = free</small>
      </label>
      <label>Individual price / mo
        <input type="number" name="price_individual" min="0" step="0.01"
               value="<?= e($num($prices['individual'])) ?>" style="width:120px">
      </label>
      <label>Team price / seat / period
        <input type="number" name="price_per_seat" min="0" step="0.01"
               value="<?= e($num($prices['per_seat'] ?? 0)) ?>" style="width:120px">
      </label>
      <label>Team minimum seats
        <input type="number" name="seats_bill_min" min="0" step="1"
               value="<?= e((int) $prices['seats_bill_min']) ?>" style="width:90px">
        <small class="muted">0 = no minimum</small>
      </label>
      <label>Cap — bill never exceeds
        <input type="number" name="price_seat_cap" min="0" step="0.01"
               value="<?= e($num($prices['seat_cap'])) ?>" style="width:120px">
        <small class="muted">0 = uncapped</small>
      </label>
      <label>Cap covers up to
        <input type="number" name="seats_cap_covers" min="0" step="1"
               value="<?= e((int) $prices['seats_cap_covers']) ?>" style="width:90px">
        <small class="muted">seats (display only)</small>
      </label>
      <label>Organization price / period
        <input type="number" name="price_organization" min="0" step="0.01"
               value="<?= e($num($prices['organization'])) ?>" style="width:120px">
        <small class="muted">legacy flat plan</small>
      </label>
      <label>Billing period
        <span style="display:inline-flex;gap:.35rem;align-items:center">
          <input type="number" name="billing_period_count" min="1" step="1"
                 value="<?= e((int) ($period['count'] ?? 1)) ?>" style="width:70px">
          <select name="billing_period_unit" style="width:auto">
            <option value="month" <?= ($period['unit'] ?? 'month') === 'month' ? 'selected' : '' ?>>month(s)</option>
            <option value="day" <?= ($period['unit'] ?? 'month') === 'day' ? 'selected' : '' ?>>day(s)</option>
          </select>
        </span>
      </label>
      <label>Marketing seat range — min
        <input type="number" name="seats_min" min="1" step="1"
               value="<?= e((int) ($prices['seats_min'] ?? 2)) ?>" style="width:90px">
      </label>
      <label>Marketing seat range — max
        <input type="number" name="seats_max" min="1" step="1"
               value="<?= e((int) ($prices['seats_max'] ?? 50)) ?>" style="width:90px">
      </label>
    </div>
    <p class="muted small">
      <b>Currently:</b>
      <?php if ((int) $prices['seats_bill_min'] > 0): ?>
        Team starts at <?= e($num($prices['per_seat'])) ?> ×
        <?= (int) $prices['seats_bill_min'] ?> seats = <b><?= e($num($floor)) ?></b>.
      <?php else: ?>
        Team has no seat minimum.
      <?php endif; ?>
      <?php if ($capSeats > 0): ?>
        The cap engages at <b><?= (int) $capSeats ?> seats</b>
        (<?= e($num($prices['seat_cap'])) ?> ÷ <?= e($num($prices['per_seat'])) ?>)<?=
          (int) $prices['seats_cap_covers'] > 0
            ? ' and covers up to ' . (int) $prices['seats_cap_covers'] . ' seats' : '' ?>.
      <?php else: ?>
        <b>No cap is set</b> — a per-seat bill grows without limit.
      <?php endif; ?>
    </p>
    <p class="muted small">Saving re-computes every tenant's effective monthly fee
       (base × (1 − discount)), per organization — a per-seat fee depends on that
       organization's own seat count.</p>
    <div><button class="btn" type="submit">Save prices</button></div>
  </form>
</div>

<div class="panel">
  <h3>Analytics</h3>
  <p class="muted">Measurement IDs for the public marketing site. Both are optional — leave blank
     and nothing is loaded. The snippet never renders for signed-in users, so internal traffic
     stays out of the numbers.</p>
  <form method="post" action="<?= e(url('/app/platform/settings')) ?>" class="stack"><?= csrf_field() ?>
    <input type="hidden" name="action" value="set_analytics">
    <div class="price-fields">
      <label>GA4 measurement ID
        <input type="text" name="ga4_measurement_id" maxlength="24" placeholder="G-XXXXXXXXXX"
               value="<?= e($analytics['ga4'] ?? '') ?>" style="width:180px">
      </label>
      <label>Microsoft Clarity project ID
        <input type="text" name="clarity_project_id" maxlength="24" placeholder="abcdefghij"
               value="<?= e($analytics['clarity'] ?? '') ?>" style="width:180px">
      </label>
    </div>
    <p class="muted small">A value that doesn't match the provider's ID format is rejected rather
       than saved — these are interpolated into a &lt;script&gt; on a public page.</p>
    <div><button class="btn" type="submit">Save analytics</button></div>
  </form>
</div>

<div class="panel">
  <h3>Free trial</h3>
  <p class="muted">How many days a newly registered organization can use DeskPulse before a
     payment is required. Applies to signups from here on; set to 0 to require payment immediately.</p>
  <form method="post" action="<?= e(url('/app/platform/settings')) ?>" class="stack"><?= csrf_field() ?>
    <input type="hidden" name="action" value="set_trial">
    <label>Trial length (days)
      <input type="number" name="trial_days" min="0" max="365" step="1"
             value="<?= e((int) $trial_days) ?>" style="width:100px">
    </label>
    <div><button class="btn" type="submit">Save trial length</button></div>
  </form>
</div>

<div class="panel">
  <h3>Screenshot retention</h3>
  <p class="muted">How long captured screenshots are kept before being permanently deleted
     (files and records), across every organization. Older screenshots are cleaned up
     automatically. Currently storing <b><?= (int) $shots_total ?></b> screenshot<?= $shots_total == 1 ? '' : 's' ?>.</p>
  <form method="post" action="<?= e(url('/app/platform/settings')) ?>" class="stack"><?= csrf_field() ?>
    <label>Keep screenshots for
      <input type="number" name="screenshot_retention_days" min="0" max="3650"
             value="<?= (int) $retention ?>" style="width:90px"> days
    </label>
    <p class="muted small">Set to <b>0</b> to keep screenshots forever (not recommended — disk usage grows unbounded).</p>
    <div><button class="btn" type="submit">Save settings</button></div>
  </form>
</div>

<div class="panel">
  <h3>Email</h3>
  <p class="muted">Applies to every organization on the platform: payslips, reminders,
     notices, and the internal signup / subscription alerts. Leave a field blank to fall
     back to whatever <code>server/config.php</code> specifies.</p>

  <div class="stat-row">
    <div class="stat"><span class="lbl">Status</span>
      <b><?= $mail['enabled'] ? 'Sending' : 'Disabled' ?></b>
      <span class="sub"><?= e($mail['label']) ?></span></div>
    <div class="stat"><span class="lbl">Queued</span><b><?= (int) $mail['queued'] ?></b></div>
    <div class="stat <?= $mail['failed'] ? 'alert' : '' ?>">
      <span class="lbl">Failed</span><b><?= (int) $mail['failed'] ?></b></div>
    <div class="stat"><span class="lbl">Overridden here</span>
      <b><?= count($mail['overrides']) ?></b>
      <span class="sub">of 6 settings</span></div>
  </div>

  <form method="post" action="<?= e(url('/app/platform/settings')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="set_mail">
    <div class="inline">
      <label>Send from address
        <input type="email" name="mail_from" value="<?= e($mail['from']) ?>"
               placeholder="support@deskpulse.click">
        <small class="muted">The From: on every outgoing message.</small></label>
      <label>Send from name
        <input type="text" name="mail_from_name" maxlength="120" value="<?= e($mail['from_name']) ?>"
               placeholder="DeskPulse"></label>
    </div>
    <div class="inline">
      <label>Reply-to address
        <input type="email" name="mail_reply_to" value="<?= e($mail['reply_to']) ?>"
               placeholder="support@deskpulse.click">
        <small class="muted">Where replies from customers land.</small></label>
      <label>Notification address
        <input type="email" name="mail_notify" value="<?= e($mail['notify']) ?>"
               placeholder="sales@deskpulse.click">
        <small class="muted">Gets an alert on every new signup and subscription event.</small></label>
    </div>
    <div class="inline">
      <label>Transport
        <select name="mail_transport">
          <option value="mail" <?= $mail['transport'] === 'mail' ? 'selected' : '' ?>>
            PHP mail() — the server's own MTA</option>
          <option value="smtp" <?= $mail['transport'] === 'smtp' ? 'selected' : '' ?>>
            SMTP — credentials from config.php</option>
        </select>
        <small class="muted">PHP mail() does not work on a local WAMP box; use SMTP for local testing.</small></label>
      <label class="check" style="align-self:end;margin-bottom:1rem">
        <input type="checkbox" name="mail_override_enabled" value="1"
               <?= array_key_exists('mail_enabled', $mail['overrides']) ? 'checked' : '' ?>>
        Control sending from here</label>
      <label class="check" style="align-self:end;margin-bottom:1rem">
        <input type="checkbox" name="mail_enabled" value="1" <?= $mail['enabled'] ? 'checked' : '' ?>>
        Sending enabled</label>
    </div>
    <button class="btn" type="submit">Save email settings</button>
  </form>

  <form method="post" action="<?= e(url('/app/platform/settings')) ?>" class="row-form" style="margin-top:.9rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mail_test">
    <label class="grow">Send a test message to
      <input type="email" name="test_to" value="<?= e($user['email']) ?>"></label>
    <button class="btn ghost" type="submit">Send test now</button>
  </form>
  <p class="muted small">The test is delivered immediately rather than queued, so any transport
     error is reported straight back to you here.</p>
</div>

<div class="panel">
  <h3>Export database</h3>
  <p class="muted">Download a full SQL dump of the entire platform database (schema + data for
     every organization). Useful for backups or migrating servers. The file is written in
     plain SQL and is <b>compatible with older MySQL / MariaDB versions</b> (MySQL 8.0
     collations are downgraded automatically), so it restores anywhere.</p>
  <p class="muted small">Screenshots are stored as files on disk, not in the database — keep a
     copy of the <code>uploads/</code> folder too for a complete backup.</p>
  <a class="btn" href="<?= e(url('/app/platform/export.sql')) ?>"
    >
     ⬇ Download SQL dump</a>
</div>
