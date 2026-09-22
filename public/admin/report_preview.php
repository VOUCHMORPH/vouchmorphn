<?php
/**
 * public/admin/report_preview.php
 * Draft PDF for people in the signing chain, watermarked "DRAFT — AWAITING
 * SIGNATURES". The regulator never uses this page; it sees only issued PDFs.
 */
declare(strict_types=1);
require __DIR__ . '/_signing_bootstrap.php';

try {
    $pdf = $workflow->previewPdf((int)($_GET['id'] ?? 0), $adminId);
    vm_audit('REPORT_PREVIEWED', ['report_id' => (int)($_GET['id'] ?? 0)]);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="draft.pdf"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
} catch (Throwable $e) {
    http_response_code(403);
    echo h($e->getMessage());
}
