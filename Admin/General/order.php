<?php
require_once 'config.php';

$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Staff Management & HR Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria','company_tagline'=>'POVERTY ERADICATION • REDUCE INEQUALITY • LEAVE NO ONE BEHIND'];

/* ===== ENSURE ORDER COLUMNS EXIST ===== */
try {
    $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('order_type', $cols)) $pdo->exec("ALTER TABLE orders ADD COLUMN order_type VARCHAR(10) DEFAULT 'outbound' AFTER order_no");
    if (!in_array('driver_id', $cols)) $pdo->exec("ALTER TABLE orders ADD COLUMN driver_id INT DEFAULT NULL AFTER created_by");
    if (!in_array('truck_price', $cols)) $pdo->exec("ALTER TABLE orders ADD COLUMN truck_price DECIMAL(12,2) DEFAULT NULL AFTER total_amount");
    if (!in_array('departure_location', $cols)) $pdo->exec("ALTER TABLE orders ADD COLUMN departure_location VARCHAR(150) DEFAULT NULL AFTER truck_price");
    if (!in_array('destination_location', $cols)) $pdo->exec("ALTER TABLE orders ADD COLUMN destination_location VARCHAR(150) DEFAULT NULL AFTER departure_location");
} catch (Exception $ignore) {}

try {
    $pcols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('stock_kg', $pcols)) $pdo->exec("ALTER TABLE products ADD COLUMN stock_kg DECIMAL(12,2) DEFAULT 0 AFTER default_price");
} catch (Exception $ignore) {}

/* ===== FETCH CLIENTS WITH BANK INFO ===== */
$clientsData = [];
try {
    $rows = $pdo->query("SELECT id, name, email, phone, bank_name, bank_account_no, bank_account_name, bank_tin FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $clientsData[] = ['id'=>(int)$r['id'],'name'=>$r['name'],'email'=>$r['email']??'','phone'=>$r['phone']??'','bank'=>$r['bank_name']??'','acc_no'=>$r['bank_account_no']??'','acc_name'=>$r['bank_account_name']??'','bank_code'=>$r['bank_tin']??''];
    }
} catch (Exception $ignore) {}

/* ===== FETCH DRIVERS ===== */
$driversData = [];
try {
    $rows = $pdo->query("SELECT id, full_name, vehicle_plate, phone FROM drivers WHERE status='Active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $driversData[] = ['id'=>(int)$r['id'],'name'=>$r['full_name'],'plate'=>$r['vehicle_plate']??'','phone'=>$r['phone']??''];
    }
} catch (Exception $ignore) {}

/* ===== FETCH ACTIVE PRODUCTS WITH IMAGES ===== */
$productsData = [];
$productStock = [];
try {
    $rows = $pdo->query("SELECT id, code, name, stock_kg, default_price, image_url FROM products WHERE is_active=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $productsData[] = ['code'=>$r['code'],'name'=>$r['name'],'stock'=>(float)$r['stock_kg'],'price'=>(float)$r['default_price'],'img'=>$r['image_url']??''];
        $productStock[$r['code']] = ['name'=>$r['name'],'stock'=>(float)$r['stock_kg'],'price'=>(float)$r['default_price'],'img'=>$r['image_url']??''];
    }
} catch (Exception $ignore) {}

