<div class="panel">
  <div class="live-head">
    <h3><span class="live-dot"></span> Live team activity</h3>
    <span class="muted" id="live-updated">connecting…</span>
  </div>
  <p class="muted">Everyone tracking right now, grouped by organization → team → client.
     Auto-refreshes every 15 seconds; agents sync roughly once a minute, so "current"
     reflects the most recent batch.</p>
  <div id="live-grid" class="live-sections" data-endpoint="<?= e(url('/app/live/data')) ?>"></div>
</div>
<script src="<?= e(url('/assets/js/live.js')) ?>"></script>
