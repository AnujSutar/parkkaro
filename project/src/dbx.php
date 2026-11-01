<?php
/**
 * Small PDO helpers shared by the newer modules (locations, reservations,
 * payments, earnings), plus time helpers.
 *
 * Everything here is written to run on MySQL/MariaDB (production) AND SQLite
 * (automated tests): no NOW(), no MySQL-only functions. The current time is always
 * passed in from PHP, which also makes the time-window logic easy to test.
 */
declare(strict_types=1);

/** MySQL DATETIME text format. Plain strings of this format compare correctly. */
const PK_DT = 'Y-m-d H:i:s';

/** The application's local time zone (not the PHP/MySQL server default). */
function app_timezone(): DateTimeZone
{
    try {
        return new DateTimeZone((string) env('APP_TIMEZONE', 'Asia/Kolkata'));
    } catch (Throwable $ex) {
        return new DateTimeZone('Asia/Kolkata');
    }
}

function app_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', app_timezone());
}

/* ------------------------------------------------------------------ */
/* Queries                                                             */
/* ------------------------------------------------------------------ */

/** Prepare + bind + run. Integers are bound as integers (needed for LIMIT). */
function q_run(PDO $pdo, string $sql, array $params = []): PDOStatement
{
    $st = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $name = is_int($key) ? $key + 1 : $key;
        if (is_int($value)) {
            $st->bindValue($name, $value, PDO::PARAM_INT);
        } elseif ($value === null) {
            $st->bindValue($name, null, PDO::PARAM_NULL);
        } elseif (is_bool($value)) {
            $st->bindValue($name, $value ? 1 : 0, PDO::PARAM_INT);
        } else {
            $st->bindValue($name, (string) $value, PDO::PARAM_STR);
        }
    }
    $st->execute();
    return $st;
}

function q_all(PDO $pdo, string $sql, array $params = []): array
{
    return q_run($pdo, $sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}

function q_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $row = q_run($pdo, $sql, $params)->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** First column of the first row, or $default when there is no row / it is NULL. */
function q_val(PDO $pdo, string $sql, array $params = [], $default = null)
{
    $row = q_run($pdo, $sql, $params)->fetch(PDO::FETCH_NUM);
    return ($row === false || $row[0] === null) ? $default : $row[0];
}

/** Escape LIKE wildcards. Use together with  LIKE ? ESCAPE '!'  (works on MySQL and SQLite). */
function like_escape(string $text): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
}

/* ------------------------------------------------------------------ */
/* Transactions and the per-location lock                              */
/* ------------------------------------------------------------------ */

function db_driver(PDO $pdo): string
{
    return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/** Start a transaction that is allowed to write. SQLite needs BEGIN IMMEDIATE to take the write lock up front. */
function tx_begin(PDO $pdo): void
{
    if (db_driver($pdo) === 'sqlite') {
        $pdo->exec('BEGIN IMMEDIATE');
    } else {
        $pdo->beginTransaction();
    }
}

function tx_commit(PDO $pdo): void
{
    if (db_driver($pdo) === 'sqlite') {
        $pdo->exec('COMMIT');
    } else {
        $pdo->commit();
    }
}

function tx_rollback(PDO $pdo): void
{
    try {
        if (db_driver($pdo) === 'sqlite') {
            $pdo->exec('ROLLBACK');
        } elseif ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $ignored) {
    }
}

/**
 * Run $fn inside a write transaction. Commits when $fn returns, rolls back and
 * rethrows when it throws. Do not nest calls.
 *
 * @return mixed whatever $fn returns
 */
function with_write_tx(PDO $pdo, callable $fn)
{
    tx_begin($pdo);
    try {
        $result = $fn($pdo);
        tx_commit($pdo);
        return $result;
    } catch (Throwable $ex) {
        tx_rollback($pdo);
        throw $ex;
    }
}

/**
 * Load a location row and lock it until the transaction ends (MySQL: SELECT ... FOR UPDATE
 * on an InnoDB row). Two people reserving the same place wait for each other here, so the
 * "is there room?" check and the INSERT behave as one step. SQLite already holds the whole-database write
 * lock from BEGIN IMMEDIATE. Must be called inside with_write_tx().
 */
function lock_location(PDO $pdo, int $locationId): ?array
{
    $sql = 'SELECT * FROM parking_locations WHERE id = ?';
    if (db_driver($pdo) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    return q_one($pdo, $sql, [$locationId]);
}
