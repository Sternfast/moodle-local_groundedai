<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * AI-access governance and compliance console.
 *
 * One screen to see the compliance posture, control every AI access (which roles, each role's course
 * scope, which data domains, which sensitive features) with one save, download the evidence pack, and
 * review the access log. Students are structurally absent from the controls.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_groundedai\local\access_manager;
use local_groundedai\local\compliance_map;
use local_groundedai\local\evidence;
use local_groundedai\local\policy;

/** @var int How many log rows to show on screen (download the range for the rest). */
const GAI_LOG_SCREEN_CAP = 50;

/** @var int Hard cap on rows in a CSV export, so it is safe even on a huge log store. */
const GAI_LOG_CSV_CAP = 10000;

$action = optional_param('action', '', PARAM_ALPHA);
$context = context_system::instance();

// The evidence pack is served as a standalone HTML file download (open or print to PDF), so the
// console stays put when the button is clicked.
if ($action === 'evidence') {
    require_login();
    require_capability('local/groundedai:manage', $context);
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="ai-teacher-assistant-evidence.html"');
    echo evidence::render_document();
    exit;
}

// The access-log date range (YYYY-MM-DD; defaults to the last 30 days). Shared by the view and the CSV.
$logfrom = optional_param('logfrom', '', PARAM_TEXT);
$logto = optional_param('logto', '', PARAM_TEXT);
$logfromts = ($logfrom !== '' && strtotime($logfrom . ' 00:00:00')) ? strtotime($logfrom . ' 00:00:00')
    : time() - 30 * DAYSECS;
$logtots = ($logto !== '' && strtotime($logto . ' 23:59:59')) ? strtotime($logto . ' 23:59:59') : time();

// The access log as a downloadable CSV over the selected range (bounded, so it is safe at scale).
if ($action === 'logcsv') {
    require_login();
    require_capability('local/groundedai:manage', $context);
    local_groundedai_download_log_csv($logfromts, $logtots);
    exit;
}

admin_externalpage_setup('local_groundedai_governance');
require_capability('local/groundedai:manage', $context);
access_manager::reset_caches();

$PAGE->set_url(new moodle_url('/local/groundedai/governance.php'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('governance', 'local_groundedai'));

// Access grants lead the page — who may use the assistant, each grant's course scope and expiry.
echo $OUTPUT->heading(get_string('gov_grants', 'local_groundedai'), 3);
echo html_writer::tag('p', get_string('gov_grants_desc', 'local_groundedai'), ['class' => 'text-muted']);
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/groundedai/grant_edit.php', ['type' => access_manager::GRANTEE_ROLE]),
        get_string('grant_addrole', 'local_groundedai'),
        ['class' => 'btn btn-primary']
    )
    . html_writer::link(
        new moodle_url('/local/groundedai/grant_edit.php', ['type' => access_manager::GRANTEE_USER]),
        get_string('grant_adduser', 'local_groundedai'),
        ['class' => 'btn btn-secondary']
    ),
    'd-flex flex-wrap mb-3',
    ['style' => 'gap:.6rem;']
);
echo render_grants_table();

// The recent access log.
echo $OUTPUT->heading(get_string('gov_recentlog', 'local_groundedai'), 3);
echo render_log_toolbar($logfromts, $logtots);
echo render_recent_log($logfromts, $logtots);

// At the bottom: the standards this maps to (types only) and the downloads.
echo html_writer::empty_tag('hr', ['style' => 'margin-top:2rem;']);
$chips = '';
foreach (compliance_map::frameworks() as $f) {
    $chips .= html_writer::tag(
        'span',
        s($f['name']),
        ['class' => 'badge badge-info', 'style' => 'margin:0 .35rem .4rem 0;font-size:.85rem;',
            'title' => s($f['short'])]
    ) . ' ';
}
echo html_writer::tag('p', html_writer::tag('strong', get_string('gov_supports', 'local_groundedai') . ' ') . $chips);
echo html_writer::tag(
    'p',
    get_string(
        'gov_controls_count',
        'local_groundedai',
        (object) ['total' => count(policy::register()), 'tested' => evidence::tested_count()]
    ),
    ['class' => 'text-muted']
);

