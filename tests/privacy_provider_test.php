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
 * Tests for the privacy provider.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_groundedai\local\access_manager;
use local_groundedai\local\conversation;
use local_groundedai\privacy\provider;

/**
 * Tests the privacy provider's metadata, export, deletion and preference handling.
 *
 * @covers \local_groundedai\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Metadata describes every store and external flow, including the two user preferences.
     */
    public function test_get_metadata(): void {
        $this->resetAfterTest();
        $collection = new collection('local_groundedai');
        $collection = provider::get_metadata($collection);
        $items = $collection->get_collection();
        // 4 database tables + 2 external links + 2 user preferences.
        $this->assertGreaterThanOrEqual(8, count($items));
    }

    /**
     * The fields declared as sent to the model provider.
     *
     * @return string[] Field names of the 'modelprovider' external location link.
     */
    private function modelprovider_fields(): array {
        $collection = provider::get_metadata(new collection('local_groundedai'));
        foreach ($collection->get_collection() as $item) {
            if (
                $item instanceof \core_privacy\local\metadata\types\external_location
                    && $item->get_name() === 'modelprovider'
            ) {
                return array_keys($item->get_privacy_fields());
            }
        }
        return [];
    }

    /**
     * Every look-up tool states which declared kinds of data its results send to the model provider.
     * The tool list is read from the live tool set, so a tool added later fails here until it is mapped
     * to declared fields (or explicitly to none).
     */
    public function test_every_tool_maps_to_declared_model_provider_fields(): void {
        $this->resetAfterTest();
        $declared = $this->modelprovider_fields();

        // Sent on every question, whatever the grant enables.
        foreach (['question', 'history', 'preferences'] as $always) {
            $this->assertContains($always, $declared);
        }

        $sends = [
            'find'                       => ['participants', 'email', 'coursecontent'],
            'get_course_contents'        => ['coursecontent'],
            'read_material'              => ['coursecontent'],
            'get_course_info'            => ['coursecontent'],
            'get_announcements'          => ['forumposts'],
            'get_upcoming'               => ['coursecontent'],
            'get_activity_settings'      => ['coursecontent'],
            'get_gradebook_structure'    => ['coursecontent'],
            'get_grading_queue'          => ['coursecontent'],
            'get_grades'                 => ['grades'],
            'get_grade_detail'           => ['grades'],
            'get_grade_overview'         => ['grades', 'participants'],
            'get_assignment_status'      => ['grades', 'participants'],
            'get_quiz_attempt_analytics' => ['grades', 'participants'],
            'get_quiz_questions'         => ['quizcontent'],
            'get_forum_activity'         => ['forumposts', 'participants'],
            'get_forum_posts'            => ['forumposts'],
            'get_enrolment_info'         => ['participants', 'activitylog'],
            'get_student_groups'         => ['participants'],
            'get_student_overview'       => ['participants', 'grades', 'completion', 'activitylog'],
            'get_activity_log'           => ['activitylog'],
            'get_completion_overview'    => ['completion', 'activitylog', 'participants'],
            'list_attachments'           => ['attachments'],
            'read_attachment'            => ['attachments'],
            'export_table'               => [], // Writes a file inside Moodle from data already in the conversation.
            'export_document'            => [], // As above.
            'get_action_link'            => [], // Returns links to Moodle pages only.
        ];

        // The live tool set, with every domain and feature on (a site administrator has them all).
        $names = [];
        foreach (\local_groundedai\local\staff\tools::schemas(1, (int) get_admin()->id) as $schema) {
            $names[] = $schema['function']['name'];
        }
        $this->assertNotEmpty($names);
        foreach ($names as $name) {
            $this->assertArrayHasKey($name, $sends, "state which declared data the '{$name}' tool sends");
            foreach ($sends[$name] as $field) {
                $this->assertContains($field, $declared, "'{$name}' sends '{$field}', which must be declared");
            }
        }
        // Nothing is declared that no tool (and no always-sent part) accounts for.
        $accounted = array_merge(['question', 'history', 'preferences'], ...array_values($sends));
        foreach ($declared as $field) {
            $this->assertContains($field, $accounted, "declared field '{$field}' is sent by nothing");
        }

        // Every declared field has a real description.
        foreach ($declared as $field) {
            $this->assertTrue(
                get_string_manager()->string_exists('privacy:metadata:modelprovider:' . $field, 'local_groundedai')
            );
        }
        // The summary must never again claim that grades or learner data stay inside Moodle.
        $summary = get_string('privacy:metadata:modelprovider', 'local_groundedai');
        $this->assertStringNotContainsString('never sent', $summary);
    }

    /**
     * Every user-id column declared as personal data is one the provider really handles: a role grant
     * names no user, so the editor recorded on it must still be discoverable.
     */
    public function test_every_declared_user_column_is_discoverable(): void {
        global $DB;
        $this->resetAfterTest();

        $editor = $this->getDataGenerator()->create_user();
        $grantee = $this->getDataGenerator()->create_user();
        $this->setUser($editor);
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        access_manager::add_role_grant($roleid, access_manager::SCOPE_ALL);
        access_manager::add_user_grant((int) $grantee->id, access_manager::SCOPE_ALL);

        $system = (int) \context_system::instance()->id;
        foreach ([$editor, $grantee] as $user) {
            $ids = array_map('intval', provider::get_contexts_for_userid((int) $user->id)->get_contextids());
            $this->assertContains($system, $ids);
        }

        $userlist = new userlist(\context_system::instance(), 'local_groundedai');
        provider::get_users_in_context($userlist);
        $userids = array_map('intval', $userlist->get_userids());
        $this->assertContains((int) $editor->id, $userids);
        $this->assertContains((int) $grantee->id, $userids);
    }

    /**
     * The administrator recorded on a grant can export that record and have it erased, without the
     * grant itself (someone else's access) being removed.
     */
    public function test_grant_editor_is_exported_and_erased(): void {
        global $DB;
        $this->resetAfterTest();

        $editor = $this->getDataGenerator()->create_user();
        $grantee = $this->getDataGenerator()->create_user();
        $this->setUser($editor);
        $grantid = access_manager::add_user_grant((int) $grantee->id, access_manager::SCOPE_ALL);
        $this->assertSame(
            (int) $editor->id,
            (int) $DB->get_field('local_groundedai_grants', 'usermodified', ['id' => $grantid])
        );

        $system = \context_system::instance();
        $approved = new approved_contextlist($editor, 'local_groundedai', [(int) $system->id]);

        provider::export_user_data($approved);
        $exported = writer::with_context($system)->get_data([
            get_string('pluginname', 'local_groundedai'),
            get_string('privacy:grantsedited', 'local_groundedai'),
        ]);
        $this->assertCount(1, $exported->grants);

        provider::delete_data_for_user($approved);
        $this->assertTrue($DB->record_exists('local_groundedai_grants', ['id' => $grantid]), 'the grantee keeps access');
        $this->assertSame(0, (int) $DB->get_field('local_groundedai_grants', 'usermodified', ['id' => $grantid]));
        $this->assertEmpty(provider::get_contexts_for_userid((int) $editor->id)->get_contextids());
    }

    /**
     * The bulk erasure paths clear the editor id as well.
     */
    public function test_grant_editor_is_erased_in_bulk(): void {
        global $DB;
        $this->resetAfterTest();

        $editor = $this->getDataGenerator()->create_user();
        $this->setUser($editor);
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $grantid = access_manager::add_role_grant($roleid, access_manager::SCOPE_ALL);
        $system = \context_system::instance();

        provider::delete_data_for_users(new approved_userlist($system, 'local_groundedai', [(int) $editor->id]));
        $this->assertSame(0, (int) $DB->get_field('local_groundedai_grants', 'usermodified', ['id' => $grantid]));

        $DB->set_field('local_groundedai_grants', 'usermodified', (int) $editor->id, ['id' => $grantid]);
        provider::delete_data_for_all_users_in_context($system);
        $this->assertTrue($DB->record_exists('local_groundedai_grants', ['id' => $grantid]), 'role grants survive');
        $this->assertSame(0, (int) $DB->get_field('local_groundedai_grants', 'usermodified', ['id' => $grantid]));
    }

    /**
     * A user's conversation context is discoverable, exportable, and deletable.
     */
    public function test_export_and_delete_conversation(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);
        $user = $this->getDataGenerator()->create_user();

        $cid = conversation::create((int) $user->id, (int) $coursecontext->id, 'staff');
        $DB->insert_record('local_groundedai_messages', (object) [
            'conversationid' => $cid, 'role' => 'user', 'content' => 'a private question', 'timecreated' => time(),
        ]);

        // Discoverable (context ids come back from the DB as strings; compare as ints).
        $contextlist = provider::get_contexts_for_userid((int) $user->id);
        $ids = array_map('intval', $contextlist->get_contextids());
        $this->assertContains((int) $coursecontext->id, $ids);

        // Exportable.
        $approved = new approved_contextlist($user, 'local_groundedai', [(int) $coursecontext->id]);
        provider::export_user_data($approved);
        $this->assertTrue(writer::with_context($coursecontext)->has_any_data());

        // Deletable.
        provider::delete_data_for_user($approved);
        $this->assertFalse($DB->record_exists('local_groundedai_conversations', ['id' => $cid]));
        $this->assertSame(0, $DB->count_records('local_groundedai_messages', ['conversationid' => $cid]));
    }

    /**
     * Deleting all data in a context clears conversations for everyone there.
     */
    public function test_delete_all_in_context(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);
        $user = $this->getDataGenerator()->create_user();
        $cid = conversation::create((int) $user->id, (int) $coursecontext->id, 'staff');

        provider::delete_data_for_all_users_in_context($coursecontext);
        $this->assertFalse($DB->record_exists('local_groundedai_conversations', ['id' => $cid]));
    }

    /**
     * The per-user answer-style preference is exported.
     */
    public function test_export_user_preferences(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('local_groundedai_tuning_staff', 'Keep answers brief.', (int) $user->id);

        provider::export_user_preferences((int) $user->id);
        $prefs = writer::with_context(\context_user::instance((int) $user->id))
            ->get_user_preferences('local_groundedai');
        $this->assertObjectHasProperty('local_groundedai_tuning_staff', $prefs);
    }
}
