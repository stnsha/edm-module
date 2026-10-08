<?php
$page_title  = 'Email creator';
$campaign_id = isset($_GET['campaign']) ? (int)$_GET['campaign'] : 0;
if (!$campaign_id) {
    $page_subtitle = 'Pick a newsletter to design.';
} else {
    // The editor bar below carries the newsletter name instead.
    $page_hide_title = true;
}
require __DIR__ . '/../partials.php';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'email-builder/email-builder.js';
?>
<?php if (!$campaign_id): ?>
<div class="table-responsive">
    <table class="table table-hover align-middle edm-view-tbl">
        <thead>
            <tr>
                <th style="width:3rem;">#</th>
                <th>Name</th>
                <th>Subject</th>
                <th>Status</th>
                <th class="text-end">Action</th>
            </tr>
        </thead>
        <tbody id="edm-eb-picker-rows">
            <tr><td colspan="5" class="text-center text-muted py-4">Loading...</td></tr>
        </tbody>
    </table>
</div>
<script>
(function () {
    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var rowsEl = document.getElementById('edm-eb-picker-rows');
    var badges = {
        1: { cls: 'edm-pill-secondary', label: 'Draft' },
        2: { cls: 'edm-pill-info', label: 'Pending submission' },
        3: { cls: 'edm-pill-info', label: 'Under BPT review' },
        4: { cls: 'edm-pill-warning', label: 'Content revision' },
        5: { cls: 'edm-pill-info', label: 'Audience validation' },
        6: { cls: 'edm-pill-primary', label: 'Scheduled' },
        7: { cls: 'edm-pill-primary', label: 'Sending' },
        8: { cls: 'edm-pill-success', label: 'Completed' },
        9: { cls: 'edm-pill-dark', label: 'Archived' }
    };
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    fetch(BASE + 'email-builder/api.php?action=campaigns_list', { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            var rows = (res.success && res.data) || [];
            if (!rows.length) {
                rowsEl.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No newsletters yet - create one under Newsletters.</td></tr>';
                return;
            }
            rowsEl.innerHTML = rows.map(function (r, i) {
                return '<tr>' +
                    '<td class="text-muted">' + (i + 1) + '</td>' +
                    '<td>' + esc(r.name) + '</td>' +
                    '<td>' + (r.subject ? esc(r.subject) : '<span class="text-muted">-</span>') + '</td>' +
                    '<td><span class="edm-pill ' + ((badges[r.status] && badges[r.status].cls) || 'edm-pill-secondary') + '">' + esc((badges[r.status] && badges[r.status].label) || r.status) + '</span></td>' +
                    '<td class="text-end"><div class="dropdown">' +
                        '<button type="button" class="edm-row-kebab" data-bs-toggle="dropdown" aria-expanded="false"' +
                            ' data-bs-popper-config=\'{"strategy":"fixed"}\' aria-label="Actions" title="Actions">' +
                            '<i class="bi bi-three-dots-vertical"></i></button>' +
                        '<ul class="dropdown-menu dropdown-menu-end edm-row-menu">' +
                            '<li><a class="dropdown-item" href="' + esc(BASE + 'email-builder/index.php?campaign=' + r.id) + '">Design</a></li>' +
                        '</ul></div></td>' +
                '</tr>';
            }).join('');
        })
        .catch(function () {
            rowsEl.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Could not reach the server.</td></tr>';
        });
})();
</script>
<?php else: ?>
<div class="edm-eb-bar">
    <a class="edm-eb-back" href="<?php echo EDM_BASE; ?>campaign/index.php" title="Back to newsletters" aria-label="Back to newsletters">
        <i class="bi bi-arrow-left"></i>
    </a>
    <div class="edm-eb-heading">
        <div class="edm-eb-title-row">
            <h1 class="edm-page-title mb-0 text-truncate" id="edm-eb-name">Loading...</h1>
        </div>
    </div>
    <div class="edm-eb-actions">
        <div class="edm-eb-buttons">
            <span class="edm-pill edm-pill-secondary" id="edm-eb-status" hidden></span>
            <button type="button" class="btn btn-outline-primary" id="edm-eb-save" disabled title="Save (Ctrl+S)">
                <i class="bi bi-floppy me-1"></i>Save
            </button>
        </div>
        <span class="edm-eb-state" id="edm-eb-state"></span>
    </div>
