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
 * Regression tests for the 2026-10 chat-continuity fixes: the get_messages "after" refresh cursor and the
 * empty-conversation sweep.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

use local_groundedai\local\conversation;

/**
 * The refresh cursor returns only newer turns, and abandoned empty conversations are swept.
 *
 * @covers \local_groundedai\local\conversation::get_messages
 * @covers \local_groundedai\task\cleanup_conversations
 */
final class continuity_test extends \advanced_testcase {
    /**
     * Insert one message row directly (add_message is protected) and return its id.
     *
     * @param int $conversationid Conversation id.
     * @param string $role user|assistant.
     * @param string $content Body.
     * @param int $timecreated Timestamp.
     * @return int New message id.
     */
    private function add_message(int $conversationid, string $role, string $content, int $timecreated): int {
        global $DB;
        return (int) $DB->insert_record('local_groundedai_messages', (object) [
            'conversationid' => $conversationid,
            'role'           => $role,
            'content'        => $content,
            'metadata'       => null,
            'timecreated'    => $timecreated,
        ]);
    }

    /**
     * get_messages(after => id) returns only messages newer than that id, oldest-first — the refresh a
     * second open view uses to pull in turns added elsewhere, without blanking what it already shows.
     */
    public function test_get_messages_after_cursor_returns_only_newer(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($user);
        $ctxid = (int) \context_course::instance((int) $course->id)->id;
        $convid = conversation::create((int) $user->id, $ctxid);

        $now = time();
        $id1 = $this->add_message($convid, 'user', 'first question', $now);
        $id2 = $this->add_message($convid, 'assistant', 'first answer', $now);
        $id3 = $this->add_message($convid, 'user', 'second question', $now);

        $res = conversation::get_messages($convid, (int) $user->id, 0, 30, $id1);
        $this->assertCount(2, $res['messages'], 'only the two rows after id1');
        $this->assertSame($id2, (int) $res['messages'][0]->id);
        $this->assertSame($id3, (int) $res['messages'][1]->id, 'returned oldest-first');
        $this->assertFalse($res['hasmore']);

        // Nothing newer than the last id.
        $this->assertCount(0, conversation::get_messages($convid, (int) $user->id, 0, 30, $id3)['messages']);
    }

    /**
     * The cleanup task sweeps message-less conversations older than an hour, but keeps a recent empty one
     * and any conversation that has messages — regardless of the retention setting (default 0 = keep).
     */
    public function test_cleanup_sweeps_abandoned_empty_conversations(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('retentiondays', 0, 'local_groundedai');   // Keep-forever: the sweep must still run.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $ctxid = (int) \context_course::instance((int) $course->id)->id;

        $oldempty = conversation::create((int) $user->id, $ctxid);
        $DB->set_field('local_groundedai_conversations', 'timemodified', time() - 7200, ['id' => $oldempty]);
        $recentempty = conversation::create((int) $user->id, $ctxid);
        $oldwithmsg = conversation::create((int) $user->id, $ctxid);
        $DB->set_field('local_groundedai_conversations', 'timemodified', time() - 7200, ['id' => $oldwithmsg]);
        $this->add_message($oldwithmsg, 'user', 'keep me', time() - 7200);

        ob_start();
        (new \local_groundedai\task\cleanup_conversations())->execute();
        ob_end_clean();

        $this->assertFalse(
            $DB->record_exists('local_groundedai_conversations', ['id' => $oldempty]),
            'an abandoned empty conversation is swept'
        );
        $this->assertTrue(
            $DB->record_exists('local_groundedai_conversations', ['id' => $recentempty]),
            'a recent empty conversation is kept'
        );
        $this->assertTrue(
            $DB->record_exists('local_groundedai_conversations', ['id' => $oldwithmsg]),
            'a conversation with messages is kept'
        );
    }
}
