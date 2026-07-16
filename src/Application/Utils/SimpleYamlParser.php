<?php
declare(strict_types=1);

namespace Core\Utils;

/**
 * Minimal YAML-subset parser used as a fallback when the native `yaml`
 * PHP extension is not installed on the server.
 *
 * IMPORTANT: This is NOT a full YAML implementation. It supports the
 * subset actually used by this project's config files: nested maps,
 * lists of scalars, lists of maps, quoted/unquoted scalars, booleans,
 * numbers, inline [a, b, c] lists, and # comments.
 *
 * It does NOT support: anchors/aliases, multi-line block scalars (| or >),
 * flow-style maps ({a: 1}), or multiple documents in one file.
 *
 * Prefer installing the real `yaml` extension in production - this class
 * exists so a missing extension doesn't take the whole app down.
 */
class SimpleYamlParser
{
    public static function parseFile(string $path): array
    {
        // Prefer the native extension when it's available - it's a full,
        // correct implementation. Only fall back to our subset parser
        // when it's genuinely missing.
        if (function_exists('yaml_parse_file')) {
            $result = @yaml_parse_file($path);
            if (is_array($result)) {
                return $result;
            }
            error_log("[SimpleYamlParser] Native yaml_parse_file() returned non-array for: {$path}, falling back to subset parser");
        }

        if (!file_exists($path)) {
            error_log("[SimpleYamlParser] File not found: {$path}");
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            error_log("[SimpleYamlParser] Failed to read file: {$path}");
            return [];
        }

        try {
            $lines = self::tokenize($content);
            $idx = 0;
            return self::parseBlock($lines, $idx, -1);
        } catch (\Throwable $e) {
            error_log("[SimpleYamlParser] Failed to parse {$path}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Split file content into [indent, content] pairs, dropping blank
     * lines, full-comment lines, and document markers.
     */
    private static function tokenize(string $content): array
    {
        $raw = explode("\n", str_replace("\r\n", "\n", $content));
        $lines = [];

        foreach ($raw as $line) {
            $trimmedRight = rtrim($line);
            if ($trimmedRight === '') {
                continue;
            }
            $stripped = ltrim($trimmedRight);
            if ($stripped === '' || $stripped[0] === '#') {
                continue;
            }
            if (preg_match('/^---\s*$/', $stripped) || preg_match('/^\.\.\.\s*$/', $stripped)) {
                continue;
            }
            $indent = strlen($trimmedRight) - strlen($stripped);
            $lines[] = [$indent, $stripped];
        }

        return array_values($lines);
    }

    /**
     * Parse a contiguous block of lines at a single indentation level
     * into either an associative array (map) or a list (sequence).
     *
     * @param array $lines  [indent, content] pairs - mutated in place for
     *                       the "- key: value" list-item reinterpretation.
     * @param int   &$idx   Current position, advanced as lines are consumed.
     * @param int   $blockIndent  Expected indent for this block, or -1 to
     *                       auto-detect from the first line encountered.
     */
    private static function parseBlock(array &$lines, int &$idx, int $blockIndent): array
    {
        $result = [];

        while ($idx < count($lines)) {
            [$indent, $content] = $lines[$idx];

            if ($blockIndent === -1) {
                $blockIndent = $indent;
            }

            if ($indent < $blockIndent) {
                break; // end of this block - caller resumes
            }

            if ($indent > $blockIndent) {
                // Line more indented than expected - shouldn't normally
                // happen given how we recurse, but skip defensively
                // rather than infinite-loop or throw on odd input.
                $idx++;
                continue;
            }

            if (str_starts_with($content, '- ')) {
                $itemContent = trim(substr($content, 2));

                if ($itemContent !== '' && preg_match('/^([A-Za-z0-9_\.\-]+):\s*(.*)$/', $itemContent, $m)) {
                    // List item is a map, e.g. "- name: foo" possibly
                    // followed by more indented "key: value" lines that
                    // belong to the same item. Reinterpret this line as
                    // a normal map key at (indent + 2) and let parseBlock
                    // consume it plus any deeper-indented continuation lines.
                    $lines[$idx] = [$indent + 2, $itemContent];
                    $item = self::parseBlock($lines, $idx, $indent + 2);
                } elseif ($itemContent === '') {
                    // "-" alone on a line: nested block follows, indented
                    // deeper than the list marker.
                    $idx++;
                    $item = self::parseBlock($lines, $idx, -1);
                } else {
                    $item = self::castScalar($itemContent);
                    $idx++;
                }

                $result[] = $item;
                continue;
            }

            if (preg_match('/^([^:]+):\s*(.*)$/', $content, $m)) {
                $key = trim($m[1]);
                $value = $m[2];
                $idx++;

                if ($value === '') {
                    // Possible nested block - only recurse if the next
                    // line is actually indented further; otherwise this
                    // key's value is legitimately null/empty.
                    if ($idx < count($lines) && $lines[$idx][0] > $indent) {
                        $result[$key] = self::parseBlock($lines, $idx, -1);
                    } else {
                        $result[$key] = null;
                    }
                } else {
                    $result[$key] = self::castScalar($value);
                }
                continue;
            }

            // Malformed/unrecognized line - skip rather than fail the
            // whole parse.
            error_log("[SimpleYamlParser] Skipping unrecognized line: {$content}");
            $idx++;
        }

        return $result;
    }

    /**
     * Convert a raw scalar string into the right PHP type: bool, null,
     * int, float, inline list, quoted string, or plain string.
     */
    private static function castScalar(string $v)
    {
        $v = trim($v);

        if ($v === '') {
            return null;
        }

        // Strip a trailing inline comment, but only outside quotes.
        if ($v[0] !== '"' && $v[0] !== "'") {
            $hashPos = strpos($v, ' #');
            if ($hashPos !== false) {
                $v = trim(substr($v, 0, $hashPos));
            }
        }

        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"') && strlen($v) >= 2) ||
            (str_starts_with($v, "'") && str_ends_with($v, "'") && strlen($v) >= 2)
        ) {
            return substr($v, 1, -1);
        }

        $lower = strtolower($v);
        if ($lower === 'true' || $lower === 'yes') {
            return true;
        }
        if ($lower === 'false' || $lower === 'no') {
            return false;
        }
        if ($lower === 'null' || $v === '~') {
            return null;
        }

        if (is_numeric($v)) {
            return $v + 0; // int if it parses as int, else float
        }

        // Inline list: [a, b, c]
        if ($v[0] === '[' && str_ends_with($v, ']')) {
            $inner = trim(substr($v, 1, -1));
            if ($inner === '') {
                return [];
            }
            return array_map(
                fn($x) => self::castScalar(trim($x)),
                explode(',', $inner)
            );
        }

        return $v;
    }
}