/* ===== HANDLE ORDER SUBMISSION ===== */
$submitFlash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order'])) {
    $kg   = floatval($_POST['kilograms'] ?? 0);
    $up   = floatval($_POST['unit_price'] ?? 0);
    $tot  = $kg * $up;
    $date = $_POST['order_date'] ?? date('Y-m-d');
    $t    = str_replace('-', '', $date);
    $orderType = $_POST['order_type'] ?? 'outbound';
    $driverId = !empty($_POST['driver_id']) ? (int)$_POST['driver_id'] : null;
    $truckPrice = floatval($_POST['truck_price'] ?? 0);
    $departLoc = trim($_POST['departure_location'] ?? '');
    $destLoc = trim($_POST['destination_location'] ?? '');

    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE order_date = ?");
        $st->execute([$date]);
        $seq = (int)$st->fetchColumn() + 1;
    } catch (Exception $e) { $seq = 1; }
    $orderNo = 'OF-' . $t . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);

    $clientName  = trim($_POST['client_name'] ?? '');
    $clientEmail = trim($_POST['email'] ?? '');
    $clientPhone = trim($_POST['phone'] ?? '');
    $clientId = null;
    try {
        $st = $pdo->prepare("SELECT id FROM clients WHERE name = ? LIMIT 1");
        $st->execute([$clientName]);
        $row = $st->fetch();
        if ($row) { $clientId = (int)$row['id']; }
        else {
            $pdo->prepare("INSERT INTO clients (name, email, phone, created_by) VALUES (?,?,?,?)")
                ->execute([$clientName, $clientEmail ?: null, $clientPhone ?: null, $auth['id']]);
            $clientId = (int)$pdo->lastInsertId();
        }
    } catch (Exception $e) {
        try { $pdo->prepare("INSERT INTO clients (name) VALUES (?)")->execute([$clientName ?: 'Walk-in Client']); $clientId = (int)$pdo->lastInsertId(); }
        catch (Exception $e2) { $clientId = 1; }
    }

    $prodInput = $_POST['product'] ?? '';
    $productId = null;
    try {
        $st = $pdo->prepare("SELECT id, stock_kg FROM products WHERE code = ? LIMIT 1");
        $st->execute([$prodInput]);
        $pr = $st->fetch();
        if ($pr) { $productId = (int)$pr['id']; }
        else {
            $st = $pdo->prepare("SELECT id, stock_kg FROM products WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $st->execute([$prodInput]);
            $pr = $st->fetch();
            if ($pr) $productId = (int)$pr['id'];
        }
    } catch (Exception $ignore) {}
    if (!$productId) {
        try { $st = $pdo->query("SELECT id FROM products LIMIT 1"); $pr = $st->fetch(); if ($pr) $productId = (int)$pr['id']; } catch (Exception $ignore) {}
    }
    if (!$productId) $productId = 1;

    if ($orderType === 'outbound' && $productId) {
        try {
            $currentStock = (float)$pdo->query("SELECT IFNULL(stock_kg,0) FROM products WHERE id=".(int)$productId)->fetchColumn();
            if ($kg > $currentStock) {
                flash('error', "Insufficient stock. Only ".number_format($currentStock,2)." kg available.");
                header('Location: order.php'); exit;
            }
        } catch (Exception $ignore) {}
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO orders (order_no, order_type, client_id, product_id, order_date, description, kilograms, unit_price, total_amount, truck_price, departure_location, destination_location, status, is_paid, created_by, driver_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $result = $stmt->execute([$orderNo, $orderType, $clientId, $productId, $date, trim($_POST['description'] ?? ''), $kg, $up, $tot, $truckPrice ?: null, $departLoc ?: null, $destLoc ?: null, $_POST['status'] ?? 'Pending', isset($_POST['is_paid']) ? 1 : 0, $auth['id'], $driverId]);
        if (!$result) throw new Exception("INSERT failed: " . implode(' ', $stmt->errorInfo()));
        $newOrderId = (int)$pdo->lastInsertId();

        if ($productId && $kg > 0) {
            try {
                if ($orderType === 'outbound') {
                    $pdo->prepare("UPDATE products SET stock_kg = GREATEST(stock_kg - ?, 0) WHERE id = ?")->execute([$kg, $productId]);
                } elseif ($orderType === 'inbound') {
                    $pdo->prepare("UPDATE products SET stock_kg = stock_kg + ? WHERE id = ?")->execute([$kg, $productId]);
                }
            } catch (Exception $stockEx) { error_log("Stock update failed: " . $stockEx->getMessage()); }
        }

        $bank = trim($_POST['bank'] ?? ''); $accNo = trim($_POST['account_no'] ?? ''); $accName = trim($_POST['account_name'] ?? '');
        if ($bank || $accNo || $accName) {
            try { $pdo->prepare("INSERT INTO payments (order_id, bank_name, account_no, account_name, amount, status) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$newOrderId, $bank ?: null, $accNo ?: null, $accName ?: null, $tot, isset($_POST['is_paid']) ? 'Paid' : 'Unpaid']); }
            catch (Exception $payEx) { error_log("Payment insert failed: " . $payEx->getMessage()); }
            try {
                $pdo->prepare("UPDATE clients SET bank_name=COALESCE(NULLIF(?,''),bank_name), bank_account_no=COALESCE(NULLIF(?,''),bank_account_no), bank_account_name=COALESCE(NULLIF(?,''),bank_account_name), bank_tin=COALESCE(NULLIF(?,''),bank_tin) WHERE id=?")
                    ->execute([$bank, $accNo, $accName, trim($_POST['bank_code'] ?? ''), $clientId]);
            } catch (Exception $ignore) {}
        }

        $sigClient = $_POST['sig_client_data'] ?? null; $sigAdmin = $_POST['sig_admin_data'] ?? null;
        if ($sigClient || $sigAdmin) {
            try { $pdo->prepare("INSERT INTO order_signatures (order_id, client_signature, admin_signature, signed_at) VALUES (?, ?, ?, NOW())")
                ->execute([$newOrderId, $sigClient, $sigAdmin]); }
            catch (Exception $sigEx) { error_log("Signature insert failed: " . $sigEx->getMessage()); }
        }

        try { $pdo->prepare("INSERT INTO activity_logs (user_id, action, entity_type, entity_id, ip_address, details) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$auth['id'], 'CREATE_ORDER', 'order', $newOrderId, $_SERVER['REMOTE_ADDR'] ?? '', "$orderNo ($orderType, ".number_format($kg,2)."kg)"]); }
        catch (Exception $ignore) {}

        $stockMsg = $orderType === 'outbound' ? " Stock -".number_format($kg,2)."kg." : " Stock +".number_format($kg,2)."kg.";
        $submitFlash = ['type' => 'success', 'msg' => 'Order ' . $orderNo . ' saved!' . $stockMsg, 'order_no' => $orderNo];
    } catch (Exception $e) {
        error_log("ORDER CREATE FAILED: " . $e->getMessage());
        $submitFlash = ['type' => 'error', 'msg' => 'Database error: ' . $e->getMessage()];
    }
}

try {
    $todayOrders  = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_date = CURDATE()")->fetchColumn();
    $totalRevenue = (float)$pdo->query("SELECT IFNULL(SUM(amount),0) FROM payments WHERE status='Paid'")->fetchColumn();
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='Pending'")->fetchColumn();
    $deliveredCnt = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='Delivered'")->fetchColumn();
} catch (Exception $e) { $todayOrders = $totalRevenue = $pendingCount = $deliveredCnt = 0; }

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtN($n): string { return '₦' . number_format((float)$n, 2); }
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
<title>Client Order Form | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f8fafc;--surface:#ffffff;--surface2:#f1f5f9;--ink:#0f172a;--ink2:#64748b;--line:#e2e8f0;--green:#059669;--green2:#047857;--green-soft:#ecfdf5;--gold:#d97706;--gold2:#b45309;--gold-soft:#fffbeb;--red:#dc2626;--red-soft:#fef2f2;--blue:#2563eb;--blue-soft:#eff6ff;--purple:#7c3aed;--purple-soft:#f5f3ff;--orange:#ea580c;--orange-soft:#fff7ed;--shadow:0 1px 3px rgba(0,0,0,.06),0 8px 24px rgba(0,0,0,.06);--shadow-sm:0 1px 2px rgba(0,0,0,.04);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:14px}
[data-theme="dark"]{--bg:#0c1222;--surface:#1a2332;--surface2:#243044;--ink:#f1f5f9;--ink2:#94a3b8;--line:#2d3f56;--green:#34d399;--green2:#10b981;--green-soft:#064e3b;--gold:#fbbf24;--gold2:#f59e0b;--gold-soft:#451a03;--red:#f87171;--red-soft:#450a0a;--blue:#60a5fa;--blue-soft:#1e3a5f;--purple:#a78bfa;--purple-soft:#2e1065;--orange:#fb923c;--orange-soft:#431407;--shadow:0 1px 3px rgba(0,0,0,.3),0 8px 24px rgba(0,0,0,.3);--shadow-sm:0 1px 2px rgba(0,0,0,.2)}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s;-webkit-font-smoothing:antialiased}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(700px 420px at 88% -12%,color-mix(in srgb,var(--green) 12%,transparent),transparent 62%),radial-gradient(560px 380px at -12% 34%,color-mix(in srgb,var(--gold) 10%,transparent),transparent 60%)}
.container{max-width:1280px;margin:0 auto;padding:0 20px}.mono{font-family:var(--fm)}svg{flex:none}button{font-family:inherit;cursor:pointer}input,select,textarea{font-family:inherit;font-size:15px;color:var(--ink)}::selection{background:color-mix(in srgb,var(--green) 25%,transparent)}.icon-svg{stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 85%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--line)}.topbar .container{display:flex;align-items:center;gap:12px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:10px;margin-right:auto}.logo-chip{width:38px;height:38px;border-radius:10px;background:var(--surface);display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line)}.logo-chip img{width:32px;height:32px;object-fit:contain}.brand strong{font-family:var(--fd);font-size:13px;font-weight:800;display:block;line-height:1.2}.brand small{color:var(--ink2);font-size:10px;font-weight:600}.back-link{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:var(--ink2);padding:6px 12px;border-radius:8px;border:1px solid var(--line);background:var(--surface);transition:.2s}.back-link:hover{color:var(--green);border-color:var(--green)}.back-link svg{width:13px;height:13px}.badge{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:800;letter-spacing:.8px;padding:5px 9px;border-radius:99px}.badge.live{background:var(--green-soft);color:var(--green)}.badge.secure{background:var(--gold-soft);color:var(--gold2)}.theme-btn{width:34px;height:34px;border-radius:9px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(18deg)}.theme-btn svg{width:15px;height:15px}
.hero{display:flex;align-items:center;gap:20px;padding:24px 0 6px;flex-wrap:wrap}.hero-left{flex:1;min-width:280px}.eyebrow{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:10px}.eyebrow span{font-size:8px;font-weight:800;letter-spacing:1.2px;color:var(--gold2);background:var(--gold-soft);border:1px solid color-mix(in srgb,var(--gold) 30%,transparent);padding:3px 9px;border-radius:99px;text-transform:uppercase}.hero h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,32px);line-height:1.15;letter-spacing:-.3px}.hero h1 em{font-style:normal;color:var(--green)}.hero .sub{color:var(--ink2);font-weight:600;margin-top:4px;font-size:13px}.addr{display:inline-flex;align-items:center;gap:5px;margin-top:10px;font-size:11px;font-weight:700;color:var(--ink2);background:var(--surface);border:1px dashed var(--line);padding:5px 11px;border-radius:99px}.clock-card{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;border-radius:var(--r);padding:16px 22px;box-shadow:var(--shadow);min-width:220px;position:relative;overflow:hidden}.clock-card::after{content:'';position:absolute;right:-15px;bottom:-15px;width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,.08)}.cc-label{font-size:8px;font-weight:800;letter-spacing:1.5px;opacity:.7;text-transform:uppercase}.clock-card strong{display:block;font-family:var(--fm);font-weight:700;font-size:24px;letter-spacing:1px;margin:3px 0 2px}.clock-card span.dt{font-size:10.5px;font-weight:700;opacity:.9}.cc-wat{display:block;font-size:8px;opacity:.5;margin-top:3px;letter-spacing:.8px}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;padding-top:16px}.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:12px 14px;display:flex;gap:10px;align-items:center;box-shadow:var(--shadow-sm);transition:transform .25s,box-shadow .25s}.stat:hover{transform:translateY(-2px);box-shadow:var(--shadow)}.stat .ic{width:36px;height:36px;border-radius:9px;display:grid;place-items:center;flex:none}.stat .ic svg{width:16px;height:16px}.stat .ic.g{background:var(--green-soft);color:var(--green)}.stat .ic.y{background:var(--gold-soft);color:var(--gold2)}.stat .ic.b{background:var(--blue-soft);color:var(--blue)}.stat .ic.r{background:var(--red-soft);color:var(--red)}.stat b{font-family:var(--fd);font-size:18px;font-weight:800;display:block;line-height:1.15}.stat span{font-size:9.5px;font-weight:700;color:var(--ink2);letter-spacing:.4px;text-transform:uppercase}
.layout{display:grid;grid-template-columns:1.55fr 1fr;gap:16px;align-items:start;padding-top:16px;padding-bottom:100px}.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}form .card{margin-bottom:12px}.step-h{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--line)}.step-no{font-family:var(--fm);font-weight:700;font-size:10px;color:var(--green);background:var(--green-soft);border:1px solid color-mix(in srgb,var(--green) 25%,transparent);width:32px;height:32px;border-radius:8px;display:grid;place-items:center;flex:none}.step-h h3{font-family:var(--fd);font-size:13px;font-weight:800}.step-h p{font-size:10px;color:var(--ink2);font-weight:600}.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:14px 18px}.span2{grid-column:1/-1}.flabel{display:block;font-size:9px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--ink2);margin-bottom:4px}.flabel b{color:var(--red)}
.control{display:flex;align-items:center;gap:7px;background:var(--surface2);border:1.5px solid var(--line);border-radius:10px;padding:0 11px;height:44px;transition:all .2s;position:relative}.control:focus-within{border-color:var(--green);box-shadow:0 0 0 3px color-mix(in srgb,var(--green) 10%,transparent);background:var(--surface)}.control.invalid{border-color:var(--red)!important;animation:shake .3s}@keyframes shake{25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}.control svg{color:var(--ink2);flex:none;width:15px;height:15px;transition:stroke .2s}.control:focus-within svg{stroke:var(--green)}.control input,.control select,.control textarea{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%;font-size:13px}.control select{cursor:pointer;appearance:none}.control textarea{padding:8px 0;height:auto;min-height:44px;resize:vertical}.control.ta{height:auto;align-items:flex-start;padding-top:2px}
.type-toggle{display:grid;grid-template-columns:1fr 1fr;gap:4px;background:var(--surface2);border:1.5px solid var(--line);border-radius:10px;padding:4px;margin-bottom:12px}.type-toggle label input{display:none}.type-toggle span{display:flex;align-items:center;justify-content:center;gap:6px;padding:10px 8px;border-radius:8px;font-weight:800;font-size:11px;color:var(--ink2);cursor:pointer;transition:all .2s;border:1px solid transparent}.type-toggle span svg{width:14px;height:14px}.type-toggle label:has(input[value="inbound"]:checked) span{background:var(--blue-soft);color:var(--blue);border-color:color-mix(in srgb,var(--blue) 20%,transparent)}.type-toggle label:has(input[value="outbound"]:checked) span{background:var(--orange-soft);color:var(--orange);border-color:color-mix(in srgb,var(--orange) 20%,transparent)}
.ac-dropdown{position:absolute;top:100%;left:0;right:0;z-index:60;background:var(--surface);border:1px solid var(--line);border-radius:10px;box-shadow:var(--shadow);margin-top:4px;max-height:200px;overflow-y:auto;display:none}.ac-dropdown.open{display:block}.ac-item{display:flex;align-items:center;gap:8px;padding:9px 12px;cursor:pointer;transition:.15s;border-bottom:1px solid var(--line)}.ac-item:last-child{border-bottom:none}.ac-item:hover,.ac-item.active{background:var(--green-soft)}.ac-item .ac-avatar{width:28px;height:28px;border-radius:50%;background:var(--green-soft);color:var(--green);display:grid;place-items:center;font-family:var(--fd);font-size:10px;font-weight:800;flex:none}.ac-item .ac-info{flex:1;min-width:0}.ac-item .ac-name{font-size:12px;font-weight:700;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ac-item .ac-sub{font-size:10px;color:var(--ink2);font-weight:600;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ac-item .ac-badge{font-size:8px;font-weight:800;padding:2px 7px;border-radius:99px;background:var(--green-soft);color:var(--green);flex:none}.ac-empty{padding:12px;text-align:center;font-size:11px;color:var(--ink2);font-weight:600}
.chips{display:flex;gap:5px;margin-top:5px;flex-wrap:wrap}.chip{font-size:10px;font-weight:800;padding:6px 12px;border-radius:99px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);transition:.15s;cursor:pointer}.chip:hover{border-color:var(--green);color:var(--green);background:var(--green-soft)}.total-box{display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,var(--gold-soft),color-mix(in srgb,var(--gold) 20%,var(--surface)));border:1.5px dashed var(--gold);border-radius:10px;padding:9px 14px;height:44px}.total-box span{font-size:9px;font-weight:800;letter-spacing:.8px;color:var(--gold2);text-transform:uppercase}.total-box strong{font-family:var(--fm);font-weight:700;font-size:15px;color:var(--gold2)}
.side{position:sticky;top:78px;display:flex;flex-direction:column;gap:12px}.pv-head{display:flex;align-items:center;gap:7px;padding:12px 16px;border-bottom:1px solid var(--line);font-family:var(--fd);font-weight:700;font-size:12px}.pv-dot{width:7px;height:7px;border-radius:50%;background:var(--green);animation:pulse 1.4s infinite}@keyframes pulse{0%{box-shadow:0 0 0 0 color-mix(in srgb,var(--green) 45%,transparent)}70%{box-shadow:0 0 0 6px transparent}100%{box-shadow:0 0 0 0 transparent}}.preview .pv-body{padding:14px}.pv-prod{display:flex;align-items:center;gap:10px;margin-bottom:12px}.pv-prod img{width:46px;height:46px;border-radius:10px;object-fit:cover;box-shadow:var(--shadow-sm);border:1px solid var(--line)}.pv-prod strong{font-family:var(--fd);font-size:14px;font-weight:800;display:block}.pv-prod span{font-size:10px;color:var(--ink2);font-weight:700}.pv-rows{display:grid;grid-template-columns:1fr 1fr;gap:6px}.pv-rows>div{background:var(--surface2);border:1px solid var(--line);border-radius:9px;padding:8px 10px}.pv-rows span{font-size:8.5px;font-weight:800;letter-spacing:.7px;color:var(--ink2);text-transform:uppercase;display:block}.pv-rows strong{font-family:var(--fm);font-size:13px;font-weight:700}.pv-total{margin-top:8px;background:linear-gradient(135deg,var(--green),var(--green2));border-radius:10px;padding:10px 14px;color:#fff;display:flex;justify-content:space-between;align-items:center}.pv-total span{font-size:8.5px;font-weight:800;letter-spacing:1px;opacity:.85;text-transform:uppercase}.pv-total strong{font-family:var(--fm);font-size:17px;font-weight:700}.pv-bar{height:5px;border-radius:99px;background:var(--line);margin-top:10px;overflow:hidden}.pv-bar div{height:100%;width:0%;border-radius:99px;background:linear-gradient(90deg,var(--green),var(--gold));transition:width .5s cubic-bezier(.2,.8,.3,1)}.pv-meta{display:flex;justify-content:space-between;margin-top:5px;font-size:9.5px;font-weight:800;color:var(--ink2)}
.pv-stock{margin-top:8px;font-size:10px;font-weight:700;padding:8px 10px;background:var(--surface2);border:1px solid var(--line);border-radius:8px}
.seg{display:grid;grid-template-columns:repeat(3,1fr);gap:3px;background:var(--surface2);border:1px solid var(--line);border-radius:9px;padding:3px;margin:10px 14px 8px}.seg label input{display:none}.seg span{display:flex;align-items:center;justify-content:center;gap:4px;padding:7px 3px;border-radius:7px;font-weight:800;font-size:10px;color:var(--ink2);cursor:pointer;transition:.2s;border:1px solid transparent}.seg span:hover{color:var(--ink)}.seg label:has(input:checked) span{background:var(--surface);box-shadow:var(--shadow-sm);color:var(--ink);border-color:var(--line)}.seg label:has(input[value="Pending"]:checked) span{color:var(--gold2)}.seg label:has(input[value="Approved"]:checked) span{color:var(--blue)}.seg label:has(input[value="Delivered"]:checked) span{color:var(--green)}.paid-toggle{display:flex;align-items:center;gap:8px;margin:0 14px 12px;font-size:11px;font-weight:800;cursor:pointer;user-select:none}.paid-toggle input{position:absolute;opacity:0;z-index:2;inset:0;cursor:pointer;width:36px;height:20px}.paid-toggle .trk{position:relative;display:inline-block;width:36px;height:20px;border-radius:99px;background:var(--line2);transition:.25s;flex:none}.paid-toggle .trk::after{content:'';position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:var(--shadow-sm);transition:.25s}.paid-toggle input:checked~.trk{background:var(--green)}.paid-toggle input:checked~.trk::after{left:18px}
.sig-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px 14px 5px}.sig{border:1.5px dashed var(--line);border-radius:9px;overflow:hidden;background:var(--surface2)}.sig canvas{width:100%;height:80px;display:block;cursor:crosshair;background:#fff;touch-action:none}.sig-foot{display:flex;justify-content:space-between;align-items:center;padding:5px 9px;border-top:1px solid var(--line)}.sig-foot span{font-size:8.5px;font-weight:800;letter-spacing:.5px;color:var(--ink2);text-transform:uppercase}.mini{font-size:9px;font-weight:800;border:none;background:var(--red-soft);color:var(--red);padding:4px 9px;border-radius:99px;transition:.15s;cursor:pointer}.mini:hover{filter:brightness(.92)}.seal-row{display:flex;align-items:center;gap:12px;padding:8px 14px 14px}.seal{width:72px;height:72px;border-radius:50%;border:2.5px dashed var(--line);display:grid;place-items:center;transition:.4s;flex:none;opacity:.5}.seal-inner{text-align:center;transform:rotate(0deg);transition:.4s}.seal-inner span{display:block;font-size:7px;font-weight:800;letter-spacing:1.2px;color:var(--ink2)}.seal-inner strong{display:block;font-family:var(--fd);font-size:8.5px;font-weight:800;color:var(--ink2)}.seal-inner em{display:block;font-style:normal;font-size:6px;color:var(--ink2);letter-spacing:1px}.seal.stamped{border-style:solid;border-color:var(--green);opacity:1;box-shadow:0 0 0 3px color-mix(in srgb,var(--green) 15%,transparent)}.seal.stamped .seal-inner{transform:rotate(-12deg)}.seal.stamped span,.seal.stamped strong,.seal.stamped em{color:var(--green)}.seal-note{font-size:9.5px;color:var(--ink2);font-weight:600}
.actions{display:grid;grid-template-columns:1fr 1fr;gap:6px;padding:0 14px 14px}.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:none;border-radius:10px;font-weight:800;font-size:12px;padding:0 14px;height:38px;transition:all .2s;cursor:pointer;-webkit-tap-highlight-color:transparent}.btn:active{transform:scale(.97)}.btn.ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}.btn.ghost:hover{border-color:var(--green);color:var(--green)}.btn-primary{grid-column:1/-1;background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;font-size:14px;padding:14px;box-shadow:0 4px 16px color-mix(in srgb,var(--green) 25%,transparent);position:relative;overflow:hidden}.btn.primary::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.12),transparent);transition:.5s}.btn.primary:hover::before{left:100%}.btn.primary:hover{transform:translateY(-2px);box-shadow:0 6px 20px color-mix(in srgb,var(--green) 30%,transparent)}.btn svg{width:14px;height:14px}
footer{margin-top:24px;border-top:1px solid var(--line);padding:16px 20px 24px;text-align:center;color:var(--ink2);font-size:10px;font-weight:700}footer div+div{margin-top:2px;font-weight:600;opacity:.7}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;z-index:100;padding:20px}.modal-overlay.open{display:flex;animation:fadein .2s}@keyframes fadein{from{opacity:0}to{opacity:1}}.modal{background:var(--surface);border:1px solid var(--line);border-radius:18px;max-width:420px;width:100%;padding:24px;text-align:center;position:relative;box-shadow:var(--shadow);animation:pop .3s cubic-bezier(.34,1.56,.64,1)}@keyframes pop{from{transform:scale(.9);opacity:0}to{transform:scale(1);opacity:1}}.m-x{position:absolute;top:10px;right:10px;width:28px;height:28px;border-radius:8px;border:1px solid var(--line);background:var(--surface2);color:var(--ink2);font-size:14px;cursor:pointer;display:grid;place-items:center}.m-x:hover{background:var(--red-soft);color:var(--red)}.m-check{width:56px;height:56px;margin:0 auto 10px}.m-check svg{width:100%;height:100%}.m-check circle{fill:none;stroke:var(--green);stroke-width:2.5;stroke-dasharray:160;stroke-dashoffset:160;animation:draw .7s .1s forwards}.m-check path{fill:none;stroke:var(--green);stroke-width:4;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:60;stroke-dashoffset:60;animation:draw .5s .6s forwards}@keyframes draw{to{stroke-dashoffset:0}}.modal h3{font-family:var(--fd);font-size:18px;font-weight:800}.m-sub{color:var(--ink2);font-size:11px;font-weight:600;margin:4px 0 10px}.m-id{font-family:var(--fm);font-weight:700;font-size:12px;background:var(--green-soft);color:var(--green);border:1px dashed color-mix(in srgb,var(--green) 40%,transparent);border-radius:9px;padding:7px;margin-bottom:12px}.m-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;text-align:left;margin-bottom:8px}.m-grid>div{background:var(--surface2);border:1px solid var(--line);border-radius:9px;padding:7px 10px}.m-grid span{font-size:8px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:var(--ink2);display:block}.m-grid strong{font-size:11px;font-weight:800}.m-total{display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;border-radius:10px;padding:10px 14px;margin-bottom:14px}.m-total span{font-size:8.5px;font-weight:800;letter-spacing:1px;opacity:.85;text-transform:uppercase}.m-total strong{font-family:var(--fm);font-size:15px;font-weight:700}.m-actions{display:grid;grid-template-columns:1fr 1fr;gap:6px}.m-actions .btn{height:38px;font-size:11px}
.toasts{position:fixed;top:70px;right:16px;z-index:120;display:flex;flex-direction:column;gap:8px}.toast{display:flex;align-items:center;gap:8px;background:var(--surface);border:1px solid var(--line);border-left:3px solid var(--green);border-radius:10px;padding:10px 14px;font-size:12px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:340px}.toast.error{border-left-color:var(--red)}.toast.info{border-left-color:var(--blue)}.toast.out{opacity:0;transform:translateX(20px);transition:.4s}@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
.mobile-actions{display:none}
@media(max-width:1020px){.layout{grid-template-columns:1fr}.side{position:static}.stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.fgrid{grid-template-columns:1fr}.stats{grid-template-columns:1fr 1fr;gap:8px}.sig-grid{grid-template-columns:1fr}.topbar .badge{display:none}.hero{padding:16px 0 4px;gap:12px}.clock-card{min-width:auto;width:100%}.actions{display:none}.mobile-actions{display:grid;grid-template-columns:1fr 1fr 1fr;gap:5px;position:fixed;bottom:0;left:0;right:0;z-index:90;background:color-mix(in srgb,var(--surface) 95%,transparent);backdrop-filter:blur(14px);border-top:1px solid var(--line);padding:8px 12px;padding-bottom:max(8px,env(safe-area-inset-bottom))}.mobile-actions .btn{padding:10px 5px;font-size:10px;border-radius:8px}.mobile-actions .btn.primary{font-size:11px}.layout{padding-bottom:80px}footer{padding-bottom:80px}}
@media(max-width:480px){.stats{grid-template-columns:1fr}}
</style>
</head>
<body>
<div id="app">
<header class="topbar"><div class="container"><div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div><a href="admin_general_dashboard.php" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Dashboard</a><span class="badge live">ONLINE</span><span class="badge secure">SECURE</span><button class="theme-btn" id="themeBtn" title="Toggle theme"></button></div></header>

