<?php
$page_title = 'Email cleaner';
$page_subtitle = 'Upload a list of email addresses and download it back as two Excel files: valid emails and invalid emails with the reason.';
// A standalone tool, unrelated to the EDM menus.
$page_hide_nav = true;
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'email-cleaner/cleaner.js?v=' . filemtime(__DIR__ . '/cleaner.js');
?>
<div id="edm-cln-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

<nav class="edm-imp-steps-bar" aria-label="Cleaning steps">
    <ol class="edm-imp-steps">
        <li class="active" data-step="1"><span class="edm-imp-step-dot"></span>1. Upload</li>
        <li class="edm-imp-steps-sep" aria-hidden="true"><i class="bi bi-chevron-right"></i></li>
        <li data-step="2"><span class="edm-imp-step-dot"></span>2. Choose columns</li>
        <li class="edm-imp-steps-sep" aria-hidden="true"><i class="bi bi-chevron-right"></i></li>
        <li data-step="3"><span class="edm-imp-step-dot"></span>3. Download</li>
    </ol>
</nav>

<!-- Step 1: file -->
<form id="edm-cln-step1" class="edm-imp-step" novalidate>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="edm-imp-section">
                <h2 class="edm-imp-section-title"><span>1</span>Upload your list</h2>
                <label class="edm-imp-drop" id="edm-cln-drop" for="edm-cln-file">
                    <i class="bi bi-cloud-arrow-up"></i>
                    <span class="edm-imp-drop-title">Drag a file here</span>
                    <span class="text-muted">or <span class="edm-imp-drop-link">select a file from your computer</span></span>
                    <span class="edm-imp-drop-hint">XLSX up to 150 MB &middot; CSV, TXT up to 200 MB &middot; up to 1,000,000 rows</span>
                </label>
                <input type="file" id="edm-cln-file" class="visually-hidden" accept=".xlsx,.csv,.txt">
                <div class="edm-imp-file-chip" id="edm-cln-file-chip" hidden>
                    <i class="bi bi-file-earmark-spreadsheet"></i>
                    <span class="edm-imp-file-name" id="edm-cln-file-name"></span>
                    <button type="button" class="btn-close" id="edm-cln-file-clear" aria-label="Remove file" title="Remove file"></button>
                </div>
            </div>

            <div id="edm-cln-progress-slot1"></div>

            <div class="edm-imp-actions justify-content-end">
                <button type="submit" class="btn btn-primary" id="edm-cln-next">Next <i class="bi bi-arrow-right ms-1"></i></button>
            </div>
        </div>

        <aside class="col-lg-4">
            <div class="edm-imp-aside">
                <div class="edm-imp-aside-title"><i class="bi bi-list-check"></i>What is checked</div>
                <ul class="small text-muted ps-3 mb-0">
                    <li>Address format, and blank or placeholder values (<code>nil</code>, <code>test</code>, <code>n/a</code>, <code>keyinemail</code>).</li>
                    <li>Usernames that are an IC number or other numbers, filler (<code>nilll</code>, <code>jcjcjc</code>), keyboard mash or too short.</li>
                    <li>Internal, disposable and misspelt domains. Small typos and local variants are corrected (<code>gmail.com.my</code> becomes <code>gmail.com</code>).</li>
                    <li>Domains that do not exist, cannot receive mail or are parked.</li>
                    <li>With a name column: test, deleted or closed accounts and gibberish names. With a mobile column: QA dummy numbers.</li>
                    <li>The same address used by 5 or more different customers (by name or IC), and repeats of an address already kept.</li>
                </ul>
                <p class="small text-muted mt-2 mb-0">Your file is not saved to the contact list. Only the first sheet is read. An old .xls or .ods file: open it in Excel and save it as .xlsx first. A file larger than 1,000,000 rows: split it. Results can be downloaded for 24 hours.</p>
            </div>
        </aside>
    </div>
</form>

<!-- Step 2: columns -->
<div id="edm-cln-step2" class="edm-imp-step" hidden>
    <div class="edm-imp-section">
        <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-1">
            <h2 class="edm-imp-section-title mb-0">Choose columns</h2>
            <span class="small text-muted"><i class="bi bi-file-earmark-spreadsheet me-1"></i><span class="fw-semibold text-body" id="edm-cln-fname"></span> &middot; <span class="fw-semibold text-body" id="edm-cln-count">0</span> rows</span>
        </div>
        <p class="small text-muted mb-3">Email is required. Name, mobile and IC are optional - they turn on the checks that need them. Every column of your file is kept in the results.</p>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="edm-cln-header">
            <label class="form-check-label" for="edm-cln-header">First row contains column names</label>
        </div>
        <div class="row g-3" id="edm-cln-columns"></div>
    </div>

    <div class="edm-imp-section">
        <h2 class="edm-imp-section-title">Preview</h2>
        <div class="table-responsive">
            <table class="table table-sm edm-view-tbl mb-0" id="edm-cln-preview"></table>
        </div>
    </div>

    <div id="edm-cln-progress-slot2"></div>

    <div class="edm-imp-actions">
        <button type="button" class="btn btn-outline-secondary" id="edm-cln-back"><i class="bi bi-arrow-left me-1"></i>Back</button>
        <button type="button" class="btn btn-primary" id="edm-cln-run"><i class="bi bi-stars me-1"></i>Clean emails</button>
    </div>
</div>

<!-- Step 3: results -->
<div id="edm-cln-step3" class="edm-imp-step" hidden>
    <div class="alert alert-success py-2 px-3" id="edm-cln-done-msg"></div>
    <div class="row g-3" id="edm-cln-summary"></div>
    <div class="d-flex flex-wrap gap-2 mt-4">
        <a class="btn btn-success" id="edm-cln-dl-valid" href="#"><i class="bi bi-download me-1"></i>Download valid emails (.xlsx)</a>
        <a class="btn btn-outline-danger" id="edm-cln-dl-invalid" href="#"><i class="bi bi-download me-1"></i>Download invalid emails (.xlsx)</a>
    </div>
    <div class="edm-imp-section mt-4" id="edm-cln-reasons-wrap" hidden>
        <h2 class="edm-imp-section-title">Why emails are invalid</h2>
        <div class="table-responsive">
            <table class="table table-sm edm-view-tbl mb-0">
                <thead><tr><th style="width:48px">#</th><th>Reason</th><th class="text-end" style="width:140px">Rows</th></tr></thead>
                <tbody id="edm-cln-reasons"></tbody>
            </table>
        </div>
    </div>
    <div class="edm-imp-actions justify-content-end">
        <a class="btn btn-primary" href="<?php echo EDM_BASE; ?>email-cleaner/index.php">Clean another file</a>
    </div>
</div>

<!-- Progress panel: moved into the current step's slot by cleaner.js -->
<div class="edm-imp-progress" id="edm-cln-progress" hidden>
    <div class="edm-imp-progress-top">
        <span class="edm-imp-progress-label">Working</span>
        <span class="edm-imp-progress-pct"></span>
    </div>
    <div class="progress" role="progressbar" aria-label="Progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
        <div class="progress-bar" style="width:0%"></div>
    </div>
    <div class="edm-imp-progress-meta"></div>
    <div class="d-flex justify-content-end mt-2">
        <button type="button" class="btn btn-sm btn-outline-danger" id="edm-cln-stop" hidden><i class="bi bi-stop-circle me-1"></i>Stop</button>
    </div>
</div>
<?php
include __DIR__ . '/../footer.php';
