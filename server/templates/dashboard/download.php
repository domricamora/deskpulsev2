<div class="panel">
  <h3>Download the DeskPulse desktop agent</h3>
  <p class="muted">Install the lightweight Windows app, sign in with your DeskPulse
     account, then press Start to track. It runs in your system tray and only monitors
     while a session is active.</p>
  <p>
    <a class="btn lg" href="<?= e(url('/download/app?os=win')) ?>">⬇ Download for Windows</a>
    <a class="btn lg" href="<?= e(url('/download/app?os=mac')) ?>">⬇ Download for macOS</a>
    <a class="btn lg" href="<?= e(url('/download/app?os=linux')) ?>">⬇ Download for Linux</a>
  </p>
  <p class="muted">You'll get the native installer (Windows <code>.exe</code> / macOS
     <code>.dmg</code> / Linux <code>.tar.gz</code>) if it has been built, otherwise a
     cross-platform portable package (needs Python 3.10+) with one-click Install / Run
     scripts.</p>
</div>

<div class="two-col">
  <div class="panel">
    <h3>Connect the app</h3>
    <ol class="steps">
      <li>Run the installer for your OS (<code>DeskPulse-Setup.exe</code> on Windows,
          drag-to-Applications from the <code>.dmg</code> on macOS, or unpack the
          <code>.tar.gz</code> on Linux).</li>
      <li>Enter this <b>Server URL</b>:<br><code><?= e($server_url) ?></code></li>
      <li>Sign in with your DeskPulse email &amp; password — the app registers this computer.</li>
      <li>Pick a project/task and press <b>Start</b>.</li>
    </ol>
    <p class="muted">If Windows SmartScreen warns about an unknown publisher, choose
       <b>More info → Run anyway</b> (signed builds remove this prompt).</p>
  </div>
  <div class="panel">
    <h3>Your registered devices</h3>
    <ul class="plain">
      <?php foreach ($devices as $d): ?>
        <li><?= e($d['name']) ?>
          <small class="muted">· last seen
            <?= $d['last_seen'] ? tlocal($d['last_seen'], 'datetime') : 'never' ?></small></li>
      <?php endforeach; ?>
      <?php if (!$devices): ?><li class="muted">No devices yet — sign in from the desktop app to register one.</li><?php endif; ?>
    </ul>
  </div>
</div>
