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
 * Extract text from one uploaded attachment, off the web request.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\task;

use local_groundedai\local\attachment_manager;

/**
 * Adhoc task: read a stored file and write its plain text back to the attachment row.
 */
class extract_text extends \core\task\adhoc_task {
    /**
     * Build a task for one attachment. Custom data is the permanent attachment id only
     * (minimal and deterministic, so the queue can dedupe identical re-queues).
     *
     * @param int $attachid Attachment id.
     * @return self
     */
    public static function instance(int $attachid): self {
        $task = new self();
        $task->set_custom_data((object) ['attachid' => $attachid]);
        $task->set_component('local_groundedai');
        return $task;
    }

    /**
     * Process the attachment. One bad file never kills the cron run.
     */
    public function execute() {
        \core_php_time_limit::raise(120);
        $data = $this->get_custom_data();
        $attachid = (int) ($data->attachid ?? 0);
        if ($attachid <= 0) {
            return;
        }
        try {
            attachment_manager::process($attachid);
        } catch (\Throwable $e) {
            attachment_manager::fail($attachid, $e->getMessage());
            mtrace('local_groundedai extract_text failed for attachment ' . $attachid . ': ' . $e->getMessage());
        }
    }
}
