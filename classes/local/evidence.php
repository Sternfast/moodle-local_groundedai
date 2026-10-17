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
 * The compliance evidence pack: a compact rendering of the policy register and the standards map against
 * the live deployment.
 *
 * Generated from policy::register() and compliance_map::frameworks() — never hand-maintained prose — so
 * it cannot claim more than the register holds. Kept deliberately short: current settings, the access
 * controls with where each is enforced and the test that covers it, and each standard's authority with
 * the controls that map to it.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local;

/**
 * Evidence-pack renderer.
 */
class evidence {
    /**
     * The live deployment posture at render time — so the pack describes THIS site, not the defaults.
     *
     * @return array
     */
    public static function posture(): array {
        return [
            'staffenabled' => (bool) get_config('local_groundedai', 'enablestaff'),
            'providerset'  => trim((string) get_config('local_groundedai', 'baseurl')) !== '',
            'telemetryset' => trim((string) get_config('local_groundedai', 'otlpendpoint')) !== '',
            'grants'       => access_manager::list_grants(),
        ];
    }

    /**
     * How many policies are covered by at least one automated test.
     *
     * @return int
     */
    public static function tested_count(): int {
        $n = 0;
        foreach (policy::register() as $p) {
            if (!empty($p['verified_by'])) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Render the evidence pack as an HTML fragment (no <html>/<head>) for embedding in an admin page.
     *
     * @return string
     */
    public static function render_fragment(): string {
        $out = '';
        $out .= self::posture_block();
        $out .= \html_writer::tag('h3', get_string('evidence_policies', 'local_groundedai'));
        $out .= self::policy_table();
        $out .= \html_writer::tag('h3', get_string('evidence_frameworks', 'local_groundedai'));
        foreach (compliance_map::frameworks() as $f) {
            $out .= self::framework_block($f);
        }
        $out .= \html_writer::tag(
            'p',
            get_string('evidence_footer', 'local_groundedai'),
            ['class' => 'text-muted', 'style' => 'font-size:.85em;margin-top:1.2rem;']
        );
        return \html_writer::div($out, 'local-groundedai-evidence');
    }

    /**
     * Render a standalone, printable/downloadable HTML document.
     *
     * @return string
     */
    public static function render_document(): string {
        global $SITE;
        $sitename = format_string($SITE->fullname);
        $title = get_string('evidence_title', 'local_groundedai');
        $generated = get_string('evidence_generated', 'local_groundedai', userdate(time()));
        $version = get_config('local_groundedai', 'version');
        $css = self::print_css();
        $body = self::render_fragment();

        return "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
            . "<title>" . s($title) . " — " . s($sitename) . "</title>"
            . "<style>{$css}</style></head><body>"
            . "<header><h1>" . s($title) . "</h1><p class=\"meta\">" . s($sitename) . " &middot; "
            . s($generated) . " &middot; v" . s((string) $version) . "</p></header>"
            . "<main>{$body}</main></body></html>";
    }

    /**
     * The current-settings block: a compact table of what is switched on.
     *
     * @return string
     */
    private static function posture_block(): string {
        $posture = self::posture();
        $onoff = fn(bool $b) => $b
            ? \html_writer::tag('span', get_string('evidence_on', 'local_groundedai'), ['class' => 'badge badge-success'])
            : \html_writer::tag('span', get_string('evidence_off', 'local_groundedai'), ['class' => 'badge badge-secondary']);
        $rows = [
            [get_string('evidence_staffenabled', 'local_groundedai'), $onoff($posture['staffenabled'])],
            [get_string('evidence_providerset', 'local_groundedai'), $onoff($posture['providerset'])],
            [get_string('evidence_telemetryset', 'local_groundedai'), $onoff($posture['telemetryset'])],
            [get_string('gov_grants', 'local_groundedai'), self::grants_label($posture['grants'])],
        ];
        $body = '';
        foreach ($rows as [$k, $v]) {
            $body .= \html_writer::tag('tr', \html_writer::tag('th', s($k), ['style' => 'width:13rem;'])
                . \html_writer::tag('td', $v));
        }
        return \html_writer::tag('h3', get_string('evidence_posture', 'local_groundedai'))
            . \html_writer::tag('table', $body, ['class' => 'generaltable']);
    }

    /**
     * One standard as a crosswalk: name, authority (the law), and a table mapping each requirement to
     * the controls (by id, from the catalog above) that supply the evidence.
     *
     * @param array $f A framework entry from compliance_map::frameworks().
     * @return string
     */
    private static function framework_block(array $f): string {
        $out = \html_writer::tag('h4', s($f['name']));
        $out .= \html_writer::tag('p', \html_writer::tag('em', get_string('evidence_authority', 'local_groundedai'))
            . ' ' . s($f['authority']), ['class' => 'authority']);
        $head = \html_writer::tag(
            'tr',
            \html_writer::tag('th', get_string('evidence_col_requirement', 'local_groundedai'), ['style' => 'width:58%;'])
            . \html_writer::tag('th', get_string('evidence_col_controls', 'local_groundedai'))
        );
        $rows = '';
        foreach ($f['obligations'] as $o) {
            $chips = [];
            foreach ($o['policies'] as $pid) {
                $chips[] = \html_writer::tag('code', s($pid), ['class' => 'control-ref']);
            }
            $rows .= \html_writer::tag(
                'tr',
                \html_writer::tag('td', s($o['label']))
                . \html_writer::tag('td', implode(' ', $chips))
            );
        }
        $out .= \html_writer::tag('table', $head . $rows, ['class' => 'generaltable crosswalk']);
        return \html_writer::div($out, 'framework');
    }

    /**
     * The access controls as a table: what each guarantees, where it is enforced, the test that covers it.
     *
     * @return string
     */
    private static function policy_table(): string {
        $head = \html_writer::tag(
            'tr',
            \html_writer::tag('th', get_string('evidence_col_policy', 'local_groundedai'))
            . \html_writer::tag('th', get_string('evidence_col_statement', 'local_groundedai'))
            . \html_writer::tag('th', get_string('evidence_col_enforced', 'local_groundedai'))
            . \html_writer::tag('th', get_string('evidence_col_test', 'local_groundedai'))
        );
        $body = '';
        foreach (policy::register() as $p) {
            $enforced = implode('<br>', array_map(fn($s) => s(self::short_symbol($s)), $p['enforced_at']));
            $tests = $p['verified_by']
                ? implode('<br>', array_map(fn($t) => s($t), $p['verified_by']))
                : \html_writer::tag('em', get_string('evidence_no_test', 'local_groundedai'));
            $body .= \html_writer::tag(
                'tr',
                \html_writer::tag('td', \html_writer::tag('code', s($p['id'])))
                . \html_writer::tag('td', s($p['statement']))
                . \html_writer::tag('td', \html_writer::tag('small', $enforced))
                . \html_writer::tag('td', \html_writer::tag('small', $tests))
            );
        }
        return \html_writer::tag(
            'table',
            $head . $body,
            ['class' => 'generaltable local-groundedai-policytable']
        );
    }

    /**
     * A readable label for the current access grants: one line per grant (grantee — scope, expiry).
     *
     * @param \stdClass[] $grants Decorated grant rows from access_manager::list_grants().
     * @return string
     */
    private static function grants_label(array $grants): string {
        if (!$grants) {
            return \html_writer::tag('em', get_string('evidence_adminonly', 'local_groundedai'));
        }
        $lines = [];
        foreach ($grants as $g) {
            $kind = $g->type === access_manager::GRANTEE_ROLE
                ? get_string('grant_role', 'local_groundedai') : get_string('grant_user', 'local_groundedai');
            $expiry = ($g->expiry === 0)
                ? get_string('expiry_never', 'local_groundedai')
                : userdate($g->expiry, get_string('strftimedate', 'langconfig'));
            $feat = $g->features ? ' + ' . implode('/', $g->features) : '';
            $lines[] = s($kind . ': ' . $g->grantee . ' — ' . $g->scopelabel . ' · ' . $g->domainlabel
                . $feat . ' · ' . $expiry);
        }
        return implode('<br>', $lines);
    }

    /**
     * Shorten a fully-qualified Class::method to a readable Class::method (drop the namespace prefix).
     *
     * @param string $symbol A "Namespace\\Class::method" string.
     * @return string
     */
    private static function short_symbol(string $symbol): string {
        if (strpos($symbol, '::') === false) {
            return $symbol;
        }
        [$class, $method] = explode('::', $symbol, 2);
        $short = substr(strrchr($class, '\\') ?: $class, 1) ?: $class;
        return $short . '::' . $method;
    }

    /**
     * Minimal print stylesheet for the standalone document.
     *
     * @return string
     */
    private static function print_css(): string {
        return 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;max-width:52rem;'
            . 'margin:2rem auto;padding:0 1rem;color:#1a1a1a;line-height:1.45;}'
            . 'h1{font-size:1.5rem;margin-bottom:.2rem;}h3{margin-top:1.8rem;border-bottom:2px solid #eee;'
            . 'padding-bottom:.3rem;}h4{margin-top:1.2rem;margin-bottom:.1rem;}'
            . '.meta{color:#666;font-size:.9rem;}'
            . 'table{border-collapse:collapse;width:100%;margin:.6rem 0;font-size:.9rem;}'
            . 'th,td{border:1px solid #ddd;padding:.35rem .5rem;text-align:left;vertical-align:top;}'
            . 'code{background:#f4f4f4;padding:.05rem .3rem;border-radius:3px;font-size:.85em;}'
            . '.authority{color:#555;font-size:.85rem;margin:.1rem 0 .4rem;}'
            . '.crosswalk td{font-size:.86rem;}'
            . '.control-ref{display:inline-block;background:#eef3fb;border:1px solid #cfe0f5;color:#24497a;'
            . 'border-radius:3px;padding:.02rem .3rem;font-size:.72rem;margin:.1rem .15rem .1rem 0;white-space:nowrap;}'
            . '.badge{display:inline-block;border-radius:3px;padding:.05rem .4rem;font-size:.78rem;}'
            . '.badge-success{background:#e3f5e3;color:#1f6f1f;}.badge-secondary{background:#eee;color:#555;}'
            . '.framework{margin-bottom:1rem;}';
    }
}
