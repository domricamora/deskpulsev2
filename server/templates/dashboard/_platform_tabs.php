<?php
/** Horizontal sub-menu for the platform management area. Expects $active. */
$tabs = [
    'platform_orgs'     => ['Organizations', url('/app/platform/orgs')],
    'platform_accounts' => ['Accounts',      url('/app/platform/accounts')],
    'platform_billing'  => ['Billing',       url('/app/platform/billing')],
    'platform_settings' => ['Settings',      url('/app/platform/settings')],
];
?>
<nav class="subnav">
  <?php foreach ($tabs as $key => [$label, $href]): ?>
    <a class="<?= ($active ?? '') === $key ? 'on' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
