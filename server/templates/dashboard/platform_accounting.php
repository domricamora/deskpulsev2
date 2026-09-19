<?php
/** Super-admin Accounting: revenue, invoices, payments, receivables + the Wise connection.
 *  Expects: $ctx,$period,$period_date,$collected,$collected_all,$outstanding,$unmatched_sum,
 *           $mrr,$mix,$receivables,$chart_labels,$chart_values,$invoices,$payments,
 *           $unmatched,$orgs,$wise,$wise_url,$wise_events */
$periods = period_options(['week', 'pay', 'month']);
$cents = fn ($c) => e(money(((int) $c) / 100, 'USD'));
$day   = fn ($d) => $d ? e(date('j M Y', strtotime($d))) : '—';
$tokenSet = $wise['api_token'] !== '';
?>
<?= period_switch_html($periods, $period, $period_date, '/app/platform/accounting', [],
      '<a class="ghost right" href="' . e(url('/app/platform/accounting.csv?' . period_qs($period, $period_date)))
      . '">Export payments CSV</a>') ?>

<div class="stat-row">
  <div class="stat"><span class="lbl">Collected</span><b><?= $cents($collected) ?></b>
    <span class="sub"><?= e($ctx['label']) ?></span></div>
  <div class="stat"><span class="lbl">MRR</span><b><?= e(money($mrr, 'USD')) ?></b>
    <span class="sub">active + comped tenants</span></div>
  <div class="stat <?= $outstanding > 0 ? 'alert' : '' ?>"><span class="lbl">Outstanding</span>
    <b><?= $cents($outstanding) ?></b><span class="sub">open invoices</span></div>
  <div class="stat <?= $unmatched_sum > 0 ? 'alert' : '' ?>"><span class="lbl">Unassigned deposits</span>
    <b><?= $cents($unmatched_sum) ?></b><span class="sub"><?= count($unmatched) ?> payment(s)</span></div>
  <div class="stat"><span class="lbl">Collected all time</span><b><?= $cents($collected_all) ?></b></div>
</div>

<div class="panel">
  <h3>Revenue — last 12 months</h3>
  <canvas class="dp-chart" height="220" data-type="bars"
    data-labels='<?= e(json_encode($chart_labels)) ?>'
    data-series='<?= e(json_encode([['name' => 'Collected (USD)', 'data' => $chart_values, 'color' => '#3b82f6']])) ?>'></canvas>
  <p class="muted small">Settled payments only — money actually received and reconciled, not invoiced.</p>
</div>

