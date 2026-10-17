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
 * Tests for the conversation store, the sliding-window memory, and the title cleaner.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

use local_groundedai\local\conversation;

/**
 * Tests the conversation store, the sliding-window memory, and the title cleaner.
 *
 * @covers \local_groundedai\local\conversation
 */
final class conversation_test extends \advanced_testcase {
    /** @var int A course context id to hang conversations on. */
    private int $contextid;

    /** @var int The owning user. */
    private int $userid;

    /**
     * Create a course + user to work with.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->contextid = (int) \context_course::instance($course->id)->id;
        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
    }

    /**
     * Insert a message row directly (bypassing the model) for history/paging tests.
     *
     * @param int $cid Conversation id.
     * @param string $role Role.
     * @param string $content Content.
     * @return int The new message id.
     */
    private function add(int $cid, string $role, string $content): int {
        global $DB;
        return (int) $DB->insert_record('local_groundedai_messages', (object) [
            'conversationid' => $cid,
            'role'           => $role,
            'content'        => $content,
            'timecreated'    => time(),
        ]);
    }

    /**
     * A fresh conversation is created as staff, active, and named "New chat".
     */
    public function test_create_and_list(): void {
        $cid = conversation::create($this->userid, $this->contextid, 'staff');
        $this->assertGreaterThan(0, $cid);

        $active = conversation::get_list($this->userid, 'staff', $this->contextid, false);
        $this->assertCount(1, $active);
        $this->assertSame(get_string('newchat', 'local_groundedai'), $active[0]->name);
        $this->assertSame([], conversation::get_list($this->userid, 'staff', $this->contextid, true));
    }

    /**
     * Rename then archive moves the conversation between the active and archived lists.
     */
    public function test_rename_and_archive(): void {
        $cid = conversation::create($this->userid, $this->contextid, 'staff');
        conversation::rename($cid, $this->userid, 'My thread');
        conversation::set_archived($cid, $this->userid, true);

        $this->assertSame([], conversation::get_list($this->userid, 'staff', $this->contextid, false));
        $archived = conversation::get_list($this->userid, 'staff', $this->contextid, true);
        $this->assertCount(1, $archived);
        $this->assertSame('My thread', $archived[0]->name);
    }

    /**
     * Deleting a conversation removes it and its messages.
     */
    public function test_delete_cascades_messages(): void {
        global $DB;
        $cid = conversation::create($this->userid, $this->contextid, 'staff');
        $this->add($cid, 'user', 'q');
        $this->add($cid, 'assistant', 'a');
        $this->assertSame(2, $DB->count_records('local_groundedai_messages', ['conversationid' => $cid]));

        conversation::delete($cid, $this->userid);
        $this->assertFalse($DB->record_exists('local_groundedai_conversations', ['id' => $cid]));
        $this->assertSame(0, $DB->count_records('local_groundedai_messages', ['conversationid' => $cid]));
    }

    /**
     * A user cannot touch another user's conversation.
     */
    public function test_ownership_is_enforced(): void {
        $cid = conversation::create($this->userid, $this->contextid, 'staff');
        $other = (int) $this->getDataGenerator()->create_user()->id;
        $this->expectException(\moodle_exception::class);
        conversation::rename($cid, $other, 'hijack');
    }

    /**
     * get_messages pages oldest-first with a hasmore flag and a before cursor.
     */
    public function test_get_messages_paging(): void {
        $cid = conversation::create($this->userid, $this->contextid, 'staff');
        for ($i = 1; $i <= 5; $i++) {
            $this->add($cid, $i % 2 ? 'user' : 'assistant', 'message ' . $i);
        }
        $page = conversation::get_messages($cid, $this->userid, 0, 3);
        $this->assertTrue($page['hasmore']);
        $this->assertCount(3, $page['messages']);
        // Oldest-first within the page: the last page of 5 with limit 3 = messages 3,4,5.
        $this->assertSame('message 3', $page['messages'][0]->content);
        $this->assertSame('message 5', $page['messages'][2]->content);

        $older = conversation::get_messages($cid, $this->userid, (int) $page['messages'][0]->id, 3);
        $this->assertFalse($older['hasmore']);
        $this->assertSame('message 1', $older['messages'][0]->content);
    }

    /**
     * history_for_model caps to the turn count, keeps order oldest-first, and never starts on assistant.
     */
    public function test_history_window_turn_cap(): void {
        set_config('historyenabled', 1, 'local_groundedai');
        set_config('historymaxturns', 4, 'local_groundedai');
        set_config('historymaxtokens', 3000, 'local_groundedai');

        $cid = conversation::create($this->userid, $this->contextid, 'staff');
        $this->add($cid, 'user', 'u1');
        $this->add($cid, 'assistant', 'a1');
        $this->add($cid, 'user', 'u2');
        $this->add($cid, 'assistant', 'a2');
        $this->add($cid, 'user', 'u3');
        $this->add($cid, 'assistant', 'a3');

        $hist = conversation::history_for_model($cid, 0);
        $this->assertLessThanOrEqual(4, count($hist));
        $this->assertSame('user', $hist[0]['role'], 'window must not begin on an assistant turn');
        // Oldest-first ordering is preserved.
        $contents = array_column($hist, 'content');
        $this->assertSame($contents, array_values(array_filter($contents)));
        $this->assertSame('a3', end($hist)['content']);
    }

