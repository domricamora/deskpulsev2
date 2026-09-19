<?php
/**
 * Shared <head> for every public marketing page.
 *
 * Expects $meta from marketing_meta(). Include it between <html> and <body>:
 *
 *     <?php $meta = marketing_meta(['path' => '/pricing', 'title' => '…']);
 *           include __DIR__ . '/_head.php'; ?>
 *
 * All absolute URLs come from abs_url() inside marketing_meta(); never compose
 * public_base_url() with url() here — that applies the base path twice.
 */
$meta = $meta ?? marketing_meta();
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="google-site-verification" content="BV8uORUcXyuDFCSrFkUZ_DKFA7GpcnoRRrXNVDNmt7g">
<title><?= e($meta['title']) ?></title>
<meta name="description" content="<?= e($meta['description']) ?>">
<?php if (!empty($meta['keywords'])): ?>
<meta name="keywords" content="<?= e($meta['keywords']) ?>">
<?php endif; ?>
<meta name="robots" content="<?= e($meta['robots']) ?>">
<?php if (!empty($meta['canonical'])): ?>
<link rel="canonical" href="<?= e($meta['canonical']) ?>">
<?php endif; ?>
<link rel="icon" href="<?= e(url('/assets/img/favicon.svg')) ?>" type="image/svg+xml">
<!-- Open Graph / Twitter -->
<meta property="og:type" content="<?= e($meta['og_type']) ?>">
<meta property="og:site_name" content="DeskPulse">
<meta property="og:title" content="<?= e($meta['og_title']) ?>">
<meta property="og:description" content="<?= e($meta['og_desc']) ?>">
<meta property="og:url" content="<?= e($meta['canonical']) ?>">
<?php if (!empty($meta['og_image'])): ?>
<meta property="og:image" content="<?= e($meta['og_image']) ?>">
<meta property="og:image:width" content="<?= (int) OG_IMAGE_W ?>">
<meta property="og:image:height" content="<?= (int) OG_IMAGE_H ?>">
<meta property="og:image:type" content="image/png">
<meta property="og:image:alt" content="<?= e($meta['og_image_alt']) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="<?= e($meta['twitter_site']) ?>">
<meta name="twitter:title" content="<?= e($meta['og_title']) ?>">
<meta name="twitter:description" content="<?= e($meta['og_desc']) ?>">
<?php if (!empty($meta['og_image'])): ?>
<meta name="twitter:image" content="<?= e($meta['og_image']) ?>">
<meta name="twitter:image:alt" content="<?= e($meta['og_image_alt']) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(url('/assets/css/deskpulse.css')) ?>">
<?php foreach ($meta['jsonld'] as $block): ?>
<script type="application/ld+json">
<?= json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>

</script>
<?php endforeach; ?>
<?php include __DIR__ . '/_analytics.php'; ?>