<section class="hero container"><div class="hero-left"><div class="eyebrow"><?php foreach(explode('•',$company['company_tagline']) as $tag): ?><span><?php echo e(trim($tag)); ?></span><?php endforeach; ?></div><h1>Client Order <em>Management</em></h1><p class="sub">Create orders, assign drivers, track deliveries, manage stock automatically</p><p class="addr"><svg class="icon-svg" width="12" height="12" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg><?php echo e($company['company_address']); ?></p></div><div class="clock-card"><span class="cc-label">Current Session</span><strong id="clockTime">--:--:--</strong><span class="dt" id="clockDate">Loading…</span><span class="cc-wat">WAT</span></div></section>

<section class="stats container"><div class="stat"><div class="ic g"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div><div><b><?php echo number_format($todayOrders); ?></b><span>Today Orders</span></div></div><div class="stat"><div class="ic y"><svg class="icon-svg" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div><div><b><?php echo fmtN($totalRevenue); ?></b><span>Revenue</span></div></div><div class="stat"><div class="ic b"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div><b><?php echo number_format($pendingCount); ?></b><span>Pending</span></div></div><div class="stat"><div class="ic r"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div><div><b><?php echo number_format($deliveredCnt); ?></b><span>Delivered</span></div></div></section>

<main class="layout container">
<form id="orderForm" method="post" novalidate>
<input type="hidden" name="sig_client_data" id="sigClientData">
<input type="hidden" name="sig_admin_data" id="sigAdminData">

