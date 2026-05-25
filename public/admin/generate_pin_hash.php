#!/usr/bin/env php
<?php
/**
 * PIN Hash Generator for VouchMorph System
 * Usage: php generate_pin_hash.php
 * 
 * This script creates secure bcrypt/Argon2i hashes for user PINs
 * Compatible with your existing password_hash() system
 */

// ============================================================
// CONFIGURATION
// ============================================================

// Choose hashing algorithm (recommended: PASSWORD_DEFAULT or PASSWORD_BCRYPT)
// Options: PASSWORD_DEFAULT, PASSWORD_BCRYPT, PASSWORD_ARGON2I, PASSWORD_ARGON2ID
define('HASH_ALGO', PASSWORD_DEFAULT);

// Cost factor for bcrypt (4-31, higher = slower but more secure)
// Recommended: 12 for production
define('BCRYPT_COST', 12);

// Argon2 options (if using Argon2)
define('ARGON2_MEMORY_COST', 65536);  // 64MB
define('ARGON2_TIME_COST', 4);        // 4 iterations
define('ARGON2_THREADS', 2);          // 2 threads

// PIN requirements
define('MIN_PIN_LENGTH', 4);
define('MAX_PIN_LENGTH', 8);
define('ALLOW_LETTERS', false);       // Set to true to allow letters in PIN
define('ALLOW_SPECIAL', false);       // Set to true to allow special characters

// Output format
define('OUTPUT_FORMAT', 'full'); // Options: 'hash_only', 'full', 'json'

// ============================================================
// FUNCTIONS
// ============================================================

function getHashAlgorithmName($algo) {
    switch ($algo) {
        case PASSWORD_BCRYPT:
            return 'BCRYPT';
        case PASSWORD_ARGON2I:
            return 'ARGON2I';
        case PASSWORD_ARGON2ID:
            return 'ARGON2ID';
        case PASSWORD_DEFAULT:
            return 'DEFAULT (BCRYPT)';
        default:
            return 'UNKNOWN';
    }
}

function getHashOptions() {
    if (HASH_ALGO === PASSWORD_BCRYPT) {
        return ['cost' => BCRYPT_COST];
    } elseif (HASH_ALGO === PASSWORD_ARGON2I || HASH_ALGO === PASSWORD_ARGON2ID) {
        return [
            'memory_cost' => ARGON2_MEMORY_COST,
            'time_cost' => ARGON2_TIME_COST,
            'threads' => ARGON2_THREADS
        ];
    }
    return [];
}

function validatePin($pin) {
    $length = strlen($pin);
    
    if ($length < MIN_PIN_LENGTH) {
        return ['valid' => false, 'message' => "PIN must be at least " . MIN_PIN_LENGTH . " characters long."];
    }
    
    if ($length > MAX_PIN_LENGTH) {
        return ['valid' => false, 'message' => "PIN cannot exceed " . MAX_PIN_LENGTH . " characters."];
    }
    
    if (!ALLOW_LETTERS && preg_match('/[a-zA-Z]/', $pin)) {
        return ['valid' => false, 'message' => "PIN cannot contain letters. Use only numbers."];
    }
    
    if (!ALLOW_SPECIAL && preg_match('/[^a-zA-Z0-9]/', $pin)) {
        return ['valid' => false, 'message' => "PIN cannot contain special characters. Use only numbers."];
    }
    
    // Check for sequential numbers (optional security check)
    // if (preg_match('/(012345|123456|234567|345678|456789|567890)/', $pin)) {
    //     return ['valid' => false, 'message' => "PIN cannot contain sequential numbers."];
    // }
    
    // Check for repeated numbers (optional)
    // if (preg_match('/(.)\1{3,}/', $pin)) {
    //     return ['valid' => false, 'message' => "PIN cannot have 4 or more identical digits in a row."];
    // }
    
    return ['valid' => true, 'message' => 'PIN is valid.'];
}

function generatePinHash($pin) {
    $options = getHashOptions();
    $hash = password_hash($pin, HASH_ALGO, $options);
    
    if ($hash === false) {
        throw new Exception("Failed to generate hash. Please check your configuration.");
    }
    
    return $hash;
}

function verifyPinHash($pin, $hash) {
    return password_verify($pin, $hash);
}

function getPinInfo($pin, $hash) {
    $info = password_get_info($hash);
    
    return [
        'pin_length' => strlen($pin),
        'hash_algo' => $info['algoName'],
        'hash_algo_id' => $info['algo'],
        'hash_length' => strlen($hash),
        'hash' => $hash
    ];
}

function printColored($text, $color = 'white') {
    $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'reset' => "\033[0m"
    ];
    
    if (PHP_SAPI === 'cli') {
        echo $colors[$color] . $text . $colors['reset'];
    } else {
        $htmlColors = [
            'red' => 'red',
            'green' => 'green',
            'yellow' => 'orange',
            'blue' => 'blue',
            'magenta' => 'purple',
            'cyan' => 'teal',
            'white' => 'white'
        ];
        echo "<span style='color: {$htmlColors[$color]}; font-family: monospace;'>{$text}</span>";
    }
}

