<?php
/**
 * Column-naming guide for a file import. Rendered from the registry in
 * helpers.php (import_specs()), so the guide, the header matching and the
 * downloadable template can never drift apart.
 *
 * Expects: $spec (from import_spec()), $spec_key.
 */
if (empty($spec)) { return; }
?>
<details class="panel import-guide" <?= !empty($guide_open) ? 'open' : '' ?>>
  <summary><b>Column headers &amp; naming rules</b> — what this file needs to contain</summary>

  <p class="muted"><?= e($spec['intro']) ?></p>

  <ul class="plain small">
    <li><b>Accepted formats:</b> <?= e($spec['formats']) ?></li>
    <li><b>Header names are matched loosely.</b> Case, spaces, underscores, hyphens and
        punctuation are all ignored — <code>Wise Recipient ID</code>, <code>wise_recipient_id</code>
        and <code>WISE-RECIPIENT-ID</code> are treated as the same column.</li>
    <li><b>Column order does not matter</b>, and extra columns you don't need are ignored.</li>
    <li><b>The header row does not have to be row&nbsp;1</b> — blank rows, titles and merged
        banners above the table are skipped automatically.</li>
    <li><b>Matching:</b> <?= e($spec['match']) ?></li>
  </ul>

  <table class="data" data-nofilter>
    <thead><tr><th>Column header</th><th>Also accepted</th><th>Required</th><th>Example</th><th>What it does</th></tr></thead>
    <tbody>
      <?php foreach ($spec['columns'] as $col): ?>
        <tr>
          <td><code><?= e($col['name']) ?></code></td>
          <td class="small muted">
            <?= $col['aliases'] ? e(implode(', ', $col['aliases'])) : '—' ?>
          </td>
          <td><?= !empty($col['required'])
                ? '<span class="status rejected">required</span>'
                : '<span class="tag">optional</span>' ?></td>
          <td class="small"><?= e($col['example'] ?? '') ?></td>
          <td class="small muted"><?= e($col['desc'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p style="margin-top:.7rem">
    <a class="btn sm ghost" href="<?= e(url('/app/import/template/' . $spec_key)) ?>">
      Download a blank template (.csv)</a>
    <small class="muted" style="margin-left:.5rem">Correct headers plus one example row —
      open it in Excel, replace the example, and save as .csv or .xlsx.</small>
  </p>
</details>
