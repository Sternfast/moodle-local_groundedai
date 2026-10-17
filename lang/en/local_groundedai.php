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
 * English strings.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'AI Teacher Assistant';

// Capabilities.
$string['groundedai:export'] = 'Request an export of records from the AI Teacher Assistant';
$string['groundedai:manage'] = 'Configure the AI Teacher Assistant';

// Settings — the kill switch.
$string['lanes'] = 'Availability';
$string['enablestaff'] = 'Enable the AI Teacher Assistant';
$string['enablestaff_desc'] = 'Turn the read-only AI Teacher Assistant on or off site-wide. Use this to shed load without uninstalling.';
$string['maxperhour'] = 'Questions per user per hour';
$string['maxperhour_desc'] = 'The most questions one staff member may ask the assistant in a rolling hour — a guard against runaway cost on a metered provider key. 0 = no limit.';
$string['ratelimited'] = 'You have reached the limit of {$a} assistant questions this hour. Please try again a little later.';

// Settings — provider (bring your own key; OpenAI-compatible wire).
$string['provider'] = 'Model provider';
$string['baseurl'] = 'API base URL';
$string['baseurl_desc'] = 'OpenAI-compatible endpoint. Point it at your chosen provider, or a local server for self-hosted models.';
$string['err_https_required'] = 'Use an https:// address (http is allowed only for a localhost endpoint), so the API key and course data are never sent in cleartext.';
$string['apikey'] = 'API key';
$string['apikey_desc'] = 'Your provider key. Stored in site config; leave blank for a keyless local endpoint.';
$string['modelcheap'] = 'Default model (cheap tier)';
$string['modelcheap_desc'] = 'Used for classification, building the retrieval query, and summarising — the high-volume, low-stakes work.';
$string['modelfrontier'] = 'Main model (frontier tier)';
$string['modelfrontier_desc'] = 'Used for the assistant\'s answers. The cheap tier is used for short background work such as naming a conversation.';

// Settings — conversation memory.
$string['conversation'] = 'Conversation memory';
$string['conversation_desc'] = 'How much of a conversation the assistant remembers within a single chat, so follow-up questions keep context. History is per conversation; it is never shared across conversations or users.';
$string['historyenabled'] = 'Remember recent turns';
$string['historyenabled_desc'] = 'Send the recent messages of the current conversation to the model so follow-ups like "and what about the other group?" make sense. Off = every question is answered on its own.';
$string['historymaxturns'] = 'Maximum remembered turns';
$string['historymaxturns_desc'] = 'The most recent messages to include (a higher number remembers more but costs more tokens per question).';
$string['historymaxtokens'] = 'Memory token budget';
$string['historymaxtokens_desc'] = 'An approximate cap on how much remembered history is sent, to keep each request affordable and within the model\'s context.';
$string['retentiondays'] = 'Delete conversations after (days)';
$string['retentiondays_desc'] = 'Automatically delete conversations and their messages, attachments and exports once they are this many days old. <strong>0 (the default) keeps them forever</strong> — set a limit to minimise retained student data (conversations can hold grades, names and other PII). On-request erasure through Moodle\'s privacy tools works regardless of this setting.';
$string['task_cleanup'] = 'Delete old conversations';

// Settings — answer style (default template; each user can add their own).
$string['tuning'] = 'Answer style';
$string['tuning_desc'] = 'A default instruction for how the assistant should write its answers. Each staff member can add their own instructions on top of this, in their preferences. These only shape tone and focus — they can never change what the assistant is allowed to see or do.';
$string['defaulttuningstaff'] = 'Default answer style';
$string['defaulttuningstaff_desc'] = 'Shown to the assistant as guidance for every staff answer. Keep it short.';
$string['defaulttuningstaff_default'] = 'Be concise and specific. Lead with the figure or fact, then a short plain reading. Use a table to compare learners or items.';

