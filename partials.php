<?php
/**
 * Shared view partials for EDM screens. Included by a page after header.php.
 * Pairs with js/edm-crud.js: emit the standard list + CRUD markup here, drive
 * the columns / form fields from window.EDM_CRUD_CONFIG on the page.
 */

if (!function_exists('edm_h')) {
    /**
     * HTML-escape helper.
     * @param string $v
     * @return string
     */
    function edm_h($v)
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('edm_title_button')) {
    /**
     * Primary "New X" button for the page-title row - atem style (see
     * atem/view.php: $page_title_actions set before `include header.php`,
     * header.php renders it beside .edm-page-title). Assign the result to
     * $page_title_actions before the header include; the id is what
     * js/edm-crud.js (or a bespoke script) wires up, regardless of where the
     * button ends up in the DOM.
     * @param string $label
     * @param string $id
     * @return string
     */
    function edm_title_button($label, $id = 'edm-crud-add')
    {
        return '<button type="button" class="btn btn-primary d-inline-flex align-items-center" id="'
            . edm_h($id) . '"><i class="bi bi-plus-lg me-1"></i>' . edm_h($label) . '</button>';
    }
}

if (!function_exists('edm_crud_screen')) {
    /**
     * Render the standard list + CRUD screen shell.
     *
     * The "New X" button normally lives in the page-title row via
     * edm_title_button() + $page_title_actions (atem style), not here - pass
     * only 'columns' and 'modal_size' for that (default) case. Pass 'title' /
     * 'subtitle' / 'add_label' only for a distinct sub-section listing that is
     * not the page's primary title (e.g. Settings > General options); set
     * 'header_button' => false there to get a local button instead.
     *
     * @param array $opts title, subtitle, add_label, header_button (default
     *                    true), columns (list of header labels),
     *                    modal_size ('' | 'modal-lg')
     * @return void
     */
    function edm_crud_screen($opts)
    {
        $title        = isset($opts['title']) ? $opts['title'] : '';
        $subtitle     = isset($opts['subtitle']) ? $opts['subtitle'] : '';
        $addLabel     = isset($opts['add_label']) ? $opts['add_label'] : 'Add';
        $headerButton = isset($opts['header_button']) ? (bool)$opts['header_button'] : true;
        $columns      = isset($opts['columns']) ? $opts['columns'] : array();
        $modalSize    = isset($opts['modal_size']) ? $opts['modal_size'] : '';
        $colCount     = count($columns) + 2; // # + configured columns + Action
        ?>
        <?php if ($title !== '' || !$headerButton): ?>
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <?php if ($title !== ''): ?>
                <h2 class="h5 mb-1"><?php echo edm_h($title); ?></h2>
                <?php endif; ?>
                <?php if ($title !== '' && $subtitle !== ''): ?>
                <p class="text-muted small mb-0"><?php echo edm_h($subtitle); ?></p>
                <?php endif; ?>
            </div>
            <?php if (!$headerButton): ?>
            <button type="button" class="btn btn-primary btn-sm" id="edm-crud-add">
                <i class="bi bi-plus-lg"></i> <?php echo edm_h($addLabel); ?>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div id="edm-crud-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

        <div class="table-responsive">
            <table class="table table-hover align-middle edm-view-tbl">
                <thead>
                    <tr>
                        <th style="width:3rem;">#</th>
                        <?php foreach ($columns as $label): ?>
                        <th><?php echo edm_h($label); ?></th>
                        <?php endforeach; ?>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody id="edm-crud-rows">
                    <tr><td colspan="<?php echo (int)$colCount; ?>" class="text-center text-muted py-4">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <div class="edm-pager" id="edm-crud-pager"></div>

        <div class="modal fade" id="edm-crud-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog <?php echo edm_h($modalSize); ?>">
                <div class="modal-content">
                    <form id="edm-crud-form">
                        <div class="modal-header">
                            <h5 class="modal-title" id="edm-crud-modal-title">Add</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" id="edm-crud-form-body"></div>
                        <div class="modal-footer">
                            <div id="edm-crud-form-error" class="text-danger small me-auto" hidden></div>
                            <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm" id="edm-crud-save">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}
