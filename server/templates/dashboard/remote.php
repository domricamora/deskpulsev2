<?php /** Remote desktop control console — super-admin only, unpublished. */ ?>
<div class="panel" id="remote-panel"
     data-start-url="<?= e(url('/app/remote/' . (int) $device['id'] . '/start')) ?>"
     data-base="<?= e(url('/app/remote/')) ?>"
     data-csrf="<?= e(csrf_token()) ?>">
  <div class="live-head">
    <h3><span class="live-dot"></span> Remote control</h3>
    <span class="status" id="remote-pill">Idle</span>
  </div>
  <p class="muted">
    <b><?= e($worker['name'] ?? 'Unknown user') ?></b>
    · <?= e($worker['org_name'] ?? '') ?>
    · device <?= e($device['name'] ?? 'Desktop') ?>
    · last seen <?= tlocal($device['last_seen'] ?? null, 'full') ?>
  </p>
  <p class="muted">
    Take full control of the worker's primary screen. The worker sees a visible
    "remote control active" indicator for the entire session. Control ends when you
    press Stop, when the agent stops responding, or after a period of inactivity.
  </p>

  <div class="remote-controls">
    <button type="button" class="btn" id="remote-start">Start control</button>
    <button type="button" class="btn danger" id="remote-stop" disabled>Stop</button>
  </div>

  <div class="remote-stage" tabindex="0">
    <p class="muted" id="remote-wait" hidden>Waiting for agent…</p>
    <img id="remote-frame" alt="Remote screen"
         style="max-width:100%;display:none;border-radius:8px;cursor:crosshair">
  </div>
  <p class="muted" style="margin-top:.5rem">
    Click the screen, then type or move the mouse to control the remote machine.
  </p>
</div>
<script src="<?= e(url('/assets/js/remote.js')) ?>"></script>
