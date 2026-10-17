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
 * Admin settings.
 *
 * Everything the plugin adds lives under ONE parent category, "AI Teacher Assistant", so its pages are
 * never scattered among the other Local plugins. Two pages, each owning its settings with no overlap:
 *   - Setup: how the assistant is wired (provider/key, models, memory, answer style, PDF tool, telemetry).
 *   - Governance & compliance: access grants (who may use it, course scope, expiry), data domains,
 *     features, the evidence pack and the access log.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // One parent category for all of the plugin's pages.
    $ADMIN->add('localplugins', new admin_category(
        'local_groundedai_cat',
        get_string('pluginname', 'local_groundedai')
    ));

    // Page 1 — Setup (how the assistant is wired).
    $settings = new admin_settingpage('local_groundedai', get_string('setup', 'local_groundedai'));
    $ADMIN->add('local_groundedai_cat', $settings);

    // Page 2 — Governance & compliance (who may use it, scope, domains, features, evidence, log).
    $ADMIN->add('local_groundedai_cat', new admin_externalpage(
        'local_groundedai_governance',
        get_string('governance', 'local_groundedai'),
        new moodle_url('/local/groundedai/governance.php'),
        'local/groundedai:manage'
    ));

    // One-line pointer: access control (grants, domains, features) is on the Governance page.
    $settings->add(new admin_setting_heading(
        'local_groundedai/accesspointer',
        get_string('setup_access_heading', 'local_groundedai'),
        get_string(
            'setup_access_desc',
            'local_groundedai',
            (new moodle_url('/local/groundedai/governance.php'))->out()
        )
    ));

    // The kill switch — shed load without uninstalling.
    $settings->add(new admin_setting_heading('local_groundedai/lanes', get_string('lanes', 'local_groundedai'), ''));
    $settings->add(new admin_setting_configcheckbox(
        'local_groundedai/enablestaff',
        get_string('enablestaff', 'local_groundedai'),
        get_string('enablestaff_desc', 'local_groundedai'),
        1
    ));
    $settings->add(new admin_setting_configtext(
        'local_groundedai/maxperhour',
        get_string('maxperhour', 'local_groundedai'),
        get_string('maxperhour_desc', 'local_groundedai'),
        60,
        PARAM_INT
    ));

    // Provider — bring your own key over the OpenAI-compatible wire.
    $settings->add(new admin_setting_heading('local_groundedai/provider', get_string('provider', 'local_groundedai'), ''));
    $settings->add(new \local_groundedai\admin\setting_endpoint_url(
        'local_groundedai/baseurl',
        get_string('baseurl', 'local_groundedai'),
        get_string('baseurl_desc', 'local_groundedai'),
        '',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_groundedai/apikey',
        get_string('apikey', 'local_groundedai'),
        get_string('apikey_desc', 'local_groundedai'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_groundedai/modelcheap',
        get_string('modelcheap', 'local_groundedai'),
        get_string('modelcheap_desc', 'local_groundedai'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_groundedai/modelfrontier',
        get_string('modelfrontier', 'local_groundedai'),
        get_string('modelfrontier_desc', 'local_groundedai'),
        ''
    ));
    // One-click check that the endpoint and key actually work, before staff rely on the assistant.
    $settings->add(new admin_setting_heading(
        'local_groundedai/conntest',
        '',
        html_writer::link(
            new moodle_url('/local/groundedai/testconnection.php'),
            get_string('conn_test', 'local_groundedai'),
            ['class' => 'btn btn-secondary']
        ) . ' ' . html_writer::span(get_string('conn_test_desc', 'local_groundedai'), 'text-muted')
    ));

    // Conversation memory — a sliding window of recent turns so follow-ups keep context.
    $settings->add(new admin_setting_heading(
        'local_groundedai/conversation',
        get_string('conversation', 'local_groundedai'),
        get_string('conversation_desc', 'local_groundedai')
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_groundedai/historyenabled',
        get_string('historyenabled', 'local_groundedai'),
        get_string('historyenabled_desc', 'local_groundedai'),
        1
    ));
    $settings->add(new admin_setting_configtext(
        'local_groundedai/historymaxturns',
        get_string('historymaxturns', 'local_groundedai'),
        get_string('historymaxturns_desc', 'local_groundedai'),
        10,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_groundedai/historymaxtokens',
        get_string('historymaxtokens', 'local_groundedai'),
        get_string('historymaxtokens_desc', 'local_groundedai'),
        3000,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_groundedai/retentiondays',
        get_string('retentiondays', 'local_groundedai'),
        get_string('retentiondays_desc', 'local_groundedai'),
        0,
        PARAM_INT
    ));

    // Answer style — a default "how to answer" template; each user can add their own in their preferences.
    $settings->add(new admin_setting_heading(
        'local_groundedai/tuning',
        get_string('tuning', 'local_groundedai'),
        get_string('tuning_desc', 'local_groundedai')
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_groundedai/defaulttuningstaff',
        get_string('defaulttuningstaff', 'local_groundedai'),
        get_string('defaulttuningstaff_desc', 'local_groundedai'),
        get_string('defaulttuningstaff_default', 'local_groundedai')
    ));

    // File upload — the PDF-to-text tool path. Upload itself is switched on under Governance → features.
    $settings->add(new admin_setting_heading(
        'local_groundedai/files',
        get_string('files', 'local_groundedai'),
        get_string('files_desc', 'local_groundedai')
    ));
    $settings->add(new admin_setting_configexecutable(
        'local_groundedai/pdftotextpath',
        get_string('pdftotextpath', 'local_groundedai'),
        get_string('pdftotextpath_desc', 'local_groundedai'),
        ''
    ));

    // Telemetry — optional OTLP/HTTP endpoint.
    $settings->add(new admin_setting_heading('local_groundedai/telemetry', get_string('telemetry', 'local_groundedai'), ''));
    $settings->add(new \local_groundedai\admin\setting_endpoint_url(
        'local_groundedai/otlpendpoint',
        get_string('otlpendpoint', 'local_groundedai'),
        get_string('otlpendpoint_desc', 'local_groundedai'),
        '',
        PARAM_URL
    ));
}
