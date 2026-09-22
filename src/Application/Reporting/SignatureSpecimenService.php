<?php
declare(strict_types=1);

namespace Application\Reporting;

use PDO;
use RuntimeException;

/**
 * SignatureSpecimenService
 *
 * Stores each admin's signature image — drawn on screen or uploaded as a
 * photo or scan — so it can be stamped onto documents they sign.
 *
 * Every image is rebuilt from pixels, never stored as uploaded:
 *   - decoded with GD, which throws away any embedded script, EXIF,
 *     GPS location or polyglot payload
 *   - scaled to fit 600 x 200
 *   - near-white paper made transparent, so a photo of a signature on
 *     paper sits cleanly on the certificate page
 *   - re-encoded as PNG and saved OUTSIDE the web root, readable only by
 *     the application
 *
 * Setting or replacing a signature requires an MFA code. Old versions are
 * retired, never deleted, because past documents were signed with them.
 */
final class SignatureSpecimenService
{
    private const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;
    private const MAX_W = 600;
    private const MAX_H = 200;
    private const WHITE_THRESHOLD = 200;   // 0–255: brighter than this becomes transparent

    public function __construct(
        private PDO $db,
        private MfaVerifier $mfa,
        private string $storageDir,        // e.g. /app/storage/signatures — NOT under public/
    ) {}

    /** From the drawing pad: a data:image/png;base64,... string. */
    public function saveDrawn(int $adminId, string $dataUrl, string $mfaCode): int
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw new RuntimeException('Drawn signature must be a PNG data URL');
        }
        $bytes = base64_decode($m[1], true);
        if ($bytes === false || strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Drawn signature is invalid or too large');
        }
        return $this->store($adminId, 'DRAWN', $bytes, $mfaCode);
    }

    /** From a file input: a photo or scan of a signature on paper. */
    public function saveUploaded(int $adminId, array $file, string $mfaCode): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Upload failed');
        }
        if ($file['size'] > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Signature image must be under 2 MB');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw new RuntimeException('Only PNG or JPEG images are accepted');
        }
        return $this->store($adminId, 'UPLOADED', file_get_contents($file['tmp_name']), $mfaCode);
    }

    /** The admin's current signature, or null if none is set. */
    public function active(int $adminId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM admin_signature_specimens WHERE admin_id = :a AND retired_at IS NULL'
        );
        $stmt->execute([':a' => $adminId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * For embedding in the issued PDF. Verifies the file still matches the
     * hash recorded when it was set — a swapped image is refused.
     */
    public function dataUri(int $specimenId): string
    {
        $stmt = $this->db->prepare('SELECT image_path, image_sha256 FROM admin_signature_specimens WHERE id = :id');
        $stmt->execute([':id' => $specimenId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException("specimen {$specimenId} not found");

        $bytes = @file_get_contents($row['image_path']);
        if ($bytes === false || hash('sha256', $bytes) !== $row['image_sha256']) {
            throw new RuntimeException("signature image {$specimenId} is missing or has been altered");
        }
        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    // -----------------------------------------------------------------
    private function store(int $adminId, string $kind, string $bytes, string $mfaCode): int
    {
        if (!$this->mfa->verify($adminId, $mfaCode)) {
            throw new RuntimeException('MFA verification failed; signature not saved');
        }

        [$png, $w, $h] = $this->clean($bytes);
        $sha = hash('sha256', $png);

        $dir = rtrim($this->storageDir, '/') . '/' . $adminId;
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create signature storage');
        }
        $path = "{$dir}/{$sha}.png";
        if (file_put_contents($path, $png) === false) {
            throw new RuntimeException('Could not save signature');
        }
        @chmod($path, 0600);

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE admin_signature_specimens SET retired_at = now()
                  WHERE admin_id = :a AND retired_at IS NULL'
            )->execute([':a' => $adminId]);

            $stmt = $this->db->prepare(
                'INSERT INTO admin_signature_specimens
                    (admin_id, kind, image_path, image_sha256, width_px, height_px, mfa_verified_at)
                 VALUES (:a, :k, :p, :s, :w, :h, now()) RETURNING id'
            );
            $stmt->execute([':a' => $adminId, ':k' => $kind, ':p' => $path, ':s' => $sha, ':w' => $w, ':h' => $h]);
            $id = (int)$stmt->fetchColumn();

            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Decode, scale, make paper transparent, re-encode. Returns [png, w, h]. */
    private function clean(string $bytes): array
    {
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new RuntimeException('Not a readable image');
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 40 || $sh < 15) {
            imagedestroy($src);
            throw new RuntimeException('Signature image is too small');
        }

        $scale = min(self::MAX_W / $sw, self::MAX_H / $sh, 1.0);
        $w = max(1, (int)round($sw * $scale));
        $h = max(1, (int)round($sh * $scale));

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
        imagedestroy($src);

        // Paper to transparent; ink to a consistent dark blue.
        $ink = imagecolorallocatealpha($out, 20, 40, 90, 0);
        $clear = imagecolorallocatealpha($out, 255, 255, 255, 127);
        $inkPixels = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($out, $x, $y);
                $a = ($c >> 24) & 0x7F;
                $lum = (int)((($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114);
                if ($a > 100 || $lum > self::WHITE_THRESHOLD) {
                    imagesetpixel($out, $x, $y, $clear);
                } else {
                    imagesetpixel($out, $x, $y, $ink);
                    $inkPixels++;
                }
            }
        }

        // Reject a blank pad or a photo with no discernible ink.
        if ($inkPixels < ($w * $h) * 0.005) {
            imagedestroy($out);
            throw new RuntimeException('No signature detected in the image');
        }

        ob_start();
        imagepng($out, null, 9);
        $png = ob_get_clean();
        imagedestroy($out);

        return [$png, $w, $h];
    }
}
