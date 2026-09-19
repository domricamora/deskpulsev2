<?php
/** Password reset email. Expects $name, $link, $ttl, $ip. */
ob_start(); ?>
<p style="margin:0 0 12px">Hi <?= e($name) ?>,</p>
<p style="margin:0 0 12px">Someone asked to reset the password for the DeskPulse account
   registered to this address. Use the button below to choose a new one.</p>
<p style="margin:0 0 12px"><strong>This link is valid for <?= (int) $ttl ?> minutes</strong>
   and can only be used once.</p>
<p style="margin:0;color:#64748b;font-size:13px">
  If you didn't ask for this, you can ignore this email — your password will not change
  until the link above is used<?= $ip ? ', and nothing has been changed on your account' : '' ?>.
  <?php if ($ip): ?><br>Requested from IP <?= e($ip) ?>.<?php endif; ?>
</p>
<?php
$body_html = ob_get_clean();
echo render('email/layout', [
    'org'       => ['name' => 'DeskPulse'],
    'heading'   => 'Reset your password',
    'body_html' => $body_html,
    'cta_url'   => $link,
    'cta_label' => 'Choose a new password',
]);
