<?php
/**
 * The price ladder. Shared by the landing page and /pricing so there is exactly one
 * rendering of what we charge.
 *
 * Every figure is derived from plan_base_prices() — nothing here is typed. "65 seats"
 * is plan_cap_seats(), not a literal, so changing the cap or the seat price on Platform
 * settings moves the whole page, the JSON-LD, /llms.txt and the calculator together.
 *
 * Expects $prices; optional $trial_days, $compact.
 */
$capSeats = plan_cap_seats($prices);
$minSeats = max(1, (int) $prices['seats_bill_min']);
$floor    = plan_seat_price($minSeats, $prices);
$cap      = (float) $prices['seat_cap'];
$covers   = (int) $prices['seats_cap_covers'];
$m        = fn($v) => money_short((float) $v);
$compact  = $compact ?? false;

$plans = [
    [
        'key'   => 'solo',
        'name'  => 'Solo',
        'price' => $prices['solo'] > 0 ? $m($prices['solo']) : 'Free',
        'per'   => $prices['solo'] > 0 ? '/mo' : 'forever',
        'desc'  => 'Track your own time and see where it goes.',
        'seats' => '1 user',
        'feats' => ['Automatic time, activity &amp; idle tracking', 'Apps, windows &amp; time-per-task',
                    'Last 7 days of history', 'No screenshots'],
        'cta'   => 'Start free', 'featured' => false,
    ],
    [
        'key'   => 'individual',
        'name'  => 'Individual',
        'price' => $m($prices['individual']),
        'per'   => '/mo',
        'desc'  => 'For freelancers and solo operators billing clients.',
        'seats' => '1 user',
        'feats' => ['Everything in Solo, unlimited history', 'Screenshots &amp; activity timeline',
                    'Client billing &amp; invoicing data', 'CSV export &amp; shareable summaries'],
        'cta'   => 'Start free trial', 'featured' => false,
    ],
    [
        'key'   => 'per_seat',
        'name'  => 'Team',
        'price' => $m($prices['per_seat']),
        'per'   => '/seat/mo',
        'desc'  => 'For BPOs and outsourcing teams that need roles, billing and oversight.',
        'seats' => $minSeats . ' seat minimum — from ' . $m($floor) . '/mo',
        'feats' => ['Everything, for your whole team', 'Live view, approvals &amp; 7 capability roles',
                    'Client billing, contracts &amp; payroll', 'Audit log, devices &amp; central policy'],
        'cta'   => 'Start free trial', 'featured' => true,
    ],
];
if ($cap > 0) {
    $plans[] = [
        'key'   => 'per_seat',
        'name'  => 'Organization',
        'price' => $m($cap),
        'per'   => '/mo',
        'desc'  => 'The Team plan, once your bill stops growing.',
        'seats' => 'Automatic at ' . $capSeats . '+ seats'
            . ($covers > 0 ? ' · covers up to ' . $covers : ''),
        'feats' => ['Everything in Team', 'Your bill never exceeds this',
                    'Hire without re-budgeting', 'Priority support'],
        'cta'   => 'Start free trial', 'featured' => false,
    ];
}
$plans[] = [
    'key'   => '',
    'name'  => 'Enterprise',
    'price' => 'Custom',
    'per'   => '',
    'desc'  => 'Above ' . ($covers > 0 ? $covers : 100) . ' seats, or with procurement requirements.',
    'seats' => 'SSO, SLA, DPA, on-prem options',
    'feats' => ['Everything in Organization', 'Security review &amp; DPA',
                'Onboarding support', 'Custom terms'],
    'cta'   => 'Talk to us', 'featured' => false,
];
?>
<div class="price-grid ladder">
  <?php foreach ($plans as $p): ?>
    <article class="price-card<?= $p['featured'] ? ' featured' : '' ?>" data-reveal>
      <?php if ($p['featured']): ?><span class="price-badge">Most popular</span><?php endif; ?>
      <h3><?= e($p['name']) ?></h3>
      <p class="price-desc"><?= e($p['desc']) ?></p>
      <div class="price"><span class="amt"><?= e($p['price']) ?></span><?php
        if ($p['per']): ?><span class="per"><?= e($p['per']) ?></span><?php endif; ?></div>
      <p class="price-seats"><?= e($p['seats']) ?></p>
      <ul class="ticks">
        <?php foreach ($p['feats'] as $f): ?><li><?= $f ?></li><?php endforeach; ?>
      </ul>
      <?php if ($p['name'] === 'Enterprise'): ?>
        <a class="btn ghost" href="<?= e(url('/contact')) ?>"><?= e($p['cta']) ?></a>
      <?php else: ?>
        <a class="btn<?= $p['featured'] ? '' : ' ghost' ?>"
           href="<?= e(url('/register?plan=' . $p['key'])) ?>"><?= e($p['cta']) ?></a>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</div>

<?php if ($cap > 0): ?>
<p class="price-cap-line" data-reveal>
  <b><?= $m($prices['per_seat']) ?> a seat until your bill reaches <?= $m($cap) ?> — that&rsquo;s
  <?= (int) $capSeats ?> seats.</b>
  Past that you pay <?= $m($cap) ?> however many people you hire<?= $covers > 0
    ? ', up to ' . (int) $covers . ' seats' : '' ?>.
</p>
<?php endif; ?>

<?php if (!$compact): ?>
<p class="price-foot muted" data-reveal>
  Every feature is included at every paid price — no screenshot upsell, no add-on modules,
  no onboarding fee, no annual lock-in.
  <?php if (!empty($trial_days)): ?>
    <?= (int) $trial_days ?>-day free trial, no credit card.
  <?php endif; ?>
  <a href="<?= e(url('/tools/cost-calculator')) ?>">Work out your own cost &rarr;</a>
</p>
<?php endif; ?>
