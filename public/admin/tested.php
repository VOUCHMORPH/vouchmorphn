<?php
// test_vouchmorph_certificate.php
// Using your actual VouchMorph certificates

// ============================================================
// YOUR ACTUAL CERTIFICATE VALUES (from the output)
// ============================================================

$privateKeyContent = '-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC/ofTsCLGnFMFM
alG89Pp+XGGM26pGRlqz6LKbD8tinwBwKGkcoNFqNdxwOfwigs7/U3jIwTKrDOuu
FMFBWYTozEhtOi5SvQunkArsyxazEz1lLpHq6hPnws2fr9VJKimRi+Y3J1dmR97b
JYU6b0NesJrOfG+a7dOuKz+svGkAQztTVYuKFkEIHb+hqcIOqP+aCahLk+GjhBOy
YfRQ++vZkVc82FC+XvVYRWfXcN4UpJDOIX/I/mA4C8HdBHKWzmhc25hQFwpPYXuP
frDESsm4Iz1+LbxazTQxqse20fAP87jxsWdgyKCbYXtvZPtjlG3of18OwnAnZ6b4
iobmHidFAgMBAAECggEAGcAjH8aJTMjasrCEvE31DR7P0vQ/nMLedFd8DJ8iNuXj
0q5zdW/cxBdpwEDicya9twyZ6ewgKWEKmHFciLIFTJ0KzHape9/AXata9HfT3Itk
0CBiZj0/IIEKTX98uyCpxk+Ux2uHcIyO00bm8LO7y1ES9FU/UAPOUpSoMUd+GpUy
nmo/5a0JAxL3+BdZU1b7wuIyRvMTYboik5710IElez41aQ0psBxAAHccZq58M3qT
DCuLVAeA1btQsMLnU0vHsYVGazUICl9ttx3F7NwEvJGS/ObiJCpYuwAA3ZEU6Chn
3ir+RpSEC8L5cpZ5EfrcMlLx6AuUVaw0H8TW4EojgQKBgQDohW/fhJC7Q9k7484i
6HBxiVtWzWaVLismhn/Bl8t3natQRwAGuVLuxgllm2PyMt/iI5Ks5n41U5T4LDDQ
COVLzQnKkpMo1B/ueJRnvEahTSh0lsu4o5UMlMhC83W5iTSxspq3OECshhsXGAw1
ocuZrXnkQXLsfG8Tn7wJG1QwwQKBgQDS+5GuaE0uNNM3GSuYGC+ZPwiLkcJuBlhh
PP1otNR6j07J+sjYp879ulUZe0tZ+ArA3wLTTdvmbVVF5vc+FaWzKWxh1cZp3Q+i
56JSmxg9+Ghu3Kj1DHr5T0b1GZTp6CnQa1nTW/m6QYSfoGLTb32t7Rx3iC8Tiio/
Ii+qkeKThQKBgQCIxs17JwjYD14+249Le32BW/Ityl94i5L4c4+9OmSGtWmrrEg4
rFU7faTTbyfIteJ6rMBAEsnU5pivr5b7GPCAuVj0H2qPTtCFv8pUhyzo/3E3u+iU
cS1hHDf9Iidy/2HO0agu9NkeYziWSiAMgGM6wA/+k/1dXQkd+w1qMfhBQQKBgDcp
nUJbdOqC10KNqy8+C5vmtiY/uvUnZY4u8xagSPmuZGw8zKyQ23bNdBiQevgP+UfK
RyPWNIt/xI7dP4GVCVjZmMPPr+vX55GxPGiasnDpdOyfdvFzDOgISUYmJSAvGleq
6bZwUs/W94UA2zXq7ZI+73V1PtG+CyOsnYfcUsA9AoGAFzpdLsnXfkm4TyARhp0S
02SMzU/naASLzlto33857Ul5ZGX8xk2Zni6rV+ztteecFJIZzHgUTlH2ZOH+iizv
d2TWyZ09EeCt8FIYTc2vhxsPFzdAGJuvl/y1rSTAaKPRTDs3hkZuGkiuiCFGz12/
X1KPdDvqpylJWtT9OTTFfcY=
-----END PRIVATE KEY-----';

