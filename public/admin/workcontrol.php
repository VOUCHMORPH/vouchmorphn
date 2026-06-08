<?php
/**
 * VouchMorph Diagnostic Tool
 * Purpose: Validate PostgreSQL compatibility and file integrity
 */

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

define('PROJECT_ROOT', dirname(__DIR__, 2));

class DiagnosticTool
{
    private array $results = [];
    private array $postgresIssues = [];
    private array $securityIssues = [];
    private array $syntaxErrors = [];
    
    public function run(): array
    {
        $this->scanFiles();
        return [
            'summary' => $this->getSummary(),
            'postgres_compatibility' => $this->postgresIssues,
            'security_issues' => $this->securityIssues,
            'syntax_errors' => $this->syntaxErrors,
            'file_count' => count($this->results)
        ];
    }
    
    private function scanFiles(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(PROJECT_ROOT, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->analyzePhpFile($file->getPathname());
            }
        }
    }
    
    private function analyzePhpFile(string $path): void
    {
        $content = file_get_contents($path);
        $relativePath = str_replace(PROJECT_ROOT, '', $path);
        
        // Syntax check
        $this->checkSyntax($path, $relativePath);
        
        // PostgreSQL compatibility (critical)
        $this->checkPostgresCompatibility($content, $relativePath);
        
        // Security issues
        $this->checkSecurityIssues($content, $relativePath);
    }
    
    private function checkSyntax(string $path, string $relativePath): void
    {
        exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            $this->syntaxErrors[$relativePath] = implode("\n", $output);
        }
    }
    
    private function checkPostgresCompatibility(string $content, string $path): void
    {
        $issues = [];
        
        // MySQL functions - CRITICAL ERROR
        $mysqlPatterns = [
            '/mysql_[a-z_]+\(/i' => 'MySQL function (use PostgreSQL)',
            '/mysqli_[a-z_]+\(/i' => 'MySQLi function (use PostgreSQL)',
            '/new\s+mysqli\(/i' => 'MySQLi connection (use PDO PostgreSQL)',
            '/mysql:/i' => 'MySQL DSN (use pgsql:)',
            '/PDO::MYSQL/i' => 'PDO MySQL (use PDO::PGSQL)',
        ];
        
        foreach ($mysqlPatterns as $pattern => $message) {
            if (preg_match($pattern, $content)) {
                $issues[] = $message;
            }
        }
        
        // Check for proper PostgreSQL usage
        $hasPostgres = preg_match('/pgsql:|pg_connect|PDO::PGSQL|new PDO\([\'"]pgsql:/i', $content);
        
        if (!empty($issues)) {
            $this->postgresIssues[$path] = [
                'severity' => 'CRITICAL',
                'issues' => $issues,
                'has_postgres_fallback' => $hasPostgres
            ];
        }
        
        // SQL injection risk (using query() without prepare)
        if (preg_match('/->query\([\'"][^)]+[\'"]\)/i', $content) && 
            !preg_match('/->prepare\(/i', $content)) {
            if (!isset($this->securityIssues[$path])) {
                $this->securityIssues[$path] = [];
            }
            $this->securityIssues[$path][] = 'Direct query() without prepared statement (SQL injection risk)';
        }
    }
    
    private function checkSecurityIssues(string $content, string $path): void
    {
        $issues = [];
        
        // Hardcoded credentials
        if (preg_match('/(password|passwd|pwd)\s*=\s*[\'"][^\'"]+[\'"]/i', $content)) {
            if (!preg_match('/getenv\(|$_ENV|$_SERVER/', $content)) {
                $issues[] = 'Hardcoded password (use environment variables)';
            }
        }
        
        // API keys hardcoded
        if (preg_match('/api[_-]?key\s*=\s*[\'"][^\'"]{10,}[\'"]/i', $content)) {
            if (!preg_match('/getenv\(|$_ENV/', $content)) {
                $issues[] = 'Hardcoded API key (use environment variables)';
            }
        }
        
        // Dangerous functions
        $dangerous = [
            '/eval\s*\(/' => 'eval() usage',
            '/base64_decode\s*\(/' => 'base64_decode() (verify necessity)',
            '/shell_exec\s*\(/' => 'shell_exec()',
            '/system\s*\(/' => 'system()',
            '/passthru\s*\(/' => 'passthru()',
        ];
        
        foreach ($dangerous as $pattern => $message) {
            if (preg_match($pattern, $content)) {
                $issues[] = $message;
            }
        }
        
        if (!empty($issues)) {
            $this->securityIssues[$path] = $issues;
        }
    }
    
    private function getSummary(): array
    {
        return [
            'files_scanned' => count($this->results) + count($this->syntaxErrors),
            'syntax_errors' => count($this->syntaxErrors),
            'postgres_issues' => count($this->postgresIssues),
            'security_issues' => count($this->securityIssues),
            'critical_blockers' => count($this->postgresIssues)
        ];
    }
}

