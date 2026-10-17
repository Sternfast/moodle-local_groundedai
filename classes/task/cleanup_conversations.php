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
 * Scheduled task: delete conversations older than the configured retention window.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\task;

use local_groundedai\local\conversation;

/**
 * Removes old conversations (and their messages, attachments and exports) when retention is set.
 */
class cleanup_conversations extends \core\task\scheduled_task {
    /**
     * Task name shown in the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup', 'local_groundedai');
    }

    /**
     * Delete conversations whose last activity is older than the retention window. 0 = keep forever.
     */
    public function execute() {
        global $DB;

        // Always sweep abandoned, message-less "New chat" rows (older than an hour, so an in-progress one
        // is never removed). The UI can persist an empty conversation when the panel is opened, so without
        // this they accumulate in the list. Runs regardless of the retention setting below.
        $this->sweep_empty_conversations();

        $days = (int) get_config('local_groundedai', 'retentiondays');
        if ($days <= 0) {
            return;
        }
        $cutoff = time() - ($days * DAYSECS);
        // Drain in keyset-paginated batches so a large backlog never loads every expired row at once,
        // and so the try/catch below (which swallows a failing row) can never spin forever on it.
        $batch = 500;
        $lastid = 0;
        $deleted = 0;
        do {
            $old = $DB->get_records_select(
                'local_groundedai_conversations',
                'timemodified < :cutoff AND id > :lastid',
                ['cutoff' => $cutoff, 'lastid' => $lastid],
                'id ASC',
                'id, userid',
                0,
                $batch
            );
            foreach ($old as $conv) {
                $lastid = (int) $conv->id;   // Advance the cursor regardless of the delete outcome.
                try {
                    // Route through delete() with the owner's id so files/attachments are cleaned too.
                    conversation::delete((int) $conv->id, (int) $conv->userid);
                    $deleted++;
                } catch (\Throwable $e) {
                    mtrace('local_groundedai cleanup: conversation ' . $conv->id . ' failed: ' . $e->getMessage());
                }
            }
        } while (count($old) === $batch);
        mtrace('local_groundedai cleanup: deleted ' . $deleted . ' conversations older than ' . $days . ' days');
    }

    /**
     * Delete conversations that have no messages and are older than an hour — abandoned "New chat" rows
     * the UI persisted on open. Owner-routed delete() so any orphaned attachment files go too.
     */
    protected function sweep_empty_conversations(): void {
        global $DB;
        $empties = $DB->get_records_sql(
            "SELECT c.id, c.userid
               FROM {local_groundedai_conversations} c
              WHERE c.timemodified < :cutoff
                AND NOT EXISTS (
                    SELECT 1 FROM {local_groundedai_messages} m WHERE m.conversationid = c.id)",
            ['cutoff' => time() - HOURSECS],
            0,
            1000
        );
        $removed = 0;
        foreach ($empties as $conv) {
            try {
                conversation::delete((int) $conv->id, (int) $conv->userid);
                $removed++;
            } catch (\Throwable $e) {
                mtrace('local_groundedai cleanup: empty conversation ' . $conv->id . ' failed: ' . $e->getMessage());
            }
        }
        if ($removed) {
            mtrace('local_groundedai cleanup: removed ' . $removed . ' empty conversations');
        }
    }
}
