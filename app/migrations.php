<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/identifiers.php';

/**
 * Schema creation. Idempotent — safe to re-run.
 *
 * All JSON-ish columns are LONGTEXT rather than the JSON type: MariaDB treats
 * JSON as an alias for LONGTEXT anyway, and LONGTEXT works on every version a
 * cPanel host might be running.
 */

function migration_statements(): array
{
    $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    return [
        'app_users' => "
            CREATE TABLE IF NOT EXISTS app_users (
              id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
              email         VARCHAR(190) NOT NULL,
              full_name     VARCHAR(120) NOT NULL,
              password_hash VARCHAR(255) NOT NULL,
              role          ENUM('admin','employee') NOT NULL DEFAULT 'employee',
              is_active     TINYINT(1) NOT NULL DEFAULT 1,
              created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              last_login_at TIMESTAMP NULL DEFAULT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_app_users_email (email)
            ) $charset",

        'login_attempts' => "
            CREATE TABLE IF NOT EXISTS login_attempts (
              id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              ip           VARCHAR(45) NOT NULL,
              email        VARCHAR(190) NOT NULL DEFAULT '',
              succeeded    TINYINT(1) NOT NULL DEFAULT 0,
              attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_login_ip_time (ip, attempted_at)
            ) $charset",

        'folders' => "
            CREATE TABLE IF NOT EXISTS folders (
              id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
              name       VARCHAR(120) NOT NULL,
              slug       VARCHAR(120) NOT NULL,
              created_by INT UNSIGNED NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_folders_slug (slug),
              CONSTRAINT fk_folders_user FOREIGN KEY (created_by)
                REFERENCES app_users(id) ON DELETE SET NULL
            ) $charset",

        'datasets' => "
            CREATE TABLE IF NOT EXISTS datasets (
              id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
              folder_id     INT UNSIGNED NULL,
              table_name    VARCHAR(64) NOT NULL,
              display_name  VARCHAR(190) NOT NULL,
              source_files  LONGTEXT NULL,
              columns_json  LONGTEXT NULL,
              row_count     BIGINT UNSIGNED NOT NULL DEFAULT 0,
              is_searchable TINYINT(1) NOT NULL DEFAULT 0,
              is_protected  TINYINT(1) NOT NULL DEFAULT 0,
              status        ENUM('importing','ready','failed') NOT NULL DEFAULT 'importing',
              error_message TEXT NULL,
              created_by    INT UNSIGNED NULL,
              created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_datasets_table (table_name),
              KEY idx_datasets_folder (folder_id),
              CONSTRAINT fk_datasets_folder FOREIGN KEY (folder_id)
                REFERENCES folders(id) ON DELETE SET NULL,
              CONSTRAINT fk_datasets_user FOREIGN KEY (created_by)
                REFERENCES app_users(id) ON DELETE SET NULL
            ) $charset",

        'upload_stages' => "
            CREATE TABLE IF NOT EXISTS upload_stages (
              id         CHAR(32) NOT NULL,
              user_id    INT UNSIGNED NULL,
              folder_id  INT UNSIGNED NULL,
              manifest   LONGTEXT NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_stage_created (created_at)
            ) $charset",

        // One row per file being ingested. byte_offset is what makes the import
        // resumable across many short HTTP requests instead of one long one.
        'import_jobs' => "
            CREATE TABLE IF NOT EXISTS import_jobs (
              id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
              dataset_id      INT UNSIGNED NOT NULL,
              file_path       VARCHAR(500) NOT NULL,
              source_file     VARCHAR(255) NOT NULL,
              mapping_json    LONGTEXT NOT NULL,
              byte_offset     BIGINT UNSIGNED NOT NULL DEFAULT 0,
              rows_done       BIGINT UNSIGNED NOT NULL DEFAULT 0,
              rows_skipped    BIGINT UNSIGNED NOT NULL DEFAULT 0,
              truncated_cells BIGINT UNSIGNED NOT NULL DEFAULT 0,
              file_size       BIGINT UNSIGNED NOT NULL DEFAULT 0,
              status          ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
              error_message   TEXT NULL,
              created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_jobs_dataset (dataset_id, status),
              CONSTRAINT fk_jobs_dataset FOREIGN KEY (dataset_id)
                REFERENCES datasets(id) ON DELETE CASCADE
            ) $charset",

        'audit_log' => "
            CREATE TABLE IF NOT EXISTS audit_log (
              id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id    INT UNSIGNED NULL,
              user_email VARCHAR(190) NOT NULL DEFAULT '',
              action     VARCHAR(60) NOT NULL,
              dataset_id INT UNSIGNED NULL,
              detail     LONGTEXT NULL,
              ip         VARCHAR(45) NOT NULL DEFAULT '',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_audit_time (created_at),
              KEY idx_audit_dataset (dataset_id)
            ) $charset",
    ];
}

