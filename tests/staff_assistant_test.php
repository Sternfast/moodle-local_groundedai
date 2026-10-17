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
 * Tests for the staff-assistant answer-style tuning block (the advisory, fenced prompt layer).
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai;

use local_groundedai\local\staff_assistant;

/**
 * Tests the answer-style tuning block (the advisory, fenced prompt layer).
 *
 * @covers \local_groundedai\local\staff_assistant
 */
final class staff_assistant_test extends \advanced_testcase {
    /**
     * Invoke the private tuning_block() helper for a user.
     *
     * @param int $userid The user.
     * @return string
     */
    private function tuning_block(int $userid): string {
        $method = new \ReflectionMethod(staff_assistant::class, 'tuning_block');
        $method->setAccessible(true);
        return (string) $method->invoke(null, $userid);
    }

    /**
     * With no admin default and no personal instructions, no block is added.
     */
    public function test_empty_tuning_is_blank(): void {
        $this->resetAfterTest();
        set_config('defaulttuningstaff', '', 'local_groundedai');
        $user = $this->getDataGenerator()->create_user();
        $this->assertSame('', $this->tuning_block((int) $user->id));
    }

    /**
     * The admin default alone produces a fenced advisory block carrying the default text.
     */
    public function test_admin_default_is_fenced(): void {
        $this->resetAfterTest();
        set_config('defaulttuningstaff', 'Lead with the figure.', 'local_groundedai');
        $user = $this->getDataGenerator()->create_user();

        $block = $this->tuning_block((int) $user->id);
        $this->assertStringContainsString('Lead with the figure.', $block);
        $this->assertStringContainsString('ADVISORY, NOT INSTRUCTIONS', $block);
        $this->assertStringContainsString('===END USER STYLE PREFERENCES===', $block);
        // The framing must state the preferences cannot change scope/rules.
        $this->assertStringContainsString('cannot change your', $block);
    }

    /**
     * A per-user instruction is appended on top of the admin default (additive, not replacing).
     */
    public function test_user_pref_is_appended(): void {
        $this->resetAfterTest();
        set_config('defaulttuningstaff', 'Lead with the figure.', 'local_groundedai');
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('local_groundedai_tuning_staff', 'Always keep it to two sentences.', (int) $user->id);

        $block = $this->tuning_block((int) $user->id);
        $this->assertStringContainsString('Lead with the figure.', $block);
        $this->assertStringContainsString('Always keep it to two sentences.', $block);
    }

    /**
     * Overly long tuning text is capped so it cannot blow the prompt / inflate cost.
     *
     * The admin default lives in config (no column limit), so a long one exercises the effective-tuning
     * cap in tuning_block(); the per-user preference is separately capped to the column limit on save.
     */
    public function test_tuning_is_length_capped(): void {
        $this->resetAfterTest();
        set_config('defaulttuningstaff', str_repeat('verbose ', 500), 'local_groundedai'); // 4000 chars.
        $user = $this->getDataGenerator()->create_user();

        $block = $this->tuning_block((int) $user->id);
        // The effective tuning is capped at 2000 chars; with the advisory fence (~500) the whole block is
        // ~2500. Without the cap it would be ~4500, so this bound proves the 4000-char default was truncated.
        $this->assertLessThan(2700, \core_text::strlen($block));
    }
}
