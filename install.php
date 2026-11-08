<?php
declare(strict_types=1);
session_start();

$message = '';
$error = '';
$defaults = ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'database' => 'rrj_clothing'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim((string)($_POST['host'] ?? '127.0.0.1'));
    $port = trim((string)($_POST['port'] ?? '3306'));
    $user = trim((string)($_POST['user'] ?? 'root'));
    $password = (string)($_POST['password'] ?? '');
    $database = trim((string)($_POST['database'] ?? 'rrj_clothing'));

    if ($host === '' || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 ||
        $user === '' || !preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        $error = 'Please provide a valid host, port, username, and database name (letters, numbers, underscore only).';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $settings = [
                'host' => $host,
                'port' => (string)$port,
                'user' => $user,
                'password' => $password,
                'database' => $database
            ];
            $file = __DIR__ . '/config/database.local.php';
            $contents = "<?php\n// Local RRJ database connection settings. Keep this file private.\nreturn " . var_export($settings, true) . ";\n";
            if (file_put_contents($file, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Could not save config/database.local.php. Check folder write permissions.');
            }
            @chmod($file, 0600);
            $message = 'MySQL connection succeeded and database "' . htmlspecialchars($database, ENT_QUOTES, 'UTF-8') . '" is ready. The application will now create any missing tables and preserve existing tables/data.';
        } catch (Throwable $e) {
            $error = 'Setup failed: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>RRJ Clothing — Database Setup</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f8;color:#182230;font:15px/1.5 Arial,sans-serif}.wrap{max-width:680px;margin:5vh auto;padding:24px}.panel{background:#fff;border:1px solid #e3e8ef;border-radius:16px;padding:28px;box-shadow:0 8px 28px #1018280d}h1{margin:0 0 8px;font-size:26px}.muted{color:#667085}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}label{display:block;font-weight:600;margin:12px 0 5px}input{width:100%;padding:11px 12px;border:1px solid #cfd6df;border-radius:8px;font-size:15px}button{margin-top:20px;background:#172b4d;color:#fff;border:0;padding:13px 18px;border-radius:8px;font-weight:700;cursor:pointer;width:100%}.notice{padding:13px 15px;border-radius:9px;margin:16px 0;background:#ecfdf3;color:#027a48}.error{background:#fef3f2;color:#b42318}.hint{background:#fffaeb;color:#93370d;padding:12px;border-radius:8px;margin-top:16px;font-size:14px}@media(max-width:560px){.grid{grid-template-columns:1fr}}
</style></head><body><main class="wrap"><section class="panel">
<h1>RRJ Clothing Database Setup</h1><p class="muted">Connect to your running MySQL server and prepare the RRJ database. Existing database tables are not dropped or reset.</p>
<?php if ($message): ?><div class="notice"><?= $message ?><p><a href="index.php">Open RRJ Clothing</a></p></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post">
<div class="grid"><div><label>MySQL Host</label><input name="host" value="<?= htmlspecialchars($_POST['host'] ?? $defaults['host']) ?>" required></div>
<div><label>MySQL Port</label><input name="port" value="<?= htmlspecialchars($_POST['port'] ?? $defaults['port']) ?>" required></div></div>
<label>MySQL Username</label><input name="user" value="<?= htmlspecialchars($_POST['user'] ?? $defaults['user']) ?>" required>
<label>MySQL Password</label><input type="password" name="password" autocomplete="new-password">
<label>Database Name</label><input name="database" value="<?= htmlspecialchars($_POST['database'] ?? $defaults['database']) ?>" required>
<button type="submit">Test Connection &amp; Prepare Database</button>
</form>
<div class="hint"><strong>Important:</strong> MySQL must be running first. If every port test shows no listener, start MySQL in Laragon before using this installer. The account must have permission to create databases. Your password is saved locally in <code>config/database.local.php</code>; do not publish this folder.</div>
</section></main></body></html>
