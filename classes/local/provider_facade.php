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
 * Thin model-provider facade over the OpenAI-compatible chat-completions wire.
 *
 * @package    local_groundedai
 * @copyright  2026 Vbounds LLC (Sternfast)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_groundedai\local;

/**
 * One place that talks to the model. Swapping provider or model is a base-URL and model-name change;
 * calls always set explicit connect and total timeouts so a slow provider cannot pin a web worker.
 */
class provider_facade {
    /** @var string OpenAI-compatible base URL, e.g. https://api.anthropic.com/v1. */
    protected string $baseurl;

    /** @var string Provider key (empty for a keyless local endpoint). */
    protected string $apikey;

    /** @var int Connect timeout, seconds. */
    const CONNECT_TIMEOUT = 10;

    /** @var int Total request timeout, seconds. */
    const TIMEOUT = 60;

    /**
     * Load provider config.
     */
    public function __construct() {
        $this->baseurl = trim((string) get_config('local_groundedai', 'baseurl'));
        $this->apikey  = trim((string) get_config('local_groundedai', 'apikey'));
    }

    /**
     * Resolve the configured model name for a tier.
     *
     * @param string $tier 'cheap' (default, high-volume work) or 'frontier' (answers, grounded checks).
     * @return string
     */
    public function model_for(string $tier = 'cheap'): string {
        $name = $tier === 'frontier' ? 'modelfrontier' : 'modelcheap';
        return trim((string) get_config('local_groundedai', $name));
    }

    /**
     * Send a chat-completions request.
     *
     * @param array $messages Messages in the OpenAI-compatible shape ([['role'=>..,'content'=>..], ...]).
     * @param string $tier 'cheap' or 'frontier'.
     * @param array $opts Optional overrides: 'temperature', 'max_tokens'.
     * @return array {content:string, model:string, usage:array|null}
     */
    public function chat(array $messages, string $tier = 'cheap', array $opts = []): array {
        if ($this->baseurl === '') {
            throw new \moodle_exception('noprovider', 'local_groundedai');
        }
        $model = $this->model_for($tier);
        if ($model === '') {
            throw new \moodle_exception('nomodel', 'local_groundedai');
        }

        $payload = [
            'model'      => $model,
            'messages'   => $messages,
            'max_tokens' => $opts['max_tokens'] ?? 1024,
        ];
        // Only sent when explicitly requested — some models reject a temperature override.
        if (isset($opts['temperature'])) {
            $payload['temperature'] = $opts['temperature'];
        }
        // Tool (function) calling, when the caller passes an OpenAI-style tool list.
        if (!empty($opts['tools'])) {
            $payload['tools'] = $opts['tools'];
            $payload['tool_choice'] = $opts['tool_choice'] ?? 'auto';
        }

        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $headers = ['Content-Type: application/json'];
        if ($this->apikey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apikey;
        }
        $curl->setHeader($headers);

        $response = $curl->post(rtrim($this->baseurl, '/') . '/chat/completions', json_encode($payload), [
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_TIMEOUT'        => self::TIMEOUT,
        ]);

        if ($curl->get_errno()) {
            throw new \moodle_exception('requestfailed', 'local_groundedai', '', $curl->error);
        }
        $info = $curl->get_info();
        $code = (int) ($info['http_code'] ?? 0);
        $data = json_decode($response, true);
        if ($code !== 200) {
            $detail = $data['error']['message'] ?? ('HTTP ' . $code);
            throw new \moodle_exception('requestfailed', 'local_groundedai', '', $detail);
        }

        $msg = $data['choices'][0]['message'] ?? [];
        return [
            'content'      => (string) ($msg['content'] ?? ''),
            'tool_calls'   => $msg['tool_calls'] ?? null,
            'finishreason' => $data['choices'][0]['finish_reason'] ?? null,
            'model'        => (string) ($data['model'] ?? $model),
            'usage'        => $data['usage'] ?? null,
        ];
    }
}