</div>

<div id="edm-eb-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

<?php
require __DIR__ . '/../app/bootstrap.php';

// Active Contacts > Custom fields are the personalisation variables ({{key}}).
$edm_custom_vars = array();
foreach (\Edm\Models\CustomField::where('`is_active` = 1') as $row) {
    if (!empty($row['key'])) {
        $edm_custom_vars[] = array('token' => '{{' . $row['key'] . '}}', 'label' => $row['label'] ?: $row['key']);
    }
}

// Images in the Files library, offered in the editor's Image block.
$edm_assets = array();
foreach (\Edm\Models\Asset::where('`type` = ?', array('image')) as $row) {
    $edm_assets[] = array('id' => (int)$row['id'], 'name' => $row['name'], 'url' => $row['url']);
}

// Sender / Reply-to / segment options for the settings panel (Sender and
// Reply-to both come from Settings > Senders).
$edm_senders = array();
foreach (\Edm\Models\Sender::all() as $row) {
    $edm_senders[] = array('id' => (int)$row['id'], 'label' => $row['from_name'] . ' <' . $row['email'] . '>');
}
$edm_segments = array();
foreach (\Edm\Models\Segment::all() as $row) {
    $edm_segments[] = array('id' => (int)$row['id'], 'label' => $row['name']);
}
$edm_templates = \Edm\Models\Template::options();
// Newsletter tags (master list) for the Setup step's tag input.
$edm_master_tags = array();
foreach (\Edm\Models\MasterTag::all() as $row) {
    $edm_master_tags[] = array('id' => (int)$row['id'], 'name' => $row['name']);
}
?>
<script>
window.EDM_EB_CUSTOM_VARS = <?php echo json_encode($edm_custom_vars); ?>;
window.EDM_EB_ASSETS = <?php echo json_encode($edm_assets); ?>;
window.EDM_EB_SEGMENTS = <?php echo json_encode($edm_segments); ?>;
window.EDM_EB_TAGS = <?php echo json_encode($edm_master_tags); ?>;
</script>


<!-- Wizard (SPEC "Newsletter creation flow"): one page, five steps - Setup,
     Recipients, Design, Tracking, Send. Every field belongs to the same
     newsletter and the top-bar Save (Ctrl+S) saves all of them from any step. -->
<nav class="edm-wiz-steps" id="edm-wiz-steps" aria-label="Newsletter steps">
    <button type="button" class="edm-wiz-step" data-step="1"><span class="edm-wiz-dot">1</span><span class="edm-wiz-label">Setup</span></button>
    <button type="button" class="edm-wiz-step" data-step="2"><span class="edm-wiz-dot">2</span><span class="edm-wiz-label">Recipients</span></button>
    <button type="button" class="edm-wiz-step" data-step="3"><span class="edm-wiz-dot">3</span><span class="edm-wiz-label">Design</span></button>
    <button type="button" class="edm-wiz-step" data-step="4"><span class="edm-wiz-dot">4</span><span class="edm-wiz-label">Tracking</span></button>
    <button type="button" class="edm-wiz-step" data-step="5"><span class="edm-wiz-dot">5</span><span class="edm-wiz-label">Send</span></button>
</nav>

<form class="edm-wiz" id="edm-eb-settings" autocomplete="off" onsubmit="return false;">