$certificateContent = '-----BEGIN CERTIFICATE-----
MIIEyjCCArKgAwIBAgIBATANBgkqhkiG9w0BAQsFADB3MQswCQYDVQQGEwJCVzER
MA8GA1UECAwIR2Fib3JvbmUxETAPBgNVBAcMCEdhYm9yb25lMSUwIwYDVQQKDBxW
b3VjaE1vcnBoIEZpbmFuY2lhbCBOZXR3b3JrMRswGQYDVQQDDBJWb3VjaE1vcnBo
IFJvb3QgQ0EwHhcNMjYwNjEyMTI0MDEzWhcNMzEwNjExMTI0MDEzWjBcMQswCQYD
VQQGEwJCVzERMA8GA1UECAwIR2Fib3JvbmUxJTAjBgNVBAoMHFZvdWNoTW9ycGgg
RmluYW5jaWFsIE5ldHdvcmsxEzARBgNVBAMMClZPVUNITU9SUEgwggEiMA0GCSqG
SIb3DQEBAQUAA4IBDwAwggEKAoIBAQCtjyLf5rfEnDl7V0G1ZoymT4/dUzJf3mRi
JGze1TBvrUvI2YxKyquCn0jdyTw/ZLDjGhfuZGcBqhx7ECMet23HFO0fBnFp5mVr
I9xA1Zc7JvSsxW4c+zCGIbCa4PYNPaeDLyyk40GB3D6Cx65B6yIljaklonmO3Rvg
WxS/5Te8hEMbhGjRW5L1NOHSRzA5AUdIdfOPvj0T2+ux0sB0lk1S0D79ObkZ5JGy
BjYPF77vtZ7ocPaHPbI8lz4OKa7NnGQwNMFK1rg2LX2aFq+K0GpTafjbQThUuT67
zR0chiIvOVL+rn4xEE2L5ZN2fUIovEOU7y5F0EccfBk/McWjcSPlAgMBAAGjfDB6
MAkGA1UdEwQCMAAwDgYDVR0PAQH/BAQDAgWgMB0GA1UdJQQWMBQGCCsGAQUFBwMC
BggrBgEFBQcDATAdBgNVHQ4EFgQUpVkkkW8zzVPLkQessBv2uAxzIYMwHwYDVR0j
BBgwFoAU0FFj1ToloFj8TAncmP+H/5iRj+0wDQYJKoZIhvcNAQELBQADggIBAGW+
XBZ2A8PaVc2ln82NoyiC9d7rKKUNtLl7stqDlN3pub0fwE1qv2FaGFsvlQoCHnl/
wM0+YsqxnyL0GExY8ZCL3FUvYghoSWlJKGQ+4O+9p0XIzcZhPYe6lRd/Yg1s6vUX
SD4t2IXkMuvGrDosPFnWscWKzU2thZc6LWdkefuG8fqfiTko5ZVTYweuWC1YUKRz
phcYUJrU8uCWA7iqxpv30R5Avg0x+kquOgauFpyoZezXtGLNoxkrXr1Fu5ayujtO
Qr03u1XVRhNdrakC/gqgVrGJq1ugMpkwVhDzRD4FSOeU6nW5xv5+v9k4KcTq039+
4atrZe2CM85h5IIBxPfX3XnQFg1spLG5mjT+tTY8diDD8DBgwDtpDGLVWLTT9pch
MJ8aihfpiUOIyEWv10xvUFRNE4NgvMy28PDSXioQ2caLW4NAyHaErLP1G2OdKVHW
CQ6yn5OohVXdg1PnojqlT5d2uJ/d1VvZ01GchBBBJwL8/rI0upnoTKN2IwMA3JkR
e7VfevNWm55NHyOMjcV8R02ZzmDPFaN1qq6L1qq2jjAMhkx4xmzt7XT/1BFctMEQ
ZyFV4qFCvOBbe3SXFT80aDH3v95huhDHja29j2bhyKtpOKyE2609uI790aDCFwL4
ygYizhWym67NvpSU02R8QpyU0QgGxEsOe4x8tFBg
-----END CERTIFICATE-----';