// Launcher & per-user preferences.
$string['mypreferences'] = 'AI Teacher Assistant';
$string['hidelauncher'] = 'Hide this button';
$string['showlauncher'] = 'Assistant button';
$string['showlauncher_label'] = 'Show the floating assistant button on course pages';
$string['tuningstaff'] = 'Your answer-style instructions';
$string['tuningstaff_help'] = 'Add your own guidance for how the assistant should write answers — for example "keep it brief", "always show a table", or "focus on at-risk learners". This is added on top of the site default above. It only shapes tone and focus; it can never let the assistant see or change anything you could not already.';

// Settings — telemetry.
$string['telemetry'] = 'Telemetry';
$string['otlpendpoint'] = 'OTLP endpoint';
$string['otlpendpoint_desc'] = 'Optional. An OTLP/HTTP endpoint to receive per-answer spans. Leave blank to disable telemetry.';

// Staff lane.
$string['staffassistant'] = 'AI Teacher Assistant';
$string['openstaffassistant'] = 'AI Teacher Assistant';
$string['attachfile'] = 'Attach a file';
$string['uploading'] = 'Uploading…';
$string['uploadtoobig'] = 'That file is larger than the {$a} MB limit.';
$string['noaccess'] = 'You do not have access to the AI Teacher Assistant.';
$string['studentaccessdetected'] = 'Student enrolment attempt detected — the assistant is halted. A student, guest or learner role must never be granted assistant access; remove that grant on the Governance page.';
$string['domaincourses'] = 'Courses & content';
$string['domaincourses_desc'] = 'Let staff ask about courses and their content.';
$string['domaingrades'] = 'Grades';
$string['domaingrades_desc'] = 'Let staff ask about grades (figures come straight from the gradebook).';
$string['domainparticipants'] = 'Participants';
$string['domainparticipants_desc'] = 'Let staff ask who is enrolled.';
$string['domaindates'] = 'Dates & deadlines';
$string['domaindates_desc'] = 'Let staff ask about due dates and deadlines.';
$string['domainprogress'] = 'Progress & at-risk';
$string['domainprogress_desc'] = 'Let staff ask about completion, engagement and at-risk learners.';
$string['domainactions'] = 'How-to action links';
$string['domainactions_desc'] = 'Let the assistant offer a direct link and short steps to the page where a staff member can make a change they already have permission to make (for example edit a grade). The assistant never makes the change itself, and only offers a link when the person already has permission. Off by default.';
$string['domainupload'] = 'File upload';
$string['nodomains'] = 'No staff data domains are enabled. An administrator can enable them in the plugin settings.';
$string['toolloop'] = 'I could not complete that request within the allowed number of steps.';

// File upload + extraction.
$string['files'] = 'File upload';
$string['files_desc'] = 'File upload lets staff attach a file for the assistant to read (text, CSV, Word, Excel, PowerPoint, PDF, ZIP); uploads are only ever read, never executed. Turn upload on under Governance &rarr; sensitive features; set the PDF-to-text tool path here.';
$string['enableupload'] = 'Allow file upload';
$string['pdftotextpath'] = 'Path to pdftotext (optional)';
$string['pdftotextpath_desc'] = 'PDFs are read with a built-in reader, so this is optional. If your server has the poppler "pdftotext" binary, give its full path here for better handling of complex layouts; otherwise leave it blank. Scanned/image-only PDFs have no text to read.';
$string['uploadnofile'] = 'No file was received, or it was not an accepted type.';
$string['uploaddisabled'] = 'File upload is turned off for the AI Teacher Assistant.';
$string['enableexport'] = 'Allow export to files';

// Attachment status (shown in the composer / tool output).
$string['attachstatus_pending'] = 'Reading…';
$string['attachstatus_done'] = 'Ready';
$string['attachstatus_failed'] = 'Could not read';
$string['attachstatus_noext'] = 'No readable text';
$string['attachstatus_notextlayer'] = 'No text layer (scanned)';
$string['attachstatus_rejected'] = 'Rejected for safety';
$string['attachremove'] = 'Remove attachment';

