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
use core_privacy\local\request\writer;
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
