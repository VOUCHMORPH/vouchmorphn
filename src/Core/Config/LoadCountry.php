<?php
declare(strict_types=1);

namespace Core\Config;

final class LoadCountry
{
    public static function getConfig(?string $countryOverride = null): array
    {
        $countryMeta = require __DIR__ . '/SystemCountry.php';

        // Existing zero-arg callers (SwapService's own constructor, and
        // every other call site in the codebase) keep working exactly as
        // before — $countryOverride defaults to null, falling through to
        // the same SYSTEM_COUNTRY/SystemCountry.php resolution as always.
        // New callers (Mojaloop's index.php, ParticipantsHandler,
        // PartiesHandler) can now pass an explicit country instead.
        $countryName = $countryOverride
            ?? (defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : ($countryMeta['name'] ?? 'Botswana'));

        $countryCode = defined('SYSTEM_COUNTRY_CODE')
            ? SYSTEM_COUNTRY_CODE
            : ($countryMeta['code'] ?? 'BW');

        $projectRoot = dirname(__DIR__, 3);
        $countryDir = $projectRoot . "/src/Core/Config/Countries/{$countryName}";
        
        error_log("[LoadCountry] Looking for config in: {$countryDir}");
        
        // Config files
        $databaseFile     = $countryDir . "/database.php";
        $participantsFile = $countryDir . "/participants.yaml";
        $feesFile         = $countryDir . "/fees.json";
        $atmNotesFile     = $countryDir . "/atm_notes.json";
        $cardsFile        = $countryDir . "/cards.json";
        $commFile         = $countryDir . "/communication.json";

        $countryConfig = [
            'country' => $countryName,
            'country_code' => $countryCode,
            'currency' => 'BWP'
        ];
        
        // 1. Load participants from YAML
        if (file_exists($participantsFile)) {
            $participantsConfig = self::parseYamlFile($participantsFile);
            if (!empty($participantsConfig)) {
                $countryConfig['participants'] = $participantsConfig['participants'] ?? [];
                $countryConfig['api_keys']     = $participantsConfig['api_keys'] ?? [];
                error_log("[LoadCountry] Loaded participants from YAML: {$participantsFile}");
            } else {
                error_log("[LoadCountry] Failed to parse YAML participants file: {$participantsFile}");
                $countryConfig['participants'] = [];
            }
        } else {
            error_log("[LoadCountry] Participants file not found: {$participantsFile}");
            $countryConfig['participants'] = [];
        }

        // 2. Load fees.json - PUT PRODUCTS AT TOP LEVEL
        if (file_exists($feesFile)) {
            $feesConfig = json_decode(file_get_contents($feesFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['fees'] = $feesConfig;
                
                // Put products at top level
                $productKeys = ['CASHOUT', 'DEPOSIT', 'CARD_LOAD'];
                foreach ($productKeys as $key) {
                    if (isset($feesConfig[$key])) {
                        $countryConfig[$key] = $feesConfig[$key];
                    }
                }
                
                if (isset($feesConfig['regulatory'])) {
                    $countryConfig['regulatory'] = $feesConfig['regulatory'];
                }
                
                error_log("[LoadCountry] Loaded fees from: {$feesFile}");
            } else {
                error_log("[LoadCountry] JSON parse error in fees file: " . json_last_error_msg());
                $countryConfig['fees'] = [];
            }
        } else {
            error_log("[LoadCountry] Fees file not found: {$feesFile}");
            $countryConfig['fees'] = [];
        }

        // 3. Load atm_notes.json
        if (file_exists($atmNotesFile)) {
            $atmNotesConfig = json_decode(file_get_contents($atmNotesFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['atm_notes'] = $atmNotesConfig;
                error_log("[LoadCountry] Loaded ATM notes from: {$atmNotesFile}");
            }
        }

        // 4. Load cards.json
        if (file_exists($cardsFile)) {
            $cardsConfig = json_decode(file_get_contents($cardsFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['card_config'] = $cardsConfig;
                error_log("[LoadCountry] Loaded card config from: {$cardsFile}");
            }
        }

        // 5. Load communication.json
        if (file_exists($commFile)) {
            $commConfig = json_decode(file_get_contents($commFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['communication'] = $commConfig;
                error_log("[LoadCountry] Loaded communication config from: {$commFile}");
            }
        }

        // 6. Database configuration
        if (file_exists($databaseFile)) {
            $dbConfig = require $databaseFile;
            $countryConfig['db']['swap'] = $dbConfig;
            error_log("[LoadCountry] Loaded database for {$countryName} from: {$databaseFile}");
        } else {
            error_log("[LoadCountry] Database file not found: {$databaseFile}");
            $countryConfig['db'] = [];
        }

        $GLOBALS['country_config'] = $countryConfig;

        if (!defined('COUNTRY_CONFIG')) {
            define('COUNTRY_CONFIG', json_encode($countryConfig, JSON_UNESCAPED_SLASHES));
        }

        return $countryConfig;
    }

    /**
     * Parse YAML file using available parser
     */
    private static function parseYamlFile(string $path): array
    {
        if (function_exists('yaml_parse_file')) {
            $data = yaml_parse_file($path);
            if ($data !== false) {
                return $data;
            }
        }
        
        if (class_exists('\Symfony\Component\Yaml\Yaml')) {
            return \Symfony\Component\Yaml\Yaml::parseFile($path);
        }
        
        return self::parseYamlManually($path);
    }
    
    /**
     * Fallback YAML parser, used only when neither ext-yaml nor Symfony's
     * YAML component is available.
     *
     * The previous version of this matched a single pattern --
     * /^    ([a-z_]+): (.+)$/ -- which is to say: four-space-indented keys
     * whose value sits on the same line. Every nested block and every list
     * in participants.yaml therefore vanished silently. capabilities,
     * identity_accounts, settlement_account, limits, card_config,
     * switch_participant_ids and asset_types all disappeared, leaving nine
     * scalar fields per institution and no indication anything was lost.
     *
     * That is worse than not parsing at all: getSourceSettlementAccount()
     * and getIdentityHoldingAccounts() would report an institution as "not
     * onboarded" when its accounts are sitting right there in the file, and
     * getCommonSwitch() would see no shared rails anywhere. Production is
     * insulated today only because railway.json pins the DOCKERFILE builder
     * and the Dockerfile both installs ext-yaml and fails the build if it is
     * missing -- but nixpacks.toml, still in the repo, installs no such
     * thing.
     *
     * So this now parses the constructs participants.yaml actually uses:
     * nested maps to arbitrary depth, block lists of scalars, inline lists
     * and inline maps, quoted strings, booleans, numbers and comments.
     */
    private static function parseYamlManually(string $path): array
    {
        error_log(
            '[LoadCountry] Neither ext-yaml nor Symfony YAML is available; ' .
            'falling back to the built-in parser for ' . $path
        );

        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        // Comments and blank lines are dropped up front so the parser below
        // only ever sees structural lines; indentation is preserved.
        $lines = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = rtrim(self::stripYamlComment($line));
            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }

        $index = 0;
        $parsed = self::parseYamlBlock($lines, $index, -1);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Removes a trailing comment, leaving `#` alone inside quotes and in the
     * middle of a bare word so URLs and fragments survive.
     */
    private static function stripYamlComment(string $line): string
    {
        $out = '';
        $inSingle = false;
        $inDouble = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif ($char === '#' && !$inSingle && !$inDouble) {
                // A comment only starts at the beginning of the line or
                // after whitespace -- otherwise it is part of a value.
                if ($i === 0 || $line[$i - 1] === ' ' || $line[$i - 1] === "\t") {
                    break;
                }
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Consumes every line indented deeper than $parentIndent and returns it
     * as a map or a list. $index is advanced past what was consumed.
     *
     * @return array<mixed>
     */
    private static function parseYamlBlock(array $lines, int &$index, int $parentIndent): array
    {
        $map = [];
        $list = [];
        $isList = false;
        $count = count($lines);

        while ($index < $count) {
            $line = $lines[$index];
            $indent = self::yamlIndent($line);

            if ($indent <= $parentIndent) {
                break;
            }

            $content = trim($line);

            // ---- block list item ----
            if ($content === '-' || str_starts_with($content, '- ')) {
                $isList = true;
                $item = trim(substr($content, 1));
                $index++;

                if ($item === '') {
                    $list[] = self::parseYamlBlock($lines, $index, $indent);
                } else {
                    $list[] = self::parseYamlScalar($item);
                }
                continue;
            }

            // ---- key: value ----
            if (preg_match('/^([^:]+):\s*(.*)$/', $content, $matches)) {
                $key = trim($matches[1], " \"'");
                $rest = trim($matches[2]);
                $index++;

                if ($rest !== '') {
                    $map[$key] = self::parseYamlScalar($rest);
                    continue;
                }

                // Empty value: either a nested block on the following lines,
                // or a genuinely null key.
                $nextIndent = $index < $count ? self::yamlIndent($lines[$index]) : null;
                $map[$key] = ($nextIndent !== null && $nextIndent > $indent)
                    ? self::parseYamlBlock($lines, $index, $indent)
                    : null;
                continue;
            }

            // Not something this parser understands. Skip it rather than
            // spin -- $index must always advance.
            error_log('[LoadCountry] Skipping unparseable YAML line: ' . $content);
            $index++;
        }

        return $isList ? $list : $map;
    }

    private static function yamlIndent(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    /**
     * A single YAML value: inline list, inline map, quoted string, boolean,
     * null, number, or bare string.
     *
     * @return mixed
     */
    private static function parseYamlScalar(string $value)
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Inline list: [DEBIT, CREDIT]
        if ($value[0] === '[' && str_ends_with($value, ']')) {
            $inner = trim(substr($value, 1, -1));
            if ($inner === '') {
                return [];
            }
            return array_map(
                static fn(string $part) => self::parseYamlScalar($part),
                self::splitInlineYaml($inner)
            );
        }

        // Inline map: { BWP: "T+0", ZAR: "T+1" }
        if ($value[0] === '{' && str_ends_with($value, '}')) {
            $inner = trim(substr($value, 1, -1));
            $map = [];
            if ($inner === '') {
                return $map;
            }
            foreach (self::splitInlineYaml($inner) as $pair) {
                if (preg_match('/^([^:]+):\s*(.*)$/', trim($pair), $matches)) {
                    $map[trim($matches[1], " \"'")] = self::parseYamlScalar($matches[2]);
                }
            }
            return $map;
        }

        // Quoted string -- returned verbatim, never type-juggled, so a
        // zero-padded account number keeps its leading zeros.
        $last = substr($value, -1);
        if (strlen($value) >= 2 && (($value[0] === '"' && $last === '"') || ($value[0] === "'" && $last === "'"))) {
            return substr($value, 1, -1);
        }

        $lower = strtolower($value);
        if ($lower === 'true')  return true;
        if ($lower === 'false') return false;
        if ($lower === 'null' || $value === '~') return null;

        if (preg_match('/^-?\d+$/', $value))        return (int)$value;
        if (preg_match('/^-?\d*\.\d+$/', $value))   return (float)$value;

        return $value;
    }

    /**
     * Splits an inline collection on commas that are not inside nested
     * brackets or quotes.
     *
     * @return string[]
     */
    private static function splitInlineYaml(string $inner): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $length = strlen($inner);

        for ($i = 0; $i < $length; $i++) {
            $char = $inner[$i];

            if ($char === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif (!$inSingle && !$inDouble) {
                if ($char === '[' || $char === '{') {
                    $depth++;
                } elseif ($char === ']' || $char === '}') {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    if (trim($buffer) !== '') {
                        $parts[] = trim($buffer);
                    }
                    $buffer = '';
                    continue;
                }
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }
}

if (!function_exists('loadCountryConfig')) {
    function loadCountryConfig(?string $countryOverride = null): array
    {
        return \Core\Config\LoadCountry::getConfig($countryOverride);
    }
}

if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    return \Core\Config\LoadCountry::getConfig();
}