// Chat.
$string['newchat'] = 'New chat';
$string['conversations'] = 'Conversations';
$string['collapsesidebar'] = 'Collapse the conversation list';
$string['showconversations'] = 'Show the conversation list';
$string['chatstab'] = 'Chats';
$string['archivedtab'] = 'Archived';
$string['nochats'] = 'No conversations yet.';
$string['startchat'] = 'Ask a question to get started.';
$string['messageplaceholder'] = 'Type your question…';
$string['aidisclaimer'] = 'AI-generated — figures come from Moodle; verify interpretations before you act.';
$string['send'] = 'Send';
$string['stop'] = 'Stop';
$string['moreactions'] = 'More actions';
$string['sources'] = 'Sources';
$string['rename'] = 'Rename';
$string['archive'] = 'Archive';
$string['unarchive'] = 'Unarchive';
$string['delete'] = 'Delete';
$string['deletechat'] = 'Delete conversation';
$string['deletechatconfirm'] = 'Delete this conversation and its messages? This cannot be undone.';
$string['moremessages'] = 'Earlier messages';
$string['youlabel'] = 'You';
$string['assistantlabel'] = 'Assistant';
$string['thinking'] = 'Thinking';
$string['bucket_today'] = 'Today';
$string['bucket_yesterday'] = 'Yesterday';
$string['bucket_last7'] = 'Previous 7 days';
$string['bucket_last30'] = 'Previous 30 days';
$string['bucket_older'] = 'Older';
$string['participantcontact'] = 'Include student email in people look-ups';
$string['layoutmode'] = 'Size and layout';
$string['windowmode'] = 'Floating window';
$string['dockmode'] = 'Dock to the side';
$string['fullmode'] = 'Full screen';
$string['minimise'] = 'Minimise';
$string['closechat'] = 'Close';
$string['actionstaken'] = 'What the assistant looked up';

// Errors.
$string['emptyquestion'] = 'Please enter a question.';
$string['notyours'] = 'That conversation does not belong to you.';
$string['staffdisabled'] = 'The AI Teacher Assistant is currently turned off for this site.';
$string['noprovider'] = 'No model provider is configured. Set the API base URL in the plugin settings.';
$string['nomodel'] = 'No model is configured for this tier. Set it in the plugin settings.';
$string['requestfailed'] = 'The model request failed: {$a}';
$string['assistantunavailable'] = 'The assistant service could not be reached just now. Please try again shortly, or ask an administrator to check the connection.';