<section class="card"><header class="step-h"><span class="step-no">01</span><div><h3>Order Type</h3><p>Inbound adds stock, Outbound reduces stock</p></div></header><div style="padding:14px 18px"><div class="type-toggle"><label><input type="radio" name="order_type" value="outbound" checked><span><svg class="icon-svg" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg> Outbound (Delivery)</span></label><label><input type="radio" name="order_type" value="inbound"><span><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg> Inbound (Receiving)</span></label></div></div></section>

<section class="card"><header class="step-h"><span class="step-no">02</span><div><h3>Client Information</h3><p>Select existing or enter new</p></div></header><div class="fgrid"><div class="field span2" style="position:relative"><span class="flabel">Client Name <b>*</b></span><div class="control" id="clientCtrl"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><input name="client_name" id="clientName" placeholder="Search client…" autocomplete="off" required></div><div class="ac-dropdown" id="clientDropdown"></div></div><div class="field"><span class="flabel">Email</span><div class="control"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg><input name="email" id="email" type="email" placeholder="client@email.com"></div></div><div class="field"><span class="flabel">Phone</span><div class="control"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.9.5 2.8.7a2 2 0 0 1 1.7 2Z"/></svg><input name="phone" id="phone" placeholder="0803 000 0000"></div></div><div class="field span2"><span class="flabel">Date <b>*</b></span><div class="control"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><input name="order_date" id="orderDate" type="date" value="<?php echo date('Y-m-d'); ?>" required></div></div></div></section>

