<?php


declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

require_once __DIR__ . '/../config/db.php';

set_time_limit(0);

class OutboxWorker
{
    private PDO $pdo;
    private int $batch = 20;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function run(): void
    {
        echo "Outbox Worker Started...\n";

        while (true) {
            $this->processRegulator();
            $this->processMessages();

            sleep(2);
        }
    }

    /* =========================================
       REGULATOR REPORT DELIVERY
    ========================================= */

    private function processRegulator(): void
    {
        $stmt = $this->pdo->query("
            SELECT * FROM regulator_outbox
            WHERE status IN ('PENDING','RETRY')
            ORDER BY created_at
            LIMIT {$this->batch}
            FOR UPDATE SKIP LOCKED
        ");

        foreach ($stmt->fetchAll() as $row) {
            $this->deliverRegulator($row);
        }
    }

    private function deliverRegulator(array $row): void
    {
        $id = $row['report_id'];

        try {
            // Simulated regulator ACK
            $ack = $this->fakeRegulatorEndpoint($row['payload']);

            if ($ack) {
                $this->updateStatus('regulator_outbox', $id, 'SENT');
            } else {
                $this->retry('regulator_outbox', $id);
            }

        } catch (\Throwable $e) {
            $this->retry('regulator_outbox', $id);
        }
    }

    private function fakeRegulatorEndpoint(string $payload): bool
    {
        // Simulate real regulator behaviour
        return random_int(1, 10) > 2; // 80% success
    }


    /* =========================================
       MESSAGE DELIVERY (SMS/USSD)
    ========================================= */

    private function processMessages(): void
    {
        $stmt = $this->pdo->query("
            SELECT * FROM message_outbox
            WHERE status IN ('PENDING','RETRY')
            ORDER BY created_at
            LIMIT {$this->batch}
            FOR UPDATE SKIP LOCKED
        ");

        foreach ($stmt->fetchAll() as $row) {
            $this->deliverMessage($row);
        }
    }

    private function deliverMessage(array $row): void
    {
        $id = $row['message_id'];

        try {
            $sent = $this->fakeSmscSend($row['destination'], $row['payload']);

            if ($sent) {
                $this->updateStatus('message_outbox', $id, 'DELIVERED');
            } else {
                $this->retry('message_outbox', $id);
            }

        } catch (\Throwable $e) {
            $this->retry('message_outbox', $id);
        }
    }

    private function fakeSmscSend(string $phone, string $payload): bool
    {
        return random_int(1, 10) > 3; // 70% success
    }


    /* =========================================
       RETRY LOGIC (critical for sandbox)
    ========================================= */

    private function retry(string $table, string $id): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE {$table}
            SET attempts = attempts + 1,
                last_attempt = NOW(),
                status = CASE
                    WHEN attempts >= 5 THEN 'FAILED'
                    ELSE 'RETRY'
                END
            WHERE ".($table === 'message_outbox' ? 'message_id' : 'report_id')." = :id
        ");

        $stmt->execute([':id' => $id]);
    }

    private function updateStatus(string $table, string $id, string $status): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE {$table}
            SET status = :status,
                last_attempt = NOW()
            WHERE ".($table === 'message_outbox' ? 'message_id' : 'report_id')." = :id
        ");

        $stmt->execute([
            ':status' => $status,
            ':id' => $id
        ]);
    }
}

$worker = new OutboxWorker($pdo);
$worker->run();