<!-- ── Payment method: direct bank transfer (live) ──────────────────── -->
<div class="panel">
  <div class="panel-head">
    <h3>Payment method — direct bank transfer</h3>
    <span class="status approved">live</span>
  </div>
  <p class="muted">Customers see these account details on their Subscription page together with a
     unique <code>DP-&lt;id&gt;-XXXX</code> reference. When a transfer lands, record it against that
     organization — from <b>Deposits needing assignment</b> below, or with <b>Record a payment</b>
     on the organization's subscription page. Either settles its open invoice and extends the paid
     period.</p>
  <p class="muted small"><b>The automated gateway integrations are archived</b> (bottom of this
     page). Nothing calls Wise or Stripe, and the Wise webhook endpoint returns <code>410 Gone</code>,
     so a stale subscription can't file phantom credits into the ledger.</p>

  <form method="post" action="<?= e(url('/app/platform/accounting')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_bank">
    <label class="check"><input type="checkbox" name="pay_enabled" <?= $wise['enabled'] ? 'checked' : '' ?>>
      Collect subscription payments (turns on trials and the paywall)</label>

    <div class="inline">
      <label>Account name
        <input type="text" name="wise_usd_holder" value="<?= e($wise['usd_details']['holder'] ?? '') ?>"
               placeholder="Name on the receiving account"></label>
      <label>Account type
        <input type="text" name="wise_usd_type" value="<?= e($wise['usd_details']['account_type'] ?? '') ?>"
               placeholder="Checking"></label>
      <label>Account number
        <input type="text" name="wise_usd_account" value="<?= e($wise['usd_details']['account_number'] ?? '') ?>"></label>
    </div>
    <div class="inline">
      <label>Routing number <small class="muted">(US wire &amp; ACH)</small>
        <input type="text" name="wise_usd_routing" value="<?= e($wise['usd_details']['routing'] ?? '') ?>"></label>
      <label>SWIFT / BIC <small class="muted">(international)</small>
        <input type="text" name="wise_usd_swift" value="<?= e($wise['usd_details']['swift'] ?? '') ?>"></label>
    </div>
    <label>Bank name and address
      <input type="text" name="wise_usd_bank" value="<?= e($wise['usd_details']['bank'] ?? '') ?>"
             placeholder="Wise US Inc, 108 W 13th St, Wilmington, DE, 19801, United States"></label>
    <label>Extra address line <small class="muted">(optional)</small>
      <input type="text" name="wise_usd_address" value="<?= e($wise['usd_details']['address'] ?? '') ?>"></label>
    <button class="btn" type="submit">Save bank transfer details</button>
  </form>

  <?php if (!empty($wise['usd_details']['account_number'])): ?>
    <h4 style="margin-top:1.2rem">What the customer sees</h4>
    <dl class="kv">
      <?php if (!empty($wise['usd_details']['holder'])): ?>
        <dt>Account name</dt><dd><?= e($wise['usd_details']['holder']) ?></dd><?php endif; ?>
      <dt>Account number</dt><dd><code><?= e($wise['usd_details']['account_number']) ?></code></dd>
      <?php if (!empty($wise['usd_details']['routing'])): ?>
        <dt>Routing (US)</dt><dd><code><?= e($wise['usd_details']['routing']) ?></code></dd><?php endif; ?>
      <?php if (!empty($wise['usd_details']['swift'])): ?>
        <dt>SWIFT/BIC (international)</dt><dd><code><?= e($wise['usd_details']['swift']) ?></code></dd><?php endif; ?>
    </dl>
    <p class="muted small">Plus their own payment reference, which is unique per organization.</p>
  <?php else: ?>
    <div class="tutorial"><b>No account details saved yet.</b> Until they are, the Subscription page
      tells customers the details are coming and shows only their payment reference.</div>
  <?php endif; ?>
</div>

<?php $pendingClaims = array_values(array_filter($claims ?? [], fn ($c) => $c['status'] === 'pending')); ?>
<?php if ($claims ?? []): ?>
<div class="panel">
  <div class="panel-head">
    <h3>Payment notifications from customers</h3>
    <?php if ($pendingClaims): ?><span class="badge"><?= count($pendingClaims) ?> awaiting review</span><?php endif; ?>
  </div>
  <p class="muted">What customers say they've sent. <b>Check it against the bank before confirming</b> —
     this is an unverified claim, not money. Confirming records the payment, settles the open invoice
     and extends the paid period; nothing here touches the ledger until you do.</p>
  <table class="data">
    <thead><tr><th>Submitted</th><th>Customer</th><th>Amount</th><th>Sent on</th><th>Method</th>
      <th>From</th><th>Reference</th><th>Receipt</th><th>Status</th><th>Review</th></tr></thead>
    <tbody>
    <?php foreach ($claims as $c): ?>
      <tr>
        <td data-sort="<?= e((string) $c['created_at']) ?>"><?= tlocal($c['created_at'], 'date') ?>
          <?php if (!empty($c['submitter'])): ?>
            <br><small class="muted"><?= e($c['submitter']) ?></small><?php endif; ?></td>
        <td><a href="<?= e(url('/app/platform/subscription/' . (int) $c['org_id'])) ?>"><?= e($c['org_name']) ?></a></td>
        <td data-sort="<?= (int) $c['amount_cents'] ?>">
          <b><?= e(money(((int) $c['amount_cents']) / 100, $c['currency'] ?: 'USD')) ?></b></td>
        <td data-sort="<?= e((string) $c['paid_on']) ?>">
          <?= $c['paid_on'] ? e(date('j M Y', strtotime($c['paid_on']))) : '—' ?></td>
        <td class="small"><?= e(($claim_methods ?? [])[$c['method']] ?? $c['method']) ?></td>
        <td class="small"><?= e($c['sender_name'] ?? '') ?: '—' ?>
          <?php if (!empty($c['sender_bank'])): ?><br><small class="muted"><?= e($c['sender_bank']) ?></small><?php endif; ?></td>
        <td class="small trunc"><?= e($c['reference'] ?? '') ?: '—' ?></td>
        <td><?php if (!empty($c['receipt_path'])): ?>
            <a class="lnk" target="_blank" href="<?= e(url('/app/payment-receipt/' . (int) $c['id'])) ?>">view</a>
          <?php else: ?>—<?php endif; ?></td>
        <td data-sort="<?= e($c['status']) ?>">
          <span class="status <?= $c['status'] === 'confirmed' ? 'approved'
                : ($c['status'] === 'rejected' ? 'rejected' : 'pending') ?>"><?= e($c['status']) ?></span></td>
        <td>
          <?php if ($c['status'] === 'pending'): ?>
            <details>
              <summary class="lnk">review</summary>
              <form method="post" action="<?= e(url('/app/platform/accounting')) ?>" class="stack" style="margin-top:.4rem">
                <?= csrf_field() ?>
                <input type="hidden" name="claim_id" value="<?= (int) $c['id'] ?>">
                <label>Amount actually received
                  <input type="number" name="amount" step="0.01" min="0"
                         value="<?= e(number_format(((int) $c['amount_cents']) / 100, 2, '.', '')) ?>">
                  <small class="muted">Adjust if the bank credited a different amount (fees, FX).</small></label>
                <label>Note <input type="text" name="review_note" maxlength="200"
                       placeholder="Shown to the customer"></label>
                <div class="actions-row">
                  <button class="btn sm" type="submit" name="action" value="confirm_claim">Confirm</button>
                  <button class="btn sm danger" type="submit" name="action" value="reject_claim">Reject</button>
                </div>
              </form>
            </details>
          <?php else: ?>
            <small class="muted"><?= $c['reviewed_at'] ? tlocal($c['reviewed_at'], 'date') : '' ?>
              <?= !empty($c['review_note']) ? '<br>' . e($c['review_note']) : '' ?></small>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($unmatched): ?>