    /**
     * history_for_model returns nothing when the memory feature is disabled.
     */
    public function test_history_disabled(): void {
        set_config('historyenabled', 0, 'local_groundedai');
        $cid = conversation::create($this->userid, $this->contextid, 'staff');
        $this->add($cid, 'user', 'u1');
        $this->assertSame([], conversation::history_for_model($cid, 0));
    }

    /**
     * history_for_model excludes the just-sent turn (id >= excludemsgid is skipped).
     */
    public function test_history_excludes_current_turn(): void {
        set_config('historyenabled', 1, 'local_groundedai');
        set_config('historymaxturns', 10, 'local_groundedai');
        $cid = conversation::create($this->userid, $this->contextid, 'staff');
        $this->add($cid, 'user', 'earlier');
        $this->add($cid, 'assistant', 'reply');
        $current = $this->add($cid, 'user', 'the new question');

        $hist = conversation::history_for_model($cid, $current);
        $contents = array_column($hist, 'content');
        $this->assertContains('earlier', $contents);
        $this->assertNotContains('the new question', $contents);
    }

    /**
     * The title cleaner strips quotes/labels, collapses whitespace, and caps length + word count.
     *
     * @dataProvider title_cases
     * @param string $input Raw candidate.
     * @param string $expected Cleaned title.
     */
    public function test_trim_title(string $input, string $expected): void {
        $method = new \ReflectionMethod(conversation::class, 'trim_title');
        $method->setAccessible(true);
        $this->assertSame($expected, $method->invoke(null, $input));
    }

    /**
     * Cases for the title cleaner.
     *
     * @return array
     */
    public static function title_cases(): array {
        return [
            'quotes stripped'   => ['"Geometry Quiz Coverage"', 'Geometry Quiz Coverage'],
            'label prefix'      => ['Title: At-Risk Learners', 'At-Risk Learners'],
            'whitespace'        => ["  Spaced   out\n title ", 'Spaced out title'],
            'word cap'          => ['one two three four five six seven eight', 'one two three four five six'],
        ];
    }

    /**
     * Links in answers are made clickable and safe: bare URLs become anchors, existing links gain a
     * new-tab target, trailing sentence punctuation stays outside the link, and URLs inside code or an
     * existing anchor are left alone.
     *
     * @dataProvider link_cases
     * @param string $input Rendered, escaped HTML.
     * @param array $contains Substrings that must be present.
     * @param array $missing Substrings that must be absent.
     */
    public function test_hyperlinkify(string $input, array $contains, array $missing): void {
        $method = new \ReflectionMethod(conversation::class, 'hyperlinkify');
        $method->setAccessible(true);
        $out = $method->invoke(null, $input);
        foreach ($contains as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
        foreach ($missing as $needle) {
            $this->assertStringNotContainsString($needle, $out);
        }
    }

    /**
     * Cases for the link hardener.
     *
     * @return array
     */
    public static function link_cases(): array {
        return [
            'bare url linked' => [
                '<p>See https://site.test/edit.php?id=8 now.</p>',
                ['<a href="https://site.test/edit.php?id=8" target="_blank" rel="noopener noreferrer">'],
                [],
            ],
            'trailing period outside link' => [
                '<p>Open https://site.test/x.</p>',
                ['>https://site.test/x</a>.'],
                ['x.</a>'],
            ],
            'existing link gets new tab' => [
                '<p><a href="https://site.test/p">quiz</a></p>',
                ['target="_blank"', 'rel="noopener noreferrer"', 'href="https://site.test/p"'],
                [],
            ],
            'url in code left alone' => [
                '<p>Run <code>curl https://api.test/v1</code>.</p>',
                ['<code>curl https://api.test/v1</code>'],
                ['<a href="https://api.test/v1"'],
            ],
            'query entity preserved' => [
                '<p>Q https://site.test/?a=1&amp;b=2 end.</p>',
                ['href="https://site.test/?a=1&amp;b=2"'],
                ['&amp;amp;'],
            ],
        ];
    }

    /**
     * A numbered or bulleted list glued directly under a sentence gets a blank line before its first
     * item, so the renderer shows the items on separate lines instead of collapsing them into one
     * run-on paragraph. Items stay tight (no blank line between them), and a decimal like "3.14" is
     * never mistaken for a list marker.
     */
    public function test_normalize_markdown_breaks_lists(): void {
        $method = new \ReflectionMethod(conversation::class, 'normalize_markdown');
        $method->setAccessible(true);

        $out = $method->invoke(null, "Questions:\n1. First. True.\n2. Second. False.");
        $this->assertStringContainsString("Questions:\n\n1. First", $out, 'a blank line must precede the first item');
        $this->assertStringContainsString("True.\n2. Second", $out, 'items stay tight — no blank line between them');

        $unordered = $method->invoke(null, "Items:\n- apple\n- pear");
        $this->assertStringContainsString("Items:\n\n- apple", $unordered);

        $decimal = "Pi is about\n3.14 exactly";
        $this->assertSame($decimal, $method->invoke(null, $decimal), 'a decimal number is not a list');
    }
}
