<?php
declare(strict_types=1);

namespace Application\Enterprise;

use PDO;
use RuntimeException;

/**
 * InductionService — reading, proving, declaring.
 *
 * Rules enforced here, not in the page:
 *   - sections are completed in order; the next unlocks only when the
 *     previous is acknowledged
 *   - "Understood" requires (a) the minimum honest reading time since the
 *     section was opened, and (b) a correct answer to its check
 *   - answer options are shuffled per person, so a pattern cannot pass
 *   - a wrong answer explains why, restarts that section's reading clock,
 *     and points to the role manual
 *   - the declaration needs every section acknowledged at its CURRENT
 *     fingerprint, the person's full name typed, and their password again
 *   - editing a section invalidates only that section; the person re-reads
 *     what changed and declares again
 *   - changing role means a new induction for the new role
 */
final class InductionService
{
    /** @param callable(int $userId, string $password): bool $passwordVerifier */
    public function __construct(private PDO $db, private $passwordVerifier) {}

    // =================================================================
    // STATUS
    // =================================================================
    public function status(int $userId, int $orgId, string $role): array
    {
        $sections = InductionCurriculum::forRole($role);
        if (!$sections) {
            throw new RuntimeException("No induction exists for role {$role}");
        }

        $acked = $this->ackedHashes($userId, $orgId, $role);
        $out = [];
        $firstOpen = null;
        foreach ($sections as $i => $s) {
            $hash = InductionCurriculum::sectionHash($s);
            $done = isset($acked[$s['id']]) && $acked[$s['id']] === $hash;
            $changed = isset($acked[$s['id']]) && $acked[$s['id']] !== $hash;
            if (!$done && $firstOpen === null) {
                $firstOpen = $i;
            }
            $out[] = [
                'id' => $s['id'], 'title' => $s['title'], 'minutes' => $s['minutes'],
                'done' => $done, 'changed' => $changed,
                'locked' => !$done && $firstOpen !== null && $i > $firstOpen,
                'foundation' => str_starts_with($s['id'], 'F'),
            ];
        }

        $total = count($out);
        $doneCount = count(array_filter($out, fn($x) => $x['done']));
        $declared = $this->currentDeclaration($userId, $orgId, $role);

        return [
            'role' => $role,
            'role_label' => InductionCurriculum::ROLES[$role],
            'sections' => $out,
            'done' => $doneCount,
            'total' => $total,
            'percent' => (int)floor($doneCount / $total * 100),
            'next' => $firstOpen === null ? null : $out[$firstOpen]['id'],
            'ready_to_declare' => $doneCount === $total && !$declared,
            'inducted' => (bool)$declared,
            'declared_at' => $declared['declared_at'] ?? null,
            'certificate_ref' => $declared ? sprintf('VM-IND-%s-%06d', $declared['curriculum_version'], $declared['id']) : null,
            'curriculum_sha256' => $declared['curriculum_sha256'] ?? null,
            'refresh_required' => !$declared && $this->hasAnyDeclaration($userId, $orgId, $role),
        ];
    }

    public function isInducted(int $userId, int $orgId, string $role): bool
    {
        if (!isset(InductionCurriculum::ROLES[$role])) {
            return false;   // unknown role: never inducted, never let through
        }
        return (bool)$this->currentDeclaration($userId, $orgId, $role);
    }

    // =================================================================
    // READ A SECTION
    // =================================================================
    /** Returns the section with options in this person's shuffled order, and records the opening. */
    public function open(int $userId, int $orgId, string $role, string $sectionId): array
    {
        $st = $this->status($userId, $orgId, $role);
        $row = $this->statusRow($st, $sectionId);
        if ($row['locked']) {
            throw new RuntimeException('Complete the earlier sections first.');
        }

        $s = $this->section($role, $sectionId);
        $hash = InductionCurriculum::sectionHash($s);

        if (!$row['done']) {
            $this->db->prepare(
                'INSERT INTO induction_progress (user_id, organization_id, role_code, section_id, section_sha256, opened_at)
                 VALUES (:u, :o, :r, :s, :h, now())
                 ON CONFLICT (user_id, organization_id, role_code, section_id, section_sha256) DO NOTHING'
            )->execute([':u' => $userId, ':o' => $orgId, ':r' => $role, ':s' => $sectionId, ':h' => $hash]);
        }

        $order = $this->optionOrder($userId, $sectionId, count($s['check']['options']));
        $s['check']['display'] = array_map(fn($i) => $s['check']['options'][$i], $order);
        unset($s['check']['answer']);   // never sent to the browser
        if (!$row['done']) {
            unset($s['check']['why']);  // the explanation would give the answer away
        }
        $s['min_seconds'] = InductionCurriculum::minSeconds($s);
        $s['seconds_remaining'] = $row['done'] ? 0 : $this->secondsRemaining($userId, $orgId, $role, $sectionId, $hash, $s['min_seconds']);
        $s['done'] = $row['done'];
        $s['position'] = array_search($sectionId, array_column($st['sections'], 'id'), true) + 1;
        $s['total'] = $st['total'];
        return $s;
    }