<section class="card"><header class="step-h"><span class="step-no">03</span><div><h3>Order Details</h3><p>Product, quantity &amp; pricing</p></div></header><div class="fgrid">
  <div class="field"><span class="flabel">Product <b>*</b></span><div class="control"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg><select name="product" id="productSel" required><option value="">Select product…</option><?php foreach($productsData as $pl): ?><option value="<?php echo e($pl['code']); ?>" data-price="<?php echo (float)$pl['price']; ?>" data-stock="<?php echo (float)$pl['stock']; ?>"><?php echo e($pl['name']); ?> (<?php echo number_format((float)$pl['stock']); ?> kg)</option><?php endforeach; ?></select></div></div>
  <div class="field"><span class="flabel">Unit Price (₦/kg) <b>*</b></span><div class="control"><input name="unit_price" id="unitPrice" inputmode="decimal" placeholder="0.00" required></div></div>
  <div class="field"><span class="flabel">Kilograms <b>*</b></span><div class="control"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M6 21h12l-2-13H8L6 21Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><input name="kilograms" id="kilograms" inputmode="decimal" placeholder="0" required></div><div class="chips"><button type="button" class="chip" data-kg="1000">1,000</button><button type="button" class="chip" data-kg="2500">2,500</button><button type="button" class="chip" data-kg="5000">5,000</button><button type="button" class="chip" data-kg="10000">10,000</button></div></div>
  <div class="field"><span class="flabel">Total</span><div class="total-box"><span>Total</span><strong id="totalField">₦0.00</strong></div></div>
  <div class="field span2"><span class="flabel">Description</span><div class="control ta"><textarea name="description" class="desc-field" rows="2" placeholder="Grade, moisture, notes…"></textarea></div></div>
</div></section>

<section class="card"><header class="step-h"><span class="step-no">04</span><div><h3>Logistics &amp; Driver</h3><p>Assign driver, truck price, route</p></div></header><div class="fgrid"><div class="field" style="position:relative"><span class="flabel">Assign Driver</span><div class="control" id="driverCtrl"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/></svg><input name="driver_name" id="driverName" placeholder="Search driver…" autocomplete="off"><input type="hidden" name="driver_id" id="driverId"></div><div class="ac-dropdown" id="driverDropdown"></div></div><div class="field"><span class="flabel">Truck Price (₦)</span><div class="control"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg><input name="truck_price" id="truckPrice" inputmode="decimal" placeholder="Transport cost"></div></div><div class="field"><span class="flabel">Departure</span><div class="control"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg><input name="departure_location" id="departLoc" placeholder="e.g. Shaki Warehouse"></div></div><div class="field"><span class="flabel">Destination</span><div class="control"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg><input name="destination_location" id="destLoc" placeholder="e.g. Lagos Market"></div></div></div></section>

<section class="card"><header class="step-h"><span class="step-no">05</span><div><h3>Payment &amp; Bank</h3><p>Auto-filled from client record</p></div></header><div class="fgrid"><div class="field"><span class="flabel">Bank Name</span><div class="control"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3"/></svg><input name="bank" id="bankName" placeholder="Bank name"></div></div><div class="field"><span class="flabel">Account No</span><div class="control"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg><input name="account_no" id="accountNo" inputmode="numeric" placeholder="0123456789"></div></div><div class="field span2"><span class="flabel">Account Name</span><div class="control"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/></svg><input name="account_name" id="accountName" placeholder="Account holder name"></div></div></div></section>
</form>

<aside class="side">
<div class="card preview"><div class="pv-head"><span class="pv-dot"></span>Live Preview</div><div class="pv-body"><div class="pv-prod"><img id="pvImg" src="logo.ico" alt=""><div><strong id="pvProduct">Select Product</strong><span id="pvClient">Walk-in Client</span></div></div><div class="pv-rows"><div><span>Kg</span><strong id="pvKg">0 kg</strong></div><div><span>Price/Kg</span><strong id="pvUnit">₦0.00</strong></div></div><div class="pv-total"><span>Total</span><strong id="pvTotal">₦0.00</strong></div><div class="pv-bar"><div id="pvBar"></div></div><div class="pv-meta"><span id="pvTons">0.00 MT</span><span id="pvBags">0 bags</span></div><div class="pv-stock" id="pvStock"></div></div></div>
<div class="card"><div class="pv-head">Status</div><div class="seg"><label><input type="radio" name="status" value="Pending" checked><span>Pending</span></label><label><input type="radio" name="status" value="Approved"><span>Approved</span></label><label><input type="radio" name="status" value="Delivered"><span>Delivered</span></label></div><label class="paid-toggle"><input type="checkbox" name="is_paid" id="paidChk"><span class="trk"></span>Paid</label></div>
<div class="card"><div class="pv-head">Authorisation</div><div class="sig-grid"><div class="sig"><canvas id="padClient"></canvas><div class="sig-foot"><span>Client</span><button type="button" class="mini" id="clearClient">Clear</button></div></div><div class="sig"><canvas id="padAdmin"></canvas><div class="sig-foot"><span>Admin</span><button type="button" class="mini" id="clearAdmin">Clear</button></div></div></div><div class="seal-row"><div class="seal" id="sealBox"><div class="seal-inner"><span>OKOYA FOOD</span><strong>COMPANY LTD</strong><em>★ OFFICIAL ★</em></div></div><p class="seal-note">Seal stamps when approved or paid.</p></div></div>
<div class="actions"><button type="button" class="btn ghost" id="btnPrint">Print</button><button type="button" class="btn ghost" id="btnReset">Reset</button><button type="button" class="btn primary" id="btnSubmit"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Submit Order</button></div>
</aside>
</main>

<div class="mobile-actions"><button type="button" class="btn ghost" id="btnPrintMob">Print</button><button type="button" class="btn ghost" id="btnResetMob">Reset</button><button type="button" class="btn primary" id="btnSubmitMob">Submit</button></div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?></div><div>Enterprise HR &amp; Operations System</div><div>Developed by Lawani Djamiou Alade</div></footer>
</div>

<div class="modal-overlay" id="modalOverlay"><div class="modal"><button class="m-x" id="modalClose" aria-label="Close">&times;</button><div class="m-check"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M14 27l8 8 16-16"/></svg></div><h3>Order Saved!</h3><p class="m-sub">Stored in database with stock updated.</p><div class="m-id" id="mOrderNo">—</div><div class="m-grid"><div><span>Client</span><strong id="mClient">—</strong></div><div><span>Product</span><strong id="mProduct">—</strong></div><div><span>Quantity</span><strong id="mQty">—</strong></div><div><span>Status</span><strong id="mStatus">—</strong></div></div><div class="m-total"><span>Total Value</span><strong id="mTotal">₦0.00</strong></div><div class="m-actions"><button class="btn ghost" id="btnPrintReceipt">Print Receipt</button><button class="btn primary" id="btnNew">＋ New Order</button></div></div></div>
<div class="toasts" id="toastWrap"></div>

