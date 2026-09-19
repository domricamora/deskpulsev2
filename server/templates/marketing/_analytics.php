<?php
/**
 * GA4 + Microsoft Clarity, rendered only when configured and only for signed-out
 * visitors (internal traffic would otherwise pollute the acquisition numbers this
 * exists to measure).
 *
 * The IDs are validated by analytics_ids() before they reach this file, so what is
 * interpolated into the <script> can only match /^G-[A-Z0-9]{4,15}$/ or
 * /^[a-z0-9]{6,15}$/i. The CSP in bootstrap.php (and its copy in public/.htaccess)
 * must allow googletagmanager.com and clarity.ms or these load into a blocked
 * request and the console fills with violations.
 */
$dpAnalytics = analytics_ids();
if (current_user() || (!$dpAnalytics['ga4'] && !$dpAnalytics['clarity'])) {
    return;
}
// Conversions that happen behind a redirect (register → 302 → /app) cannot be seen
// by a page-load tag. handle_register() and start_trial() queue them here instead,
// and this drains the queue exactly once.
$dpEvents = $_SESSION['dp_track'] ?? [];
unset($_SESSION['dp_track']);
?>
<?php if ($dpAnalytics['ga4']): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($dpAnalytics['ga4']) ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '<?= e($dpAnalytics['ga4']) ?>');
<?php foreach ($dpEvents as $ev): ?>
gtag('event', <?= json_encode((string) ($ev[0] ?? 'event')) ?>, <?= json_encode((object) ($ev[1] ?? [])) ?>);
<?php endforeach; ?>
</script>
<?php endif; ?>
<?php if ($dpAnalytics['clarity']): ?>
<script>
(function(c,l,a,r,i,t,y){
  c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
  t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
  y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
})(window, document, "clarity", "script", "<?= e($dpAnalytics['clarity']) ?>");
</script>
<?php endif; ?>