    // =================================================================
    // ANSWER THE CHECK — the "Understood" button
    // =================================================================
    /** @return array{correct:bool, why:string, next:?string} */
    public function answer(int $userId, int $orgId, string $role, string $sectionId, int $displayedIndex): array
    {
        $s = $this->section($role, $sectionId);
        $hash = InductionCurriculum::sectionHash($s);
        $st = $this->status($userId, $orgId, $role);
        $row = $this->statusRow($st, $sectionId);

        if ($row['locked']) {
            throw new RuntimeException('Complete the earlier sections first.');
        }
        if ($row['done']) {
            return ['correct' => true, 'why' => $s['check']['why'], 'next' => $st['next']];
        }

        $remaining = $this->secondsRemaining($userId, $orgId, $role, $sectionId, $hash, InductionCurriculum::minSeconds($s));
        if ($remaining > 0) {
            throw new RuntimeException("Please finish reading — about {$remaining} seconds left.");
        }

        $order = $this->optionOrder($userId, $sectionId, count($s['check']['options']));
        if (!isset($order[$displayedIndex])) {
            throw new RuntimeException('Choose one of the answers.');
        }
        $chosen = $order[$displayedIndex];
        $correct = $chosen === $s['check']['answer'];

        $this->db->prepare(
            'INSERT INTO induction_attempts (user_id, organization_id, role_code, section_id, section_sha256, chosen_option, correct)
             VALUES (:u, :o, :r, :s, :h, :c, :ok)'
        )->execute([':u' => $userId, ':o' => $orgId, ':r' => $role, ':s' => $sectionId, ':h' => $hash,
                    ':c' => $chosen, ':ok' => $correct ? 't' : 'f']);

        if ($correct) {
            $this->db->prepare(
                'UPDATE induction_progress
                    SET acknowledged_at = now(),
                        seconds_read = EXTRACT(EPOCH FROM (now() - opened_at))::int
                  WHERE user_id = :u AND organization_id = :o AND role_code = :r
                    AND section_id = :s AND section_sha256 = :h AND acknowledged_at IS NULL'
            )->execute([':u' => $userId, ':o' => $orgId, ':r' => $role, ':s' => $sectionId, ':h' => $hash]);

            $after = $this->status($userId, $orgId, $role);
            return ['correct' => true, 'why' => $s['check']['why'], 'next' => $after['next']];
        }

        // Wrong: explain, and restart this section's reading clock.
        $this->db->prepare(
            'UPDATE induction_progress SET opened_at = now()
              WHERE user_id = :u AND organization_id = :o AND role_code = :r
                AND section_id = :s AND section_sha256 = :h AND acknowledged_at IS NULL'
        )->execute([':u' => $userId, ':o' => $orgId, ':r' => $role, ':s' => $sectionId, ':h' => $hash]);

        return ['correct' => false, 'why' => $s['check']['why'], 'next' => $sectionId];
    }

    // =================================================================
    // "I DON'T UNDERSTAND"
    // =================================================================
    public function requestHelp(int $userId, int $orgId, string $role, string $sectionId, string $note): int
    {
        $this->section($role, $sectionId);
        $stmt = $this->db->prepare(
            'INSERT INTO induction_help_requests (user_id, organization_id, role_code, section_id, note)
             VALUES (:u, :o, :r, :s, :n) RETURNING id'
        );
        $stmt->execute([':u' => $userId, ':o' => $orgId, ':r' => $role, ':s' => $sectionId,
                        ':n' => mb_substr(trim($note), 0, 1000)]);
        return (int)$stmt->fetchColumn();
    }

