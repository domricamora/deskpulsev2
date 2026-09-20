/*
 * The app bundle. Each module is the legacy assets/js file it is named after,
 * ported as-is: every one of them is a self-contained IIFE that looks for the
 * elements it enhances and does nothing when they are absent, so bundling them
 * together loads the same behaviour the legacy <script> tags did page by page.
 */
import './auth.js';
import './dashboard.js';
import './charts.js';
import './tables.js';
import './agent-modal.js';
import './live.js';
import './remote.js';
