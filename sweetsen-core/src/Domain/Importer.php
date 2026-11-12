<?php

declare(strict_types=1);

namespace SweetSen\Domain;

use SweetSen\Audit\Audit;
use SweetSen\Support\Db;
use SweetSen\Support\DomainError;
use SweetSen\Support\Sequence;
use SweetSen\Support\Uuid;
use SweetSen\Tenancy\Actor;

/**
 * CSV import of existing senior records.
 *
 * Rules (spec 5A): validate every row; never silently merge; probable duplicates are flagged for
 * review instead of created; created records always start as 'unverified'.
 */
final class Importer
{
    public const COLUMNS = ['ref_no', 'last_name', 'first_name', 'middle_name', 'suffix', 'sex', 'birthdate',
        'barangay', 'address_line', 'contact_phone', 'osca_id'];

    public const MIN_AGE = 60;

    /**
     * @return array{job_id:string,total:int,created:int,flagged:int,errors:int,rows:list<array<string,mixed>>}
     */
    public static function importCsv(Actor $actor, string $path, ?string $displayName = null): array
    {
        $actor->require('ss_import');
        if (!is_readable($path)) {
            throw new DomainError('file_unreadable', 'The CSV file could not be read.');
        }
        $fh = fopen($path, 'rb');
        $header = fgetcsv($fh);
        if ($header === false) {
            throw new DomainError('empty_file', 'The CSV file is empty.');
        }
        $header = array_map(static fn($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $missing = array_diff(['last_name', 'first_name', 'birthdate', 'barangay'], $header);
        if ($missing !== []) {
            throw new DomainError('missing_columns', 'Missing required columns: ' . implode(', ', $missing));
        }

        $jobId = Uuid::v7();
        Db::insert('import_jobs', [
            'id' => $jobId,
            'tenant_id' => $actor->tenantId,
            'filename' => $displayName ?? basename($path),
            'sha256' => hash_file('sha256', $path),
            'status' => 'running',
            'created_by' => $actor->userId,
            'created_at' => Db::now(),
        ], ['id', 'tenant_id']);

        $counts = ['total' => 0, 'created' => 0, 'flagged' => 0, 'errors' => 0];
        $report = [];
        $rowNo = 1;
        while (($cells = fgetcsv($fh)) !== false) {
            $rowNo++;
            if ($cells === [null] || implode('', $cells) === '') {
                continue;
            }
            $counts['total']++;
            $data = [];
            foreach ($header as $i => $col) {
                if (in_array($col, self::COLUMNS, true)) {
                    $data[$col] = trim((string) ($cells[$i] ?? ''));
                }
            }
            $result = self::importRow($actor, $jobId, $rowNo, $data);
            $counts[$result['outcome'] === 'created' ? 'created' : ($result['outcome'] === 'flagged' ? 'flagged' : 'errors')]++;
            $report[] = $result;
        }
        fclose($fh);

        Db::exec(Db::prepare(
            'UPDATE ' . Db::t('import_jobs') . ' SET status = %s, total_rows = %d, created_count = %d, flagged_count = %d,
             error_count = %d, finished_at = %s WHERE id = UNHEX(%s)',
            'done', $counts['total'], $counts['created'], $counts['flagged'], $counts['errors'], Db::now(), $jobId
        ));
        Audit::log($actor->tenantId, $actor->userId, 'import.completed', 'import_job', $jobId, $counts);

        return ['job_id' => $jobId] + $counts + ['rows' => $report];
    }

    /**
     * @param array<string, string> $d
     * @return array<string, mixed>
     */
    private static function importRow(Actor $actor, string $jobId, int $rowNo, array $d): array
    {
        $rowId = Uuid::v7();
        $base = ['row_no' => $rowNo, 'source_ref' => $d['ref_no'] ?? null];
        $record = static function (string $outcome, ?string $match = null, ?string $reason = null, ?string $benId = null, ?string $msg = null)
            use ($actor, $jobId, $rowId, $rowNo, $d): void {
            Db::insert('import_rows', [
                'id' => $rowId,
                'tenant_id' => $actor->tenantId,
                'job_id' => $jobId,
                'row_no' => $rowNo,
                'payload_json' => wp_json_encode($d),
                'outcome' => $outcome,
                'match_beneficiary_id' => $match,
                'match_reason' => $reason,
                'beneficiary_id' => $benId,
                'message' => $msg,
                'created_at' => Db::now(),
            ], ['id', 'tenant_id', 'job_id', 'match_beneficiary_id', 'beneficiary_id']);
        };

        // 1. Validate.
        $error = self::validate($d);
        if ($error !== null) {
            $record('error', null, null, null, $error);
            return $base + ['outcome' => 'error', 'message' => $error];
        }

        // 2. Duplicate checks. Flag, never merge.
        $match = self::findDuplicate($actor->tenantId, $d);
        if ($match !== null) {
            $record('flagged', $match['id'], $match['reason'], null, 'Possible duplicate of ' . $match['ref_no']);
            return $base + ['outcome' => 'flagged', 'match_ref' => $match['ref_no'], 'reason' => $match['reason']];
        }

        // 3. Create as unverified. The import row is written first (beneficiary references it).
        $benId = Uuid::v7();
        $refNo = Sequence::ref($actor->tenantId, 'SS');
        $record('created', null, null, $benId, null);
        Db::insert('beneficiaries', [
            'id' => $benId,
            'tenant_id' => $actor->tenantId,
            'ref_no' => $refNo,
            'source_ref' => $d['ref_no'] ?? null,
            'last_name' => $d['last_name'],
            'first_name' => $d['first_name'],
            'middle_name' => self::nullIfEmpty($d['middle_name'] ?? ''),
            'suffix' => self::nullIfEmpty($d['suffix'] ?? ''),
            'sex' => self::nullIfEmpty(strtoupper($d['sex'] ?? '')),
            'birthdate' => $d['birthdate'],
            'barangay' => $d['barangay'],
            'address_line' => self::nullIfEmpty($d['address_line'] ?? ''),
            'contact_phone' => self::nullIfEmpty($d['contact_phone'] ?? ''),
            'osca_id' => self::nullIfEmpty($d['osca_id'] ?? ''),
            'verification_status' => 'unverified',
            'import_row_id' => $rowId,
            'status' => 'active',
            'created_at' => Db::now(),
        ], ['id', 'tenant_id', 'import_row_id']);

        return $base + ['outcome' => 'created', 'ref_no' => $refNo];
    }

    /**
     * Same validation as a CSV row, for other ways a record can arrive (website requests).
     *
     * @param array<string, string> $d
     */
    public static function validateRecord(array $d): ?string
    {
        return self::validate($d);
    }

    /**
     * Same duplicate checks as a CSV row: OSCA ID, source ref, or name + birthdate within a day.
     *
     * @param array<string, string> $d
     * @return array{id:string, ref_no:string, reason:string}|null
     */
    public static function duplicateOf(string $tenantId, array $d): ?array
    {
        return self::findDuplicate($tenantId, $d);
    }

    /** @param array<string, string> $d */
    private static function validate(array $d): ?string
    {
        if (($d['last_name'] ?? '') === '' || ($d['first_name'] ?? '') === '') {
            return 'Last name and first name are required.';
        }
        $dob = \DateTimeImmutable::createFromFormat('!Y-m-d', $d['birthdate'] ?? '');
        if ($dob === false || $dob->format('Y-m-d') !== $d['birthdate']) {
            return 'Birthdate must be a valid date in YYYY-MM-DD format.';
        }
        $age = $dob->diff(Db::todayManila())->y;
        if ($age < self::MIN_AGE || $age > 120) {
            return "Age {$age} is outside the senior citizen range.";
        }
        if (($d['barangay'] ?? '') === '') {
            return 'Barangay is required.';
        }
        $sex = strtoupper($d['sex'] ?? '');
        if ($sex !== '' && !in_array($sex, ['F', 'M'], true)) {
            return 'Sex must be F or M.';
        }
        return null;
    }

    /**
     * @param array<string, string> $d
     * @return array{id:string, ref_no:string, reason:string}|null
     */
    private static function findDuplicate(string $tenantId, array $d): ?array
    {
        $t = Db::t('beneficiaries');
        $osca = trim($d['osca_id'] ?? '');
        if ($osca !== '') {
            $hit = Db::row(Db::prepare(
                'SELECT ' . Db::hex('id') . ', ref_no FROM ' . $t . ' WHERE tenant_id = UNHEX(%s) AND osca_id = %s',
                $tenantId, $osca
            ));
            if ($hit) {
                return ['id' => $hit['id'], 'ref_no' => $hit['ref_no'], 'reason' => 'same_osca_id'];
            }
        }

        $src = trim($d['ref_no'] ?? '');
        if ($src !== '') {
            $hit = Db::row(Db::prepare(
                'SELECT ' . Db::hex('id') . ', ref_no FROM ' . $t . ' WHERE tenant_id = UNHEX(%s) AND source_ref = %s',
                $tenantId, $src
            ));
            if ($hit) {
                return ['id' => $hit['id'], 'ref_no' => $hit['ref_no'], 'reason' => 'same_source_ref'];
            }
        }

        // Same last name + overlapping first name + birthdate within one day (typo tolerance).
        $candidates = Db::rows(Db::prepare(
            'SELECT ' . Db::hex('id') . ', ref_no, last_name, first_name, birthdate FROM ' . $t . '
             WHERE tenant_id = UNHEX(%s) AND birthdate BETWEEN DATE_SUB(%s, INTERVAL 1 DAY) AND DATE_ADD(%s, INTERVAL 1 DAY)',
            $tenantId, $d['birthdate'], $d['birthdate']
        ));
        $last = Names::key($d['last_name']);
        foreach ($candidates as $c) {
            if (Names::key($c['last_name']) === $last && Names::firstNamesMatch($c['first_name'], $d['first_name'])) {
                $reason = $c['birthdate'] === $d['birthdate'] ? 'same_name_and_birthdate' : 'same_name_birthdate_off_by_one';
                return ['id' => $c['id'], 'ref_no' => $c['ref_no'], 'reason' => $reason];
            }
        }
        return null;
    }

    private static function nullIfEmpty(string $v): ?string
    {
        return $v === '' ? null : $v;
    }
}
