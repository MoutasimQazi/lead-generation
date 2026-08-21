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

/**
 * Adds useful AI context without exposing sample lead rows or querying every
 * distinct value on every search. Uploaded datasets use generic descriptions;
 * the protected leads table gets the controlled values we already know.
 */
function search_column_profile(string $table, array $column): array
{
    $name  = (string) ($column['name'] ?? '');
    $label = (string) ($column['label'] ?? ucwords(str_replace('_', ' ', $name)));
    $type  = (string) ($column['type'] ?? 'TEXT');

    $profile = [
        'name'        => $name,
        'label'       => $label,
        'type'        => $type,
        'description' => (string) ($column['description'] ?? ''),
        'values'      => array_values(array_slice(
            is_array($column['values'] ?? null) ? $column['values'] : [],
            0,
            100
        )),
    ];

    if ($table !== config('leads_table')) {
        return $profile;
    }

    $known = [
        'size_band' => [
            'description' => 'Company-size band. Unknown is common and should be excluded from size analysis.',
            'values' => ['Unknown', 'Solo (1)', 'Micro (2-10)', 'Small (11-50)', 'Mid (51-200)', 'Large (201-1,000)', 'Enterprise (1,000+)'],
        ],
        'phone_type' => [
            'description' => 'Telephone line classification.',
            'values' => ['mobile', 'fixed line', 'voip', 'other'],
        ],
        'has_email' => [
            'description' => 'Whether an email address is present. Stored as text, not a boolean.',
            'values' => ['Yes', 'No'],
        ],
        'sectors_norm' => [
            'description' => 'Comma-separated exact trade vocabulary. Use contains_any/FIND_IN_SET for inclusive trade matching.',
        ],
        'primary_sector' => [
            'description' => 'The single main trade. Use only when the question explicitly asks for the primary trade.',
        ],
        'contact_person' => [
            'description' => 'May contain several people and job titles in one text field.',
        ],
        'corporate_email' => [
            'description' => 'Direct corporate contact email when available.',
        ],
        'generic_email' => [
            'description' => 'May contain shared or multiple email addresses.',
        ],
        'phone' => [
            'description' => 'Formatted telephone text rather than digits-only data.',
        ],
        'state' => [
            'description' => 'Usually a two-letter US state or territory abbreviation.',
        ],
    ];

    if (isset($known[$name])) {
        $profile = array_replace($profile, $known[$name]);
    }

    return $profile;
}

function search_dataset_description(array $dataset): string
{
    if ((string) $dataset['table_name'] === config('leads_table')) {
        return 'US business leads, primarily trades and services. Empty contact fields are normal. Use sectors_norm for inclusive trade searches and primary_sector only for explicitly primary-trade questions.';
    }

    return 'Admin-uploaded dataset named "' . (string) $dataset['display_name']
        . '". Use only its declared columns and do not assume relationships to other datasets.';
}

/** Schema summary for every dataset the admin has marked searchable. */
function searchable_schemas(array $user): array
{
    if ($user['role'] === 'admin') {
        $rows = db_all(
            'SELECT table_name, display_name, columns_json, row_count, is_protected
               FROM datasets
              WHERE is_searchable = 1 AND status = "ready"'
        );
    } else {
        $rows = db_all(
            'SELECT d.table_name, d.display_name, d.columns_json, d.row_count, d.is_protected
               FROM dataset_assignments a
               JOIN datasets d ON d.id = a.dataset_id
              WHERE a.user_id = ?
                AND a.search_enabled = 1
                AND d.is_searchable = 1
                AND d.status = "ready"',
            [(int) $user['id']]
        );
    }

    usort($rows, static function (array $a, array $b): int {
        $protected = (int) $b['is_protected'] <=> (int) $a['is_protected'];
        return $protected !== 0
            ? $protected
            : strcasecmp((string) $a['display_name'], (string) $b['display_name']);
    });

    $schemas = [];

    foreach ($rows as $r) {
        $cols = json_decode((string) $r['columns_json'], true) ?: [];

        $schemas[] = [
            'table'       => $r['table_name'],
            'label'       => $r['display_name'],
            'description' => search_dataset_description($r),
            'row_count'   => (int) $r['row_count'],
            'columns'     => array_values(array_map(
                static fn($c) => search_column_profile((string) $r['table_name'], $c),
                $cols
            )),
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

    $schemas = searchable_schemas($user);

    if ($schemas === []) {
        fail(
            $user['role'] === 'admin'
                ? 'No datasets are currently enabled for search.'
                : 'No assigned datasets are enabled in your search. Open Datasets to choose one, or ask an admin for access.',
            422
        );
    }

    $requestId = bin2hex(random_bytes(16));
    $payload = json_encode([
        'request_id' => $requestId,
        'question'   => $question,
        'schemas'    => $schemas,
        'asked_by'   => $user['email'],
        'max_rows'   => 250,
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

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

    if ($status >= 400 || (is_array($decoded) && ($decoded['success'] ?? true) === false)) {
        $message = is_array($decoded)
            ? (string) ($decoded['error'] ?? $decoded['message'] ?? "The search service returned HTTP $status.")
            : "The search service returned HTTP $status.";

        // Preserve useful validation failures from n8n. Infrastructure and AI
        // failures remain a gateway error rather than pretending PHP caused it.
        $clientStatus = $status >= 400 && $status < 500 ? $status : 502;
        fail($message, $clientStatus);
    }

    $rows = is_array($decoded) && array_is_list($decoded)
        ? $decoded
        : (is_array($decoded) ? ($decoded['data'] ?? []) : []);

    audit('search', $user, null, [
        'request_id' => $requestId,
        'question' => mb_substr($question, 0, 500),
        'rows'     => is_array($rows) ? count($rows) : 0,
        'dataset'  => is_array($decoded) ? ($decoded['dataset'] ?? null) : null,
        'intent'   => is_array($decoded) ? ($decoded['intent'] ?? null) : null,
        'confidence' => is_array($decoded) ? ($decoded['confidence'] ?? null) : null,
    ]);

    // Employees get results and an explanation, but not the generated SQL.
    // Admins retain the existing "Show query" debugging feature.
    if (is_array($decoded) && empty($user['is_admin'])) {
        unset($decoded['sql']);
    }

    // Relayed as-is so the existing render()/paint() frontend keeps working
    // against the same response shape it was written for.
    json_out(is_array($decoded) ? $decoded : ['success' => true, 'data' => []]);
}
