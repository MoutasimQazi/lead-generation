<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/audit.php';

/**
 * Proxies a natural-language question to n8n.
 *
 * The whole point of this route is that the webhook URL and API key live in
 * .env and never reach the browser. Before this existed, the page carried both
 * in localStorage, which made the login pointless — anyone who could open the
 * page could read the key and call n8n directly.
 */

/** Schema summary for every dataset the admin has marked searchable. */
function searchable_schemas(): array
{
    $rows = db_all(
        'SELECT table_name, display_name, columns_json, row_count
           FROM datasets
          WHERE is_searchable = 1 AND status = "ready"
       ORDER BY is_protected DESC, display_name ASC'
    );

    $schemas = [];

    foreach ($rows as $r) {
        $cols = json_decode((string) $r['columns_json'], true) ?: [];

        $schemas[] = [
            'table'     => $r['table_name'],
            'label'     => $r['display_name'],
            'row_count' => (int) $r['row_count'],
            'columns'   => array_map(static fn($c) => [
                'name' => $c['name'] ?? '',
                'type' => $c['type'] ?? 'TEXT',
            ], $cols),
        ];
    }

    return $schemas;
}

function route_search(): never
{
    $user = require_auth();
    require_csrf();

    $question = body_string('question', '', 2000) ?? '';

    if ($question === '') {
        fail('Type what you are looking for first.', 422);
    }

    $url = config('n8n_url');

    if ($url === '') {
        fail('Search is not configured yet — set N8N_WEBHOOK_URL in .env.', 503);
    }

    $payload = json_encode([
        'question' => $question,
        'schemas'  => searchable_schemas(),
        'asked_by' => $user['email'],
    ], JSON_UNESCAPED_SLASHES);

    $headers = ['Content-Type: application/json'];

    if (config('n8n_key') !== '') {
        $headers[] = 'x-api-key: ' . config('n8n_key');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => config('n8n_timeout'),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log('[lead-site] n8n call failed: ' . $err);
        fail('Could not reach the search service. It may be down or the workflow inactive.', 502);
    }

    $decoded = json_decode((string) $body, true);

    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        error_log('[lead-site] n8n returned non-JSON (HTTP ' . $status . '): ' . mb_substr((string) $body, 0, 500));
        fail('The search service returned something unreadable. Check the n8n workflow.', 502);
    }

    if ($status >= 400) {
        $message = is_array($decoded)
            ? (string) ($decoded['error'] ?? $decoded['message'] ?? "The search service returned HTTP $status.")
            : "The search service returned HTTP $status.";

        fail($message, 502);
    }

    $rows = is_array($decoded) && array_is_list($decoded)
        ? $decoded
        : (is_array($decoded) ? ($decoded['data'] ?? []) : []);

    audit('search', $user, null, [
        'question' => mb_substr($question, 0, 500),
        'rows'     => is_array($rows) ? count($rows) : 0,
    ]);

    // Relayed as-is so the existing render()/paint() frontend keeps working
    // against the same response shape it was written for.
    json_out(is_array($decoded) ? $decoded : ['success' => true, 'data' => []]);
}