$caCertificateContent = '-----BEGIN CERTIFICATE-----
MIIFwTCCA6mgAwIBAgIUbqAzvI1c1vpp7+CTC/Tuou4SzJswDQYJKoZIhvcNAQEL
BQAwdzELMAkGA1UEBhMCQlcxETAPBgNVBAgMCEdhYm9yb25lMREwDwYDVQQHDAhH
YWJvcm9uZTElMCMGA1UECgwcVm91Y2hNb3JwaCBGaW5hbmNpYWwgTmV0d29yazEb
MBkGA1UEAwwSVm91Y2hNb3JwaCBSb290IENBMB4XDTI2MDYxMjEyNDAzMFoXDTQ2
MDYwNzEyNDAzMFowdzELMAkGA1UEBhMCQlcxETAPBgNVBAgMCEdhYm9yb25lMREw
DwYDVQQHDAhHYWJvcm9uZTElMCMGA1UECgwcVm91Y2hNb3JwaCBGaW5hbmNpYWwg
TmV0d29yazEbMBkGA1UEAwwSVm91Y2hNb3JwaCBSb290IENBMIICIjANBgkqhkiG
9w0BAQEFAAOCAg8AMIICCgKCAgEAsQIxT7qpxCmEFSiWvnJyDiZsGapgSjGSppMx
T9nmv5Lj+EZn+baZYcDvaGuT1BPHGZTVM+SUogqzdpXM0uy0owIuvV0ueCYAfam1
OUFZSoK9yah3CarYWqzoxXpAxbGBUiGbMPDEOd/VXM/7/12Maxc6nRiiiPnDEaoK
59fH8606DWa7QVPDe0OvzCZZkK+1nbPtzKUOKs3kmWaej0JlRl3InUKEx99T+cYw
+Gjpyvip5NpgrLwevdF/rFtCAc1BwvwKPHp3S/qY+GErQDf/JgMB91HcPwVQQIJw
/qzgjg8//M17Ar/NTwFH8x43YJvwUYVw7wzPBZk4B84yDMOYkNV8/h+CQu/XVt2A
PTNAbKhbE2WDlyHZNqaSGH3VEOmjWN2O9ZxsRqAQR50F1QGnXBvODXFH1nQmMpYO
5BW5pf6cpSuLdAiEBA2aIlNsHLUbtaXjV0hYFbf/WG/G1Gw2pGfyQLj0JiTSdAIv
3hlVPgfVlZLqZ24IXFncZje4qHTObV3yZgtL2f8jQdN0FpHBfoJ/ypw0Py/1fg+R
jDHpzKSpDupy0bStnD8nNwPp+ddes/3fKMzXE7YNwABBDVhv5+h3YHdv+xNIoH8Z
CIJZK4ykJzuYaksxt8bn3Wr+kPrBioEMTNfh7KapGVSsR/88SLw9EhZ64oM6ajIo
dald6tECAwEAAaNFMEMwEgYDVR0TAQH/BAgwBgEB/wIBATAOBgNVHQ8BAf8EBAMC
AQYwHQYDVR0OBBYEFNxbwalKi6L4UFnj023cTLgE/vqLMA0GCSqGSIb3DQEBCwUA
A4ICAQBsBbyVHEQ5qau4qW2z6brhprgDT1LeRr4sFFY8zd2jLOVxfBEvpqCO35nw
Zh7O8vH5X4PtsJjUf/nCpvs8IgYhwPj5SIXD3lydAW6H20I4IK39VRvaa6GgymkQ
i9DSV7X4ISP6bkl0OO7j6DOrYOU4qCuRUnlg3wXJAn2wRH+hkeaERqo+n6+iqd0t
volldLqAynblRmzWqg60q8gaDlktWMUvnOZpzOsdyuACtxLIVXhqe8gumex2uABe
lwZ6jLLUe+tf57pZJ7jlGUNZ68M7oFqKRqYAu4yu/mUZ3CFWBBNdRWLJY5e4AN5l
/sHG3Ep4mjmVdS0zQWlDtV4Gke+utb4xO0xYTX/2QgdfbcAeFbIh9J+pCBYyTHXc
SQ/szQvX97uEKGr0/HqszFeKHAE9M7j3fXgvLO4E2MuYMa7ahPQDgkMZj5C8zJO6
YwAMH0M87jjnYklFhYwpqn5WtS4H2IM8z/up1sDfH46omuzhV/cLS2tQd1rO7D37
bUnOA6TNmIkWgQsRQGJsQq812TxfgawKJCMY273C2sQByOSD2GyzVeWHVtdrMeeb
nFftyIHr4bdbXzMPad4sERPK+w5NW/iR7MSfOhDeqoKrIzCNeaWu2IUWLUQhKweO
AJRPiPC/8PT2onES8K4PalvnlF84PdHRnxDpN1FkXzA9zujlUg==
-----END CERTIFICATE-----';