// AJAX handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    
    $tool = new DiagnosticTool();
    $results = $tool->run();
    
    echo json_encode($results);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>VouchMorph Diagnostic</title>
    <style>
        body { font-family: monospace; background: #0a0a0a; color: #e0e0e0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: #111; border: 1px solid #333; margin-bottom: 20px; padding: 20px; }
        .critical { color: #ef4444; border-left-color: #ef4444; }
        .warning { color: #f59e0b; border-left-color: #f59e0b; }
        .success { color: #10b981; }
        .stats { display: flex; gap: 20px; margin-bottom: 20px; }
        .stat { background: #1a1a1a; padding: 15px; text-align: center; flex: 1; }
        .stat-value { font-size: 32px; font-weight: bold; }
        .stat-label { font-size: 11px; color: #888; }
        pre { background: #0a0a0a; padding: 10px; overflow-x: auto; white-space: pre-wrap; font-size: 11px; }
        button { background: #001B44; color: #FFDA63; border: none; padding: 10px 20px; cursor: pointer; font-family: monospace; }
        button:hover { background: #002a6e; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>VouchMorph Diagnostic Tool</h1>
        <p>PostgreSQL Compatibility + Security Scan</p>
        <button onclick="runDiagnostic()">Run Diagnostic</button>
    </div>
    
    <div id="results"></div>
</div>

<script>
async function runDiagnostic() {
    const resultsDiv = document.getElementById('results');
    resultsDiv.innerHTML = '<div class="card">Scanning files...</div>';
    
    const response = await fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    
    const data = await response.json();
    
    let html = `
        <div class="stats">
            <div class="stat"><div class="stat-value">${data.summary.files_scanned}</div><div class="stat-label">FILES SCANNED</div></div>
            <div class="stat"><div class="stat-value ${data.summary.syntax_errors > 0 ? 'critical' : 'success'}">${data.summary.syntax_errors}</div><div class="stat-label">SYNTAX ERRORS</div></div>
            <div class="stat"><div class="stat-value ${data.summary.postgres_issues > 0 ? 'critical' : 'success'}">${data.summary.postgres_issues}</div><div class="stat-label">POSTGRES ISSUES</div></div>
            <div class="stat"><div class="stat-value ${data.summary.security_issues > 0 ? 'warning' : 'success'}">${data.summary.security_issues}</div><div class="stat-label">SECURITY ISSUES</div></div>
        </div>
    `;
    
    if (data.summary.critical_blockers > 0) {
        html += `<div class="card critical"><strong>❌ CRITICAL:</strong> ${data.summary.critical_blockers} files have PostgreSQL compatibility issues</div>`;
    }
    
    if (Object.keys(data.postgres_compatibility).length > 0) {
        html += `<div class="card"><h3>PostgreSQL Compatibility Issues</h3>`;
        for (const [file, issue] of Object.entries(data.postgres_compatibility)) {
            html += `<div class="critical"><strong>${file}</strong><br>`;
            html += `Severity: ${issue.severity}<br>`;
            html += `Issues: ${issue.issues.join(', ')}<br>`;
            if (!issue.has_postgres_fallback) {
                html += `<span class="critical">⚠️ No PostgreSQL alternative found</span>`;
            }
            html += `</div>`;
        }
        html += `</div>`;
    }
    
    if (Object.keys(data.security_issues).length > 0) {
        html += `<div class="card"><h3>Security Issues</h3>`;
        for (const [file, issues] of Object.entries(data.security_issues)) {
            html += `<div class="warning"><strong>${file}</strong><br>`;
            html += `Issues: ${issues.join(', ')}<br>`;
            html += `</div>`;
        }
        html += `</div>`;
    }
    
    if (Object.keys(data.syntax_errors).length > 0) {
        html += `<div class="card"><h3>PHP Syntax Errors</h3>`;
        for (const [file, error] of Object.entries(data.syntax_errors)) {
            html += `<div class="critical"><strong>${file}</strong><br>`;
            html += `<pre>${error}</pre>`;
            html += `</div>`;
        }
        html += `</div>`;
    }
    
    if (data.summary.critical_blockers === 0 && data.summary.syntax_errors === 0) {
        html += `<div class="card success"><strong>✅ All checks passed. PostgreSQL compatible.</strong></div>`;
    }
    
    resultsDiv.innerHTML = html;
}
</script>
</body>
</html>