function printHashResult($pin, $hash, $info, $isCli = true) {
    if (OUTPUT_FORMAT === 'json') {
        echo json_encode([
            'pin' => $pin,
            'hash' => $hash,
            'info' => $info,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_PRETTY_PRINT);
        return;
    }
    
    if (OUTPUT_FORMAT === 'hash_only') {
        echo $hash . "\n";
        return;
    }
    
    // Full output
    echo "\n";
    echo str_repeat("=", 60) . "\n";
    printColored("🔐 PIN HASH GENERATED SUCCESSFULLY\n", 'green');
    echo str_repeat("=", 60) . "\n\n";
    
    printColored("📌 PIN Value: ", 'cyan');
    echo $pin . "\n\n";
    
    printColored("🔑 Hash: ", 'cyan');
    echo $hash . "\n\n";
    
    printColored("ℹ️  Hash Information:\n", 'yellow');
    echo "   ├─ Algorithm: " . $info['hash_algo'] . "\n";
    echo "   ├─ PIN Length: " . $info['pin_length'] . " digits\n";
    echo "   ├─ Hash Length: " . $info['hash_length'] . " characters\n";
    echo "   └─ Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    if (BCRYPT_COST && HASH_ALGO === PASSWORD_BCRYPT) {
        echo "⚙️  BCRYPT Settings:\n";
        echo "   └─ Cost Factor: " . BCRYPT_COST . "\n\n";
    }
    
    echo str_repeat("=", 60) . "\n";
}

// ============================================================
// MAIN SCRIPT
// ============================================================

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>PIN Hash Generator</title>";
    echo "<style>body{background:#0a0a0a;color:#fff;font-family:monospace;padding:20px;}</style>";
    echo "</head><body>";
}

echo "\n";
printColored("╔════════════════════════════════════════════════════╗\n", 'cyan');
printColored("║         VOUCHMORPH PIN HASH GENERATOR             ║\n", 'cyan');
printColored("╚════════════════════════════════════════════════════╝\n", 'cyan');
echo "\n";

// Check if PIN provided via command line
$pin = null;
$verifyHash = null;

if ($isCli) {
    $options = getopt('', ['pin:', 'verify:', 'help', 'batch']);
    
    if (isset($options['help'])) {
        echo "Usage: php generate_pin_hash.php [options]\n\n";
        echo "Options:\n";
        echo "  --pin=<pin>       Generate hash for a specific PIN\n";
        echo "  --verify=<hash>   Verify a PIN against a hash (requires --pin)\n";
        echo "  --batch           Batch mode (no prompts)\n";
        echo "  --help            Show this help message\n\n";
        echo "Examples:\n";
        echo "  php generate_pin_hash.php\n";
        echo "  php generate_pin_hash.php --pin=1234\n";
        echo "  php generate_pin_hash.php --pin=1234 --verify=\$2y\$10\$...\n";
        exit(0);
    }
    
    if (isset($options['pin'])) {
        $pin = $options['pin'];
    }
    
    if (isset($options['verify'])) {
        $verifyHash = $options['verify'];
    }
}

// Verify mode
if ($verifyHash && $pin) {
    printColored("🔍 VERIFICATION MODE\n", 'yellow');
    echo "\n";
    
    $isValid = verifyPinHash($pin, $verifyHash);
    
    if ($isValid) {
        printColored("✓ PIN VERIFICATION: SUCCESSFUL\n", 'green');
        echo "\n";
        printColored("The PIN matches the provided hash.\n", 'green');
    } else {
        printColored("✗ PIN VERIFICATION: FAILED\n", 'red');
        echo "\n";
        printColored("The PIN does NOT match the provided hash.\n", 'red');
    }
    echo "\n";
    exit($isValid ? 0 : 1);
}

// Batch mode with PIN provided
if ($pin) {
    $validation = validatePin($pin);
    if (!$validation['valid']) {
        printColored("✗ ERROR: " . $validation['message'] . "\n", 'red');
        exit(1);
    }
    
    $hash = generatePinHash($pin);
    $info = getPinInfo($pin, $hash);
    printHashResult($pin, $hash, $info, $isCli);
    exit(0);
}

