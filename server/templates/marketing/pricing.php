<?php
/** /pricing — the ladder, the seat-by-seat comparison, and the objections answered. */
$capSeats = plan_cap_seats($prices);
$cap = (float) $prices['seat_cap'];
$m = fn($v) => money_short((float) $v);
$bands = [5, 10, 20, 30, 45, 65, 80, 100];
$vendors = competitor_prices();
$faqs = marketing_pricing_faqs($prices);
?><!doctype html>
<html lang="en">
<head>
<?php include __DIR__ . '/_head.php'; ?>
</head>
<body class="public landing">
<?php include __DIR__ . '/_nav.php'; ?>

<section class="hero compact">
  <span class="aurora" aria-hidden="true"></span>
  <div class="hero-in" data-reveal>
    <h1>Per-seat pricing that <span class="grad">stops</span></h1>
    <p class="lead"><?= e($m($prices['per_seat'])) ?> per seat per month. Once your bill reaches
      <?= e($m($cap)) ?> it stops there &mdash; hire another twenty agents and pay the same.
      Every feature is included at every price.</p>
  </div>
</section>

<section class="pricing" id="plans">
  <?php include __DIR__ . '/_pricing.php'; ?>
</section>

<section class="features alt" id="compare">
  <div class="section-head" data-reveal>
    <span class="eyebrow">The arithmetic</span>
    <h2>What you&rsquo;d pay, seat by seat</h2>
    <p class="sub">List prices, per seat per month, on the cheapest tier of each product that
      actually includes screenshots. Verified <?= e($vendors[0]['checked']) ?>.</p>
  </div>
  <table class="data" data-nofilter>
    <thead>
      <tr>
        <th>Seats</th>
        <th>DeskPulse</th>
        <th>per seat</th>
        <?php foreach ($vendors as $v): ?><th><?= e($v['name']) ?></th><?php endforeach; ?>
        <th>You save vs Hubstaff</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($bands as $seats): $dp = plan_seat_price($seats, $prices);
            $hs = $vendors[0]['per_seat'] * $seats; ?>
        <tr<?= $cap > 0 && $seats >= $capSeats ? ' class="row-capped"' : '' ?>>
          <td data-sort="<?= $seats ?>"><b><?= $seats ?></b></td>
          <td data-sort="<?= $dp ?>"><b><?= e($m($dp)) ?></b><?php
            if ($cap > 0 && $seats >= $capSeats): ?> <span class="tag">cap</span><?php endif; ?></td>
          <td data-sort="<?= round($dp / $seats, 2) ?>"><?= e($m(round($dp / $seats, 2))) ?></td>
          <?php foreach ($vendors as $v): ?>
            <td data-sort="<?= $v['per_seat'] * $seats ?>"><?= e($m($v['per_seat'] * $seats)) ?></td>
          <?php endforeach; ?>
          <td data-sort="<?= round((1 - $dp / $hs) * 100) ?>"><b><?= round((1 - $dp / $hs) * 100) ?>%</b></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted small" data-reveal style="margin-top:1rem">
    Competitor figures are published list prices for the named tier, checked
    <?= e($vendors[0]['checked']) ?>. They change; we re-verify quarterly and date every
    figure. Where a vendor sells analytics or screenshots as an add-on, the add-on is not
    included in the figure above &mdash; so the real gap is usually wider, not narrower.
  </p>
  <ul class="muted small" data-reveal>
    <?php foreach ($vendors as $v): ?>
      <li><b><?= e($v['name']) ?></b> — <?= e($v['tier']) ?> tier at <?= e($m($v['per_seat'])) ?>/seat.
        <?= e($v['note']) ?></li>
    <?php endforeach; ?>
  </ul>
</section>

<section class="features" id="included">
  <div class="section-head" data-reveal>
    <span class="eyebrow">No tiers</span>
    <h2>What&rsquo;s included at every paid price</h2>
    <p class="sub">Plans differ by seat count. They do not differ by feature.</p>
  </div>
  <div class="feat-grid">
    <?php foreach ([
      ['Screenshots &amp; activity', 'Interval screenshots with optional blur, activity and idle detection, and a full timeline. Not held back for a higher tier.'],
      ['Seven capability roles', 'Company admin, manager, HR, IT, employee, client viewer and platform. Access is capability-gated, not role-name guessed.'],
      ['Client billing &amp; contracts', 'Bill rate per worker or a flat monthly service charge, reported per agent and per customer.'],
      ['Payroll &amp; payslips', 'Pay runs, adjustments, paid leave, Wise payout export and PDF payslips emailed to staff.'],
      ['Audit log &amp; devices', 'Who did what, and every agent install, with a remote-control path for IT.'],
      ['Exports &amp; share links', 'CSV everywhere, public read-only summaries by token, and an API-shaped webhook ingest.'],
    ] as [$t, $d]): ?>
      <article class="card" data-reveal><h3><?= $t ?></h3><p><?= $d ?></p></article>
    <?php endforeach; ?>
  </div>
</section>

<section class="features alt" id="faq">
  <div class="section-head" data-reveal>
    <span class="eyebrow">Questions</span>
    <h2>Before you ask</h2>
  </div>
  <div class="faq-list">
    <?php foreach ($faqs as [$q, $a]): ?>
      <details class="faq-item" data-reveal>
        <summary><?= e($q) ?></summary>
        <p><?= e($a) ?></p>
      </details>
    <?php endforeach; ?>
  </div>
</section>

<section class="cta-band">
  <div class="cta-in" data-reveal>
    <h2>Work out your own number</h2>
    <p>Put your seat count in and see the year-one difference against every major vendor.</p>
    <div class="cta-row">
      <a class="btn lg" href="<?= e(url('/tools/cost-calculator')) ?>">Open the cost calculator</a>
      <a class="btn lg ghost" href="<?= e(url('/register?plan=per_seat')) ?>">Start a free trial</a>
    </div>
  </div>
</section>

<?php include __DIR__ . '/_foot.php'; ?>
<script src="<?= e(url('/assets/js/reveal.js')) ?>" defer></script>
<script src="<?= e(url('/assets/js/tables.js')) ?>" defer></script>
</body>
</html>
