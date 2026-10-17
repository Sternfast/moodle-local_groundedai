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
 * Per-answer telemetry emitter.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local;

/**
 * Emits one span per answer to the configured OTLP/HTTP endpoint as a dependency-free JSON POST.
 * A no-op when no endpoint is configured; it never blocks (short timeout) or throws into the reply.
 */
class telemetry {
    /**
     * Is a telemetry endpoint configured?
     *
     * @return bool
     */
    public static function enabled(): bool {
        return trim((string) get_config('local_groundedai', 'otlpendpoint')) !== '';
    }

    /**
     * Record one answer. Builds a minimal GenAI-style span and posts it; failures are swallowed.
     *
     * @param array $fields Flat attributes: operation, model, lane, input_tokens, output_tokens, duration_ms.
     */
    public static function record(array $fields): void {
        $endpoint = trim((string) get_config('local_groundedai', 'otlpendpoint'));
        if ($endpoint === '') {
            return;
        }
        $span = self::build_span($fields);

        try {
            $curl = new \curl();
            $curl->setHeader(['Content-Type: application/json']);
            $curl->post(rtrim($endpoint, '/') . '/v1/traces', json_encode($span), [
                'CURLOPT_CONNECTTIMEOUT' => 2,
                'CURLOPT_TIMEOUT'        => 2,
            ]);
        } catch (\Throwable $e) {
            // Telemetry must never break a reply.
            debugging('groundedai telemetry post failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Build the OTLP span from flat metadata fields. Every field becomes a `gen_ai.`-prefixed string
     * attribute — there is no path here for prompt or response content to be included.
     *
     * @param array $fields Flat metadata attributes.
     * @return array The OTLP traces payload.
     */
    public static function build_span(array $fields): array {
        $attrs = [];
        foreach ($fields as $key => $value) {
            $attrs[] = ['key' => 'gen_ai.' . $key, 'value' => ['stringValue' => (string) $value]];
        }
        return ['resourceSpans' => [[
            'scopeSpans' => [[
                'spans' => [[
                    'name'       => 'chat ' . ($fields['model'] ?? ''),
                    'kind'       => 3,
                    'attributes' => $attrs,
                ]],
            ]],
        ]]];
    }
}
