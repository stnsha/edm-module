<?php
/**
 * One-time "Coming from GetResponse?" orientation panel.
 *
 * The marketing team is moving off GetResponse. This maps the old GetResponse
 * menu names to where the same thing lives in this module. Dismissed per
 * browser via localStorage ('edm_gr_help_dismissed'); shown only to users who
 * can build campaigns (edm tier 1-3).
 */
if (empty($edm_can_build)) {
    return;
}
?>
<div id="edm-gr-help" class="edm-gr-help" hidden>
    <button type="button" class="edm-gr-help-close" aria-label="Dismiss" onclick="edmDismissGrHelp()">&times;</button>
    <div class="edm-gr-help-title">
        <i class="bi bi-info-circle"></i> Coming from GetResponse? Here is where things moved.
    </div>
    <ul class="edm-gr-help-list">
        <li><strong>Contacts / Lists</strong> &rarr; <em>Contacts</em></li>
        <li><strong>Search / Segments</strong> &rarr; <em>Contacts &rsaquo; Segments</em></li>
        <li><strong>Custom fields</strong> &rarr; <em>Contacts &rsaquo; Custom fields</em></li>
        <li><strong>Suppression / Blacklist</strong> &rarr; <em>Contacts &rsaquo; Suppression lists</em></li>
        <li><strong>Newsletters</strong> &rarr; <em>Newsletters</em></li>
        <li><strong>Email editor</strong> &rarr; <em>Newsletters &rsaquo; Edit</em></li>
        <li><strong>Autoresponders / Marketing Automation</strong> &rarr; <em>Automation</em></li>
        <li><strong>Calendar</strong> &rarr; <em>Calendar</em></li>
        <li><strong>Statistics</strong> &rarr; <em>Statistics</em></li>
        <li><strong>Templates</strong> &rarr; <em>Templates</em></li>
        <li><strong>File manager</strong> &rarr; <em>Files</em></li>
        <li><strong>From fields / DKIM</strong> &rarr; <em>Settings &rsaquo; Senders / Sending domains</em></li>
    </ul>
    <div class="edm-gr-help-note">
        New: campaigns route through <em>Approval</em> before sending. You submit for review
        rather than sending directly.
    </div>
</div>
<script>
(function () {
    try {
        if (localStorage.getItem('edm_gr_help_dismissed') === '1') { return; }
    } catch (e) {}
    var el = document.getElementById('edm-gr-help');
    if (el) { el.hidden = false; }
})();
function edmDismissGrHelp() {
    try { localStorage.setItem('edm_gr_help_dismissed', '1'); } catch (e) {}
    var el = document.getElementById('edm-gr-help');
    if (el) { el.hidden = true; }
}
</script>
