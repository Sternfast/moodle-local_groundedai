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
 * Deleting a course removes the conversations stored in it.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

use local_groundedai\local\conversation;

/**
 * Conversations live in a course context. Once that context is gone the privacy subsystem can no longer
 * reach them for export or erasure, so they must be removed with the course.
 *
 * @covers \local_groundedai\observer
 */
final class course_deletion_test extends \advanced_testcase {
    /**
     * A deleted course leaves no conversation, message or attachment rows behind; other courses are
     * untouched.
     */
    public function test_course_deletion_removes_its_conversations(): void {
        global $DB;
        $this->resetAfterTest();

        $gone = $this->getDataGenerator()->create_course();
        $kept = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $ids = [];
        foreach ([$gone, $kept] as $course) {
            $contextid = (int) \context_course::instance($course->id)->id;
            $cid = conversation::create((int) $user->id, $contextid);
            $DB->insert_record('local_groundedai_messages', (object) [
                'conversationid' => $cid, 'role' => 'user', 'content' => 'a private question', 'timecreated' => time(),
            ]);
            $DB->insert_record('local_groundedai_attach', (object) [
                'conversationid' => $cid, 'userid' => $user->id, 'contextid' => $contextid,
                'fileid' => 900000 + (int) $course->id,
                'contenthash' => 'x', 'filename' => 'notes.txt', 'mimetype' => 'text/plain', 'type' => 'txt',
                'status' => 'done', 'content' => 'private notes', 'chars' => 13,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
            $ids[$course->id] = $cid;
        }

        delete_course($gone, false);

        $this->assertFalse($DB->record_exists('local_groundedai_conversations', ['id' => $ids[$gone->id]]));
        $this->assertFalse($DB->record_exists('local_groundedai_messages', ['conversationid' => $ids[$gone->id]]));
        $this->assertFalse($DB->record_exists('local_groundedai_attach', ['conversationid' => $ids[$gone->id]]));

        $this->assertTrue($DB->record_exists('local_groundedai_conversations', ['id' => $ids[$kept->id]]));
        $this->assertTrue($DB->record_exists('local_groundedai_messages', ['conversationid' => $ids[$kept->id]]));
        $this->assertTrue($DB->record_exists('local_groundedai_attach', ['conversationid' => $ids[$kept->id]]));
    }
}
