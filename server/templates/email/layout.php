<?php
/** Shared HTML shell for outbound email.
 *  Expects: $org, $heading, $body_html; optional $cta_url, $cta_label.
 *  Styles are inline because email clients strip <style> blocks. */
$logo = !empty($org['logo_path'])
    ? rtrim((string) public_base_url(), '/') . url('/uploads/' . $org['logo_path'])
    : null;
$orgName = $org['name'] ?? 'DeskPulse';
?>
<div style="margin:0;padding:24px 12px;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0">
    <tr>
      <td style="padding:20px 26px;border-bottom:3px solid #2563eb">
        <?php if ($logo): ?>
          <img src="<?= e($logo) ?>" alt="<?= e($orgName) ?>" height="34"
               style="display:block;max-height:34px;border:0">
        <?php else: ?>
          <div style="font-size:19px;font-weight:800;letter-spacing:-.02em"><?= e($orgName) ?></div>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <td style="padding:26px">
        <h1 style="margin:0 0 14px;font-size:19px;line-height:1.35;font-weight:700;color:#0f172a">
          <?= e($heading ?? '') ?></h1>
        <div style="font-size:14.5px;line-height:1.65;color:#334155">
          <?= $body_html ?? '' ?>
        </div>
        <?php if (!empty($cta_url)): ?>
          <p style="margin:24px 0 0">
            <a href="<?= e($cta_url) ?>"
               style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;
                      padding:11px 20px;border-radius:6px;font-weight:600;font-size:14px">
              <?= e($cta_label ?? 'Open DeskPulse') ?></a>
          </p>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <td style="padding:16px 26px;background:#f8fafc;border-top:1px solid #e2e8f0;
                 font-size:12px;line-height:1.5;color:#64748b">
        Sent by <?= e($orgName) ?> via DeskPulse.
        <?php if (!empty($cta_url)): ?><br>If the button doesn't work, paste this into your browser:<br>
          <span style="word-break:break-all"><?= e($cta_url) ?></span><?php endif; ?>
      </td>
    </tr>
  </table>
</div>
