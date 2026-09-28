<?php
$page_title = 'Import contacts';
$page_subtitle = 'Add contacts to a list from a file or pasted text, then match each column to a contact field.';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'audience/import.js';

require __DIR__ . '/../app/bootstrap.php';
$edm_import_lists = \Edm\Models\ContactList::all();
$edm_import_list = isset($_GET['list']) ? (int)$_GET['list'] : 0;
?>
<div id="edm-imp-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

<ol class="edm-imp-steps" aria-label="Import steps">
    <li class="active" data-step="1"><span>1</span> Upload</li>
    <li data-step="2"><span>2</span> Match columns</li>
    <li data-step="3"><span>3</span> Done</li>
</ol>

<!-- Step 1: list + file / paste + permission -->
<form id="edm-imp-step1" class="edm-imp-step" novalidate>
    <div class="mb-3" style="max-width:420px;">
        <label class="form-label" for="edm-imp-list">List <span class="text-danger" aria-hidden="true">*</span></label>
        <select class="form-select" id="edm-imp-list" required>
            <option value="">Select a list</option>
            <?php foreach ($edm_import_lists as $l): ?>
            <option value="<?php echo (int)$l['id']; ?>"<?php echo (int)$l['id'] === $edm_import_list ? ' selected' : ''; ?>><?php echo htmlspecialchars($l['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (!$edm_import_lists): ?>
        <div class="form-text">No lists yet - <a href="<?php echo EDM_BASE; ?>audience/index.php">create a list</a> first.</div>
        <?php endif; ?>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" type="button" data-imp-mode="file" role="tab" aria-selected="true"><i class="bi bi-upload me-1"></i>Upload file</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" type="button" data-imp-mode="paste" role="tab" aria-selected="false"><i class="bi bi-clipboard me-1"></i>Paste</button>
        </li>
    </ul>

    <div id="edm-imp-file-pane">
        <label class="edm-imp-drop" id="edm-imp-drop" for="edm-imp-file">
            <i class="bi bi-cloud-arrow-up"></i>
            <span class="edm-imp-drop-title">Drag a file here</span>
            <span class="text-muted">or <span class="edm-imp-drop-link">select a file from your computer</span></span>
            <span class="edm-imp-drop-file" id="edm-imp-file-name">No file chosen</span>
        </label>
        <input type="file" id="edm-imp-file" class="visually-hidden" accept=".csv,.txt,.vcf,.xls,.xlsx,.ods">
        <div class="form-text">You can use .XLS up to 10 MB and .CSV, .TXT, .VCF, .XLSX, .ODS up to 50 MB. One contact per row; an email column is required. A header row (Email, Name, Member code, custom field names) is matched automatically.</div>
    </div>

    <div id="edm-imp-paste-pane" hidden>
        <textarea class="form-control font-monospace" id="edm-imp-paste" rows="8" placeholder="email, name, member code&#10;jane@example.com, Jane Tan, M0001&#10;ali@example.com, Ali Ahmad, M0002"></textarea>
        <div class="form-text">One contact per line. Separate values with commas, semicolons or tabs (pasting from Excel works).</div>
    </div>

    <div class="form-check mt-4">
        <input class="form-check-input" type="checkbox" id="edm-imp-consent" required>
        <label class="form-check-label" for="edm-imp-consent">I have permission to add these people to my list</label>
    </div>

    <div class="d-flex gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="<?php echo EDM_BASE; ?>audience/index.php">Cancel</a>
        <button type="submit" class="btn btn-primary" id="edm-imp-next">Next <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</form>

<!-- Step 2: column mapping -->
<div id="edm-imp-step2" class="edm-imp-step" hidden>
    <p class="mb-2"><strong id="edm-imp-count">0</strong> <span id="edm-imp-count-label">rows found.</span> Choose what each column holds. Columns set to "Do not import" are ignored.</p>
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="edm-imp-header">
        <label class="form-check-label" for="edm-imp-header">First row contains column names</label>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle edm-view-tbl edm-imp-map">
            <thead id="edm-imp-map-head"></thead>
            <tbody id="edm-imp-map-body"></tbody>
        </table>
    </div>
    <div class="form-text mb-3">Showing the first rows only. Contacts already on the list are matched by email and updated; unsubscribed contacts stay unsubscribed.</div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary" id="edm-imp-back"><i class="bi bi-arrow-left me-1"></i>Back</button>
        <button type="button" class="btn btn-primary" id="edm-imp-run"><i class="bi bi-person-plus me-1"></i>Import contacts</button>
    </div>
</div>

<!-- Step 3: summary -->
<div id="edm-imp-step3" class="edm-imp-step" hidden>
    <div class="alert alert-success py-2 px-3" id="edm-imp-done-msg"></div>
    <div class="row g-3 mb-4" id="edm-imp-summary"></div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?php echo EDM_BASE; ?>audience/index.php">Back to lists</a>
        <a class="btn btn-primary" href="<?php echo EDM_BASE; ?>audience/import.php" id="edm-imp-again">Import another file</a>
    </div>
</div>
<?php
include __DIR__ . '/../footer.php';
?>