// ============================================================
// SET UP ENVIRONMENT
// ============================================================

putenv('MEMBER_NAME=VOUCHMORPH');
putenv('VOUCHMORPH_PRIVATE_KEY_CONTENT=' . $privateKeyContent);
putenv('VOUCHMORPH_CERT_CONTENT=' . $certificateContent);
putenv('VOUCHMORPH_CA_CERT_CONTENT=' . $caCertificateContent);

// Include the classes
require_once '/../../src/Infrastructure/Crypto/CertificateManager.php';
require_once '/../../src/Infrastructure/Crypto/MessagerSigner.php';

use Infrastructure\Crypto\CertificateManager;
use Infrastructure\Crypto\MessagerSigner;

// ============================================================
// CREATE SIGNED REQUEST
// ============================================================

echo "========== VOUCHMORPH CERTIFICATE-BASED SIGNING ==========\n\n";

$certManager = new CertificateManager('VOUCHMORPH');

if (!$certManager->isConfigured()) {
    die("ERROR: CertificateManager not configured\n");
}

echo "✓ CertificateManager configured\n\n";

// Create payload
$payload = [
    'action' => 'PLACE_HOLD',
    'reference' => 'SWAP_TEST_' . time(),
    'asset_type' => 'BANK-WALLET',
    'amount' => 100,
    'currency' => 'BWP',
    'hold_reason' => 'PENDING_SWAP',
    'destination_institution' => 'ZURUBANK',
    'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour')),
    'source_identifier' => '+26770000000',
    'source_identifier_type' => 'phone',
    'asset_id' => 4,
    'wallet_phone' => '+26770000000',
    'phone' => '+26770000000',
    'national_id' => '+26770000000',
    'email' => '+26770000000'
];

// Create signed request with certificate
$signedRequest = $certManager->createSignedRequest($payload, 'VOUCHMORPH');

echo "Signed request created with:\n";
echo "  - Certificate: " . (isset($signedRequest['certificate']) ? "✓ PRESENT" : "✗ MISSING") . "\n";
echo "  - Signature: " . (isset($signedRequest['signature']) ? "✓ PRESENT" : "✗ MISSING") . "\n";
echo "  - Timestamp: " . ($signedRequest['timestamp'] ?? 'MISSING') . "\n\n";

// Save to file
file_put_contents('hold_request_cert.json', json_encode($signedRequest));

echo "========== CURL COMMAND ==========\n";
echo "curl -X POST https://saccussalis-production.up.railway.app/backend/api/v1/hold.php \\\n";
echo "  -H \"Content-Type: application/json\" \\\n";
echo "  -d @hold_request_cert.json\n\n";

// ============================================================
// LOCAL VERIFICATION
// ============================================================

echo "========== LOCAL VERIFICATION ==========\n";
$verification = $certManager->verifySignedRequest($signedRequest);
echo "Result: " . ($verification['verified'] ? "✓ VALID" : "✗ INVALID") . "\n";
echo "Requester: " . ($verification['requester'] ?? 'UNKNOWN') . "\n";