<section class="edm-wiz-pane" data-pane="1" aria-label="Setup">
    <h2 class="edm-wiz-title">Newsletter setup</h2>
    <p class="edm-wiz-sub">Title the newsletter, choose who it comes from and write what recipients see in their inbox.</p>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label" for="edm-eb-f-name">Title <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="edm-eb-f-name" maxlength="255" required>
            <div class="form-text">For your team only - recipients do not see it.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="edm-eb-f-sender">From <span class="text-danger" aria-hidden="true">*</span></label>
            <select class="form-select" id="edm-eb-f-sender" required>
                <option value="">Select a sender</option>
                <?php foreach ($edm_senders as $o): ?>
                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['label']); ?></option>
                <?php endforeach; ?>
                <option value="__new">+ Create new sender</option>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="edm-eb-f-reply-to">Reply-to</label>
            <select class="form-select" id="edm-eb-f-reply-to">
                <option value="">Same as sender</option>
                <?php foreach ($edm_senders as $o): ?>
                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['label']); ?></option>
                <?php endforeach; ?>
                <option value="__new">+ Create new sender</option>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label" for="edm-eb-f-subject">Subject line <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="edm-eb-f-subject" maxlength="255" required>
        </div>
        <div class="col-12">
            <label class="form-label" for="edm-eb-f-preheader">Preview text</label>
            <input type="text" class="form-control" id="edm-eb-f-preheader" maxlength="255" placeholder="e.g. 20% off your favourite vitamins this weekend only">
            <div class="form-text">Shown after the subject line in the inbox list. Personalisation variables such as {{first_name}} work here too.</div>
        </div>
        <div class="col-12">
            <label class="form-label" for="edm-eb-f-tag-input">Tags <span class="text-muted fw-normal">(optional)</span></label>
            <!-- Chips + input (email-builder.js): pick existing tags (edm_master_tags)
                 or type a new name and press Enter; new tags are created on Save. -->
            <div class="edm-tag-input" id="edm-eb-tags">
                <input type="text" id="edm-eb-f-tag-input" list="edm-eb-tag-list" maxlength="100" autocomplete="off" placeholder="Add a tag...">
            </div>
            <datalist id="edm-eb-tag-list">
                <?php foreach ($edm_master_tags as $t): ?>
                <option value="<?php echo htmlspecialchars($t['name']); ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <div class="form-text">Several tags allowed. Pick an existing one or type a new name and press Enter - new tags are saved with the newsletter.</div>
        </div>
    </div>
    <div class="edm-wiz-nav">
        <span></span>
        <button type="button" class="btn btn-primary" data-wiz-next>Next: Recipients <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</section>

<section class="edm-wiz-pane" data-pane="2" aria-label="Recipients" hidden>
    <h2 class="edm-wiz-title">Recipients</h2>
    <p class="edm-wiz-sub">Choose any number of segments and contacts, and leave some out. Each address gets the email once; unsubscribed contacts and the exclusion list are always skipped.</p>
    <div id="edm-eb-audience"></div>
    <div class="edm-wiz-nav">
        <button type="button" class="btn btn-outline-secondary" data-wiz-prev><i class="bi bi-arrow-left me-1"></i>Previous</button>
        <button type="button" class="btn btn-primary" data-wiz-next>Next: Design <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</section>

<section class="edm-wiz-pane edm-wiz-pane--wide" data-pane="3" aria-label="Design" hidden>
    <div class="edm-wiz-design-head">
        <div>
            <h2 class="edm-wiz-title">Design &amp; content</h2>
            <p class="edm-wiz-sub mb-0">Drag blocks into the email, or start from a template. Send a test to see it in an inbox.</p>
        </div>
        <div class="edm-wiz-tools">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="edm-eb-template" disabled title="Replace the design with a template">
                <i class="bi bi-layout-text-window me-1"></i>Start from template
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="edm-eb-test" disabled title="Save, then send one test email through Amazon SES">
                <i class="bi bi-envelope-paper me-1"></i>Send test
            </button>
        </div>
    </div>
    <!-- EmailBuilder.js (usewaypoint/email-builder-js, MIT), prebuilt from
         email-builder/editor-src into email-builder/editor. Talks to
         email-builder.js over postMessage (see editor-src/src/bridge.ts). -->
    <div class="edm-eb-frame-wrap" data-campaign="<?php echo (int)$campaign_id; ?>">
        <iframe id="edm-eb-frame" class="edm-eb-frame" title="Email creator"
            src="<?php echo EDM_BASE; ?>email-builder/editor/index.html?v=<?php echo (int)@filemtime(__DIR__ . '/editor/index.html'); ?>"></iframe>
    </div>
    <div class="edm-wiz-nav">
        <button type="button" class="btn btn-outline-secondary" data-wiz-prev><i class="bi bi-arrow-left me-1"></i>Previous</button>
        <button type="button" class="btn btn-primary" data-wiz-next>Next: Tracking <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</section>