<div class="panel">
  <h3>Deposits needing assignment <span class="badge"><?= count($unmatched) ?></span></h3>
  <p class="muted">Money arrived without a reference DeskPulse could match to an organization.
     Assign one to settle its invoice and extend its subscription.</p>
  <table class="data">
    <thead><tr><th>Received</th><th>Amount</th><th>Reference as sent</th><th>Provider</th><th>Assign to</th></tr></thead>
    <tbody>
    <?php foreach ($unmatched as $p): ?>
      <tr>
        <td data-sort="<?= e((string) $p['occurred_at']) ?>"><?= tlocal($p['occurred_at']) ?></td>
        <td data-sort="<?= (int) $p['amount_cents'] ?>">
          <?= e(money(((int) $p['amount_cents']) / 100, $p['currency'] ?: 'USD')) ?></td>
        <td class="small trunc"><?= e($p['reference_raw'] ?? '') ?: '—' ?></td>
        <td><span class="tag"><?= e($p['provider']) ?></span></td>
        <td>
          <form method="post" action="<?= e(url('/app/platform/accounting')) ?>" class="row-form" style="gap:.35rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign_payment">
            <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
            <select name="org_id" style="width:190px">
              <option value="">Choose…</option>
              <?php foreach ($orgs as $o): ?>
                <option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn sm" type="submit">Assign</button>
          </form>
          <form method="post" action="<?= e(url('/app/platform/accounting')) ?>" class="inline-form"
                onsubmit="return confirm('Discard this deposit record? The money is not affected.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="void_payment">
            <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
            <button class="lnk danger" type="submit">discard</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($receivables): ?>
