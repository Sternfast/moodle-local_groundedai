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
 * An endpoint-URL admin setting that requires https, except for a loopback/local host.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\admin;

/**
 * A text setting for an outbound endpoint URL. Allows an empty value (unconfigured, fail-closed) and a
 * loopback/local endpoint over http (a self-hosted model such as Ollama), but requires https for any
 * other host so the API key and grounded content are never sent in cleartext.
 */
class setting_endpoint_url extends \admin_setting_configtext {
    /**
     * Validate the submitted URL.
     *
     * @param string $data The submitted value.
     * @return true|string True if valid, else an error string.
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }
        $data = trim((string) $data);
        if ($data === '') {
            return true;
        }
        $scheme = strtolower((string) parse_url($data, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($data, PHP_URL_HOST));
        $islocal = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || substr($host, -10) === '.localhost';
        if ($scheme !== 'https' && !$islocal) {
            return get_string('err_https_required', 'local_groundedai');
        }
        return true;
    }
}