<section class="edm-wiz-pane" data-pane="4" aria-label="Tracking" hidden>
    <h2 class="edm-wiz-title">Tracking</h2>
    <p class="edm-wiz-sub">What is measured for this newsletter. Results appear on the Dashboard; UTM tags let Google Analytics show the site visits and behaviour the email brings.</p>
    <div class="edm-wiz-toggles">
        <label class="edm-wiz-toggle">
            <span class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="edm-eb-f-track-opens" autocomplete="off"></span>
            <span><strong>Open rate</strong><span class="edm-wiz-toggle-hint">Counts who opened the email (a tiny tracking image).</span></span>
        </label>
        <label class="edm-wiz-toggle">
            <span class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="edm-eb-f-track-clicks" autocomplete="off"></span>
            <span><strong>Click rate</strong><span class="edm-wiz-toggle-hint">Counts who clicked a link (links pass through Amazon SES first).</span></span>
        </label>
        <label class="edm-wiz-toggle">
            <span class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="edm-eb-f-utm-enabled" autocomplete="off"></span>
            <span><strong>Google Analytics (UTM)</strong><span class="edm-wiz-toggle-hint">Adds UTM tags to every link, so site visits and behaviour from this email show in Google Analytics.</span></span>
        </label>
    </div>
    <div class="row g-3 mt-1" id="edm-eb-utm-fields">
        <div class="col-md-6">
            <label class="form-label" for="edm-eb-f-utm-source">utm_source</label>
            <input type="text" class="form-control" id="edm-eb-f-utm-source" maxlength="100" placeholder="edm">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="edm-eb-f-utm-medium">utm_medium</label>
            <input type="text" class="form-control" id="edm-eb-f-utm-medium" maxlength="100" placeholder="email">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="edm-eb-f-utm-campaign">utm_campaign</label>
            <input type="text" class="form-control" id="edm-eb-f-utm-campaign" maxlength="100">
            <div class="form-text">Empty = taken from the title.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="edm-eb-f-utm-content">utm_content</label>
            <input type="text" class="form-control" id="edm-eb-f-utm-content" maxlength="100" placeholder="optional, e.g. hero-banner">
        </div>
        <div class="col-12">
            <div class="edm-wiz-example" id="edm-eb-utm-example"></div>
        </div>
    </div>
    <div class="edm-wiz-nav">
        <button type="button" class="btn btn-outline-secondary" data-wiz-prev><i class="bi bi-arrow-left me-1"></i>Previous</button>
        <button type="button" class="btn btn-primary" data-wiz-next>Next: Send <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</section>

<section class="edm-wiz-pane" data-pane="5" aria-label="Send" hidden>
    <h2 class="edm-wiz-title">Send</h2>
    <p class="edm-wiz-sub">Choose when it goes out, check everything, then submit it for review. It is sent only after final approval - with one minute to recall it.</p>
    <div class="edm-wiz-when">
        <label class="edm-wiz-when-opt">
            <input class="form-check-input" type="radio" name="edm-eb-when" id="edm-eb-f-when-now" value="now" autocomplete="off">
            <span><strong>Send now</strong><span class="edm-wiz-toggle-hint">As soon as final approval is given.</span></span>
        </label>
        <label class="edm-wiz-when-opt">
            <input class="form-check-input" type="radio" name="edm-eb-when" id="edm-eb-f-when-later" value="later" autocomplete="off">
            <span><strong>Schedule</strong><span class="edm-wiz-toggle-hint">On a date and time you choose.</span></span>
        </label>
    </div>
    <div class="row g-3 mt-1" id="edm-eb-schedule-row">
        <div class="col-12">
            <label class="form-label" for="edm-eb-f-scheduled">Send date and time</label>
            <input type="datetime-local" class="form-control" id="edm-eb-f-scheduled">
            <div class="form-text text-warning-emphasis" id="edm-eb-conflicts" hidden></div>
        </div>
    </div>
    <h3 class="edm-wiz-subtitle">Summary</h3>
    <dl class="edm-wiz-summary" id="edm-wiz-summary"></dl>
    <div class="edm-wiz-nav">
        <button type="button" class="btn btn-outline-secondary" data-wiz-prev><i class="bi bi-arrow-left me-1"></i>Previous</button>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" id="edm-eb-preview"><i class="bi bi-eye me-1"></i>Preview message</button>
            <button type="button" class="btn btn-success" id="edm-eb-submit" hidden title="Save, then submit for review">
                <i class="bi bi-send me-1"></i>Submit for review
            </button>
        </div>
    </div>
