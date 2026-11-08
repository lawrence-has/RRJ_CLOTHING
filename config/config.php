<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'httponly' => true, 'secure' => $https, 'samesite' => 'Lax', 'path' => '/',
    ]);
    session_start();
}
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

const APP_NAME    = 'RRJ Clothing';
const BASE_PATH   = __DIR__ . '/..';
const UPLOAD_DIR  = BASE_PATH . '/uploads/products/';
const UPLOAD_URL  = 'uploads/products/';

// Ensure required directories exist
foreach ([BASE_PATH . '/data', UPLOAD_DIR] as $_dir) {
    if (!is_dir($_dir)) mkdir($_dir, 0777, true);
}

/* ── Database singleton ─────────────────────────────────────── */
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // Installer-created local settings take precedence over environment defaults.
    $localSettingsFile = __DIR__ . '/database.local.php';
    $localSettings = is_file($localSettingsFile) ? require $localSettingsFile : [];
    if (!is_array($localSettings)) $localSettings = [];

    $host = getenv('RRJ_DB_HOST') ?: ($localSettings['host'] ?? '127.0.0.1');
    $port = (string)(getenv('RRJ_DB_PORT') ?: ($localSettings['port'] ?? '3306'));
    $user = getenv('RRJ_DB_USER') ?: ($localSettings['user'] ?? 'root');
    $envPass = getenv('RRJ_DB_PASS');
    $pass = $envPass !== false ? $envPass : (string)($localSettings['password'] ?? '');
    $name = getenv('RRJ_DB_NAME') ?: ($localSettings['database'] ?? 'rrj_clothing');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Invalid database name. Use letters, numbers, and underscores only.');
    }
    if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
        throw new RuntimeException('Invalid MySQL port configuration.');
    }

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$name}`");
    } catch (PDOException $e) {
        // Show a useful, credential-free diagnostic while keeping secrets hidden.
        $driverCode = (string)($e->errorInfo[1] ?? 'unknown');
        $message = 'Could not connect to MySQL or create/select the application database.';
        if (str_contains(strtolower($e->getMessage()), 'could not find driver')) {
            $message = 'PHP PDO MySQL driver is not enabled. Enable pdo_mysql in the PHP configuration used by Laragon, then restart Apache.';
        } elseif (in_array($driverCode, ['1045', '1698'], true)) {
            $message = 'MySQL rejected the configured credentials. Open install.php and save credentials that successfully connect to your MySQL server.';
        } elseif (in_array($driverCode, ['2002', '2003', '10061'], true)) {
            $message = 'MySQL is not reachable at the configured host and port. Start MySQL in Laragon, confirm its TCP port, then run install.php.';
        } elseif ($driverCode === '1044' || $driverCode === '1049') {
            $message = 'The MySQL account lacks database permissions, or the configured database name is invalid. Check RRJ_DB_NAME and grant the account access.';
        }
        http_response_code(500);
        die('<div style="font-family:Arial,sans-serif;max-width:760px;margin:40px auto;padding:24px;border:1px solid #ddd;border-radius:12px;color:#222">'
            . '<h2 style="color:#b42318">&#9888; Database Connection Error</h2>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Check <code>config/config.php</code>. Defaults are host <code>127.0.0.1</code>, port <code>3306</code>, user <code>root</code>, blank password, and database <code>rrj_clothing</code>.</p>'
            . '<p>If your Laragon MySQL uses a different port, set <code>RRJ_DB_PORT</code> or update the <code>$port</code> value in this file. No password is displayed here.</p>'
            . '<p style="color:#666;font-size:13px">Diagnostic code: ' . htmlspecialchars($driverCode, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div>');
    }

    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    initialize_database($pdo);
    return $pdo;
}

/* ── Schema bootstrap ───────────────────────────────────────── */
function initialize_database(PDO $pdo): void {

    /* users */
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(120)  NOT NULL,
        email       VARCHAR(160)  NOT NULL UNIQUE,
        password    VARCHAR(255)  NOT NULL,
        role        ENUM('admin','staff','customer') NOT NULL DEFAULT 'customer',
        phone       VARCHAR(40)   DEFAULT '',
        address     TEXT,
        status      ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    /* categories */
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id    INT AUTO_INCREMENT PRIMARY KEY,
        name  VARCHAR(80) NOT NULL UNIQUE
    ) ENGINE=InnoDB");

    /* products */
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        name          VARCHAR(160)   NOT NULL,
        category      VARCHAR(60)    NOT NULL,
        price         DECIMAL(10,2)  NOT NULL,
        stock         INT            NOT NULL DEFAULT 0,
        reorder_level INT            NOT NULL DEFAULT 5,
        color         VARCHAR(60)    DEFAULT '',
        sizes         VARCHAR(120)   DEFAULT 'S,M,L,XL',
        description   TEXT,
        image         VARCHAR(255)   DEFAULT '',
        status        ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    /* orders */
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        order_no        VARCHAR(50)   NOT NULL UNIQUE,
        user_id         INT           NOT NULL,
        subtotal        DECIMAL(10,2) NOT NULL,
        shipping        DECIMAL(10,2) NOT NULL DEFAULT 60,
        total           DECIMAL(10,2) NOT NULL,
        payment_method  VARCHAR(80)   NOT NULL,
        shipping_method VARCHAR(80)   NOT NULL,
        address         TEXT          NOT NULL,
        status          VARCHAR(40)   NOT NULL DEFAULT 'Order Placed',
        payment_status  ENUM('Unpaid','Paid','Refunded') NOT NULL DEFAULT 'Unpaid',
        notes           TEXT,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Backward-compatible migration for databases created by earlier versions.
    $paymentStatusColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'payment_status'")->fetch();
    if (!$paymentStatusColumn) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_status ENUM('Unpaid','Paid','Refunded') NOT NULL DEFAULT 'Unpaid' AFTER status");
    }

    /* order_items */
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        order_id    INT           NOT NULL,
        product_id  INT           NOT NULL,
        quantity    INT           NOT NULL,
        price       DECIMAL(10,2) NOT NULL,
        size        VARCHAR(40)   DEFAULT '',
        color       VARCHAR(60)   DEFAULT '',
        CONSTRAINT fk_items_order   FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
        CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");

    /* stock_movements — audit trail for inventory changes */
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_movements (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        product_id  INT           NOT NULL,
        user_id     INT           NULL,
        type        ENUM('in','out','sale','adjustment') NOT NULL DEFAULT 'adjustment',
        quantity    INT           NOT NULL,
        note        VARCHAR(255)  DEFAULT '',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        CONSTRAINT fk_sm_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE SET NULL
    ) ENGINE=InnoDB");

    /* wishlist */
    $pdo->exec("CREATE TABLE IF NOT EXISTS wishlists (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        product_id  INT NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_wishlist (user_id, product_id),
        CONSTRAINT fk_wl_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
        CONSTRAINT fk_wl_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    /* activity_logs */
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NULL,
        action      VARCHAR(100) NOT NULL,
        details     TEXT,
        ip          VARCHAR(45)  DEFAULT '',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");

    /* suppliers */
    $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(160)  NOT NULL,
        contact_name VARCHAR(120)  DEFAULT '',
        email        VARCHAR(160)  DEFAULT '',
        phone        VARCHAR(60)   DEFAULT '',
        address      TEXT,
        notes        TEXT,
        status       ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    /* supplier_products — links suppliers to products */
    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_products (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        product_id  INT NOT NULL,
        unit_cost   DECIMAL(10,2) DEFAULT 0.00,
        UNIQUE KEY uq_sup_prod (supplier_id, product_id),
        CONSTRAINT fk_sp_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
        CONSTRAINT fk_sp_product  FOREIGN KEY (product_id)  REFERENCES products(id)  ON DELETE CASCADE
    ) ENGINE=InnoDB");

    /* restock_requests */
    $pdo->exec("CREATE TABLE IF NOT EXISTS restock_requests (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        product_id  INT NOT NULL,
        quantity    INT NOT NULL DEFAULT 1,
        status      ENUM('Pending','Ordered','Received','Cancelled') NOT NULL DEFAULT 'Pending',
        notes       TEXT,
        requested_by INT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_rr_supplier FOREIGN KEY (supplier_id)   REFERENCES suppliers(id) ON DELETE CASCADE,
        CONSTRAINT fk_rr_product  FOREIGN KEY (product_id)    REFERENCES products(id)  ON DELETE CASCADE,
        CONSTRAINT fk_rr_user     FOREIGN KEY (requested_by)  REFERENCES users(id)     ON DELETE SET NULL
    ) ENGINE=InnoDB");

    /* settings */
   $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    `key`   VARCHAR(80)  NOT NULL PRIMARY KEY,
    `value` TEXT         NOT NULL
    ) ENGINE=InnoDB");

    /* ── Seed demo data ────────────────────────────────────── */
    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount === 0) {
        $s = $pdo->prepare('INSERT INTO users(name,email,password,role,phone,address) VALUES(?,?,?,?,?,?)');
        $s->execute(['RRJ Administrator','admin@rrjclothing.com',password_hash('admin123',PASSWORD_DEFAULT),'admin','09171234567','123 Sampaguita St., Brgy. Malinta, Batangas City']);
        $s->execute(['Juan Dela Cruz','juan@email.com',password_hash('123456',PASSWORD_DEFAULT),'customer','09171234567','123 Sampaguita St., Brgy. Malinta, Batangas City']);
        $s->execute(['Maria Santos','maria@email.com',password_hash('123456',PASSWORD_DEFAULT),'customer','09087654321','Poblacion, Batangas City']);
    }

    $catCount = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($catCount === 0) {
        $cs = $pdo->prepare('INSERT IGNORE INTO categories(name) VALUES(?)');
        foreach (['Men','Women','Kids','Accessories'] as $c) $cs->execute([$c]);
    }

    $productCount = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($productCount === 0) {
        $products = [
            ['RRJ Basic Tee (Black)',   'Men',         299,  25, 10, 'Black', 'S,M,L,XL,XXL', 'Classic RRJ basic tee made with soft and breathable fabric.'],
            ['RRJ Plain Tee (White)',   'Women',       349,  18,  8, 'White', 'S,M,L,XL',     'Clean everyday white tee with RRJ styling.'],
            ['RRJ Polo Shirt (Navy)',   'Men',         599,  20,  8, 'Navy',  'S,M,L,XL',     'Smart casual polo shirt for everyday wear.'],
            ['RRJ Denim Pants',         'Men',         999,   6, 10, 'Blue',  '30,32,34,36',  'Comfortable denim pants with classic fit.'],
            ['RRJ Hoodie (Black)',       'Men',         699,  12,  5, 'Black', 'S,M,L,XL',     'Soft hoodie for cool days.'],
            ['RRJ Cap (Red)',            'Accessories', 199,   3,  5, 'Red',   'One Size',     'RRJ embroidered cap.'],
            ['RRJ Ladies Shirt',         'Women',       499,  12,  8, 'White', 'S,M,L,XL',     'Comfortable shirt designed for everyday use.'],
            ['RRJ Kids Shirt',           'Kids',        249,  30,  8, 'White', 'XS,S,M,L',     'Comfortable shirt for kids.'],
            ['RRJ Classic Jacket',       'Men',         899,   8,  5, 'Khaki', 'S,M,L,XL',     'Versatile everyday jacket for all occasions.'],
        ];
        $s = $pdo->prepare('INSERT INTO products(name,category,price,stock,reorder_level,color,sizes,description) VALUES(?,?,?,?,?,?,?,?)');
        foreach ($products as $p) $s->execute($p);
    }

    /* Seed default settings */
    $settingsCount = (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn();
    if ($settingsCount === 0) {
        $ss = $pdo->prepare('INSERT IGNORE INTO settings(`key`,`value`) VALUES(?,?)');
        foreach ([
            ['store_name',      'RRJ Clothing'],
            ['store_phone',     '0917 123 4567'],
            ['store_email',     'support@rrjclothing.com'],
            ['store_address',   '123 Sampaguita St., Brgy. Malinta, Batangas City'],
            ['shipping_standard', '60'],
            ['shipping_express',  '120'],
            ['currency_symbol', '₱'],
        ] as $row) $ss->execute($row);
    }
}

/* Auto-run bootstrap */
db();

/* ── Core helpers ───────────────────────────────────────────── */
function e(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}
function money(float $v): string {
    return e(setting('currency_symbol', '₱')) . number_format($v, 2);
}
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}
function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}
function flashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}
function login_user(array $u): void {
    session_regenerate_id(true);
    $_SESSION['user'] = $u;
    unset($_SESSION['csrf_token']);
}
function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
function require_customer(): array {
    $u = current_user();
    if (!$u || $u['role'] !== 'customer') {
        $page = $_GET['page'] ?? '';
        $next = in_array($page, ['wishlist', 'orders'], true) ? '&next=' . $page : '';
        redirect('index.php?page=login' . $next);
    }
    return $u;
}
function require_staff(): array {
    $u = current_user();
    if (!$u || !in_array($u['role'] ?? '', ['admin','staff'], true)) {
        redirect('../staff/login.php');
    }

    // Re-check the account on every protected request so a deactivated or
    // demoted account cannot continue using an already-open staff session.
    $stmt = db()->prepare('SELECT id,name,email,role,status FROM users WHERE id=? LIMIT 1');
    $stmt->execute([(int)$u['id']]);
    $fresh = $stmt->fetch();
    if (!$fresh || $fresh['status'] !== 'Active' || !in_array($fresh['role'], ['admin','staff'], true)) {
        logout_user();
        session_start();
        flash('error', 'Your staff account is inactive or no longer authorised. Please contact the administrator.');
        redirect('../staff/login.php');
    }

    // Keep the session role/name in sync with the database.
    $_SESSION['user'] = array_merge($u, $fresh);
    return $_SESSION['user'];
}
function require_admin(): array {
    $u = require_staff();
    if (($u['role'] ?? '') !== 'admin') {
        flash('error', 'Administrator access is required for this page.');
        redirect('../staff/dashboard.php');
    }
    return $u;
}
function log_activity(?int $uid, string $action, string $details = ''): void {

    $s = db()->prepare(
        'INSERT INTO activity_logs(user_id,action,details) VALUES(?,?,?)'
    );

    $s->execute([$uid, $action, $details]);
}

/* ── Cart helpers ───────────────────────────────────────────── */
function cart(): array {
    return $_SESSION['cart'] ?? [];
}
function cart_count(): int {
    return array_sum(array_column(cart(), 'qty'));
}
function cart_total(): float {
    $t = 0;
    foreach (cart() as $i) $t += $i['price'] * $i['qty'];
    return $t;
}

/* ── CSRF helpers ───────────────────────────────────────────── */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}
function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        flash('error', 'Invalid or expired request token. Please try again.');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../index.php'));
        exit;
    }
}

/* ── Settings helper ────────────────────────────────────────── */
function setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT `key`,`value` FROM settings')->fetchAll() as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }
    return $cache[$key] ?? $default;
}

/* ── Image upload helper ────────────────────────────────────── */
function handle_product_image(array $file, string $oldImage = ''): string {
    if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return $oldImage;
    if (($file['size'] ?? 0) < 1 || ($file['size'] ?? 0) > 5 * 1024 * 1024) return $oldImage;
    if (!is_uploaded_file($file['tmp_name'])) return $oldImage;

    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    $imageInfo = @getimagesize($file['tmp_name']);
    $imageType = $imageInfo[2] ?? 0;
    if (!isset($allowed[$imageType])) return $oldImage;

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) return $oldImage;
    $filename = 'product_' . bin2hex(random_bytes(16)) . '.' . $allowed[$imageType];
    $dest = UPLOAD_DIR . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        @chmod($dest, 0644);
        $oldName = basename($oldImage);
        if ($oldName !== '' && $oldName === $oldImage && is_file(UPLOAD_DIR . $oldName)) @unlink(UPLOAD_DIR . $oldName);
        return $filename;
    }
    return $oldImage;
}