// Privacy.
$string['privacy:metadata:conversations'] = 'Chat conversations a user has with the assistant.';
$string['privacy:metadata:conversations:userid'] = 'The user who owns the conversation.';
$string['privacy:metadata:conversations:name'] = 'The conversation title (taken from the first question).';
$string['privacy:metadata:conversations:lane'] = 'The assistant lane this conversation belongs to.';
$string['privacy:metadata:conversations:timecreated'] = 'When the conversation was created.';
$string['privacy:metadata:conversations:timemodified'] = 'When the conversation was last active.';
$string['privacy:metadata:messages'] = 'The messages within a conversation.';
$string['privacy:metadata:messages:role'] = 'Who sent the message (the user or the assistant).';
$string['privacy:metadata:messages:content'] = 'The message text.';
$string['privacy:metadata:messages:timecreated'] = 'When the message was sent.';
$string['privacy:metadata:attach'] = 'Files a user attaches to a staff conversation and the text extracted from them.';
$string['privacy:metadata:attach:userid'] = 'The user who uploaded the file.';
$string['privacy:metadata:attach:filename'] = 'The uploaded file name.';
$string['privacy:metadata:attach:content'] = 'The plain text extracted from the uploaded file.';
$string['privacy:metadata:attach:timecreated'] = 'When the file was uploaded.';
$string['privacy:accessgrants'] = 'Access grants';
$string['privacy:metadata:grants'] = 'Access grants deciding who may open the AI Teacher Assistant. A grant naming a specific user records that user; a role grant records no user.';
$string['privacy:metadata:grants:userid'] = 'The user a user-grant names (0 for a role grant).';
$string['privacy:metadata:grants:roleid'] = 'The role a role-grant names (0 for a user grant).';
$string['privacy:metadata:grants:coursescope'] = 'Whether the grant covers all courses, enrolled courses only, or one course.';
$string['privacy:metadata:grants:courseid'] = 'The specific course when the grant is course-scoped.';
$string['privacy:metadata:grants:domains'] = 'The data domains the grant enables.';
$string['privacy:metadata:grants:features'] = 'The sensitive features the grant enables.';
$string['privacy:metadata:grants:expiry'] = 'When the grant expires (0 = never).';
$string['privacy:metadata:grants:timecreated'] = 'When the grant was created.';
$string['privacy:metadata:grants:timemodified'] = 'When the grant was last changed.';
$string['privacy:metadata:grants:usermodified'] = 'The id of the administrator who created or last edited the grant.';
$string['privacy:metadata:modelprovider'] = 'To answer a question, the question and the retrieved content are sent to the configured model provider. Grades, user profiles and quiz question-bank content are never sent.';
$string['privacy:metadata:modelprovider:question'] = 'The question the user asked.';
$string['privacy:metadata:modelprovider:content'] = 'Retrieved course content or attached-file text used to answer.';
$string['privacy:metadata:otlpendpoint'] = 'If configured, per-answer telemetry spans are sent to an OTLP endpoint. No message content is included.';
$string['privacy:metadata:otlpendpoint:spans'] = 'Operational metrics about each answer (timings, model, token counts).';
$string['privacy:metadata:preference:hidelauncher'] = 'Whether you have hidden the floating assistant button on course pages.';
$string['privacy:metadata:preference:tuningstaff'] = 'Your own instructions for how the assistant should write its answers.';

// Events (the access log).
$string['event'] = 'Event';
$string['event_assistant_queried'] = 'AI Teacher Assistant queried';
$string['event_access_denied'] = 'AI Teacher Assistant access denied';

// Course-scope labels.
$string['scope_all'] = 'All courses';
$string['scope_enrolled'] = 'Enrolled courses only';

// Governance & compliance console.
$string['governance'] = 'Governance & compliance';
$string['setup'] = 'Setup';
$string['setup_access_heading'] = 'Access control';
$string['setup_access_desc'] = 'Who may use the assistant, each role\'s course scope, the data domains and the sensitive features are all on the <a href="{$a}">Governance &amp; compliance</a> page.';

// Connection test.
$string['conn_test'] = 'Test connection';
$string['conn_test_desc'] = 'Check the endpoint and key work before staff use the assistant.';
$string['conn_title'] = 'AI Teacher Assistant — connection test';
$string['conn_noendpoint'] = 'No model endpoint is set yet. Enter the base URL (and key) above, save, then test.';
$string['conn_ok'] = 'Verified — connected to {$a->model} in {$a->ms} ms.';
$string['conn_fail'] = 'Connection failed: {$a}';
$string['conn_back'] = 'Back to setup';
$string['gov_supports'] = 'Evidence toward:';
$string['gov_controls_count'] = '{$a->total} access controls, {$a->tested} covered by automated tests.';
$string['gov_download_evidence'] = 'Download evidence pack';
$string['gov_open_log'] = 'Open the full site log';
$string['gov_grants'] = 'Who may use the assistant';
$string['gov_grants_desc'] = 'Grant access by role or person, scoped and with an optional expiry. Admins always have access; students never.';

