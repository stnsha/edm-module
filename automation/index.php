<?php
$page_title = 'Workflows';
$page_subtitle = 'Trigger-based automation journeys (Phase 2). The visual canvas is a later feature; define the journey shell here.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New workflow');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

// Email content choices: every newsletter (not archived) and every template.
require __DIR__ . '/../app/bootstrap.php';
// Triggers: built-ins + custom (edm_triggers), then "+ Create new trigger".
$edm_trigger_options = array();
foreach (\Edm\Models\Workflow::triggers() as $key => $label) {
    $edm_trigger_options[] = array('value' => $key, 'label' => $label);
}
$edm_trigger_options[] = array('value' => '__new', 'label' => '+ Create new trigger');
$edm_content_options = array(array('value' => '', 'label' => 'None yet'));
foreach (\Edm\Models\Campaign::listing(null, false) as $c) {
    $edm_content_options[] = array('value' => 'campaign:' . (int)$c['id'], 'label' => $c['name'], 'group' => 'Newsletters');
}
foreach (\Edm\Models\Template::options() as $t) {
    $edm_content_options[] = array(
        'value' => 'template:' . (int)$t['id'],
        'label' => $t['name'] . ($t['category'] ? ' (' . $t['category'] . ')' : ''),
        'group' => 'Templates',
    );
}

edm_crud_screen(array(
    'columns' => array('Name', 'When', 'Email', 'Status'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'automation/api.php',
    entity: 'workflow',
    actions: { list: 'workflows_list', create: 'workflows_create', update: 'workflows_update', 'delete': 'workflows_delete' },
    badges: { status: {
        1: { cls: 'edm-pill-secondary', label: 'Draft' },
        2: { cls: 'edm-pill-success', label: 'Active' },
        3: { cls: 'edm-pill-warning', label: 'Paused' }
    } },
    columns: [
        { key: 'name', label: 'Name' },
        { key: 'when_label', label: 'When' },
        { key: 'content_label', label: 'Email', value: function (r) { return r.content_label || 'None yet'; } },
        { key: 'status', label: 'Status', type: 'badge' }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'description', label: 'Description', type: 'text' },
        // When to send (Workflow::timingInput): an event, or a schedule.
        { name: 'trigger_type', label: 'When to send', type: 'select', options: [
            { value: 'event', label: 'When something happens (event)' },
            { value: 'schedule', label: 'On a schedule (date / repeating)' }
        ] },
        { name: 'trigger', label: 'Event', type: 'select', options: <?php echo json_encode($edm_trigger_options); ?> },
        { name: 'new_trigger', label: 'New trigger name', type: 'text',
          help: 'Saved with this workflow, then offered for every workflow.' },
        { name: 'send_mode', label: 'Send', type: 'select', options: [
            { value: 'now', label: 'Immediately after the event' },
            { value: 'delay', label: 'After a delay' }
        ] },
        { name: 'delay_value', label: 'Delay', type: 'number' },
        { name: 'delay_unit', label: 'Delay unit', type: 'select', options: [
            { value: 'minutes', label: 'minutes' }, { value: 'hours', label: 'hours' }, { value: 'days', label: 'days' }
        ] },
        { name: 'send_time', label: 'At time of day (optional)', type: 'time',
          help: 'Leave empty to send as soon as the delay is over.' },
        { name: 'repeat', label: 'Repeat', type: 'select', options: [
            { value: 'once', label: 'Once' }, { value: 'daily', label: 'Every day' },
            { value: 'weekly', label: 'Every week' }, { value: 'monthly', label: 'Every month' }
        ] },
        { name: 'schedule_date', label: 'Date', type: 'date' },
        { name: 'schedule_weekday', label: 'Day of the week', type: 'select', options: [
            { value: 1, label: 'Monday' }, { value: 2, label: 'Tuesday' }, { value: 3, label: 'Wednesday' },
            { value: 4, label: 'Thursday' }, { value: 5, label: 'Friday' }, { value: 6, label: 'Saturday' }, { value: 7, label: 'Sunday' }
        ] },
        { name: 'schedule_day', label: 'Day of the month', type: 'number', help: '1 to 31.' },
        { name: 'schedule_time', label: 'Time', type: 'time' },
        { name: 'content', label: 'Newsletter / template', type: 'select', options: <?php echo json_encode($edm_content_options); ?>,
          help: 'The newsletter or template this workflow sends.' },
        { name: 'status', label: 'Status', type: 'select', options: [
            { value: 1, label: 'Draft' }, { value: 2, label: 'Active' }, { value: 3, label: 'Paused' }
        ] }
    ],
    // Only the fields of the chosen "When to send" show: event (trigger,
    // send now / after a delay, time of day) or schedule (repeat + its date /
    // weekday / day + time). "+ Create new trigger" shows the name field; the
    // trigger is created with the workflow on Save (AutomationController).
    onFormOpen: function (row, bodyEl) {
        var el = function (n) { return bodyEl.querySelector('#edm-f-' + n); };
        var wrap = function (n) { var e = el(n); return e ? (e.closest('.mb-3') || e.parentNode) : null; };
        var show = function (n, on) { var w = wrap(n); if (w) { w.hidden = !on; } };
        var sync = function () {
            var event = el('trigger_type').value !== 'schedule';
            var delay = el('send_mode').value === 'delay';
            var repeat = el('repeat').value;
            show('trigger', event);
            show('new_trigger', event && el('trigger').value === '__new');
            show('send_mode', event);
            show('delay_value', event && delay);
            show('delay_unit', event && delay);
            show('send_time', event);
            show('repeat', !event);
            show('schedule_date', !event && repeat === 'once');
            show('schedule_weekday', !event && repeat === 'weekly');
            show('schedule_day', !event && repeat === 'monthly');
            show('schedule_time', !event);
        };
        if (!bodyEl.dataset.whenWired) {
            bodyEl.dataset.whenWired = '1';
            ['trigger_type', 'trigger', 'send_mode', 'repeat'].forEach(function (n) {
                el(n).addEventListener('change', function () {
                    sync();
                    if (n === 'trigger' && el('trigger').value === '__new') { el('new_trigger').focus(); }
                });
            });
        }
        if (!row) {
            // New workflow: an event, sent immediately; a delay defaults to days.
            el('trigger_type').value = 'event';
            el('send_mode').value = 'now';
            el('delay_unit').value = 'days';
            el('repeat').value = 'once';
        }
        el('new_trigger').value = '';
        sync();
    },
    // A trigger created with a workflow joins the select for the next form.
    onLoad: function (rows) {
        var tf = window.EDM_CRUD_CONFIG.fields.filter(function (f) { return f.name === 'trigger'; })[0];
        var sel = document.getElementById('edm-f-trigger');
        (rows || []).forEach(function (r) {
            if (!r.trigger || tf.options.some(function (o) { return o.value === r.trigger; })) { return; }
            var o = { value: r.trigger, label: r.trigger_label || r.trigger };
            tf.options.splice(tf.options.length - 1, 0, o);
            if (sel) {
                var opt = document.createElement('option');
                opt.value = o.value;
                opt.textContent = o.label;
                sel.insertBefore(opt, sel.querySelector('option[value="__new"]'));
            }
        });
    }
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
