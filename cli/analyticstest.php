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
 * Smoke-test the staff analytics tools against a seeded course.
 *
 * Usage: php analyticstest.php [courseid] [userid]
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

use local_groundedai\local\staff\tools;

$courseid = isset($argv[1]) ? (int) $argv[1] : 4;
$userid = isset($argv[2]) ? (int) $argv[2] : 4;
\core\session\manager::set_user(get_admin());

echo "=== get_grade_overview ===\n";
$r = tools::dispatch('get_grade_overview', ['courseid' => $courseid], $userid);
if (isset($r['error'])) {
    echo "error: {$r['error']}\n";
} else {
    echo "students={$r['student_count']} at_risk={$r['at_risk_count']}\n";
    echo "hardest: {$r['items_hardest_first'][0]['item']} ({$r['items_hardest_first'][0]['average_pct']}%)\n";
    foreach ($r['items_hardest_first'] as $it) {
        echo "  item {$it['item']}: avg {$it['average_pct']}% (below pass {$it['below_pass']}/{$it['graded']})\n";
    }
    foreach ($r['students'] as $s) {
        $risk = $s['at_risk'] ? ' [AT RISK]' : '';
        echo "  {$s['name']}: overall {$s['overall_pct']}% | best {$s['strongest']} | weak {$s['weakest']}{$risk}\n";
    }
}

echo "\n=== get_course_contents ===\n";
$c = tools::dispatch('get_course_contents', ['courseid' => $courseid], $userid);
if (isset($c['error'])) {
    echo "error: {$c['error']}\n";
} else {
    foreach ($c['sections'] as $sec) {
        $acts = array_map(fn($a) => "{$a['name']}({$a['type']},cm{$a['cmid']})", $sec['activities']);
        echo "  [{$sec['name']}] " . implode(', ', $acts) . "\n";
    }
}

echo "\n=== read_material (first page) ===\n";
$firstpage = 0;
if (isset($c['sections'])) {
    foreach ($c['sections'] as $sec) {
        foreach ($sec['activities'] as $a) {
            if (in_array($a['type'], ['page', 'book', 'label', 'url'], true)) {
                $firstpage = $a['cmid'];
                break 2;
            }
        }
    }
}
if ($firstpage) {
    $m = tools::dispatch('read_material', ['courseid' => $courseid, 'cmid' => $firstpage], $userid);
    if (isset($m['error'])) {
        echo "error: {$m['error']}\n";
    } else {
        echo "  {$m['name']} ({$m['type']}): " . \core_text::substr($m['text'] ?? ($m['note'] ?? ''), 0, 160) . "\n";
    }
} else {
    echo "  no readable page found\n";
}

echo "\ndone\n";
