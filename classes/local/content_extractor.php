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
 * Extracts a course's learning content as plain text, scoped to one user.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local;

/**
 * Pulls text from the content activities a given user may view.
 *
 * It reads only learning-content fields (page/book/label/url bodies and section summaries) and relies
 * on cm_info::$uservisible, so availability, group and visibility restrictions are honoured per user.
 * It never touches grades, user profiles, or the quiz question bank.
 */
class content_extractor {
    /** @var string[] Activity types whose body is safe learning content. */
    const SUPPORTED = ['page', 'label', 'url', 'book', 'lesson', 'glossary', 'wiki'];

    /**
     * Snippets of viewable learning content for a course.
     *
     * @param int $courseid Course id.
     * @param int $userid User the content is scoped to.
     * @return array List of objects {cmid, name, modname, url, text}.
     */
    public static function for_course(int $courseid, int $userid): array {
        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course, $userid);
        $out = [];

        foreach ($modinfo->get_section_info_all() as $section) {
            $text = self::plain($section->summary);
            if ($text !== '') {
                $out[] = (object) [
                    'cmid'    => 0,
                    'name'    => get_section_name($course, $section),
                    'modname' => 'section',
                    'url'     => '',
                    'text'    => $text,
                ];
            }
        }

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible || !in_array($cm->modname, self::SUPPORTED, true)) {
                continue;
            }
            $text = self::extract_cm($cm, $userid);
            if ($text === '') {
                continue;
            }
            $out[] = (object) [
                'cmid'    => $cm->id,
                'name'    => $cm->get_formatted_name(),
                'modname' => $cm->modname,
                'url'     => $cm->url ? $cm->url->out(false) : '',
                'text'    => $text,
            ];
        }

        return $out;
    }

    /** @var int Largest single course file we read into the extractor. */
    const FILE_MAX = 20971520;   // 20 MB.

    /**
     * Readable text of one module: its content body plus the text of any files it stores
     * (uploaded resources, folder files, SCORM assets, embedded files, subtitle tracks).
     *
     * @param \cm_info $cm Course module.
     * @param int $userid The acting user, so per-group/per-user content (wiki subwikis) is filtered to
     *                    what they may see. 0 = no filtering (trusted callers only).
     * @return string Plain text, or '' when there is nothing readable.
     */
    public static function read_module(\cm_info $cm, int $userid = 0): string {
        global $DB;
        $parts = [];
        $body = self::extract_cm($cm, $userid);
        if ($body === '') {
            // Fall back to the activity's own description/intro. Some module tables (e.g. core
            // mod_subsection) have no `intro` column, so guard the read and degrade to empty text.
            $intro = '';
            try {
                $intro = $DB->get_field($cm->modname, 'intro', ['id' => $cm->instance]);
            } catch (\dml_exception $e) {
                $intro = '';
            }
            $body = self::plain($intro ?: '');
        }
        if ($body !== '') {
            $parts[] = $body;
        }

        $chars = \core_text::strlen($body);
        foreach (self::module_files($cm) as $file) {
            if ($chars > 1000000) {
                break;   // Bound the total text pulled from one activity.
            }
            $text = self::read_stored_file($file);
            if ($text !== '') {
                $parts[] = '[' . $file->get_filename() . "]\n" . $text;
                $chars += \core_text::strlen($text);
            }
        }
        return trim(implode("\n\n", $parts));
    }

    /**
     * The stored files that make up a module's readable content, by module type.
     *
     * @param \cm_info $cm Course module.
     * @return \stored_file[]
     */
    protected static function module_files(\cm_info $cm): array {
        $fs = get_file_storage();
        $contextid = \context_module::instance($cm->id)->id;

        if ($cm->modname === 'scorm') {
            // ZIP packages are extracted into 'content'; a direct imsmanifest reference sits in 'package'.
            $files = $fs->get_area_files($contextid, 'mod_scorm', 'content', 0, 'filepath, filename', false);
            if (!$files) {
                $files = $fs->get_area_files($contextid, 'mod_scorm', 'package', 0, 'filepath, filename', false);
            }
            return array_values($files);
        }

        $map = [
            'resource' => ['mod_resource', 'content'],
            'folder'   => ['mod_folder', 'content'],
            'page'     => ['mod_page', 'content'],
        ];
        if (isset($map[$cm->modname])) {
            [$component, $area] = $map[$cm->modname];
            return array_values($fs->get_area_files(
                $contextid,
                $component,
                $area,
                0,
                'sortorder, filepath, filename',
                false
            ));
        }
        return [];
    }

    /**
     * Read one stored file's text through the shared extractor, cleaning subtitle tracks.
     *
     * @param \stored_file $file
     * @return string
     */
    protected static function read_stored_file(\stored_file $file): string {
        if ($file->is_directory() || $file->get_filesize() <= 0 || $file->get_filesize() > self::FILE_MAX) {
            return '';
        }
        $ext = \core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $dir = make_request_directory();
        $tmp = $dir . '/' . $file->get_id() . '_' . clean_param($file->get_filename(), PARAM_FILE);
        $file->copy_content_to($tmp);
        $res = extractor::extract($tmp, $file->get_filename());
        if ($res['status'] !== extractor::DONE) {
            return '';
        }
        if (in_array($ext, ['vtt', 'srt'], true)) {
            return self::clean_subtitles($res['text']);
        }
        return $res['text'];
    }

    /**
     * Strip WebVTT/SRT cue indices, timing lines and inline tags, leaving the spoken text.
     *
     * @param string $text Raw caption file text.
     * @return string
     */
    protected static function clean_subtitles(string $text): string {
        $lines = preg_split('/\r?\n/', $text);
        $kept = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'WEBVTT' || strpos($line, '-->') !== false) {
                continue;
            }
            if (preg_match('/^\d+$/', $line) || preg_match('/^(NOTE|STYLE|REGION)\b/', $line)) {
                continue;
            }
            $line = trim((string) preg_replace('/<[^>]+>/', '', $line));
            if ($line !== '' && end($kept) !== $line) {
                $kept[] = $line;   // Drop consecutive duplicate caption lines.
            }
        }
        return trim(implode(' ', $kept));
    }

    /**
     * Plain text body of one supported activity.
     *
     * @param \cm_info $cm Course module.
     * @param int $userid The acting user, so per-group/per-user content (e.g. wiki subwikis) is filtered
     *                    to what they may see. 0 = no filtering (trusted/system callers only).
     * @return string Plain text, or '' when unsupported or empty.
     */
    protected static function extract_cm(\cm_info $cm, int $userid = 0): string {
        global $DB;

        switch ($cm->modname) {
            case 'page':
                return self::plain($DB->get_field('page', 'content', ['id' => $cm->instance]));
            case 'label':
                return self::plain($DB->get_field('label', 'intro', ['id' => $cm->instance]));
            case 'url':
                $rec = $DB->get_record('url', ['id' => $cm->instance], 'externalurl, intro');
                return trim(self::plain($rec->intro ?? '') . ' ' . ($rec->externalurl ?? ''));
            case 'book':
                $chapters = $DB->get_fieldset_select('book_chapters', 'content', 'bookid = ? AND hidden = 0', [$cm->instance]);
                return self::plain(implode("\n\n", $chapters));
            case 'lesson':
                $pages = $DB->get_fieldset_select('lesson_pages', 'contents', 'lessonid = ?', [$cm->instance]);
                return self::plain(implode("\n\n", $pages));
            case 'glossary':
                $entries = $DB->get_records_select(
                    'glossary_entries',
                    'glossaryid = ? AND approved = 1',
                    [$cm->instance],
                    'concept ASC',
                    'concept, definition',
                    0,
                    300
                );
                $lines = [];
                foreach ($entries as $e) {
                    $lines[] = trim($e->concept) . ': ' . self::plain($e->definition);
                }
                return self::plain(implode("\n", $lines));
            case 'wiki':
                // Restrict to the subwikis the acting user may read: under separate groups, only their own
                // groups; for an individual wiki, only their own subwiki — unless they manage the wiki or
                // can access all groups. A bare wikiid query would leak every group's/learner's pages.
                $subwhere = 'sw.wikiid = :wikiid';
                $subparams = ['wikiid' => $cm->instance];
                $modcontext = \context_module::instance($cm->id);
                if ($userid && !has_capability('mod/wiki:managewiki', $modcontext, $userid)) {
                    $wiki = $DB->get_record('wiki', ['id' => $cm->instance], 'id, wikimode');
                    if ($wiki && $wiki->wikimode === 'individual') {
                        $subwhere .= ' AND (sw.userid = :suid OR sw.userid = 0)';
                        $subparams['suid'] = $userid;
                    } else if (
                        groups_get_activity_groupmode($cm) == SEPARATEGROUPS
                            && !has_capability('moodle/site:accessallgroups', $modcontext, $userid)
                    ) {
                        $mygroups = array_map('intval', array_keys(groups_get_all_groups($cm->course, $userid, 0, 'g.id')));
                        if ($mygroups) {
                            [$gin, $gp] = $DB->get_in_or_equal($mygroups, SQL_PARAMS_NAMED, 'wg');
                            $subwhere .= " AND (sw.groupid = 0 OR sw.groupid $gin)";
                            $subparams += $gp;
                        } else {
                            $subwhere .= ' AND sw.groupid = 0';
                        }
                    }
                }
                $pages = $DB->get_records_sql(
                    "SELECT wp.id, wp.title, wp.cachedcontent
                       FROM {wiki_pages} wp
                       JOIN {wiki_subwikis} sw ON sw.id = wp.subwikiid
                      WHERE $subwhere",
                    $subparams,
                    0,
                    100
                );
                $parts = [];
                foreach ($pages as $p) {
                    // The current rendered body is in cachedcontent; wiki_pages has no `content` column
                    // (versioned source lives in wiki_versions).
                    $body = (string) $p->cachedcontent;
                    $parts[] = trim((string) $p->title) . "\n" . self::plain($body);
                }
                return self::plain(implode("\n\n", $parts));
            default:
                return '';
        }
    }

    /**
     * Collapse HTML to a single run of plain text.
     *
     * @param string|null $html Raw HTML.
     * @return string
     */
    protected static function plain(?string $html): string {
        if ($html === null || trim($html) === '') {
            return '';
        }
        $text = html_to_text($html, 0, false);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
