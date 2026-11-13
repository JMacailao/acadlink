<?php
declare(strict_types=1);

/**
 * v59 Canteen POS: products and categories, stock per item, sales and receipts, cashier shifts and cash counts,
 * supervisor-approved voids and refunds, sales reports, and one Accounting journal entry per accepted shift.
 * Amounts are whole centavos (BIGINT). Prices always come from the database, never from the browser.
 */
final class Canteen
{
    public const METHODS = ['CASH' => 'Cash', 'QR' => 'QR payment'];
    public const MOVE_KINDS = ['SALE' => 'Sale', 'VOID' => 'Void', 'REFUND' => 'Refund', 'RECEIVE' => 'Received', 'PREPARE' => 'Prepared', 'ADJUST' => 'Adjustment', 'STOCKTAKE' => 'Stocktake'];
    public const DENOMINATIONS = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 100, 25];
    public const MAX_IMAGE_BYTES = 5000000;   // largest upload accepted (5 MB); photos are shrunk before saving
    public const IMAGE_MAX_SIDE = ['PRODUCT' => 800, 'QR' => 1000, 'COVER' => 600, 'PHOTO' => 600]; // COVER: Library book covers (v60.1)

    // ------------------------------------------------------------------ install

    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS canteen_categories (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL, icon VARCHAR(40) NOT NULL DEFAULT 'tag',
            sort_order SMALLINT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY u_canteen_cat_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS canteen_products (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, category_id INT UNSIGNED NULL,
            sku VARCHAR(40) NULL, barcode VARCHAR(64) NULL, price_cents BIGINT NOT NULL, cost_cents BIGINT NOT NULL DEFAULT 0,
            track_stock TINYINT(1) NOT NULL DEFAULT 1, stock_qty INT NOT NULL DEFAULT 0, low_stock_at INT NOT NULL DEFAULT 5,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY u_canteen_barcode (barcode), UNIQUE KEY u_canteen_sku (sku), KEY k_canteen_prod_cat (category_id),
            CONSTRAINT fk_canteen_prod_cat FOREIGN KEY (category_id) REFERENCES canteen_categories (id),
            CONSTRAINT ck_canteen_price CHECK (price_cents >= 0 AND cost_cents >= 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS canteen_shifts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, cashier_id INT UNSIGNED NOT NULL,
            status ENUM('OPEN','CLOSED','ACCEPTED') NOT NULL DEFAULT 'OPEN',
            opening_cents BIGINT NOT NULL DEFAULT 0, opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME NULL, counted_cents BIGINT NULL, expected_cents BIGINT NULL, count_detail VARCHAR(500) NULL, close_note VARCHAR(255) NULL,
            accepted_by INT UNSIGNED NULL, accepted_at DATETIME NULL, review_note VARCHAR(255) NULL, journal_id INT UNSIGNED NULL,
            open_guard INT UNSIGNED GENERATED ALWAYS AS (IF(status='OPEN', cashier_id, NULL)) STORED,
            UNIQUE KEY u_canteen_one_open_shift (open_guard), KEY k_canteen_shift_status (status), KEY k_canteen_shift_opened (opened_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS canteen_sales (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, receipt_no VARCHAR(20) NOT NULL, idempotency_key CHAR(32) NOT NULL,
            shift_id INT UNSIGNED NOT NULL, cashier_id INT UNSIGNED NOT NULL, method ENUM('CASH','QR') NOT NULL,
            total_cents BIGINT NOT NULL, tendered_cents BIGINT NOT NULL DEFAULT 0, change_cents BIGINT NOT NULL DEFAULT 0,
            refunded_cents BIGINT NOT NULL DEFAULT 0, reference_no VARCHAR(60) NULL, customer VARCHAR(100) NULL,
            status ENUM('POSTED','VOIDED') NOT NULL DEFAULT 'POSTED', sold_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            voided_at DATETIME NULL, voided_by INT UNSIGNED NULL, void_reason VARCHAR(255) NULL,
            UNIQUE KEY u_canteen_receipt (receipt_no), UNIQUE KEY u_canteen_idem (idempotency_key), KEY k_canteen_sale_shift (shift_id), KEY k_canteen_sale_date (sold_at),
            CONSTRAINT fk_canteen_sale_shift FOREIGN KEY (shift_id) REFERENCES canteen_shifts (id), CONSTRAINT ck_canteen_total CHECK (total_cents > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS canteen_sale_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sale_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL, price_cents BIGINT NOT NULL, cost_cents BIGINT NOT NULL DEFAULT 0, qty INT NOT NULL, refunded_qty INT NOT NULL DEFAULT 0,
            KEY k_canteen_item_sale (sale_id), KEY k_canteen_item_product (product_id),
            CONSTRAINT fk_canteen_item_sale FOREIGN KEY (sale_id) REFERENCES canteen_sales (id), CONSTRAINT fk_canteen_item_product FOREIGN KEY (product_id) REFERENCES canteen_products (id),
            CONSTRAINT ck_canteen_qty CHECK (qty > 0 AND refunded_qty >= 0 AND refunded_qty <= qty)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS canteen_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, kind ENUM('VOID','REFUND') NOT NULL, sale_id INT UNSIGNED NOT NULL,
            items VARCHAR(2000) NOT NULL DEFAULT '[]', amount_cents BIGINT NOT NULL, reason VARCHAR(255) NOT NULL,
            requested_by INT UNSIGNED NOT NULL, requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, shift_id INT UNSIGNED NULL,
            status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING', decided_by INT UNSIGNED NULL, decided_at DATETIME NULL, decision_note VARCHAR(255) NULL,
            KEY k_canteen_req_status (status), KEY k_canteen_req_sale (sale_id), KEY k_canteen_req_shift (shift_id),
            CONSTRAINT fk_canteen_req_sale FOREIGN KEY (sale_id) REFERENCES canteen_sales (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS canteen_stock_moves (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL,
            kind ENUM('SALE','VOID','REFUND','RECEIVE','PREPARE','ADJUST','STOCKTAKE') NOT NULL, delta INT NOT NULL, balance INT NOT NULL,
            unit_cost_cents BIGINT NULL, ref_type VARCHAR(20) NULL, ref_id INT UNSIGNED NULL, note VARCHAR(255) NULL,
            actor_id INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY k_canteen_move_product (product_id, created_at), KEY k_canteen_move_kind (kind),
            CONSTRAINT fk_canteen_move_product FOREIGN KEY (product_id) REFERENCES canteen_products (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Images are stored as bytes (MEDIUMBLOB), one row per product photo and one for the payment QR code
        $db->exec("CREATE TABLE IF NOT EXISTS canteen_images (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, owner_type ENUM('PRODUCT','QR') NOT NULL, owner_id INT UNSIGNED NOT NULL DEFAULT 0,
            mime VARCHAR(20) NOT NULL, bytes MEDIUMBLOB NOT NULL, width SMALLINT UNSIGNED NOT NULL DEFAULT 0, height SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            size_bytes INT UNSIGNED NOT NULL, sha1 CHAR(40) NOT NULL, uploaded_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY u_canteen_image_owner (owner_type, owner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS canteen_settings (
            skey VARCHAR(40) NOT NULL PRIMARY KEY, svalue MEDIUMTEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::migrateImages($db);
        $db->exec("INSERT IGNORE INTO canteen_categories (name, icon, sort_order) VALUES ('Rice Meals','egg-fried',1),('Snacks','basket',2),('Drinks','cup-straw',3),('Others','three-dots',4)");
        // Accounting: canteen sales revenue, cash short/over, and the journal source
        $has = fn(string $t) => (bool) $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$t'")->fetchColumn();
        if ($has('sis_accounts') && $has('sis_account_map')) {
            $db->exec("INSERT IGNORE INTO sis_accounts (code, name, type, is_cash, description) VALUES
                ('4600','Canteen Sales','REVENUE',0,'Canteen POS sales, less approved refunds'),
                ('5600','Cash Short and Over','EXPENSE',0,'Differences between counted and expected cash at the end of a shift')");
            $db->exec("INSERT IGNORE INTO sis_account_map (map_key, account_id)
                SELECT k, (SELECT id FROM sis_accounts WHERE code = c) FROM (SELECT 'canteen:sales' k, '4600' c UNION ALL SELECT 'canteen:overshort', '5600'
                UNION ALL SELECT 'canteen:cash', '1010' UNION ALL SELECT 'canteen:qr', '1030') m WHERE (SELECT id FROM sis_accounts WHERE code = c) IS NOT NULL");
        }
        if ($has('sis_journal')) {
            $type = (string) $db->query("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_journal' AND column_name='source'")->fetchColumn();
            if ($type !== '' && !str_contains($type, "'CANTEEN'")) {
                preg_match_all("/'([A-Z_]+)'/", $type, $m);
                $db->exec("ALTER TABLE sis_journal MODIFY source ENUM('" . implode("','", array_unique(array_merge($m[1], ['CANTEEN']))) . "') NOT NULL");
            }
        }
    }

    // ------------------------------------------------------------------ settings

    public static function setting(string $key, string $default = ''): string
    {
        $v = Db::val('SELECT svalue FROM canteen_settings WHERE skey = ?', [$key]);
        return $v === null ? $default : (string) $v;
    }

    public static function saveSettings(array $in, ?array $qrFile, bool $removeQr): void
    {
        Auth::require('canteen.products.edit');
        $vals = [
            'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 80),
            'receipt_footer' => mb_substr(trim((string) ($in['receipt_footer'] ?? '')), 0, 200),
            'qr_label' => mb_substr(trim((string) ($in['qr_label'] ?? '')), 0, 80),
        ];
        $qr = $qrFile && ($qrFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? self::imageFromUpload($qrFile, 'QR code', 'QR') : null;
        Db::tx(function () use ($vals, $qr, $removeQr) {
            foreach ($vals as $k => $v) Db::q('INSERT INTO canteen_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$k, $v]);
            if ($qr) self::saveImage('QR', 0, $qr); elseif ($removeQr) self::deleteImage('QR', 0);
            Audit::log('canteen_settings_saved', 'canteen_settings', null, $vals);
        });
    }

    /** Early v59 builds kept images as base64 text; move them into canteen_images as bytes (once). */
    private static function migrateImages(PDO $db): void
    {
        $col = $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='canteen_products' AND column_name='image'")->fetchColumn();
        if ($col) {
            foreach ($db->query("SELECT id, image FROM canteen_products WHERE image IS NOT NULL AND image <> ''")->fetchAll(PDO::FETCH_ASSOC) as $r)
                if ($img = self::fromDataUri((string) $r['image'])) self::writeImage($db, 'PRODUCT', (int) $r['id'], $img);
            $db->exec('ALTER TABLE canteen_products DROP COLUMN image');
        }
        $qr = $db->query("SELECT svalue FROM canteen_settings WHERE skey='qr_image'")->fetchColumn();
        if ($qr !== false) {
            if ($img = self::fromDataUri((string) $qr)) self::writeImage($db, 'QR', 0, $img);
            $db->exec("DELETE FROM canteen_settings WHERE skey='qr_image'");
        }
    }

    private static function fromDataUri(string $uri): ?array
    {
        if (!preg_match('#^data:(image/(?:png|jpeg|gif|webp));base64,(.+)$#s', $uri, $m)) return null;
        $bytes = (string) base64_decode($m[2], true);
        $info = @getimagesizefromstring($bytes);
        return $info ? ['mime' => $m[1], 'bytes' => $bytes, 'width' => (int) $info[0], 'height' => (int) $info[1]] : null;
    }

    /**
     * An uploaded PNG, JPEG, GIF or WebP, checked by its content (not its name). Photos larger than the limit for their
     * kind are shrunk with GD. Returns [mime, bytes, width, height]; the bytes are what gets stored in the database.
     */
    public static function imageFromUpload(array $file, string $label, string $kind = 'PRODUCT'): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name']) && PHP_SAPI !== 'cli') {
            $big = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new AppError($big ? "The $label image is too large for the server. Use a smaller image." : "The $label image could not be uploaded. Try again.");
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_IMAGE_BYTES) throw new AppError("The $label image must be smaller than " . round(self::MAX_IMAGE_BYTES / 1000000) . ' MB.');
        $bytes = (string) file_get_contents((string) $file['tmp_name']);
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) throw new AppError("The $label must be a PNG, JPEG, GIF or WebP image.");
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        $max = self::IMAGE_MAX_SIDE[$kind] ?? 800;
        if (($w > $max || $h > $max) && function_exists('imagecreatefromstring') && ($src = @imagecreatefromstring($bytes))) {
            $scale = $max / max($w, $h); $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            if ($mime === 'image/jpeg') imagejpeg($dst, null, 85);
            elseif ($mime === 'image/webp' && function_exists('imagewebp')) imagewebp($dst, null, 85);
            else { imagepng($dst, null, 6); $mime = 'image/png'; }
            $bytes = (string) ob_get_clean();
            [$w, $h] = [$nw, $nh];
        }
        return ['mime' => $mime, 'bytes' => $bytes, 'width' => $w, 'height' => $h];
    }

    /** Store an image's bytes for a product (PRODUCT, id) or the payment QR code (QR, 0); replaces the old one. */
    public static function saveImage(string $type, int $ownerId, array $img): void
    {
        self::writeImage(Db::pdo(), $type, $ownerId, $img);
        Audit::log('canteen_image_saved', $type === 'QR' ? 'canteen_settings' : 'canteen_product', $type === 'QR' ? null : $ownerId,
            ['type' => $img['mime'], 'bytes' => strlen($img['bytes']), 'size' => $img['width'] . '×' . $img['height']]);
    }

    private static function writeImage(PDO $db, string $type, int $ownerId, array $img): void
    {
        $st = $db->prepare('INSERT INTO canteen_images (owner_type, owner_id, mime, bytes, width, height, size_bytes, sha1, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE mime=VALUES(mime), bytes=VALUES(bytes), width=VALUES(width), height=VALUES(height), size_bytes=VALUES(size_bytes), sha1=VALUES(sha1), uploaded_by=VALUES(uploaded_by)');
        $st->bindValue(1, $type); $st->bindValue(2, $ownerId, PDO::PARAM_INT); $st->bindValue(3, $img['mime']);
        $st->bindValue(4, $img['bytes'], PDO::PARAM_LOB);
        $st->bindValue(5, min(65535, (int) $img['width']), PDO::PARAM_INT); $st->bindValue(6, min(65535, (int) $img['height']), PDO::PARAM_INT);
        $st->bindValue(7, strlen($img['bytes']), PDO::PARAM_INT); $st->bindValue(8, sha1($img['bytes']));
        $uid = PHP_SAPI === 'cli' && !class_exists('Auth', false) ? null : Auth::id();
        $st->bindValue(9, $uid, $uid === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->execute();
    }

    public static function deleteImage(string $type, int $ownerId): void
    {
        if (Db::q('DELETE FROM canteen_images WHERE owner_type = ? AND owner_id = ?', [$type, $ownerId])->rowCount())
            Audit::log('canteen_image_removed', $type === 'QR' ? 'canteen_settings' : 'canteen_product', $type === 'QR' ? null : $ownerId, []);
    }

    /** [mime, bytes] of a stored image, or null. */
    public static function image(string $type, int $ownerId): ?array
    {
        $st = Db::pdo()->prepare('SELECT mime, bytes FROM canteen_images WHERE owner_type = ? AND owner_id = ?');
        $st->execute([$type, $ownerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? [(string) $r['mime'], is_resource($r['bytes']) ? (string) stream_get_contents($r['bytes']) : (string) $r['bytes']] : null;
    }

    /** A short version tag of a stored image for cache-busting links ('' when there is none). */
    public static function imageVersion(string $type, int $ownerId): string
    {
        return substr((string) Db::val('SELECT sha1 FROM canteen_images WHERE owner_type = ? AND owner_id = ?', [$type, $ownerId]), 0, 10);
    }

    // ------------------------------------------------------------------ categories and products

    public static function categories(bool $activeOnly = false): array
    {
        return Db::all('SELECT c.*, (SELECT COUNT(*) FROM canteen_products p WHERE p.category_id = c.id) AS products FROM canteen_categories c'
            . ($activeOnly ? ' WHERE c.is_active = 1' : '') . ' ORDER BY c.sort_order, c.name');
    }

    public static function saveCategory(?int $id, array $in): int
    {
        Auth::require('canteen.products.edit');
        $name = In::str($in, 'name', 60, true, 'Category name');
        $icon = preg_match('/^[a-z0-9-]{1,40}$/', (string) ($in['icon'] ?? '')) ? (string) $in['icon'] : 'tag';
        $row = ['name' => $name, 'icon' => $icon, 'sort_order' => max(0, min(999, (int) ($in['sort_order'] ?? 0))), 'is_active' => empty($in['is_active']) ? 0 : 1];
        return Db::tx(function () use ($id, $row) {
            if (Db::val('SELECT 1 FROM canteen_categories WHERE name = ? AND id <> ?', [$row['name'], (int) $id])) throw new AppError('A category with that name already exists.');
            if ($id) { Db::q('UPDATE canteen_categories SET name=?, icon=?, sort_order=?, is_active=? WHERE id=?', [...array_values($row), $id]); }
            else $id = Db::insert('canteen_categories', $row);
            Audit::log('canteen_category_saved', 'canteen_category', $id, $row);
            return (int) $id;
        });
    }

    public static function products(array $f = []): array
    {
        $w = []; $p = [];
        if (!empty($f['active'])) $w[] = 'p.is_active = 1';
        if (!empty($f['category'])) { $w[] = 'p.category_id = ?'; $p[] = (int) $f['category']; }
        if (($f['q'] ?? '') !== '') { $w[] = '(p.name LIKE ? OR p.barcode = ? OR p.sku = ?)'; array_push($p, '%' . $f['q'] . '%', $f['q'], $f['q']); }
        if (!empty($f['low'])) $w[] = 'p.track_stock = 1 AND p.stock_qty <= p.low_stock_at';
        return Db::all("SELECT p.*, c.name AS category_name, c.icon AS category_icon, LEFT(i.sha1, 10) AS image_ver FROM canteen_products p LEFT JOIN canteen_categories c ON c.id = p.category_id
            LEFT JOIN canteen_images i ON i.owner_type = 'PRODUCT' AND i.owner_id = p.id"
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY c.sort_order, p.name', $p);
    }

    public static function product(int $id): ?array
    {
        return Db::one("SELECT p.*, c.name AS category_name, LEFT(i.sha1, 10) AS image_ver FROM canteen_products p LEFT JOIN canteen_categories c ON c.id = p.category_id
            LEFT JOIN canteen_images i ON i.owner_type = 'PRODUCT' AND i.owner_id = p.id WHERE p.id = ?", [$id]);
    }

    public static function saveProduct(?int $id, array $in, ?array $imageFile = null): int
    {
        Auth::require('canteen.products.edit');
        $name = In::str($in, 'name', 100, true, 'Product name');
        $price = Money::cents((string) ($in['price'] ?? ''));
        $cost = Money::cents((string) ($in['cost'] ?? '0'));
        if ($price <= 0) throw new AppError('Enter the selling price.');
        if ($cost < 0) throw new AppError('The cost can\'t be negative.');
        $barcode = trim((string) ($in['barcode'] ?? '')); $sku = trim((string) ($in['sku'] ?? ''));
        if ($barcode !== '' && !preg_match('/^[A-Za-z0-9\-_.]{3,64}$/', $barcode)) throw new AppError('A barcode may only have letters, numbers, dashes and dots (3 to 64).');
        if ($sku !== '' && !preg_match('/^[A-Za-z0-9\-_.]{1,40}$/', $sku)) throw new AppError('A SKU may only have letters, numbers, dashes and dots.');
        $cat = (int) ($in['category_id'] ?? 0);
        if ($cat && !Db::val('SELECT 1 FROM canteen_categories WHERE id = ?', [$cat])) throw new AppError('Choose a category.');
        $row = ['name' => $name, 'category_id' => $cat ?: null, 'sku' => $sku ?: null, 'barcode' => $barcode ?: null, 'price_cents' => $price, 'cost_cents' => $cost,
            'track_stock' => empty($in['track_stock']) ? 0 : 1, 'low_stock_at' => max(0, min(100000, (int) ($in['low_stock_at'] ?? 5))), 'is_active' => empty($in['is_active']) ? 0 : 1];
        $img = $imageFile && ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? self::imageFromUpload($imageFile, 'product', 'PRODUCT') : null;
        $removeImage = !$img && !empty($in['remove_image']);
        return Db::tx(function () use ($id, $row, $img, $removeImage) {
            if ($row['barcode'] && Db::val('SELECT name FROM canteen_products WHERE barcode = ? AND id <> ?', [$row['barcode'], (int) $id])) throw new AppError('Another product already uses that barcode.');
            if ($row['sku'] && Db::val('SELECT name FROM canteen_products WHERE sku = ? AND id <> ?', [$row['sku'], (int) $id])) throw new AppError('Another product already uses that SKU.');
            if ($id) {
                $old = Db::one('SELECT price_cents, cost_cents, is_active FROM canteen_products WHERE id = ? FOR UPDATE', [$id]);
                if (!$old) throw new AppError('Product not found.');
                Db::q('UPDATE canteen_products SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $id]);
                Audit::log('canteen_product_saved', 'canteen_product', $id, ['name' => $row['name'], 'price' => [$old['price_cents'], $row['price_cents']], 'cost' => [$old['cost_cents'], $row['cost_cents']], 'active' => $row['is_active']]);
            } else {
                $id = Db::insert('canteen_products', $row);
                Audit::log('canteen_product_added', 'canteen_product', $id, ['name' => $row['name'], 'price' => $row['price_cents']]);
            }
            if ($img) self::saveImage('PRODUCT', (int) $id, $img); elseif ($removeImage) self::deleteImage('PRODUCT', (int) $id);
            return (int) $id;
        });
    }

    // ------------------------------------------------------------------ stock

    /** Change one product's stock, inside the caller's transaction or its own. Returns the new balance. */
    public static function move(int $productId, string $kind, int $delta, ?string $refType = null, ?int $refId = null, ?string $note = null, ?int $unitCost = null): int
    {
        if (!isset(self::MOVE_KINDS[$kind])) throw new AppError('Unknown stock movement.');
        return Db::tx(function () use ($productId, $kind, $delta, $refType, $refId, $note, $unitCost) {
            $p = Db::one('SELECT id, name, stock_qty, track_stock FROM canteen_products WHERE id = ? FOR UPDATE', [$productId]);
            if (!$p) throw new AppError('Product not found.');
            $balance = (int) $p['stock_qty'] + $delta;
            Db::q('UPDATE canteen_products SET stock_qty = ? WHERE id = ?', [$balance, $productId]);
            Db::insert('canteen_stock_moves', ['product_id' => $productId, 'kind' => $kind, 'delta' => $delta, 'balance' => $balance, 'unit_cost_cents' => $unitCost,
                'ref_type' => $refType, 'ref_id' => $refId, 'note' => $note === null ? null : mb_substr($note, 0, 255), 'actor_id' => Auth::id()]);
            return $balance;
        });
    }

    /** Receive stock from a supplier (RECEIVE) or record servings cooked today (PREPARE). */
    public static function addStock(int $productId, string $kind, int $qty, ?int $unitCost, string $note): int
    {
        Auth::require('canteen.inventory.edit');
        if (!in_array($kind, ['RECEIVE', 'PREPARE'], true)) throw new AppError('Choose Received or Prepared.');
        if ($qty <= 0 || $qty > 100000) throw new AppError('Enter how many were added.');
        return Db::tx(function () use ($productId, $kind, $qty, $unitCost, $note) {
            $b = self::move($productId, $kind, $qty, null, null, $note ?: null, $unitCost);
            if ($kind === 'RECEIVE' && $unitCost !== null && $unitCost > 0) Db::q('UPDATE canteen_products SET cost_cents = ? WHERE id = ?', [$unitCost, $productId]);
            Audit::log('canteen_stock_' . strtolower($kind), 'canteen_product', $productId, ['qty' => $qty, 'unit_cost' => $unitCost, 'note' => $note]);
            return $b;
        });
    }

    /** Correct the count of one product (spoiled, lost, found…), with a reason. */
    public static function adjust(int $productId, int $delta, string $reason): int
    {
        Auth::require('canteen.inventory.edit');
        if ($delta === 0) throw new AppError('Enter how many to add (+) or remove (−).');
        if (mb_strlen(trim($reason)) < 4) throw new AppError('Give a reason for the adjustment.');
        return Db::tx(function () use ($productId, $delta, $reason) {
            $b = self::move($productId, 'ADJUST', $delta, null, null, $reason);
            Audit::log('canteen_stock_adjusted', 'canteen_product', $productId, ['delta' => $delta, 'reason' => $reason]);
            return $b;
        });
    }

    /** Stocktake: the counted quantity of each product; differences are booked as STOCKTAKE moves. Returns [changed, units difference]. */
    public static function stocktake(array $counts, string $note): array
    {
        Auth::require('canteen.inventory.edit');
        return Db::tx(function () use ($counts, $note) {
            $changed = 0; $diffTotal = 0;
            foreach ($counts as $pid => $counted) {
                if (!is_scalar($counted) || trim((string) $counted) === '') continue;
                if (!preg_match('/^\d{1,6}$/', trim((string) $counted))) throw new AppError('Counts must be whole numbers.');
                $p = Db::one('SELECT stock_qty, track_stock FROM canteen_products WHERE id = ? FOR UPDATE', [(int) $pid]);
                if (!$p || !(int) $p['track_stock']) continue;
                $diff = (int) $counted - (int) $p['stock_qty'];
                if ($diff === 0) continue;
                self::move((int) $pid, 'STOCKTAKE', $diff, null, null, $note ?: 'Stocktake');
                $changed++; $diffTotal += $diff;
            }
            Audit::log('canteen_stocktake', 'canteen_product', null, ['changed' => $changed, 'difference' => $diffTotal, 'note' => $note]);
            return [$changed, $diffTotal];
        });
    }

    public static function moves(array $f = [], int $limit = 200): array
    {
        $w = []; $p = [];
        if (!empty($f['product'])) { $w[] = 'm.product_id = ?'; $p[] = (int) $f['product']; }
        if (!empty($f['kind']) && isset(self::MOVE_KINDS[$f['kind']])) { $w[] = 'm.kind = ?'; $p[] = $f['kind']; }
        if (!empty($f['from'])) { $w[] = 'm.created_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to'])) { $w[] = 'm.created_at < ?'; $p[] = date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'; }
        return Db::all('SELECT m.*, p.name AS product_name, u.full_name AS actor FROM canteen_stock_moves m JOIN canteen_products p ON p.id = m.product_id LEFT JOIN sis_users u ON u.id = m.actor_id'
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY m.id DESC LIMIT ' . max(1, min(1000, $limit)), $p);
    }

    // ------------------------------------------------------------------ shifts

    public static function openShift(?int $cashierId = null): ?array
    {
        return Db::one("SELECT s.*, u.full_name AS cashier FROM canteen_shifts s LEFT JOIN sis_users u ON u.id = s.cashier_id WHERE s.cashier_id = ? AND s.status = 'OPEN'", [$cashierId ?? (int) Auth::id()]);
    }

    public static function shift(int $id): ?array
    {
        return Db::one('SELECT s.*, u.full_name AS cashier, a.full_name AS accepted_name FROM canteen_shifts s LEFT JOIN sis_users u ON u.id = s.cashier_id LEFT JOIN sis_users a ON a.id = s.accepted_by WHERE s.id = ?', [$id]);
    }

    public static function startShift(int $openingCents): int
    {
        Auth::require('canteen.sell');
        if ($openingCents < 0 || $openingCents > 10000000) throw new AppError('Enter the opening cash in the drawer.');
        return Db::tx(function () use ($openingCents) {
            if (self::openShift()) throw new AppError('You already have an open shift.');
            $id = Db::insert('canteen_shifts', ['cashier_id' => Auth::id(), 'opening_cents' => $openingCents]);
            Audit::log('canteen_shift_opened', 'canteen_shift', $id, ['opening' => $openingCents]);
            return $id;
        });
    }

    /** Sales, refunds and expected cash of a shift. */
    public static function shiftTotals(int $shiftId): array
    {
        $s = Db::one('SELECT opening_cents FROM canteen_shifts WHERE id = ?', [$shiftId]);
        $t = ['sales' => 0, 'count' => 0, 'cash' => 0, 'qr' => 0, 'refunds' => 0, 'voided' => 0, 'cost' => 0];
        foreach (Db::all("SELECT method, status, COUNT(*) n, SUM(total_cents) t FROM canteen_sales WHERE shift_id = ? GROUP BY method, status", [$shiftId]) as $r) {
            if ($r['status'] === 'VOIDED') { $t['voided'] += (int) $r['t']; continue; }
            $t['sales'] += (int) $r['t']; $t['count'] += (int) $r['n']; $t[$r['method'] === 'CASH' ? 'cash' : 'qr'] += (int) $r['t'];
        }
        $t['refunds'] = (int) Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM canteen_requests WHERE kind='REFUND' AND status='APPROVED' AND shift_id = ?", [$shiftId]);
        $t['cost'] = (int) Db::val("SELECT COALESCE(SUM(i.cost_cents * (i.qty - i.refunded_qty)),0) FROM canteen_sale_items i JOIN canteen_sales s ON s.id = i.sale_id WHERE s.shift_id = ? AND s.status = 'POSTED'", [$shiftId]);
        $t['opening'] = (int) ($s['opening_cents'] ?? 0);
        $t['expected'] = $t['opening'] + $t['cash'] - $t['refunds'];
        $t['net'] = $t['sales'] - $t['refunds'];
        $t['pending'] = (int) Db::val("SELECT COUNT(*) FROM canteen_requests r JOIN canteen_sales s ON s.id = r.sale_id WHERE r.status='PENDING' AND (s.shift_id = ? OR r.shift_id = ?)", [$shiftId, $shiftId]);
        return $t;
    }

    /** The cashier counts the drawer and closes the shift; a supervisor accepts it later. */
    public static function closeShift(int $shiftId, array $denoms, string $note): array
    {
        Auth::require('canteen.sell');
        return Db::tx(function () use ($shiftId, $denoms, $note) {
            $s = Db::one('SELECT * FROM canteen_shifts WHERE id = ? FOR UPDATE', [$shiftId]);
            if (!$s || $s['status'] !== 'OPEN') throw new AppError('This shift is not open.');
            if ((int) $s['cashier_id'] !== (int) Auth::id() && !Auth::can('canteen.supervise')) throw new Forbidden('Only the cashier of this shift or a supervisor can close it.');
            [$counted, $detail] = self::countCash($denoms);
            $t = self::shiftTotals($shiftId);
            if ($t['pending']) throw new AppError('This shift has void or refund requests waiting for the supervisor. They must be decided before closing.');
            Db::q("UPDATE canteen_shifts SET status='CLOSED', closed_at=NOW(), counted_cents=?, expected_cents=?, count_detail=?, close_note=? WHERE id=?",
                [$counted, $t['expected'], $detail, mb_substr(trim($note), 0, 255) ?: null, $shiftId]);
            Audit::log('canteen_shift_closed', 'canteen_shift', $shiftId, ['counted' => $counted, 'expected' => $t['expected'], 'difference' => $counted - $t['expected']]);
            return ['counted' => $counted, 'expected' => $t['expected'], 'difference' => $counted - $t['expected']];
        });
    }

    /** denomination (centavos) => pieces; returns [total centavos, "1000×2, 500×1"]. */
    public static function countCash(array $denoms): array
    {
        $total = 0; $parts = [];
        foreach (self::DENOMINATIONS as $d) {
            $n = trim((string) ($denoms[$d] ?? ''));
            if ($n === '' || $n === '0') continue;
            if (!preg_match('/^\d{1,5}$/', $n)) throw new AppError('Enter whole numbers of bills and coins.');
            $total += $d * (int) $n; $parts[] = Money::fmt($d) . '×' . (int) $n;
        }
        return [$total, implode(', ', $parts)];
    }

    /** Supervisor accepts a closed shift: one journal entry goes to Finance → Review and Post. Or returns it to the cashier. */
    public static function reviewShift(int $shiftId, bool $accept, string $note): ?int
    {
        Auth::require('canteen.supervise');
        return Db::tx(function () use ($shiftId, $accept, $note) {
            $s = Db::one('SELECT * FROM canteen_shifts WHERE id = ? FOR UPDATE', [$shiftId]);
            if (!$s || $s['status'] !== 'CLOSED') throw new AppError('Only a closed shift can be reviewed.');
            $note = mb_substr(trim($note), 0, 255);
            if (!$accept) {
                if (mb_strlen($note) < 4) throw new AppError('Say why the count is returned to the cashier.');
                if (self::openShift((int) $s['cashier_id'])) throw new AppError('The cashier already opened a new shift. Accept this one with a note instead.');
                Db::q("UPDATE canteen_shifts SET status='OPEN', closed_at=NULL, counted_cents=NULL, expected_cents=NULL, count_detail=NULL, review_note=? WHERE id=?", [$note, $shiftId]);
                Audit::log('canteen_shift_returned', 'canteen_shift', $shiftId, ['note' => $note]);
                return null;
            }
            $diff = (int) $s['counted_cents'] - (int) $s['expected_cents'];
            if ($diff !== 0 && mb_strlen($note) < 4) throw new AppError('The count is ₱' . Money::fmt(abs($diff)) . ($diff < 0 ? ' short' : ' over') . '. Add a note before accepting.');
            $journal = self::bookShift($shiftId, (int) $s['counted_cents'], (int) $s['opening_cents']);
            Db::q("UPDATE canteen_shifts SET status='ACCEPTED', accepted_by=?, accepted_at=NOW(), review_note=?, journal_id=? WHERE id=?", [Auth::id(), $note ?: null, $journal, $shiftId]);
            Audit::log('canteen_shift_accepted', 'canteen_shift', $shiftId, ['journal' => $journal, 'difference' => $diff, 'note' => $note]);
            return $journal;
        });
    }

    /**
     * The shift's journal entry (status REVIEW, for Finance to approve and post):
     * Dr Cash (counted − opening float) and Dr QR collections; Cr Canteen Sales (sales − refunds); short/over to Cash Short and Over.
     */
    private static function bookShift(int $shiftId, int $counted, int $opening): ?int
    {
        $t = self::shiftTotals($shiftId);
        $cashIn = $counted - $opening;
        $netSales = $t['net'];
        if ($netSales === 0 && $cashIn === 0 && $t['qr'] === 0) return null;
        $map = Accounting::map();
        foreach (['canteen:cash', 'canteen:qr', 'canteen:sales', 'canteen:overshort'] as $k) if (empty($map[$k])) throw new AppError('Accounting: no account is mapped for the Canteen (' . $k . '). Finance sets it in Accounting → Account Mappings.');
        $diff = $cashIn + $t['qr'] - $netSales; // positive = over
        $lines = [];
        if ($cashIn > 0) $lines[] = [$map['canteen:cash'], $cashIn, 0, 'Canteen cash'];
        if ($cashIn < 0) $lines[] = [$map['canteen:cash'], 0, -$cashIn, 'Canteen cash paid out'];
        if ($t['qr'] > 0) $lines[] = [$map['canteen:qr'], $t['qr'], 0, 'Canteen QR payments'];
        if ($netSales > 0) $lines[] = [$map['canteen:sales'], 0, $netSales, 'Canteen sales'];
        if ($netSales < 0) $lines[] = [$map['canteen:sales'], -$netSales, 0, 'Canteen refunds'];
        if ($diff > 0) $lines[] = [$map['canteen:overshort'], 0, $diff, 'Cash over'];
        if ($diff < 0) $lines[] = [$map['canteen:overshort'], -$diff, 0, 'Cash short'];
        $s = self::shift($shiftId);
        return Accounting::post(substr((string) $s['opened_at'], 0, 10), 'CANTEEN', $shiftId, null,
            'Canteen shift #' . $shiftId . ' · ' . ($s['cashier'] ?? '') . ' · ' . $t['count'] . ' sales', $lines, null, 'REVIEW');
    }

    public static function shifts(array $f = []): array
    {
        $w = []; $p = [];
        if (!empty($f['status'])) { $w[] = 's.status = ?'; $p[] = $f['status']; }
        if (!empty($f['cashier'])) { $w[] = 's.cashier_id = ?'; $p[] = (int) $f['cashier']; }
        if (!empty($f['from'])) { $w[] = 's.opened_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to'])) { $w[] = 's.opened_at < ?'; $p[] = date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'; }
        return Db::all('SELECT s.*, u.full_name AS cashier, j.entry_no, j.status AS journal_status FROM canteen_shifts s LEFT JOIN sis_users u ON u.id = s.cashier_id LEFT JOIN sis_journal j ON j.id = s.journal_id'
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY s.id DESC LIMIT 300', $p);
    }

    // ------------------------------------------------------------------ sales

    /**
     * Ring up a sale in the cashier's open shift. $items: [[product id, qty], …]. Cash needs the amount tendered;
     * QR payment is confirmed by the cashier (reference number optional). Returns the sale id.
     */
    public static function sell(array $items, string $method, int $tenderedCents, string $idem, ?string $reference = null, ?string $customer = null): int
    {
        Auth::require('canteen.sell');
        if (!preg_match('/^[a-f0-9]{32}$/', $idem)) throw new AppError('The order form expired. Reload the page and try again.');
        if (!isset(self::METHODS[$method])) throw new AppError('Choose Cash or QR payment.');
        $qty = [];
        foreach ($items as $it) {
            $pid = (int) ($it[0] ?? 0); $q = (int) ($it[1] ?? 0);
            if ($pid <= 0 || $q <= 0 || $q > 999) throw new AppError('Check the quantities in the order.');
            $qty[$pid] = ($qty[$pid] ?? 0) + $q;
        }
        if (!$qty) throw new AppError('Add at least one item to the order.');
        if (count($qty) > 100) throw new AppError('An order can have at most 100 different items.');
        return Db::tx(function () use ($qty, $method, $tenderedCents, $idem, $reference, $customer) {
            if ($done = Db::val('SELECT id FROM canteen_sales WHERE idempotency_key = ?', [$idem])) return (int) $done; // double submit
            $shift = Db::one("SELECT * FROM canteen_shifts WHERE cashier_id = ? AND status = 'OPEN' FOR UPDATE", [Auth::id()]);
            if (!$shift) throw new AppError('Open your shift before selling.');
            ksort($qty);
            $lines = []; $total = 0;
            foreach ($qty as $pid => $q) {
                $p = Db::one('SELECT * FROM canteen_products WHERE id = ? FOR UPDATE', [$pid]);
                if (!$p || !(int) $p['is_active']) throw new AppError('An item in the order is no longer sold. Remove it and try again.');
                if ((int) $p['track_stock'] && (int) $p['stock_qty'] < $q) throw new AppError($p['name'] . ': only ' . max(0, (int) $p['stock_qty']) . ' left.');
                $lines[] = [$p, $q]; $total += (int) $p['price_cents'] * $q;
            }
            $change = 0;
            if ($method === 'CASH') {
                if ($tenderedCents < $total) throw new AppError('The cash received (₱' . Money::fmt($tenderedCents) . ') is less than the total (₱' . Money::fmt($total) . ').');
                $change = $tenderedCents - $total;
            } else $tenderedCents = $total;
            $no = 'CN-' . str_pad((string) Seq::next('canteen_receipt'), 7, '0', STR_PAD_LEFT);
            $id = Db::insert('canteen_sales', ['receipt_no' => $no, 'idempotency_key' => $idem, 'shift_id' => (int) $shift['id'], 'cashier_id' => Auth::id(), 'method' => $method,
                'total_cents' => $total, 'tendered_cents' => $tenderedCents, 'change_cents' => $change,
                'reference_no' => $reference !== null && trim($reference) !== '' ? mb_substr(trim($reference), 0, 60) : null,
                'customer' => $customer !== null && trim($customer) !== '' ? mb_substr(trim($customer), 0, 100) : null]);
            foreach ($lines as [$p, $q]) {
                Db::insert('canteen_sale_items', ['sale_id' => $id, 'product_id' => (int) $p['id'], 'name' => $p['name'], 'price_cents' => (int) $p['price_cents'], 'cost_cents' => (int) $p['cost_cents'], 'qty' => $q]);
                if ((int) $p['track_stock']) self::move((int) $p['id'], 'SALE', -$q, 'sale', $id, $no);
            }
            return $id;
        });
    }

    public static function sale(int $id): ?array
    {
        $s = Db::one('SELECT s.*, u.full_name AS cashier FROM canteen_sales s LEFT JOIN sis_users u ON u.id = s.cashier_id WHERE s.id = ?', [$id]);
        if ($s) {
            $s['items'] = Db::all('SELECT * FROM canteen_sale_items WHERE sale_id = ? ORDER BY id', [$id]);
            $s['requests'] = Db::all('SELECT r.*, a.full_name AS requester, d.full_name AS decider FROM canteen_requests r LEFT JOIN sis_users a ON a.id = r.requested_by LEFT JOIN sis_users d ON d.id = r.decided_by WHERE r.sale_id = ? ORDER BY r.id', [$id]);
        }
        return $s;
    }

    public static function sales(array $f = [], int $limit = 300): array
    {
        $w = []; $p = [];
        if (!empty($f['shift'])) { $w[] = 's.shift_id = ?'; $p[] = (int) $f['shift']; }
        if (!empty($f['cashier'])) { $w[] = 's.cashier_id = ?'; $p[] = (int) $f['cashier']; }
        if (!empty($f['method']) && isset(self::METHODS[$f['method']])) { $w[] = 's.method = ?'; $p[] = $f['method']; }
        if (!empty($f['status'])) { $w[] = 's.status = ?'; $p[] = $f['status']; }
        if (!empty($f['from'])) { $w[] = 's.sold_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to'])) { $w[] = 's.sold_at < ?'; $p[] = date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'; }
        if (($f['q'] ?? '') !== '') { $w[] = '(s.receipt_no LIKE ? OR s.reference_no LIKE ? OR s.customer LIKE ?)'; array_push($p, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%'); }
        return Db::all('SELECT s.*, u.full_name AS cashier, (SELECT COUNT(*) FROM canteen_requests r WHERE r.sale_id = s.id AND r.status = \'PENDING\') AS pending
            FROM canteen_sales s LEFT JOIN sis_users u ON u.id = s.cashier_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY s.id DESC LIMIT ' . max(1, min(2000, $limit)), $p);
    }

    // ------------------------------------------------------------------ voids and refunds (supervisor approval)

    /**
     * A cashier asks to void a whole sale (only while its shift is open) or to refund some items (paid back in cash
     * from the requester's open shift). Returns the request id.
     */
    public static function request(int $saleId, string $kind, array $refundQty, string $reason): int
    {
        Auth::require('canteen.sell', 'canteen.supervise');
        if (!in_array($kind, ['VOID', 'REFUND'], true)) throw new AppError('Choose Void or Refund.');
        $reason = trim($reason);
        if (mb_strlen($reason) < 8) throw new AppError('Give a reason of at least 8 characters.');
        return Db::tx(function () use ($saleId, $kind, $refundQty, $reason) {
            $s = Db::one('SELECT * FROM canteen_sales WHERE id = ? FOR UPDATE', [$saleId]);
            if (!$s || $s['status'] !== 'POSTED') throw new AppError('Only a posted sale can be voided or refunded.');
            if (Db::val("SELECT 1 FROM canteen_requests WHERE sale_id = ? AND status = 'PENDING'", [$saleId])) throw new AppError('This sale already has a request waiting for the supervisor.');
            $items = []; $amount = 0; $shiftId = null;
            if ($kind === 'VOID') {
                if ((int) $s['refunded_cents'] > 0) throw new AppError('Part of this sale was refunded; refund the rest instead of voiding.');
                if (Db::val('SELECT status FROM canteen_shifts WHERE id = ?', [(int) $s['shift_id']]) !== 'OPEN') throw new AppError('The shift of this sale is closed. Ask for a refund instead.');
                $amount = (int) $s['total_cents']; $shiftId = (int) $s['shift_id'];
            } else {
                $open = self::openShift();
                if (!$open) throw new AppError('Open your shift first: the refund is paid from your drawer.');
                $shiftId = (int) $open['id'];
                foreach (Db::all('SELECT * FROM canteen_sale_items WHERE sale_id = ?', [$saleId]) as $it) {
                    $q = (int) ($refundQty[$it['id']] ?? 0);
                    if ($q < 0 || $q > (int) $it['qty'] - (int) $it['refunded_qty']) throw new AppError($it['name'] . ': you can refund at most ' . ((int) $it['qty'] - (int) $it['refunded_qty']) . '.');
                    if ($q > 0) { $items[(int) $it['id']] = $q; $amount += $q * (int) $it['price_cents']; }
                }
                if (!$items) throw new AppError('Choose the items to refund.');
            }
            $id = Db::insert('canteen_requests', ['kind' => $kind, 'sale_id' => $saleId, 'items' => json_encode($items, JSON_THROW_ON_ERROR), 'amount_cents' => $amount,
                'reason' => mb_substr($reason, 0, 255), 'requested_by' => Auth::id(), 'shift_id' => $shiftId]);
            Audit::log('canteen_' . strtolower($kind) . '_requested', 'canteen_sale', $saleId, ['request' => $id, 'amount' => $amount, 'reason' => $reason]);
            return $id;
        });
    }

    public static function decide(int $requestId, bool $approve, string $note): void
    {
        Auth::require('canteen.supervise');
        Db::tx(function () use ($requestId, $approve, $note) {
            $r = Db::one('SELECT * FROM canteen_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if (!$r || $r['status'] !== 'PENDING') throw new AppError('This request was already decided.');
            if ((int) $r['requested_by'] === (int) Auth::id() && !Auth::can('users.manage')) throw new Forbidden('Another supervisor must decide your own request.');
            $note = mb_substr(trim($note), 0, 255);
            if (!$approve) {
                if (mb_strlen($note) < 4) throw new AppError('Say why the request is rejected.');
                Db::q("UPDATE canteen_requests SET status='REJECTED', decided_by=?, decided_at=NOW(), decision_note=? WHERE id=?", [Auth::id(), $note, $requestId]);
                Audit::log('canteen_request_rejected', 'canteen_sale', (int) $r['sale_id'], ['request' => $requestId, 'note' => $note]);
                return;
            }
            $s = Db::one('SELECT * FROM canteen_sales WHERE id = ? FOR UPDATE', [(int) $r['sale_id']]);
            if (!$s || $s['status'] !== 'POSTED') throw new AppError('The sale is no longer posted.');
            if (Db::val('SELECT status FROM canteen_shifts WHERE id = ?', [(int) $r['shift_id']]) !== 'OPEN') throw new AppError('The shift this would change is already closed. Reject the request; the cashier can ask again from an open shift.');
            if ($r['kind'] === 'VOID') {
                Db::q("UPDATE canteen_sales SET status='VOIDED', voided_at=NOW(), voided_by=?, void_reason=? WHERE id=?", [Auth::id(), $r['reason'], (int) $s['id']]);
                foreach (Db::all('SELECT i.*, p.track_stock FROM canteen_sale_items i JOIN canteen_products p ON p.id = i.product_id WHERE i.sale_id = ?', [(int) $s['id']]) as $it)
                    if ((int) $it['track_stock']) self::move((int) $it['product_id'], 'VOID', (int) $it['qty'], 'sale', (int) $s['id'], 'Void ' . $s['receipt_no']);
            } else {
                $items = (array) json_decode((string) $r['items'], true);
                foreach ($items as $itemId => $q) {
                    $it = Db::one('SELECT i.*, p.track_stock FROM canteen_sale_items i JOIN canteen_products p ON p.id = i.product_id WHERE i.id = ? AND i.sale_id = ? FOR UPDATE', [(int) $itemId, (int) $s['id']]);
                    if (!$it || (int) $q > (int) $it['qty'] - (int) $it['refunded_qty']) throw new AppError('The refund no longer matches the sale.');
                    Db::q('UPDATE canteen_sale_items SET refunded_qty = refunded_qty + ? WHERE id = ?', [(int) $q, (int) $itemId]);
                    if ((int) $it['track_stock']) self::move((int) $it['product_id'], 'REFUND', (int) $q, 'sale', (int) $s['id'], 'Refund ' . $s['receipt_no']);
                }
                Db::q('UPDATE canteen_sales SET refunded_cents = refunded_cents + ? WHERE id = ?', [(int) $r['amount_cents'], (int) $s['id']]);
            }
            Db::q("UPDATE canteen_requests SET status='APPROVED', decided_by=?, decided_at=NOW(), decision_note=? WHERE id=?", [Auth::id(), $note ?: null, $requestId]);
            Audit::log('canteen_' . strtolower($r['kind']) . '_approved', 'canteen_sale', (int) $s['id'], ['request' => $requestId, 'amount' => (int) $r['amount_cents'], 'note' => $note]);
        });
    }

    public static function pendingRequests(): array
    {
        return Db::all("SELECT r.*, s.receipt_no, s.total_cents, s.sold_at, s.method, a.full_name AS requester FROM canteen_requests r JOIN canteen_sales s ON s.id = r.sale_id
            LEFT JOIN sis_users a ON a.id = r.requested_by WHERE r.status = 'PENDING' ORDER BY r.id");
    }

    // ------------------------------------------------------------------ reports

    /** Net sales (posted sales − approved refunds) between two dates, for Finance → Collections by source. */
    public static function netSales(string $from, string $toExclusive): array
    {
        if (!Db::val("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'canteen_requests'")) return [0, 0]; // not installed yet
        $sales = Db::one("SELECT COALESCE(SUM(total_cents),0) t, COUNT(*) n FROM canteen_sales WHERE status='POSTED' AND sold_at >= ? AND sold_at < ?", [$from, $toExclusive]);
        $ref = (int) Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM canteen_requests WHERE kind='REFUND' AND status='APPROVED' AND decided_at >= ? AND decided_at < ?", [$from, $toExclusive]);
        return [(int) $sales['t'] - $ref, (int) $sales['n']];
    }

    public static function report(string $from, string $to): array
    {
        $a = $from . ' 00:00:00'; $b = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
        $byDay = Db::all("SELECT DATE(sold_at) d, COUNT(*) n, SUM(total_cents) t, SUM(IF(method='CASH',total_cents,0)) cash, SUM(IF(method='QR',total_cents,0)) qr
            FROM canteen_sales WHERE status='POSTED' AND sold_at >= ? AND sold_at < ? GROUP BY DATE(sold_at) ORDER BY d", [$a, $b]);
        $refundsByDay = array_column(Db::all("SELECT DATE(decided_at) d, SUM(amount_cents) t FROM canteen_requests WHERE kind='REFUND' AND status='APPROVED' AND decided_at >= ? AND decided_at < ? GROUP BY DATE(decided_at)", [$a, $b]), 't', 'd');
        $byProduct = Db::all("SELECT i.product_id, i.name, SUM(i.qty - i.refunded_qty) qty, SUM((i.qty - i.refunded_qty) * i.price_cents) t, SUM((i.qty - i.refunded_qty) * i.cost_cents) cost
            FROM canteen_sale_items i JOIN canteen_sales s ON s.id = i.sale_id WHERE s.status='POSTED' AND s.sold_at >= ? AND s.sold_at < ? GROUP BY i.product_id, i.name ORDER BY t DESC", [$a, $b]);
        $byCashier = Db::all("SELECT u.full_name cashier, COUNT(*) n, SUM(s.total_cents) t FROM canteen_sales s LEFT JOIN sis_users u ON u.id = s.cashier_id
            WHERE s.status='POSTED' AND s.sold_at >= ? AND s.sold_at < ? GROUP BY s.cashier_id, u.full_name ORDER BY t DESC", [$a, $b]);
        $voided = Db::one("SELECT COUNT(*) n, COALESCE(SUM(total_cents),0) t FROM canteen_sales WHERE status='VOIDED' AND sold_at >= ? AND sold_at < ?", [$a, $b]);
        $gross = array_sum(array_map(fn($r) => (int) $r['t'], $byDay)); $refunds = array_sum(array_map('intval', $refundsByDay));
        return ['from' => $from, 'to' => $to, 'byDay' => $byDay, 'refundsByDay' => $refundsByDay, 'byProduct' => $byProduct, 'byCashier' => $byCashier,
            'gross' => $gross, 'refunds' => $refunds, 'net' => $gross - $refunds, 'count' => array_sum(array_map(fn($r) => (int) $r['n'], $byDay)),
            'cost' => array_sum(array_map(fn($r) => (int) $r['cost'], $byProduct)), 'voided' => $voided];
    }
}
