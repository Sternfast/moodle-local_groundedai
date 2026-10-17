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
 * A simple per-user hourly request cap, to bound spend on a metered provider key.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local;

/**
 * Counts assistant questions per user per rolling hour against the 'maxperhour' setting. A cheap
 * application-cache counter (not a DB table): an approximate cap is all a denial-of-wallet guard needs.
 */
class rate_limiter {
    /** @var string The MUC cache area (see db/caches.php). */
    const CACHE_AREA = 'ratelimit';

    /**
     * Record one question for this user and throw if they are over the hourly limit.
     *
     * @param int $userid The acting user.
     * @throws \moodle_exception 'ratelimited' when the hourly cap is reached.
     */
    public static function check(int $userid): void {
        $max = (int) get_config('local_groundedai', 'maxperhour');
        if ($max <= 0 || $userid <= 0) {
            return;   // 0 = no limit.
        }
        $cache = \cache::make('local_groundedai', self::CACHE_AREA);
        $now = time();
        $entry = $cache->get($userid);
        $start = (is_array($entry) && isset($entry['start'])) ? (int) $entry['start'] : 0;
        $count = (is_array($entry) && isset($entry['count'])) ? (int) $entry['count'] : 0;
        if ($now - $start >= HOURSECS) {
            $start = $now;
            $count = 0;
        }
        if ($count >= $max) {
            throw new \moodle_exception('ratelimited', 'local_groundedai', '', $max);
        }
        $cache->set($userid, ['start' => $start, 'count' => $count + 1]);
    }
}