// Downloads — spaced buttons.
$evidenceurl = new moodle_url('/local/groundedai/governance.php', ['action' => 'evidence']);
$logurl = new moodle_url('/report/log/index.php', ['chooselog' => 1, 'id' => SITEID]);
echo html_writer::div(
    html_writer::link(
        $evidenceurl,
        get_string('gov_download_evidence', 'local_groundedai'),
        ['class' => 'btn btn-secondary']
    )
    . html_writer::link(
        $logurl,
        get_string('gov_open_log', 'local_groundedai'),
        ['class' => 'btn btn-secondary']
    ),
    'd-flex flex-wrap mt-1',
    ['style' => 'gap:.6rem;']
);

echo $OUTPUT->footer();

/**
 * Render the access-grants table: one row per grant, with per-row edit/delete actions.
 *
 * @return string
 */
function render_grants_table(): string {
    global $OUTPUT;
    $rows = access_manager::list_grants();
    if (!$rows) {
        return html_writer::tag('p', get_string('grants_empty', 'local_groundedai'), ['class' => 'text-muted']);
    }
    $table = new html_table();
    $table->attributes['class'] = 'generaltable local-groundedai-grants';
    $table->head = [
        get_string('grant_grantee', 'local_groundedai'),
        get_string('grant_scope', 'local_groundedai'),
        get_string('grant_data', 'local_groundedai'),
        get_string('grant_expires', 'local_groundedai'),
        get_string('grant_created', 'local_groundedai'),
        get_string('grant_modified', 'local_groundedai'),
        get_string('actions'),
    ];
    $datefmt = get_string('strftimedate', 'langconfig');
    foreach ($rows as $r) {
        $badge = $r->type === access_manager::GRANTEE_ROLE
            ? html_writer::span(get_string('grant_role', 'local_groundedai'), 'badge badge-info')
            : html_writer::span(get_string('grant_user', 'local_groundedai'), 'badge badge-secondary');
        $grantee = $badge . ' ' . s($r->grantee);
        if ($r->stale) {
            $grantee .= ' ' . html_writer::span(get_string('grant_stale', 'local_groundedai'), 'badge badge-warning');
        }

        if ($r->expiry === 0) {
            $expires = html_writer::span(get_string('expiry_never', 'local_groundedai'), 'text-muted');
        } else {
            $expires = ($r->active ? '' : html_writer::span(
                get_string('grant_expired', 'local_groundedai'),
                'badge badge-secondary'
            ) . ' ') . userdate($r->expiry, $datefmt);
        }
        $modified = $r->timemodified > $r->timecreated ? userdate($r->timemodified, $datefmt) : '—';

        $editurl = new moodle_url('/local/groundedai/grant_edit.php', ['id' => $r->id]);
        $delurl = new moodle_url('/local/groundedai/grant_edit.php', ['id' => $r->id, 'action' => 'delete']);
        $actions = $OUTPUT->action_icon($editurl, new pix_icon('t/edit', get_string('edit')))
            . $OUTPUT->action_icon($delurl, new pix_icon('t/delete', get_string('delete')));

        $data = s($r->domainlabel);
        if ($r->features) {
            $data .= ' ' . html_writer::span('+ ' . s(implode('/', $r->features)), 'badge badge-light');
        }
        $row = new html_table_row([
            $grantee,
            s($r->scopelabel),
            $data,
            $expires,
            userdate($r->timecreated, $datefmt),
            $modified,
            $actions,
        ]);
        if (!$r->active || $r->stale) {
            $row->attributes['class'] = 'text-muted';
        }
        $table->data[] = $row;
    }
    return html_writer::table($table);
}

/**
 * The first standard SQL log reader, or null if none is enabled.
 *
 * @return \core\log\sql_reader|null
 */
function local_groundedai_log_reader() {
    foreach (get_log_manager()->get_readers('\core\log\sql_reader') as $candidate) {
        return $candidate;
    }
    return null;
}

/**
 * The toolbar above the log: a date-range filter and a CSV download, plus a "capped" note.
 *
 * @param int $from Range start (unix time).
 * @param int $to Range end (unix time).
 * @return string
 */