<script>
var PRODUCTS=<?php echo json_encode($productStock,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var CLIENTS=<?php echo json_encode($clientsData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var DRIVERS=<?php echo json_encode($driversData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var $=id=>document.getElementById(id);
var num=v=>{const n=parseFloat(v);return isNaN(n)?0:n};
var fmtN=n=>'₦'+Number(n||0).toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2});
var todayISO=()=>{const d=new Date();return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')};
var esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function toast(msg,type='success'){const t=document.createElement('div');t.className='toast '+(type==='error'?'error':(type==='info'?'info':''));t.innerHTML='<span>'+(type==='error'?'⚠️':(type==='info'?'ℹ️':'✅'))+'</span>'+esc(msg);$('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3000);setTimeout(()=>t.remove(),3500);}

const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
const sunSVG='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg>';
const moonSVG='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>';
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);$('themeBtn').innerHTML=t==='dark'?sunSVG:moonSVG;}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));

function tick(){const now=new Date();try{$('clockTime').textContent=new Intl.DateTimeFormat('en-NG',{timeZone:'Africa/Lagos',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(now);$('clockDate').textContent=new Intl.DateTimeFormat('en-NG',{timeZone:'Africa/Lagos',weekday:'short',day:'2-digit',month:'short',year:'numeric'}).format(now);}catch(e){$('clockTime').textContent=now.toLocaleTimeString();$('clockDate').textContent=now.toLocaleDateString();}}
tick();setInterval(tick,1000);

class SmartAutocomplete{
  constructor(inputEl,dropdownEl,data,nameField,subFields,onSelect){this.input=inputEl;this.dd=dropdownEl;this.data=data;this.nameField=nameField;this.subFields=subFields;this.onSelect=onSelect;this.idx=-1;this.filtered=[];this.open=false;inputEl.addEventListener('input',()=>this.onInput());inputEl.addEventListener('focus',()=>this.onInput());inputEl.addEventListener('keydown',e=>this.onKey(e));document.addEventListener('click',e=>{if(!inputEl.contains(e.target)&&!dropdownEl.contains(e.target))this.close();});}
  onInput(){const q=this.input.value.trim().toLowerCase();this.filtered=q?this.data.filter(d=>d[this.nameField].toLowerCase().includes(q)||this.subFields.some(f=>(d[f]||'').toLowerCase().includes(q))):this.data.slice(0,10);this.idx=-1;this.render();}
  render(){if(!this.filtered.length){this.dd.innerHTML='<div class="ac-empty">No matches</div>';this.dd.classList.add('open');this.open=true;return;}this.dd.innerHTML=this.filtered.map((d,i)=>{const initials=d[this.nameField].split(' ').map(w=>w[0]).join('').substring(0,2).toUpperCase();const sub=this.subFields.map(f=>d[f]).filter(Boolean).join(' · ');return '<div class="ac-item'+(i===this.idx?' active':'')+'" data-i="'+i+'"><div class="ac-avatar">'+initials+'</div><div class="ac-info"><span class="ac-name">'+esc(d[this.nameField])+'</span><span class="ac-sub">'+esc(sub)+'</span></div><span class="ac-badge">Select</span></div>';}).join('');this.dd.querySelectorAll('.ac-item').forEach(el=>el.addEventListener('mousedown',e=>{e.preventDefault();this.select(parseInt(el.dataset.i));}));this.dd.classList.add('open');this.open=true;}
  onKey(e){if(!this.open)return;if(e.key==='ArrowDown'){e.preventDefault();this.idx=Math.min(this.idx+1,this.filtered.length-1);this.updateActive();}else if(e.key==='ArrowUp'){e.preventDefault();this.idx=Math.max(this.idx-1,0);this.updateActive();}else if(e.key==='Enter'&&this.idx>=0){e.preventDefault();this.select(this.idx);}else if(e.key==='Escape'){this.close();}}
  updateActive(){this.dd.querySelectorAll('.ac-item').forEach((el,i)=>el.classList.toggle('active',i===this.idx));}
  select(i){const d=this.filtered[i];if(!d)return;this.input.value=d[this.nameField];this.close();if(this.onSelect)this.onSelect(d);}
  close(){this.dd.classList.remove('open');this.open=false;}
}

new SmartAutocomplete($('clientName'),$('clientDropdown'),CLIENTS,'name',['email','phone','acc_no'],function(c){
  $('email').value=c.email||'';$('phone').value=c.phone||'';
  $('bankName').value=c.bank||'';$('accountNo').value=c.acc_no||'';$('accountName').value=c.acc_name||'';
  toast('Client: '+c.name+(c.acc_no?' — Acc: '+c.acc_no:''),'info');updatePreview();
});

new SmartAutocomplete($('driverName'),$('driverDropdown'),DRIVERS,'name',['plate','phone'],function(d){
  $('driverId').value=d.id||'';
  toast('Driver: '+d.name+(d.plate?' — '+d.plate:''),'info');
});

class SignaturePad{constructor(canvas){this.c=canvas;this.ctx=canvas.getContext('2d');this.strokes=[];this.cur=null;canvas.style.touchAction='none';canvas.addEventListener('pointerdown',e=>{e.preventDefault();canvas.setPointerCapture(e.pointerId);this.cur=[this.pt(e)];this.strokes.push(this.cur);this.draw();});canvas.addEventListener('pointermove',e=>{if(this.cur){this.cur.push(this.pt(e));this.draw();}});const up=()=>{this.cur=null};canvas.addEventListener('pointerup',up);canvas.addEventListener('pointercancel',up);this.resize();}
pt(e){const r=this.c.getBoundingClientRect();return{x:(e.clientX-r.left)/r.width,y:(e.clientY-r.top)/r.height}}
resize(){const r=this.c.getBoundingClientRect();const d=window.devicePixelRatio||1;this.c.width=Math.max(1,r.width*d);this.c.height=Math.max(1,r.height*d);this.ctx.setTransform(d,0,0,d,0,0);this.draw();}
draw(){const r=this.c.getBoundingClientRect(),x=this.ctx;x.clearRect(0,0,r.width,r.height);x.strokeStyle='#1f2d26';x.fillStyle='#1f2d26';x.lineWidth=2.2;x.lineCap='round';x.lineJoin='round';for(const s of this.strokes){if(s.length<2){const p=s[0];x.beginPath();x.arc(p.x*r.width,p.y*r.height,1.4,0,7);x.fill();continue}x.beginPath();s.forEach((p,i)=>{const px=p.x*r.width,py=p.y*r.height;i?x.lineTo(px,py):x.moveTo(px,py)});x.stroke();}}
isEmpty(){return !this.strokes.some(s=>s.length>1)}clear(){this.strokes=[];this.draw()}
exportPNG(){if(this.isEmpty())return null;const c=document.createElement('canvas');c.width=this.c.width;c.height=this.c.height;const x=c.getContext('2d');x.fillStyle='#ffffff';x.fillRect(0,0,c.width,c.height);x.drawImage(this.c,0,0);return c.toDataURL('image/png');}
}
const padClient=new SignaturePad($('padClient'));const padAdmin=new SignaturePad($('padAdmin'));
window.addEventListener('resize',()=>{padClient.resize();padAdmin.resize();});
$('clearClient').addEventListener('click',()=>padClient.clear());$('clearAdmin').addEventListener('click',()=>padAdmin.clear());

const clientName=$('clientName'),productSel=$('productSel'),kilograms=$('kilograms'),unitPrice=$('unitPrice'),paidChk=$('paidChk');
function currentStatus(){return document.querySelector('input[name="status"]:checked').value}
function updateSeal(){$('sealBox').classList.toggle('stamped',currentStatus()!=='Pending'||paidChk.checked)}
document.querySelectorAll('input[name="status"]').forEach(r=>r.addEventListener('change',updateSeal));
paidChk.addEventListener('change',updateSeal);

productSel.addEventListener('change',function(){
  var opt=productSel.options[productSel.selectedIndex];
  if(opt&&opt.dataset.price){unitPrice.value=opt.dataset.price;}
  updatePreview();
});

function updatePreview(){
  const p=PRODUCTS[productSel.value]||{name:'Select Product',img:'',stock:0};
  const orderType=document.querySelector('input[name="order_type"]:checked')?.value||'outbound';
  $('pvImg').src=p.img||'logo.ico';$('pvProduct').textContent=p.name||'Select Product';$('pvClient').textContent=clientName.value.trim()||'Walk-in Client';
  const kg=num(kilograms.value),up=num(unitPrice.value),tot=kg*up;
  $('pvKg').textContent=kg.toLocaleString('en-NG')+' kg';$('pvUnit').textContent=fmtN(up);$('pvTotal').textContent=fmtN(tot);$('totalField').textContent=fmtN(tot);
  $('pvTons').textContent=(kg/1000).toFixed(2)+' MT';$('pvBags').textContent=Math.floor(kg/50)+' bags';$('pvBar').style.width=Math.min(100,kg/10000*100)+'%';
  const stockEl=$('pvStock');
  if(stockEl){
    const avail=p.stock||0;
    if(orderType==='outbound'){
      const remain=avail-kg;
      stockEl.innerHTML='<span style="color:'+(remain<0?'var(--red)':'var(--green)')+'">Stock: '+Number(avail).toLocaleString()+' kg → '+Number(Math.max(0,remain)).toLocaleString()+' kg'+(remain<0?' ⚠ INSUFFICIENT':'')+'</span>';
    }else{
      stockEl.innerHTML='<span style="color:var(--blue)">Stock: '+Number(avail).toLocaleString()+' kg → '+Number(avail+kg).toLocaleString()+' kg (+incoming)</span>';
    }
  }
}
[clientName,kilograms,unitPrice].forEach(el=>el.addEventListener('input',()=>{el.closest('.control').classList.remove('invalid');updatePreview();}));
document.querySelectorAll('input[name="order_type"]').forEach(r=>r.addEventListener('change',updatePreview));
document.querySelectorAll('.chip').forEach(ch=>ch.addEventListener('click',()=>{kilograms.value=ch.dataset.kg;updatePreview();}));

function mark(el){el.closest('.control').classList.add('invalid')}
function handleFormSubmit(){document.querySelectorAll('.control.invalid').forEach(c=>c.classList.remove('invalid'));let errs=[];const orderType=document.querySelector('input[name="order_type"]:checked')?.value||'outbound';const p=PRODUCTS[productSel.value];if(!clientName.value.trim()){mark(clientName);errs.push('Client name required');}if(!productSel.value){mark(productSel);errs.push('Select a product');}if(!(num(kilograms.value)>0)){mark(kilograms);errs.push('Kg must be > 0');}if(!(num(unitPrice.value)>0)){mark(unitPrice);errs.push('Price must be > 0');}if(!$('orderDate').value){mark($('orderDate'));errs.push('Date required');}if(orderType==='outbound'&&p&&num(kilograms.value)>(p.stock||0)){mark(kilograms);errs.push('Insufficient stock! Available: '+Number(p.stock||0).toLocaleString()+' kg');}if(errs.length){toast(errs[0],'error');return;}$('sigClientData').value=padClient.exportPNG()||'';$('sigAdminData').value=padAdmin.exportPNG()||'';let hidden=document.createElement('input');hidden.type='hidden';hidden.name='submit_order';hidden.value='1';$('orderForm').appendChild(hidden);$('orderForm').submit();}
$('btnSubmit').addEventListener('click',handleFormSubmit);$('btnSubmitMob').addEventListener('click',handleFormSubmit);

function resetForm(){$('orderForm').reset();productSel.value='';kilograms.value='';unitPrice.value='';$('orderDate').value=todayISO();paidChk.checked=false;document.querySelector('input[name="status"][value="Pending"]').checked=true;$('driverId').value='';padClient.clear();padAdmin.clear();$('sigClientData').value='';$('sigAdminData').value='';updateSeal();updatePreview();}
$('btnReset').addEventListener('click',()=>{resetForm();toast('Form cleared','info');});
$('btnResetMob').addEventListener('click',()=>{resetForm();toast('Form cleared','info');});

let lastSubmitted=null;
function openModal(o){$('mOrderNo').textContent=o.id;$('mClient').textContent=o.client;$('mProduct').textContent=o.product;$('mQty').textContent=num(o.kg).toLocaleString('en-NG')+' kg';$('mStatus').textContent=o.status+(o.paid?' • Paid':'');$('mTotal').textContent=fmtN(o.total);$('modalOverlay').classList.add('open');lastSubmitted=o;}
function closeModal(){$('modalOverlay').classList.remove('open')}
$('modalClose').addEventListener('click',closeModal);$('modalOverlay').addEventListener('click',e=>{if(e.target===$('modalOverlay'))closeModal()});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal()});
$('btnNew').addEventListener('click',()=>{closeModal();resetForm();clientName.focus();});

