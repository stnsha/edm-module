<?php
$page_title = 'Import contacts';
$page_subtitle = 'Add contacts to a list from a file or pasted text, then match each column to a contact field.';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'audience/import.js';

require __DIR__ . '/../app/bootstrap.php';
$edm_import_lists = \Edm\Models\ContactList::all();
$edm_import_list = isset($_GET['list']) ? (int)$_GET['list'] : 0;
$edm_import_modes = \Edm\Services\Import\ContactImport::MODES;
?>
<div id="edm-imp-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

<nav class="edm-imp-steps-bar" aria-label="Import steps">
    <ol class="edm-imp-steps">
        <li class="active" data-step="1"><span class="edm-imp-step-dot"></span>1. Upload</li>
        <li class="edm-imp-steps-sep" aria-hidden="true"><i class="bi bi-chevron-right"></i></li>
        <li data-step="2"><span class="edm-imp-step-dot"></span>2. Match columns</li>
        <li class="edm-imp-steps-sep" aria-hidden="true"><i class="bi bi-chevron-right"></i></li>
        <li data-step="3"><span class="edm-imp-step-dot"></span>3. Summary</li>
    </ol>
</nav>

<!-- Step 1: list + file / paste, with the template and checklist beside it -->
<form id="edm-imp-step1" class="edm-imp-step" novalidate>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="edm-imp-section">
                <h2 class="edm-imp-section-title"><span>1</span>Choose a list</h2>
                <select class="form-select edm-imp-narrow" id="edm-imp-list" required aria-label="List">
                    <option value="">Select a list</option>
                    <?php foreach ($edm_import_lists as $l): ?>
                    <option value="<?php echo (int)$l['id']; ?>"<?php echo (int)$l['id'] === $edm_import_list ? ' selected' : ''; ?>><?php echo htmlspecialchars($l['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$edm_import_lists): ?>
                <div class="form-text">No lists yet - <a href="<?php echo EDM_BASE; ?>audience/index.php">create a list</a> first.</div>
                <?php endif; ?>
            </div>

            <div class="edm-imp-section">
                <h2 class="edm-imp-section-title"><span>2</span>Add contacts</h2>
                <div class="edm-imp-seg" role="tablist" aria-label="How to add contacts">
                    <button type="button" class="active" data-imp-mode="file" role="tab" aria-selected="true"><i class="bi bi-upload me-1"></i>Upload file</button>
                    <button type="button" data-imp-mode="paste" role="tab" aria-selected="false"><i class="bi bi-clipboard me-1"></i>Paste</button>
                </div>

                <div id="edm-imp-file-pane">
                    <label class="edm-imp-drop" id="edm-imp-drop" for="edm-imp-file">
                        <i class="bi bi-cloud-arrow-up"></i>
                        <span class="edm-imp-drop-title">Drag a file here</span>
                        <span class="text-muted">or <span class="edm-imp-drop-link">select a file from your computer</span></span>
                        <span class="edm-imp-drop-hint">CSV, TXT, VCF, XLSX, ODS up to 50 MB &middot; XLS up to 10 MB</span>
                    </label>
                    <input type="file" id="edm-imp-file" class="visually-hidden" accept=".csv,.txt,.vcf,.xls,.xlsx,.ods">
                    <div class="edm-imp-file-chip" id="edm-imp-file-chip" hidden>
                        <i class="bi bi-file-earmark-text"></i>
                        <span class="edm-imp-file-name" id="edm-imp-file-name"></span>
                        <button type="button" class="btn-close" id="edm-imp-file-clear" aria-label="Remove file" title="Remove file"></button>
                    </div>
                </div>

                <div id="edm-imp-paste-pane" hidden>
                    <textarea class="form-control font-monospace" id="edm-imp-paste" rows="9" placeholder="email, name, member_code&#10;jane@example.com, Jane Tan, M0001&#10;ali@example.com, Ali Ahmad, M0002"></textarea>
                    <div class="form-text">One contact per line. Separate values with commas, semicolons or tabs - copying cells from Excel works.</div>
                </div>
            </div>

            <div id="edm-imp-progress-slot1"></div>

            <div class="edm-imp-actions">
                <a class="btn btn-outline-secondary" href="<?php echo EDM_BASE; ?>audience/index.php" data-imp-reset>Cancel</a>
                <button type="submit" class="btn btn-primary" id="edm-imp-next">Next <i class="bi bi-arrow-right ms-1"></i></button>
            </div>
        </div>

        <aside class="col-lg-4">
            <div class="edm-imp-aside">
                <div class="edm-imp-aside-title"><i class="bi bi-file-earmark-spreadsheet"></i>Import template</div>
                <p class="small text-muted mb-2">Column headers for email, name, member code and every active custom field, matched automatically. Replace the two sample rows with your contacts.</p>
                <a class="btn btn-outline-primary btn-sm w-100" href="<?php echo EDM_BASE; ?>audience/api.php?action=import_template"><i class="bi bi-download me-1"></i>Download template (.csv)</a>
            </div>

            <div class="edm-imp-aside mt-3">
                <div class="edm-imp-aside-title"><i class="bi bi-list-check"></i>Before you import</div>
                <div class="edm-imp-guide">
                    <details class="edm-imp-guide-item">
                        <summary>Format</summary>
                        <ul>
                            <li>Use one of these formats: CSV, TXT, VCF, XLS, XLSX, ODS. Separate columns with commas, semicolons or tabs.</li>
                            <li>Save the file with UTF-8 encoding.</li>
                            <li>CSV, TXT, VCF, XLSX and ODS files can be up to 50 MB; XLS files up to 10 MB.</li>
                            <li>Text custom field values can be up to 255 characters; longer values are clipped at 255.</li>
                            <li>To assign the Name field automatically, label its column <code>name</code>. Columns whose headers match a custom field key or label are assigned automatically too. The email column is found automatically whether or not the file has headers. Any column that is not matched is set to "Do not import" - you can assign it in the next step.</li>
                            <li>Only the first sheet of a multi-sheet spreadsheet is imported.</li>
                        </ul>
                    </details>
                    <details class="edm-imp-guide-item">
                        <summary>Required field</summary>
                        <ul>
                            <li>The file must contain an email column.</li>
                            <li>Put only one email address per contact in the email column.</li>
                            <li>Email addresses must be complete. Incomplete or badly formatted addresses such as <code>john@aol</code>, <code>johnaol.com</code> or <code>@aol.com</code> (instead of <code>john@aol.com</code>) are skipped.</li>
                            <li>An empty email, special characters that are not allowed in an address (e.g. <code>! # $ % ^ &amp; ( ) | \ ,</code>) or an email longer than 128 characters make the row invalid, and it is skipped. Blank lines are ignored.</li>
                        </ul>
                    </details>
                    <details class="edm-imp-guide-item">
                        <summary>Custom fields</summary>
                        <ul>
                            <li>You can import values for any active custom field (Contacts &gt; Custom fields).</li>
                            <li>For a select field, the values in the file must match the field's options exactly (letter case does not matter). For example, if the options are "Male" and "Female", the values "man" and "woman" are not imported.</li>
                            <li>Yes/no fields take <code>1</code> or <code>0</code> (<code>yes</code> / <code>no</code> and <code>true</code> / <code>false</code> are also accepted); number fields take numbers only.</li>
                            <li>A value that does not fit its field is left out, but the contact is still imported; the summary shows how many values were left out.</li>
                        </ul>
                    </details>
                    <details class="edm-imp-guide-item">
                        <summary>Dates</summary>
                        <ul>
                            <li>Date custom field values use <code>YYYY-MM-DD</code> (e.g. <code>2026-01-31</code>) or day-first <code>D/M/YYYY</code> (e.g. <code>31/1/2026</code>, as Excel saves it).</li>
                            <li>For Excel files (.xls and .xlsx), format date and number cells as text so the values are imported exactly as typed.</li>
                        </ul>
                    </details>
                </div>
            </div>
        </aside>
    </div>
</form>

<!-- Step 2: update mode + column mapping -->
<div id="edm-imp-step2" class="edm-imp-step" hidden>
    <div class="edm-imp-section">
        <h2 class="edm-imp-section-title">Import settings</h2>
        <div class="row g-3">
            <div class="col-md-6 col-xl-5">
                <label class="form-label" for="edm-imp-mode">What should we do with contact information?</label>
                <select class="form-select" id="edm-imp-mode">
                    <?php foreach ($edm_import_modes as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text" id="edm-imp-mode-help"></div>
            </div>
            <div class="col-md-6 col-xl-7 d-flex align-items-center">
                <div class="form-check mt-md-3">
                    <input class="form-check-input" type="checkbox" id="edm-imp-header">
                    <label class="form-check-label" for="edm-imp-header">First row contains column names</label>
                </div>
            </div>
        </div>
    </div>

    <div class="edm-imp-section">
        <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-1">
            <h2 class="edm-imp-section-title mb-0">Match columns</h2>
            <span class="small text-muted"><i class="bi bi-people me-1"></i><span class="fw-semibold text-body" id="edm-imp-count">0</span> <span id="edm-imp-count-label">contacts found</span></span>
        </div>
        <p class="small text-muted mb-3">Make sure each column in your file goes to the right contact field. Email is required; columns set to "Do not import" are ignored.</p>
        <div class="edm-imp-map" id="edm-imp-map"></div>
    </div>

    <div id="edm-imp-check-result" class="alert py-2 px-3 small mb-0 mt-4" role="status" hidden></div>
    <div id="edm-imp-progress-slot2"></div>

    <div class="edm-imp-actions">
        <a class="btn btn-outline-secondary" href="<?php echo EDM_BASE; ?>audience/index.php" data-imp-reset>Cancel</a>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" id="edm-imp-back"><i class="bi bi-arrow-left me-1"></i>Back</button>
            <button type="button" class="btn btn-outline-primary" id="edm-imp-check" title="Go through every row without saving anything"><i class="bi bi-clipboard-check me-1"></i>Check for errors</button>
            <button type="button" class="btn btn-primary" id="edm-imp-run"><i class="bi bi-person-plus me-1"></i>Import contacts</button>
        </div>
    </div>
</div>

<!-- Step 3: summary -->
<div id="edm-imp-step3" class="edm-imp-step" hidden>
    <div class="alert alert-success py-2 px-3" id="edm-imp-done-msg"></div>
    <div class="row g-3" id="edm-imp-summary"></div>
    <div id="edm-imp-progress-slot3"></div>
    <div class="edm-imp-actions justify-content-end">
        <a class="btn btn-primary" href="<?php echo EDM_BASE; ?>audience/import.php" id="edm-imp-again" data-imp-reset>Import another file</a>
        <a class="btn btn-outline-secondary" href="<?php echo EDM_BASE; ?>audience/index.php" data-imp-reset>Back to lists</a>
    </div>
</div>

<!-- Create a custom field from a file column (step 2) -->
<div class="modal fade" id="edm-imp-field-modal" tabindex="-1" aria-labelledby="edm-imp-field-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="edm-imp-field-form" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="edm-imp-field-title">Create custom field</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="edm-imp-field-error" class="alert alert-danger py-2 px-3 small" hidden></div>
                <div class="mb-3">
                    <label class="form-label" for="edm-imp-field-label">Field name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" class="form-control" id="edm-imp-field-label" maxlength="255" required>
                    <div class="form-text">Personalisation variable: <code id="edm-imp-field-key"></code></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="edm-imp-field-type">Type</label>
                    <select class="form-select" id="edm-imp-field-type">
                        <option value="text">Text</option>
                        <option value="number">Number</option>
                        <option value="date">Date (YYYY-MM-DD)</option>
                        <option value="boolean">Yes / No (1 or 0)</option>
                        <option value="select">Select (one of a list of options)</option>
                    </select>
                </div>
                <div id="edm-imp-field-options-wrap" hidden>
                    <label class="form-label" for="edm-imp-field-options">Options</label>
                    <textarea class="form-control" id="edm-imp-field-options" rows="4"></textarea>
                    <div class="form-text">One per line. Filled from the values in the preview - add any missing ones; values in the file that match no option are left out.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="edm-imp-field-save">Create and match</button>
            </div>
        </form>
    </div>
</div>

<!-- Progress + log panel: moved into the current step's slot by import.js -->
<div class="edm-imp-progress" id="edm-imp-progress" hidden>
    <div class="edm-imp-progress-top">
        <span class="edm-imp-progress-label">Working</span>
        <span class="edm-imp-progress-pct"></span>
    </div>
    <div class="progress" role="progressbar" aria-label="Import progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
        <div class="progress-bar" style="width:0%"></div>
    </div>
    <div class="edm-imp-progress-meta"></div>
    <div class="edm-imp-log-head">
        <span>Log</span>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-danger edm-imp-stop" hidden><i class="bi bi-stop-circle me-1"></i>Stop</button>
            <button type="button" class="btn btn-sm btn-outline-secondary edm-imp-log-download"><i class="bi bi-download me-1"></i>Download log (.txt)</button>
        </div>
    </div>
    <ol class="edm-imp-log" aria-live="polite"></ol>
</div>
<?php
include __DIR__ . '/../footer.php';
?>