// Interactive mode
if ($isCli) {
    printColored("💡 Interactive PIN Hash Generator\n", 'yellow');
    echo "\n";
    printColored("Enter PIN to hash (or 'quit' to exit):\n", 'cyan');
    
    while (true) {
        echo "\n";
        echo "PIN: ";
        $pin = trim(fgets(STDIN));
        
        if (strtolower($pin) === 'quit' || strtolower($pin) === 'exit') {
            printColored("\nGoodbye!\n", 'green');
            break;
        }
        
        if (empty($pin)) {
            printColored("Please enter a PIN.\n", 'red');
            continue;
        }
        
        $validation = validatePin($pin);
        if (!$validation['valid']) {
            printColored("✗ " . $validation['message'] . "\n", 'red');
            continue;
        }
        
        $hash = generatePinHash($pin);
        $info = getPinInfo($pin, $hash);
        printHashResult($pin, $hash, $info, $isCli);
        
        // Option to verify
        echo "\n";
        printColored("Test verification? (y/n): ", 'cyan');
        $test = trim(fgets(STDIN));
        if (strtolower($test) === 'y') {
            echo "\n";
            printColored("Enter PIN to verify: ", 'cyan');
            $testPin = trim(fgets(STDIN));
            if (verifyPinHash($testPin, $hash)) {
                printColored("✓ Verification successful!\n", 'green');
            } else {
                printColored("✗ Verification failed!\n", 'red');
            }
        }
    }
} else {
    // Web interface
    ?>
    <div style="max-width: 800px; margin: 0 auto;">
        <h2>PIN Hash Generator</h2>
        
        <form method="POST" action="">
            <div style="margin-bottom: 15px;">
                <label>Enter PIN:</label>
                <input type="password" name="pin" style="width: 100%; padding: 10px; background: #1a1a2e; border: 1px solid #00F0FF; color: #fff; font-size: 16px;" required>
                <small style="color: #888;">PIN: <?= MIN_PIN_LENGTH ?>-<?= MAX_PIN_LENGTH ?> digits only</small>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label>Verify (optional):</label>
                <input type="password" name="verify_pin" style="width: 100%; padding: 10px; background: #1a1a2e; border: 1px solid #00F0FF; color: #fff; font-size: 16px;" placeholder="Re-enter PIN to verify">
            </div>
            
            <button type="submit" style="background: linear-gradient(135deg, #00F0FF, #B000FF); color: #000; padding: 12px 24px; border: none; cursor: pointer; font-weight: bold;">Generate Hash</button>
        </form>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
            $webPin = $_POST['pin'];
            $validation = validatePin($webPin);
            
            if (!$validation['valid']) {
                echo "<div style='margin-top: 20px; padding: 15px; background: rgba(255,0,0,0.1); border-left: 3px solid red;'>";
                echo "<strong style='color: red'>Error:</strong> " . $validation['message'];
                echo "</div>";
            } else {
                $webHash = generatePinHash($webPin);
                $webInfo = getPinInfo($webPin, $webHash);
                
                echo "<div style='margin-top: 20px; padding: 15px; background: rgba(0,240,255,0.1); border-left: 3px solid #00F0FF;'>";
                echo "<h3>Generated Hash:</h3>";
                echo "<code style='display: block; background: #0a0a0a; padding: 10px; margin: 10px 0; word-break: break-all;'>" . htmlspecialchars($webHash) . "</code>";
                
                echo "<h3>PIN Information:</h3>";
                echo "<ul>";
                echo "<li>Algorithm: " . $webInfo['hash_algo'] . "</li>";
                echo "<li>PIN Length: " . $webInfo['pin_length'] . "</li>";
                echo "<li>Hash Length: " . $webInfo['hash_length'] . "</li>";
                echo "</ul>";
                
                if (isset($_POST['verify_pin']) && !empty($_POST['verify_pin'])) {
                    if (verifyPinHash($_POST['verify_pin'], $webHash)) {
                        echo "<p style='color: #00FF00;'>✓ PIN verification successful!</p>";
                    } else {
                        echo "<p style='color: #FF0000;'>✗ PIN verification failed! PIN does not match.</p>";
                    }
                }
                
                echo "<h3>SQL INSERT Statement:</h3>";
                echo "<code style='display: block; background: #0a0a0a; padding: 10px; margin: 10px 0; word-break: break-all;'>";
                echo "INSERT INTO users (pin_code) VALUES ('" . addslashes($webHash) . "');";
                echo "</code>";
                
                echo "<h3>PHP Verification Code:</h3>";
                echo "<code style='display: block; background: #0a0a0a; padding: 10px; margin: 10px 0; word-break: break-all;'>";
                echo htmlspecialchars('<?php
$enteredPin = $_POST["pin"];
$storedHash = "' . $webHash . '";

if (password_verify($enteredPin, $storedHash)) {
    echo "PIN is correct!";
} else {
    echo "Invalid PIN!";
}
?>');
                echo "</code>";
                
                echo "</div>";
            }
        }
        ?>
        
        <div style="margin-top: 30px; padding: 15px; background: rgba(255,255,255,0.05);">
            <h3>Quick Reference:</h3>
            <h4>To verify a PIN in your code:</h4>
            <pre style="background: #0a0a0a; padding: 10px; overflow-x: auto;">
if (password_verify($user_input_pin, $stored_hash_from_database)) {
    // PIN is correct - proceed with login/transaction
} else {
    // Invalid PIN
}</pre>
        </div>
    </div>
    <?php
}

if (!$isCli) {
    echo "</body></html>";
}
?>