/* ===== PROFESSIONAL PRINT VIA IFRAME WITH COMPANY LOGO ===== */
function buildPrintHTML(o){
  var cn='<?php echo addslashes($company["company_name"]); ?>';
  var ca='<?php echo addslashes($company["company_address"]); ?>';
  var tl='<?php echo addslashes($company["company_tagline"]); ?>';
  var dept='<?php echo addslashes($company["company_dept"]); ?>';
  var logoAbs='<?php echo (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['REQUEST_URI']).'/logo.ico'; ?>';
  var genDate=new Date().toLocaleString('en-NG',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
  var stamped=(o.status!=='Pending'||o.paid);
  var orderTypeLabel=(o.orderType||'outbound').toUpperCase();
  var f=function(label,value,mono){return '<div style="margin-bottom:5pt"><div style="font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.5pt;color:#7a857c;margin-bottom:1pt">'+label+'</div><div style="font-size:9pt;font-weight:700;color:#1a231d'+(mono?';font-family:monospace;color:#059669':'')+'">'+(value?esc(value):'—')+'</div></div>';};

  var h='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Order Form</title>';
  h+='<style>@page{size:A4;margin:14mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;margin:0;padding:0;box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#1a231d;font-size:9pt;line-height:1.4;background:#fff}</style>';
  h+='</head><body>';

  /* LETTERHEAD WITH COMPANY LOGO */
  h+='<div style="display:flex;align-items:center;gap:14pt;border-bottom:3pt double #059669;padding-bottom:10pt;margin-bottom:16pt">';
  h+='<img src="'+logoAbs+'" alt="Logo" style="width:56pt;height:56pt;object-fit:contain;border:2pt solid #059669;border-radius:8pt;padding:3pt;background:#fff;flex:none">';
  h+='<div style="flex:1"><h1 style="font-size:16pt;font-weight:800;color:#059669;line-height:1.15">'+cn+'</h1>';
  h+='<p style="font-size:7.5pt;color:#5a655c;margin:2pt 0 0">'+ca+'</p>';
  h+='<p style="font-size:7.5pt;color:#5a655c;margin:1pt 0 0">'+dept+'</p>';
  h+='<p style="font-size:5.5pt;font-weight:800;letter-spacing:1.4pt;color:#b57e17;margin-top:3pt;text-transform:uppercase">'+tl+'</p></div>';
  h+='<div style="text-align:right;flex:none"><div style="font-size:11pt;font-weight:800;letter-spacing:1pt;color:#fff;background:#059669;padding:5pt 12pt;border-radius:4pt;display:inline-block;margin-bottom:4pt">ORDER FORM</div>';
  h+='<p style="font-size:7pt;color:#7a857c;margin:2pt 0">Ref: <span style="font-family:monospace;font-weight:700;color:#059669">'+esc(o.id)+'</span></p>';
  h+='<p style="font-size:7pt;color:#7a857c;margin:1pt 0">Type: <b>'+orderTypeLabel+'</b></p>';
  h+='<p style="font-size:7pt;color:#7a857c;margin:1pt 0">Date: '+esc(o.date)+'</p>';
  h+='<p style="font-size:7pt;color:#7a857c;margin:1pt 0">Printed: '+genDate+'</p></div></div>';

  /* CLIENT */
  h+='<div style="margin-bottom:14pt"><div style="font-size:8pt;font-weight:800;text-transform:uppercase;letter-spacing:1pt;color:#059669;margin-bottom:6pt;padding-bottom:4pt;border-bottom:1.5pt solid #e2f2e8">Client Information</div>';
  h+='<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:4pt 16pt">'+f('Client Name',o.client)+f('Email',o.email)+f('Phone',o.phone)+'</div></div>';

  /* ORDER DETAILS */
  h+='<div style="margin-bottom:14pt"><div style="font-size:8pt;font-weight:800;text-transform:uppercase;letter-spacing:1pt;color:#059669;margin-bottom:6pt;padding-bottom:4pt;border-bottom:1.5pt solid #e2f2e8">Order Details</div>';
  h+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:4pt 16pt">'+f('Product',o.product)+f('Quantity',num(o.kg).toLocaleString()+' kg')+f('Unit Price',fmtN(o.price),true)+(o.description?f('Description',o.description):'')+'</div></div>';

  /* TOTAL BAR */
  h+='<div style="background:#059669;color:#fff;padding:12pt 16pt;border-radius:6pt;display:flex;justify-content:space-between;align-items:center;margin-bottom:14pt">';
  h+='<span style="font-size:7pt;font-weight:800;letter-spacing:1pt;text-transform:uppercase;opacity:.9">Total Amount</span>';
  h+='<span style="font-family:monospace;font-size:16pt;font-weight:700">'+fmtN(o.total)+'</span></div>';

  /* LOGISTICS */
  h+='<div style="margin-bottom:14pt"><div style="font-size:8pt;font-weight:800;text-transform:uppercase;letter-spacing:1pt;color:#059669;margin-bottom:6pt;padding-bottom:4pt;border-bottom:1.5pt solid #e2f2e8">Logistics & Route</div>';
  h+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:4pt 16pt">'+f('Driver',o.driver||'Not assigned')+f('Vehicle Plate',o.vehicle||'—')+f('Truck Price',o.truckPrice>0?fmtN(o.truckPrice):null,true)+f('Status',o.status+(o.paid?' • Paid':''))+'</div>';
  if(o.departure||o.destination){
    h+='<div style="display:flex;align-items:center;gap:8pt;margin-top:8pt;padding:8pt 12pt;background:#f8fafc;border:1pt solid #e2e8f0;border-radius:6pt">';
    h+='<div style="flex:1;text-align:center"><div style="font-size:5.5pt;font-weight:800;text-transform:uppercase;letter-spacing:.5pt;color:#7a857c">Departure</div><div style="font-size:9pt;font-weight:700;color:#1a231d;margin-top:1pt">'+esc(o.departure||'—')+'</div></div>';
    h+='<div style="color:#2563eb;flex:none;font-size:14pt;font-weight:700">→</div>';
    h+='<div style="flex:1;text-align:center"><div style="font-size:5.5pt;font-weight:800;text-transform:uppercase;letter-spacing:.5pt;color:#7a857c">Destination</div><div style="font-size:9pt;font-weight:700;color:#1a231d;margin-top:1pt">'+esc(o.destination||'—')+'</div></div></div>';
  }
  h+='</div>';

  /* BANK */
  h+='<div style="margin-bottom:14pt"><div style="font-size:8pt;font-weight:800;text-transform:uppercase;letter-spacing:1pt;color:#059669;margin-bottom:6pt;padding-bottom:4pt;border-bottom:1.5pt solid #e2f2e8">Payment & Bank</div>';
  h+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:4pt 16pt">'+f('Bank Name',o.bank)+f('Account No',o.accNo,true)+f('Account Name',o.accName)+'</div></div>';

  /* SIGNATURES */
  h+='<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14pt;margin-top:18pt;page-break-inside:avoid">';
  h+='<div style="text-align:center"><div style="height:40pt;border-bottom:1.5pt solid #333;margin-bottom:4pt;display:flex;align-items:flex-end;justify-content:center;overflow:hidden">'+(o.sigClient?'<img src="'+o.sigClient+'" style="max-height:36pt;max-width:100%">':'')+'</div><span style="font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.8pt;color:#555;display:block">Client Signature</span><small style="font-size:5.5pt;color:#999;display:block;margin-top:1pt">Name & Date</small></div>';
  h+='<div style="text-align:center"><div style="height:40pt;border-bottom:1.5pt solid #333;margin-bottom:4pt;display:flex;align-items:flex-end;justify-content:center;overflow:hidden">'+(o.sigAdmin?'<img src="'+o.sigAdmin+'" style="max-height:36pt;max-width:100%">':'')+'</div><span style="font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.8pt;color:#555;display:block">Admin Approval</span><small style="font-size:5.5pt;color:#999;display:block;margin-top:1pt">Name & Date</small></div>';
  h+='<div style="text-align:center"><div style="height:40pt;border-bottom:1.5pt solid #333;margin-bottom:4pt;display:flex;align-items:flex-end;justify-content:center;overflow:hidden">'+(stamped?'<div style="border:2pt solid #059669;border-radius:50%;width:38pt;height:38pt;display:flex;align-items:center;justify-content:center;font-size:5pt;font-weight:800;color:#059669;transform:rotate(-12deg);line-height:1.2">OKOYA<br>FOOD<br>★ OFFICIAL ★</div>':'')+'</div><span style="font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.8pt;color:#555;display:block">Company Seal</span><small style="font-size:5.5pt;color:#999;display:block;margin-top:1pt">Official Stamp</small></div>';
  h+='</div>';

  /* FOOTER */
  h+='<div style="margin-top:16pt;border-top:1pt solid #dde5dd;padding-top:6pt;display:flex;justify-content:space-between;font-size:5.5pt;color:#9aa79c">';
  h+='<span style="color:#c0392b;font-weight:800;letter-spacing:.5pt">CONFIDENTIAL — INTERNAL USE ONLY</span>';
  h+='<span>'+cn+'</span>';
  h+='<span>Generated '+genDate+'</span></div>';

  h+='</body></html>';
  return h;
}

function doPrint(htmlContent){
  var old=document.getElementById('printFrame');if(old)old.remove();
  var iframe=document.createElement('iframe');
  iframe.id='printFrame';
  iframe.style.position='fixed';iframe.style.right='0';iframe.style.bottom='0';
  iframe.style.width='0';iframe.style.height='0';iframe.style.border='0';
  document.body.appendChild(iframe);
  var doc=iframe.contentWindow.document;
  doc.open();doc.write(htmlContent);doc.close();
  iframe.contentWindow.focus();
  setTimeout(function(){iframe.contentWindow.print();setTimeout(function(){iframe.remove()},1000)},500);
}

function printDraft(){
  var kg=num(kilograms.value),up=num(unitPrice.value);
  var orderType=document.querySelector('input[name="order_type"]:checked')?.value||'outbound';
  var html=buildPrintHTML({
    id:'DRAFT-'+Date.now(),orderType:orderType,
    client:clientName.value.trim(),email:$('email').value,phone:$('phone').value,
    date:$('orderDate').value,product:productSel.options[productSel.selectedIndex]?.text||'',
    kg:kg,price:up,total:kg*up,description:$('description')?.value||'',
    driver:$('driverName').value,vehicle:'',
    truckPrice:num($('truckPrice')?.value||0),
    departure:$('departLoc')?.value||'',destination:$('destLoc')?.value||'',
    bank:$('bankName').value,accNo:$('accountNo').value,
    status:currentStatus(),paid:paidChk.checked,
    sigClient:padClient.exportPNG(),sigAdmin:padAdmin.exportPNG()
  });
  doPrint(html);
}
$('btnPrint').addEventListener('click',printDraft);
$('btnPrintMob').addEventListener('click',printDraft);
$('btnPrintReceipt').addEventListener('click',function(){
  if(lastSubmitted){doPrint(buildPrintHTML(lastSubmitted));}
});

<?php if ($submitFlash): ?>
window.addEventListener('DOMContentLoaded',()=>{const f=<?php echo json_encode($submitFlash,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;if(f.type==='success'){openModal({id:f.order_no,client:<?php echo json_encode($_POST['client_name']??'',JSON_HEX_TAG|JSON_HEX_QUOT); ?>,product:<?php echo json_encode(PRODUCTS[$_POST['product']??'']['name']??'Product',JSON_HEX_TAG|JSON_HEX_QUOT); ?>,kg:<?php echo json_encode($_POST['kilograms']??0); ?>,price:<?php echo json_encode($_POST['unit_price']??0); ?>,total:<?php echo json_encode(floatval($_POST['kilograms']??0)*floatval($_POST['unit_price']??0)); ?>,status:<?php echo json_encode($_POST['status']??'Pending',JSON_HEX_TAG); ?>,paid:<?php echo isset($_POST['is_paid'])?'true':'false'; ?>,driver:<?php echo json_encode($_POST['driver_name']??'',JSON_HEX_TAG|JSON_HEX_QUOT); ?>,vehicle:'',bank:<?php echo json_encode($_POST['bank']??'',JSON_HEX_TAG|JSON_HEX_QUOT); ?>,accNo:<?php echo json_encode($_POST['account_no']??'',JSON_HEX_TAG|JSON_HEX_QUOT); ?>,sigClient:<?php echo json_encode($_POST['sig_client_data']??null,JSON_HEX_TAG|JSON_HEX_QUOT); ?>,sigAdmin:<?php echo json_encode($_POST['sig_admin_data']??null,JSON_HEX_TAG|JSON_HEX_QUOT); ?>});toast(f.msg,'success');resetForm();}else{toast(f.msg,'error');}});
<?php endif; ?>
updatePreview();
</script>
</body>
</html>