function render_log_toolbar(int $from, int $to): string {
    $base = new moodle_url('/local/groundedai/governance.php');
    $fromval = date('Y-m-d', $from);
    $toval = date('Y-m-d', $to);

    // A tiny GET form: two date inputs + filter. Native date inputs keep it dependency-free.
    $form = html_writer::start_tag('form', ['method' => 'get', 'action' => $base->out(false),
        'class' => 'form-inline', 'style' => 'display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;']);
    $form .= html_writer::tag('label', get_string('log_from', 'local_groundedai') . ' '
        . html_writer::empty_tag('input', ['type' => 'date', 'name' => 'logfrom', 'value' => $fromval,
            'class' => 'form-control']), []);
    $form .= html_writer::tag('label', get_string('log_to', 'local_groundedai') . ' '
        . html_writer::empty_tag('input', ['type' => 'date', 'name' => 'logto', 'value' => $toval,
            'class' => 'form-control']), []);
    $form .= html_writer::empty_tag('input', ['type' => 'submit',
        'value' => get_string('log_filter', 'local_groundedai'), 'class' => 'btn btn-secondary']);
    $form .= html_writer::end_tag('form');

    $csvurl = new moodle_url(
        '/local/groundedai/governance.php',
        ['action' => 'logcsv', 'logfrom' => $fromval, 'logto' => $toval]
    );
    $download = html_writer::link(
        $csvurl,
        get_string('log_download', 'local_groundedai'),
        ['class' => 'btn btn-secondary']
    );

    return html_writer::div(
        html_writer::div($form, '', ['style' => 'flex:1 1 auto;'])
        . html_writer::div($download, '', ['style' => 'align-self:flex-end;']),
        'mb-2',
        ['style' => 'display:flex;flex-wrap:wrap;gap:1rem;justify-content:space-between;align-items:flex-end;']
    );
}

/**
 * Render the assistant access events in the selected range, capped on screen.
 *
 * @param int $from Range start (unix time).
 * @param int $to Range end (unix time).
 * @return string
 */
function render_recent_log(int $from, int $to): string {
    $reader = local_groundedai_log_reader();
    if (!$reader) {
        return html_writer::tag('p', get_string('gov_nolog', 'local_groundedai'), ['class' => 'text-muted']);
    }
    $select = "component = :component AND timecreated >= :from AND timecreated <= :to";
    $params = ['component' => 'local_groundedai', 'from' => $from, 'to' => $to];
    // Fetch one more than the cap so we can tell the admin there are more to download.
    $events = $reader->get_events_select($select, $params, 'timecreated DESC', 0, GAI_LOG_SCREEN_CAP + 1);
    if (!$events) {
        return html_writer::tag('p', get_string('gov_logempty', 'local_groundedai'), ['class' => 'text-muted']);
    }
    $more = count($events) > GAI_LOG_SCREEN_CAP;
    $events = array_slice($events, 0, GAI_LOG_SCREEN_CAP);

    $head = html_writer::tag(
        'tr',
        html_writer::tag('th', get_string('time'))
        . html_writer::tag('th', get_string('event', 'local_groundedai'))
        . html_writer::tag('th', get_string('description'))
    );
    $body = '';
    foreach ($events as $event) {
        $body .= html_writer::tag(
            'tr',
            html_writer::tag('td', userdate($event->timecreated))
            . html_writer::tag('td', s($event->get_name()))
            . html_writer::tag('td', s($event->get_description()))
        );
    }
    $table = html_writer::tag('table', $head . $body, ['class' => 'generaltable']);
    $note = $more
        ? get_string('log_capped', 'local_groundedai', GAI_LOG_SCREEN_CAP)
        : get_string('log_showing', 'local_groundedai', count($events));
    return $table . html_writer::tag('p', $note, ['class' => 'text-muted']);
}

/**
 * Stream the access log over a range as a CSV download (capped), then exit.
 *
 * @param int $from Range start (unix time).
 * @param int $to Range end (unix time).
 */
function local_groundedai_download_log_csv(int $from, int $to): void {
    global $CFG;
    require_once($CFG->libdir . '/csvlib.class.php');
    $csv = new csv_export_writer();
    $csv->set_filename('ai-teacher-assistant-access-log');
    $csv->add_data([get_string('time'), get_string('event', 'local_groundedai'), get_string('description')]);
    $reader = local_groundedai_log_reader();
    if ($reader) {
        $select = "component = :component AND timecreated >= :from AND timecreated <= :to";
        $params = ['component' => 'local_groundedai', 'from' => $from, 'to' => $to];
        $events = $reader->get_events_select($select, $params, 'timecreated DESC', 0, GAI_LOG_CSV_CAP);
        foreach ($events as $event) {
            $csv->add_data([
                userdate($event->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                $event->get_name(),
                $event->get_description(),
            ]);
        }
    }
    $csv->download_file();
}