<div class="panel">
  <h3>Receivables &amp; lapsed subscriptions</h3>
  <table class="data">
    <thead><tr><th>Customer</th><th>State</th><th>Due / cycle</th><th>Open invoices</th><th>Paid until</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($receivables as $r): $o = $r['org']; ?>
      <tr>
        <td><a href="<?= e(url('/app/platform/subscription/' . (int) $o['id'])) ?>"><b><?= e($o['name']) ?></b></a></td>
        <td><span class="status <?= sub_is_current($o) ? 'approved' : 'rejected' ?>"><?= e($r['state']) ?></span></td>
        <td data-sort="<?= e(number_format($r['due'], 2, '.', '')) ?>"><?= e(money($r['due'], $o['billing_currency'] ?: 'USD')) ?></td>
        <td data-sort="<?= (int) $r['open_cents'] ?>"><?= $r['open_cents'] ? $cents($r['open_cents']) : '—' ?></td>
        <td data-sort="<?= e((string) $o['current_period_end']) ?>"><?= $day($o['current_period_end']) ?></td>
        <td><a class="lnk" href="<?= e(url('/app/platform/subscription/' . (int) $o['id'])) ?>">manage</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="two-col">
  <div class="panel">
    <h3>Payments — <?= e($ctx['label']) ?></h3>
    <?php if (!$payments): ?>
      <div class="empty-state">No payments in this period.</div>
    <?php else: ?>
    <table class="data">
      <thead><tr><th>Received</th><th>Customer</th><th>Amount</th><th>Via</th><th>Reference</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td data-sort="<?= e((string) $p['occurred_at']) ?>"><?= tlocal($p['occurred_at'], 'date') ?></td>
          <td><?= e($p['org_name'] ?? '') ?: '<span class="muted">(unassigned)</span>' ?></td>
          <td data-sort="<?= (int) $p['amount_cents'] ?>">
            <?= e(money(((int) $p['amount_cents']) / 100, $p['currency'] ?: 'USD')) ?></td>
          <td><span class="tag"><?= e($p['provider']) ?></span></td>
          <td class="small muted trunc"><?= e($p['wise_reference_code'] ?: ($p['reference_raw'] ?: ($p['note'] ?? ''))) ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Invoices — <?= e($ctx['label']) ?></h3>
    <?php if (!$invoices): ?>
      <div class="empty-state">No invoices issued in this period.</div>
    <?php else: ?>
    <table class="data">
      <thead><tr><th>Reference</th><th>Customer</th><th>Amount</th><th>Status</th><th>Issued</th></tr></thead>
      <tbody>
      <?php foreach ($invoices as $i): ?>
        <tr>
          <td class="small"><?= e($i['reference']) ?></td>
          <td><?= e($i['org_name'] ?? '—') ?></td>
          <td data-sort="<?= (int) $i['amount_cents'] ?>"><?= $cents($i['amount_cents']) ?></td>
          <td><span class="status <?= $i['status'] === 'paid' ? 'approved'
                : ($i['status'] === 'void' ? 'rejected' : 'pending') ?>"><?= e($i['status']) ?></span></td>
          <td data-sort="<?= e((string) $i['issued_at']) ?>"><?= tlocal($i['issued_at'], 'date') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <h3>Subscription mix</h3>
  <div class="stat-row">
    <?php foreach (['active' => 'Active', 'trialing' => 'On trial', 'past_due' => 'Past due',
                    'canceled' => 'Canceled', 'none' => 'No subscription'] as $k => $lbl): ?>
      <div class="stat <?= ($k === 'past_due' && !empty($mix[$k])) ? 'alert' : '' ?>">
        <span class="lbl"><?= e($lbl) ?></span><b><?= (int) ($mix[$k] ?? 0) ?></b></div>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($wise_events): ?>