</section>

</form>

<!-- Preview message: the saved email as it will be sent (preview text, UTM
     tags), from campaign/api.php campaigns_preview (CSP sandboxed). -->
<div class="modal fade" id="edm-eb-preview-modal" tabindex="-1" aria-labelledby="edm-eb-preview-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="edm-eb-preview-title">Preview message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="edm-wiz-inbox" id="edm-eb-preview-inbox"></div>
                <iframe class="edm-nl-preview-frame" id="edm-eb-preview-frame" sandbox="" title="Email preview"></iframe>
            </div>
        </div>
    </div>
</div>
<!-- Start from template: replaces the editor's design with a copy of a
     template. Nothing is written until Save. -->
<div class="modal fade" id="edm-eb-template-modal" tabindex="-1" aria-labelledby="edm-eb-template-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="edm-eb-template-title">Start from template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($edm_templates): ?>
                <label class="form-label" for="edm-eb-template-select">Template</label>
                <select class="form-select" id="edm-eb-template-select">
                    <?php foreach ($edm_templates as $t): ?>
                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name'] . ($t['category'] ? ' (' . $t['category'] . ')' : '')); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">The current design is replaced. Nothing is saved until you click Save.</div>
                <?php else: ?>
                <p class="text-muted mb-0">No templates yet - create one under Templates.</p>
                <?php endif; ?>
                <div class="alert alert-danger py-2 px-3 small mt-3 mb-0" id="edm-eb-template-error" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <?php if ($edm_templates): ?>
                <button type="button" class="btn btn-primary btn-sm" id="edm-eb-template-apply">Use template</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<!-- Send test: saves, then sends one "[Test]" email of the design via SES. -->
<div class="modal fade" id="edm-eb-test-modal" tabindex="-1" aria-labelledby="edm-eb-test-title" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="edm-eb-test-form" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="edm-eb-test-title">Send test email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="edm-eb-test-to">Send to <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="email" class="form-control" id="edm-eb-test-to" maxlength="255" required autocomplete="email">
                <div class="form-text">The newsletter is saved first. While the SES account is in the sandbox, this address must be verified in SES too.</div>
                <div class="alert py-2 px-3 small mt-3 mb-0" id="edm-eb-test-result" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary btn-sm" id="edm-eb-test-send">Save and send test</button>
            </div>
        </form>
    </div>
</div>
<!-- Create new sender: from the Sender / Reply-to selects. Adds a pending
     sender to Settings > Senders and asks SES to email it a verification link. -->
<div class="modal fade" id="edm-eb-sender-modal" tabindex="-1" aria-labelledby="edm-eb-sender-title" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="edm-eb-sender-form" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="edm-eb-sender-title">Create new sender</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="edm-eb-sender-name">From name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" class="form-control" id="edm-eb-sender-name" maxlength="255" required>
                </div>
                <label class="form-label" for="edm-eb-sender-email">Email <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="email" class="form-control" id="edm-eb-sender-email" maxlength="255" required autocomplete="off">
                <div class="form-text">Added to Settings &gt; Senders. Amazon SES emails this address a verification link; it can be the Sender once the link is clicked. As a Reply-to it works straight away.</div>
                <div class="alert py-2 px-3 small mt-3 mb-0" id="edm-eb-sender-result" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm" id="edm-eb-sender-save">Create sender</button>
            </div>
        </form>
    </div>
</div>
<script src="<?php echo EDM_BASE; ?>js/edm-confirm.js?v=<?php echo filemtime(__DIR__ . '/../js/edm-confirm.js'); ?>"></script>
<script src="<?php echo EDM_BASE; ?>js/edm-audience-picker.js?v=<?php echo filemtime(__DIR__ . '/../js/edm-audience-picker.js'); ?>"></script>
<?php endif; ?>
<?php
include __DIR__ . '/../footer.php';
?>