    // =================================================================
    // DECLARE INDUCTED
    // =================================================================
    public function declare(int $userId, int $orgId, string $role, string $registeredFullName,
                            string $typedName, string $password, ?string $ip = null): array
    {
        $st = $this->status($userId, $orgId, $role);
        if ($st['inducted']) {
            throw new RuntimeException('You have already declared this induction.');
        }
        if ($st['done'] !== $st['total']) {
            throw new RuntimeException('Every section must be marked Understood before you can declare.');
        }
        if ($this->normaliseName($typedName) !== $this->normaliseName($registeredFullName)) {
            throw new RuntimeException('Type your full name exactly as registered: ' . $registeredFullName . '.');
        }
        if (!($this->passwordVerifier)($userId, $password)) {
            throw new RuntimeException('Password incorrect. The declaration was not recorded.');
        }

        $hash = InductionCurriculum::curriculumHash($role);
        $stmt = $this->db->prepare(
            'INSERT INTO induction_declarations
                (user_id, organization_id, role_code, curriculum_sha256, curriculum_version, full_name_typed,
                 sections_count, password_reverified, ip_address)
             VALUES (:u, :o, :r, :h, :v, :n, :c, TRUE, :ip)
             RETURNING id, declared_at'
        );
        $stmt->execute([':u' => $userId, ':o' => $orgId, ':r' => $role, ':h' => $hash,
                        ':v' => InductionCurriculum::VERSION_LABEL, ':n' => trim($typedName),
                        ':c' => $st['total'], ':ip' => $ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return ['declaration_id' => (int)$row['id'], 'declared_at' => $row['declared_at'],
                'certificate_ref' => sprintf('VM-IND-%s-%06d', InductionCurriculum::VERSION_LABEL, $row['id']),
                'curriculum_sha256' => $hash];
    }

    // =================================================================
    // REGISTER — for the Owner, auditors and the regulator
    // =================================================================
    public function register(int $orgId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM v_induction_register WHERE organization_id = :o ORDER BY full_name');
        $stmt->execute([':o' => $orgId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $current = isset(InductionCurriculum::ROLES[$r['role_code']])
                ? InductionCurriculum::curriculumHash($r['role_code']) : null;
            $r['status'] = match (true) {
                $r['curriculum_sha256'] === null  => 'NOT INDUCTED',
                $r['curriculum_sha256'] === $current => 'INDUCTED',
                default => 'REFRESH DUE',
            };
        }
        return $rows;
    }

    // =================================================================
    // Internals
    // =================================================================
    private function section(string $role, string $id): array
    {
        foreach (InductionCurriculum::forRole($role) as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }
        throw new RuntimeException("Section {$id} is not part of this induction.");
    }

    private function statusRow(array $st, string $id): array
    {
        foreach ($st['sections'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        throw new RuntimeException("Section {$id} is not part of this induction.");
    }

    private function ackedHashes(int $userId, int $orgId, string $role): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT ON (section_id) section_id, section_sha256
               FROM induction_progress
              WHERE user_id = :u AND organization_id = :o AND role_code = :r AND acknowledged_at IS NOT NULL
              ORDER BY section_id, acknowledged_at DESC'
        );
        $stmt->execute([':u' => $userId, ':o' => $orgId, ':r' => $role]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private function currentDeclaration(int $userId, int $orgId, string $role): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM induction_declarations
              WHERE user_id = :u AND organization_id = :o AND role_code = :r AND curriculum_sha256 = :h'
        );
        $stmt->execute([':u' => $userId, ':o' => $orgId, ':r' => $role, ':h' => InductionCurriculum::curriculumHash($role)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function hasAnyDeclaration(int $userId, int $orgId, string $role): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM induction_declarations WHERE user_id = :u AND organization_id = :o AND role_code = :r');
        $stmt->execute([':u' => $userId, ':o' => $orgId, ':r' => $role]);
        return (bool)$stmt->fetchColumn();
    }

    private function secondsRemaining(int $userId, int $orgId, string $role, string $id, string $hash, int $min): int
    {
        $stmt = $this->db->prepare(
            'SELECT EXTRACT(EPOCH FROM (now() - opened_at))::int FROM induction_progress
              WHERE user_id = :u AND organization_id = :o AND role_code = :r AND section_id = :s AND section_sha256 = :h'
        );
        $stmt->execute([':u' => $userId, ':o' => $orgId, ':r' => $role, ':s' => $id, ':h' => $hash]);
        $elapsed = $stmt->fetchColumn();
        return $elapsed === false ? $min : max(0, $min - (int)$elapsed);
    }

    /** Stable, per-person permutation of option indices. */
    public function optionOrder(int $userId, string $sectionId, int $n): array
    {
        $idx = range(0, $n - 1);
        usort($idx, fn($a, $b) => strcmp(hash('sha256', "{$userId}|{$sectionId}|{$a}"), hash('sha256', "{$userId}|{$sectionId}|{$b}")));
        return $idx;
    }

    private function normaliseName(string $n): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($n)));
    }
}