<div class="panel">
  <h3>Recent Wise deliveries</h3>
  <p class="muted small">Every event Wise has delivered, de-duplicated. Useful for confirming the
     webhook is actually reaching this server.</p>
  <table class="data">
    <thead><tr><th>When</th><th>Event</th><th>Delivery id</th></tr></thead>
    <tbody>
    <?php foreach ($wise_events as $ev): ?>
      <tr>
        <td data-sort="<?= e((string) $ev['created_at']) ?>"><?= tlocal($ev['created_at']) ?></td>
        <td><span class="tag"><?= e($ev['type'] ?: 'unknown') ?></span></td>
        <td class="small muted trunc"><?= e($ev['event_id']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── Archived integrations ────────────────────────────────────────── -->
<details class="panel">
  <summary><b>Archived payment integrations</b> — Wise API and Stripe (not in use)</summary>
  <p class="muted" style="margin-top:.6rem">Kept so they can be switched back on, but nothing calls
     them today. <b>Stripe</b> was never finished and its module now lives in
     <code>server/src/archive/stripe.php</code> — not loaded, no route. <b>Wise API</b>
     reconciliation is below; while the payment method is <i>direct bank transfer</i> the webhook
     at <code><?= e($wise_url) ?></code> returns <code>410 Gone</code>.</p>

  <form method="post" action="<?= e(url('/app/platform/accounting')) ?>" class="row-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="set_pay_method">
    <label>Payment method <select name="pay_method">
      <option value="bank" <?= ($wise['pay_method'] ?? 'bank') === 'bank' ? 'selected' : '' ?>>
        Direct bank transfer (manual reconciliation)</option>
      <option value="wise_api" <?= ($wise['pay_method'] ?? '') === 'wise_api' ? 'selected' : '' ?>>
        Wise API (webhook + statement sync)</option>
    </select></label>
    <button class="btn sm ghost" type="submit">Change method</button>
  </form>
  <hr style="border:0;border-top:1px solid var(--line);margin:1rem 0">


  <h4>Wise API — archived</h4>
  <p class="muted small">Credentials and connection state, kept for a future switch back.
     None of this runs while the payment method is direct bank transfer.</p>

  <div class="stat-row">
    <div class="stat"><span class="lbl">Environment</span><b><?= e(strtoupper($wise['env'])) ?></b></div>
    <div class="stat <?= $tokenSet ? '' : 'alert' ?>"><span class="lbl">API token</span>
      <b><?= $tokenSet ? 'stored' : 'not set' ?></b>
      <span class="sub"><?= $tokenSet ? 'encrypted at rest' : 'required for sync' ?></span></div>
    <div class="stat"><span class="lbl">Last sync</span>
      <b><?= $wise['last_sync_at'] ? tlocal($wise['last_sync_at'], 'date') : '—' ?></b>
      <span class="sub"><?= $wise['last_error'] ? 'last attempt failed' : 'no errors' ?></span></div>
    <?php $ks = wise_key_status(); ?>
    <div class="stat <?= $ks['state'] === 'ok' ? '' : 'alert' ?>"><span class="lbl">Webhook signature</span>
      <b><?= $ks['state'] === 'ok' ? 'verified' : 'unverified' ?></b>
      <span class="sub"><?= e($ks['label']) ?> · <?= e($ks['detail']) ?></span></div>
  </div>

  <?php if (!empty($wise['last_error'])): ?>
    <div class="tutorial"><b>Last Wise error:</b> <?= e($wise['last_error']) ?></div>
  <?php endif; ?>

  <h4>Webhook endpoint</h4>
  <p class="muted">Give this URL to Wise (Settings → Webhooks), or use <b>Register webhook</b> below
     to create the subscription over the API. Wise only delivers to <b>HTTPS</b>.</p>
  <div class="row-form" style="align-items:center">
    <input type="text" readonly value="<?= e($wise_url) ?>" class="grow"
           onclick="this.select()" aria-label="Wise webhook URL">
    <button type="button" class="btn sm ghost copy-link" data-link="<?= e($wise_url) ?>">copy</button>
  </div>
  <p class="muted small">Event to subscribe to: <code>balances#credit</code> (delivery version 2.0.0).
     Deliveries are verified with Wise's RSA public key and de-duplicated on the
     <code>X-Delivery-Id</code> header, so a retry can never extend a subscription twice.</p>

  <form method="post" action="<?= e(url('/app/platform/accounting')) ?>" class="stack" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_wise">
    <label class="check"><input type="checkbox" name="pay_enabled" <?= $wise['enabled'] ? 'checked' : '' ?>>
      Collect subscription payments (turns on trials and the paywall)</label>
    <div class="inline">
      <label>Environment <select name="wise_env">
        <option value="sandbox" <?= $wise['env'] === 'sandbox' ? 'selected' : '' ?>>Sandbox (testing)</option>
        <option value="live" <?= $wise['env'] === 'live' ? 'selected' : '' ?>>Live</option>
      </select></label>
      <label>API token
        <input type="text" name="wise_api_token" autocomplete="off"
               placeholder="<?= $tokenSet ? e(dp_mask_secret($wise['api_token'])) . ' — leave blank to keep' : 'paste your Wise API token' ?>">
        <small class="muted">Stored encrypted (AES-256-GCM). Blank keeps the current token.</small></label>
    </div>
    <div class="inline">
      <label>Profile ID <input type="text" name="wise_profile_id" value="<?= e($wise['profile_id']) ?>"></label>
      <label>USD balance ID <input type="text" name="wise_balance_id" value="<?= e($wise['balance_id']) ?>"></label>
    </div>
    <label>Webhook public key (PEM)
      <textarea name="wise_webhook_key" rows="5" spellcheck="false"
        placeholder="-----BEGIN PUBLIC KEY-----&#10;MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A...&#10;-----END PUBLIC KEY-----"><?= e($wise['webhook_key']) ?></textarea>
      <small class="muted">
        <b>This key belongs to Wise — you don't generate it.</b> Wise signs every delivery
        with their private key and publishes the matching public key for each environment
        (<b><?= e(strtoupper($wise['env'])) ?></b> here). Copy it from Wise's webhook
        documentation and paste it whole, including the BEGIN/END lines. Sandbox and
        production use <b>different</b> keys — a production key will not verify sandbox
        deliveries. Pasting a key you made yourself would reject every genuine delivery.
        The key is checked when you save, so a bad paste is refused rather than failing
        later on a real payment.
      </small></label>

    <p class="muted small">The account details customers see are edited in the live
       <b>direct bank transfer</b> panel above, not here.</p>
    <button class="btn" type="submit">Save Wise API settings</button>
  </form>

  <h4 style="margin-top:1.4rem">Strong Customer Authentication (your keypair)</h4>
  <p class="muted">A <b>different key from the one above.</b> Wise protects sensitive endpoints —
     balance statements included — by answering <code>403</code> with a one-time
     <code>x-2fa-approval</code> token. DeskPulse signs that token with the private key below
     and replays the request automatically. Here <b>you own the private key</b> and upload the
     <b>public</b> half to Wise; the webhook key above is the reverse.
     <b>Statement sync will fail until this is set up.</b></p>

  <?php if ($wise['sca_public']): ?>
    <p class="muted small">Generated <?= tlocal($wise['sca_created'], 'datetime') ?>.
      Private key held encrypted; only the public half is shown.</p>
    <label>Your public key — paste this into Wise (Settings → API tokens → Manage public keys)
      <textarea rows="7" readonly spellcheck="false" onclick="this.select()"
        style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.78rem"><?= e($wise['sca_public']) ?></textarea></label>
    <div class="actions-row">
      <button type="button" class="btn sm ghost copy-link" data-link="<?= e($wise['sca_public']) ?>">copy public key</button>
      <a class="btn sm ghost" href="<?= e(url('/app/platform/sca-public-key.pem')) ?>">download .pem</a>
      <form method="post" action="<?= e(url('/app/platform/accounting')) ?>"
            onsubmit="return confirm('Generate a NEW keypair? The key currently uploaded to Wise stops working until you upload the new public key.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="gen_sca">
        <button class="lnk" type="submit">regenerate</button>
      </form>
      <form method="post" action="<?= e(url('/app/platform/accounting')) ?>"
            onsubmit="return confirm('Remove the stored SCA keypair?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="clear_sca">
        <button class="lnk danger" type="submit">remove</button>
      </form>
    </div>
  <?php else: ?>
    <div class="tutorial"><b>No SCA keypair yet.</b> Generate one, then upload the public key to
      Wise under <b>Settings → API tokens → Manage public keys</b>. The private key is stored
      encrypted on this server and never leaves it.</div>
    <form method="post" action="<?= e(url('/app/platform/accounting')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="gen_sca">
      <button class="btn" type="submit">Generate SCA keypair</button>
    </form>
  <?php endif; ?>

  <div class="actions-row" style="margin-top:.9rem">
    <form method="post" action="<?= e(url('/app/platform/accounting')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="test_wise">
      <button class="btn sm ghost" type="submit">Test connection</button>
    </form>
    <form method="post" action="<?= e(url('/app/platform/accounting')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="list_balances">
      <button class="btn sm ghost" type="submit">List balances</button>
    </form>
    <form method="post" action="<?= e(url('/app/platform/accounting')) ?>" class="row-form" style="gap:.35rem">
      <?= csrf_field() ?><input type="hidden" name="action" value="sync_wise">
      <input type="number" name="days" value="30" min="1" max="180" style="width:74px" aria-label="Days to sync">
      <button class="btn sm" type="submit">Sync statement</button>
    </form>
    <form method="post" action="<?= e(url('/app/platform/accounting')) ?>"
          onsubmit="return confirm('Create a balances#credit subscription at Wise pointing to this server?')">
      <?= csrf_field() ?><input type="hidden" name="action" value="register_webhook">
      <button class="btn sm ghost" type="submit">Register webhook</button>
    </form>
    <?php if ($tokenSet): ?>
      <form method="post" action="<?= e(url('/app/platform/accounting')) ?>"
            onsubmit="return confirm('Remove the stored Wise API token?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="clear_token">
        <button class="lnk danger" type="submit">remove token</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($wise['webhook_id']): ?>
    <p class="muted small">Registered subscription id: <code><?= e($wise['webhook_id']) ?></code></p>
  <?php endif; ?>
</details>