/**
 * Creates every core table, then registers the pre-existing leads table as a
 * protected dataset so it shows up in the UI and can be searched, but can never
 * be dropped or schema-edited.
 *
 * @return string[] human-readable log lines
 */
function run_migrations(): array
{
    $log = [];

    foreach (migration_statements() as $name => $sql) {
        $existed = db_table_exists($name);
        db()->exec($sql);
        $log[] = $existed ? "· $name already present" : "+ created $name";
    }

    $leads = config('leads_table');

    if ($leads === '') {
        $log[] = '· LEADS_TABLE not set — skipping registration of the existing leads table';
        return $log;
    }

    if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/i', $leads)) {
        $log[] = "! LEADS_TABLE '$leads' is not a plain identifier — skipped";
        return $log;
    }

    if (!db_table_exists($leads)) {
        $log[] = "! LEADS_TABLE '$leads' does not exist in " . config('db_name') . ' — skipped';
        return $log;
    }

    $already = db_one('SELECT id FROM datasets WHERE table_name = ?', [$leads]);

    if ($already) {
        $log[] = "· '$leads' already registered as a dataset";
        return $log;
    }

    $cols = [];
    foreach (db_table_columns($leads) as $c) {
        $cols[] = [
            'name'  => $c,
            'label' => ucwords(str_replace('_', ' ', $c)),
            'type'  => 'TEXT',
        ];
    }

    $count = (int) db_value('SELECT COUNT(*) FROM ' . qi(strtolower($leads)), [], 0);

    db_exec(
        'INSERT INTO datasets
           (table_name, display_name, columns_json, source_files, row_count,
            is_searchable, is_protected, status)
         VALUES (?, ?, ?, ?, ?, 1, 1, "ready")',
        [$leads, 'Leads (master)', json_encode($cols), json_encode([]), $count]
    );

    $log[] = "+ registered '$leads' as a protected, searchable dataset ("
           . number_format($count) . ' rows)';

    return $log;
}

/**
 * An upgraded installation may already have app_users (which locks setup.php)
 * while missing dataset-management tables introduced later. Run the existing
 * idempotent migration only when one of those tables is absent.
 */
function ensure_management_schema(): void
{
    $required = ['folders', 'datasets', 'upload_stages', 'import_jobs', 'audit_log'];
    $missing  = false;

    foreach ($required as $table) {
        if (!db_table_exists($table)) {
            $missing = true;
            break;
        }
    }

    if (!$missing) {
        return;
    }

    $lockName = 'movenetics_lead_schema_bootstrap';
    $locked   = (int) db_value('SELECT GET_LOCK(?, 15)', [$lockName], 0) === 1;

    if (!$locked) {
        throw new RuntimeException('Could not acquire the database migration lock. Please retry.');
    }

    try {
        // The parallel folders request may have completed the migration while
        // this request waited for the lock, so check again before writing.
        foreach ($required as $table) {
            if (!db_table_exists($table)) {
                run_migrations();
                break;
            }
        }
    } finally {
        db_value('SELECT RELEASE_LOCK(?)', [$lockName]);
    }
}
