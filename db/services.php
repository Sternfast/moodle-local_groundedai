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
 * Web service functions (AJAX) for the chat UI.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_groundedai_get_conversations' => [
        'classname'   => 'local_groundedai\external\get_conversations',
        'methodname'  => 'execute',
        'description' => 'List the user\'s conversations in a context + lane.',
        'type'        => 'read',
        'ajax'        => true,
    ],
    'local_groundedai_create_conversation' => [
        'classname'   => 'local_groundedai\external\create_conversation',
        'methodname'  => 'execute',
        'description' => 'Create an empty conversation.',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'local_groundedai_rename_conversation' => [
        'classname'   => 'local_groundedai\external\rename_conversation',
        'methodname'  => 'execute',
        'description' => 'Rename a conversation.',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'local_groundedai_archive_conversation' => [
        'classname'   => 'local_groundedai\external\archive_conversation',
        'methodname'  => 'execute',
        'description' => 'Archive or unarchive a conversation.',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'local_groundedai_delete_conversation' => [
        'classname'   => 'local_groundedai\external\delete_conversation',
        'methodname'  => 'execute',
        'description' => 'Delete a conversation and its messages.',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'local_groundedai_get_messages' => [
        'classname'   => 'local_groundedai\external\get_messages',
        'methodname'  => 'execute',
        'description' => 'Fetch a page of messages.',
        'type'        => 'read',
        'ajax'        => true,
    ],
    'local_groundedai_send_message' => [
        'classname'   => 'local_groundedai\external\send_message',
        'methodname'  => 'execute',
        'description' => 'Send a message and return the reply.',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'local_groundedai_get_attachments' => [
        'classname'   => 'local_groundedai\external\get_attachments',
        'methodname'  => 'execute',
        'description' => 'List a conversation\'s attachments.',
        'type'        => 'read',
        'ajax'        => true,
    ],
    'local_groundedai_prepare_upload' => [
        'classname'   => 'local_groundedai\external\prepare_upload',
        'methodname'  => 'execute',
        'description' => 'Allocate a fresh draft area for one upload.',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'local_groundedai_upload_file' => [
        'classname'   => 'local_groundedai\external\upload_file',
        'methodname'  => 'execute',
        'description' => 'Attach an uploaded draft file to a conversation.',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'local_groundedai_delete_attachment' => [
        'classname'   => 'local_groundedai\external\delete_attachment',
        'methodname'  => 'execute',
        'description' => 'Remove an attachment from a conversation.',
        'type'        => 'write',
        'ajax'        => true,
    ],
];