// Access grants.
$string['grants_empty'] = 'No access grants yet — only site administrators can use the assistant.';
$string['grant_addrole'] = '+ Add role';
$string['grant_adduser'] = '+ Add user';
$string['grant_grantee'] = 'Grantee';
$string['grant_scope'] = 'Course scope';
$string['grant_expires'] = 'Expires';
$string['grant_created'] = 'Created';
$string['grant_modified'] = 'Modified';
$string['grant_role'] = 'Role';
$string['grant_user'] = 'User';
$string['grant_course'] = 'Course';
$string['grant_expiry'] = 'Expiry';
$string['grant_stale'] = 'Removed';
$string['grant_expired'] = 'Expired';
$string['grant_edit'] = 'Edit grant';
$string['grant_addrole_title'] = 'Add a role grant';
$string['grant_adduser_title'] = 'Add a user grant';
$string['grant_save'] = 'Save grant';
$string['grant_saved'] = 'Access grant saved.';
$string['grant_deleted'] = 'Access grant removed.';
$string['grant_confirmdelete'] = 'Remove the assistant access grant for {$a}?';
$string['grant_role_none'] = 'Choose a role…';
$string['grant_user_none'] = 'Choose a person…';
$string['grant_course_none'] = 'Choose a course…';
$string['grant_course_hidden'] = '(hidden)';
$string['grant_role_required'] = 'Choose a staff role to grant.';
$string['grant_user_required'] = 'Choose a person to grant.';
$string['grant_course_required'] = 'Choose the course this grant is limited to.';
$string['scope_course'] = 'Specific course(s)';
$string['grant_courses_added'] = '{$a} access grant(s) saved.';
$string['grant_domains'] = 'Data domains';
$string['grant_features'] = 'Sensitive features';
$string['grant_data'] = 'Data';
$string['grant_domains_all'] = 'All domains';
$string['grant_domains_some'] = '{$a} domains';
$string['grant_domains_none'] = 'No data';
$string['evidence_staffenabled'] = 'Assistant enabled';
$string['expiry_never'] = 'Never';
$string['expiry_date'] = 'On a date';
$string['expiry_days'] = 'After a number of days';
$string['expiry_on'] = 'Expiry date';
$string['expiry_after'] = 'Expire after';
$string['expiry_past'] = 'The expiry date must be today or later.';
$string['expiry_tooshort'] = 'Choose at least one day.';
$string['scope_blocked'] = 'Your assistant access does not include this course.';
$string['cannotgrantrole'] = 'That role cannot be granted assistant access.';
$string['invaliduser'] = 'That is not a valid user to grant.';
$string['invalidcourse'] = 'That is not a valid course for a course-scoped grant.';
$string['grantexists'] = 'An identical access grant already exists.';
$string['event_grant_changed'] = 'AI Teacher Assistant access grant changed';
$string['gov_recentlog'] = 'Recent access log';
$string['gov_nolog'] = 'No standard log reader is enabled, so recent events cannot be shown here. Enable the standard log store to keep an access trail.';
$string['gov_logempty'] = 'No assistant access has been logged in this range.';
$string['log_from'] = 'From';
$string['log_to'] = 'To';
$string['log_filter'] = 'Apply filter';
$string['log_download'] = 'Download (CSV)';
$string['log_capped'] = 'Showing the most recent {$a} in this range — use Download to export the full range.';
$string['log_showing'] = 'Showing {$a} event(s) in this range.';

// Evidence pack.
$string['evidence_title'] = 'AI Teacher Assistant — compliance evidence';
$string['evidence_generated'] = 'Generated {$a}';
$string['evidence_posture'] = 'Current settings';
$string['evidence_providerset'] = 'Model endpoint configured';
$string['evidence_telemetryset'] = 'Telemetry endpoint configured';
$string['evidence_on'] = 'On';
$string['evidence_off'] = 'Off';
$string['evidence_adminonly'] = 'Administrators only';
$string['evidence_policies'] = 'Controls';
$string['evidence_frameworks'] = 'Standards crosswalk';
$string['evidence_authority'] = 'Authority:';
$string['evidence_footer'] = 'Generated from the plugin\'s policy register. Supports an institution\'s own compliance assessment; not legal advice.';
$string['evidence_col_policy'] = 'ID';
$string['evidence_col_statement'] = 'Guarantee';
$string['evidence_col_enforced'] = 'Enforced at';
$string['evidence_col_test'] = 'Test';
$string['evidence_col_requirement'] = 'Requirement';
$string['evidence_col_controls'] = 'Controls';
$string['evidence_no_test'] = 'enforced in code';
