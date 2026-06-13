<?php
// ================================================================
// ParkNPlace - Unified Web Application
// ================================================================

session_start();

// --- PDO Database Connection ---
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=parknplace;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// --- Helper Functions ---
function isLoggedIn()      { return !empty($_SESSION['user_id']); }
function currentUserId()   { return $_SESSION['user_id'] ?? 0; }
function currentUserRole() { return $_SESSION['user_role'] ?? ''; }
function currentUserName() { return $_SESSION['user_name'] ?? ''; }
function e($s)             { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect($url)    { header("Location: $url"); exit; }
function unreadCount() {
    global $pdo;
    if (!isLoggedIn()) return 0;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0');
    $stmt->execute([currentUserId()]);
    return (int)$stmt->fetchColumn();
}

// ================================================================
// AJAX ACTION HANDLERS (return JSON)
// ================================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    // --- Search Spaces ---
    if ($action === 'search_spaces') {
        $type     = $_GET['type'] ?? '';
        $audience = $_GET['audience'] ?? '';
        $search   = $_GET['search'] ?? '';
        $lat      = $_GET['lat'] ?? '';
        $lng      = $_GET['lng'] ?? '';
        $sort     = $_GET['sort'] ?? 'newest';

        $params = [];
        $where  = "s.status = 'verified'";

        if ($type && $type !== 'All') {
            $where .= " AND s.type = ?";
            $params[] = $type;
        }
        if ($audience && $audience !== 'All') {
            $where .= " AND s.target_audience = ?";
            $params[] = $audience;
        }
        if ($search) {
            $where .= " AND (s.title LIKE ? OR s.address LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $orderBy = "s.created_at DESC";
        if ($sort === 'price_low')  $orderBy = "s.price ASC";
        if ($sort === 'price_high') $orderBy = "s.price DESC";

        if ($lat !== '' && $lng !== '') {
            $params[] = $lat;
            $params[] = $lng;
            $sql = "SELECT s.*, u.name AS owner_name,
                (6371 * ACOS(
                    COS(RADIANS(?)) * COS(RADIANS(s.latitude)) *
                    COS(RADIANS(s.longitude) - RADIANS(?)) +
                    SIN(RADIANS(?)) * SIN(RADIANS(s.latitude))
                )) AS distance
                FROM spaces s
                JOIN users u ON s.owner_id = u.id
                WHERE $where
                ORDER BY distance ASC, s.created_at DESC";
            $params[] = $lat;
            $params[] = $lat;
        } else {
            $sql = "SELECT s.*, u.name AS owner_name
                FROM spaces s
                JOIN users u ON s.owner_id = u.id
                WHERE $where
                ORDER BY $orderBy";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // --- Get Conversations (FIXED: positional params) ---
    if ($action === 'get_conversations') {
        if (!isLoggedIn()) { echo json_encode([]); exit; }
        $uid = currentUserId();
        $sql = "SELECT conv.property_id, conv.other_id, u.name AS other_name, p.title AS property_title, conv.last_time,
                (SELECT COUNT(*) FROM messages m2
                 WHERE m2.property_id = conv.property_id
                 AND m2.sender_id = conv.other_id
                 AND m2.receiver_id = ?
                 AND m2.is_read = 0
                ) AS unread
                FROM (
                    SELECT property_id,
                        CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS other_id,
                        MAX(timestamp) AS last_time
                    FROM messages
                    WHERE sender_id = ? OR receiver_id = ?
                    GROUP BY property_id, CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END
                ) conv
                JOIN users u ON conv.other_id = u.id
                JOIN spaces p ON conv.property_id = p.id
                ORDER BY conv.last_time DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$uid, $uid, $uid, $uid, $uid]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // --- Get Chat Info (for opening chat from property page) ---
    if ($action === 'get_chat_info') {
        $otherId = (int)($_GET['other_id'] ?? 0);
        $propId  = (int)($_GET['property_id'] ?? 0);
        $otherName = 'User';
        $propTitle = 'Property';
        if ($otherId) {
            $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
            $stmt->execute([$otherId]);
            $u = $stmt->fetch();
            if ($u) $otherName = $u['name'];
        }
        if ($propId) {
            $stmt = $pdo->prepare('SELECT title FROM spaces WHERE id = ?');
            $stmt->execute([$propId]);
            $s = $stmt->fetch();
            if ($s) $propTitle = $s['title'];
        }
        echo json_encode(['other_name' => $otherName, 'property_title' => $propTitle]);
        exit;
    }

    // --- Get Messages (FIXED: positional params) ---
    if ($action === 'get_messages') {
        if (!isLoggedIn()) { echo json_encode([]); exit; }
        $pid    = (int)($_GET['property_id'] ?? 0);
        $other  = (int)($_GET['other_id'] ?? 0);
        $uid    = currentUserId();

        $pdo->prepare('UPDATE messages SET is_read = 1 WHERE property_id = ? AND sender_id = ? AND receiver_id = ? AND is_read = 0')
            ->execute([$pid, $other, $uid]);

        $sql = "SELECT m.*, su.name AS sender_name
                FROM messages m
                JOIN users su ON m.sender_id = su.id
                WHERE m.property_id = ?
                AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
                ORDER BY m.timestamp ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$pid, $uid, $other, $other, $uid]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // --- Send Message ---
    if ($action === 'send_message') {
        if (!isLoggedIn()) { echo json_encode(['error' => 'Not logged in']); exit; }
        $pid  = (int)($_POST['property_id'] ?? 0);
        $rid  = (int)($_POST['receiver_id'] ?? 0);
        $msg  = trim($_POST['message'] ?? '');
        if (!$pid || !$rid || !$msg) { echo json_encode(['error' => 'Missing data']); exit; }

        $stmt = $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, property_id, message) VALUES (?, ?, ?, ?)');
        $stmt->execute([currentUserId(), $rid, $pid, $msg]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    // --- Create Booking ---
    if ($action === 'create_booking') {
        if (!isLoggedIn()) { echo json_encode(['error' => 'Not logged in']); exit; }
        $pid     = (int)($_POST['property_id'] ?? 0);
        $dur     = (float)($_POST['duration'] ?? 1);
        $tname   = trim($_POST['tenant_name'] ?? '');
        $tphone  = trim($_POST['tenant_phone'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM spaces WHERE id = ? AND status = ?');
        $stmt->execute([$pid, 'verified']);
        $space = $stmt->fetch();
        if (!$space) { echo json_encode(['error' => 'Space not found']); exit; }

        $amount = $space['price'] * $dur;
        if ($space['pricing_model'] === 'monthly' && $space['deposit'] > 0) {
            $amount += $space['deposit'];
        }

        $ins = $pdo->prepare('INSERT INTO bookings (tenant_id, owner_id, property_id, amount, status, tenant_name, tenant_phone, duration) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([currentUserId(), $space['owner_id'], $pid, $amount, 'confirmed', $tname, $tphone, $dur]);
        $bid = $pdo->lastInsertId();

        echo json_encode([
            'success'    => true,
            'booking_id' => $bid,
            'amount'     => $amount,
            'title'      => $space['title'],
            'type'       => $space['type'],
            'model'      => $space['pricing_model'],
            'duration'   => $dur,
            'rate'       => $space['price'],
            'deposit'    => $space['deposit'],
            'address'    => $space['address'],
            'tname'      => $tname,
            'tphone'     => $tphone
        ]);
        exit;
    }

    // --- Verify Space (Admin) ---
    if ($action === 'verify_space') {
        if (currentUserRole() !== 'admin') { echo json_encode(['error' => 'Unauthorized']); exit; }
        $sid = (int)($_POST['space_id'] ?? 0);
        $pdo->prepare('UPDATE spaces SET status = ? WHERE id = ?')->execute(['verified', $sid]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ================================================================
// FORM POST HANDLERS
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    $fa = $_POST['form_action'];

    if ($fa === 'register') {
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $role  = $_POST['role'] ?? 'tenant';
        $phone = trim($_POST['phone'] ?? '');
        if ($name && $email && $pass && in_array($role, ['tenant','owner'])) {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            try {
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role, phone) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$name, $email, $hash, $role, $phone]);
                $_SESSION['user_id']    = $pdo->lastInsertId();
                $_SESSION['user_name']  = $name;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role']  = $role;
                $_SESSION['user_phone'] = $phone;
            } catch (PDOException $ex) {
                $_SESSION['flash_error'] = 'Email already registered.';
            }
        } else {
            $_SESSION['flash_error'] = 'All fields are required.';
        }
        redirect('index.php');
    }

    if ($fa === 'login') {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($pass, $user['password'])) {
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['user_phone'] = $user['phone'];
        } else {
            $_SESSION['flash_error'] = 'Invalid email or password.';
        }
        redirect('index.php');
    }

    if ($fa === 'logout') { session_destroy(); redirect('index.php'); }

    if ($fa === 'create_space') {
        if (!isLoggedIn()) redirect('index.php');
        $title   = trim($_POST['title'] ?? '');
        $type    = $_POST['type'] ?? 'Home';
        $price   = (float)($_POST['price'] ?? 0);
        $deposit = (float)($_POST['deposit'] ?? 0);
        $area    = (float)($_POST['area'] ?? 0);
        $address = trim($_POST['address'] ?? '');
        $lat     = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $lng     = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $pmodel  = $_POST['pricing_model'] ?? 'monthly';
        $vtype   = $_POST['vehicle_type'] ?? 'none';
        $hasev   = isset($_POST['has_ev']) ? 1 : 0;
        $taud    = $_POST['target_audience'] ?? 'none';
        $rooms   = !empty($_POST['rooms']) ? (int)$_POST['rooms'] : null;
        $baths   = !empty($_POST['bathrooms']) ? (int)$_POST['bathrooms'] : null;
        $imgurl  = trim($_POST['image_url'] ?? '') ?: 'https://picsum.photos/seed/space'.rand(100,999).'/600/400';
        $stmt = $pdo->prepare('INSERT INTO spaces
            (owner_id, title, type, price, deposit, area, address, latitude, longitude,
             pricing_model, vehicle_type, has_ev, target_audience, rooms, bathrooms, image_url, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            currentUserId(), $title, $type, $price, $deposit, $area, $address, $lat, $lng,
            $pmodel, $vtype, $hasev, $taud, $rooms, $baths, $imgurl, 'pending'
        ]);
        $_SESSION['flash_success'] = 'Space listed successfully! Awaiting verification.';
        redirect('index.php?view=dashboard');
    }
}

// ================================================================
// PAGE DATA
// ================================================================
 $view      = $_GET['view'] ?? 'home';
 $propId    = (int)($_GET['id'] ?? 0);
 $flashErr  = $_SESSION['flash_error'] ?? '';
 $flashOk   = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);
 $unread    = unreadCount();

 $property = null;
if ($view === 'property' && $propId > 0) {
    $pdo->prepare('UPDATE spaces SET views = views + 1 WHERE id = ?')->execute([$propId]);
    $stmt = $pdo->prepare('SELECT s.*, u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone FROM spaces s JOIN users u ON s.owner_id = u.id WHERE s.id = ?');
    $stmt->execute([$propId]);
    $property = $stmt->fetch();
}

 $mySpaces = $myBookings = $pendingSpaces = [];
if ($view === 'dashboard' && isLoggedIn()) {
    if (currentUserRole() === 'owner') {
        $stmt = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM bookings b WHERE b.property_id = s.id) AS booking_count FROM spaces s WHERE s.owner_id = ? ORDER BY s.created_at DESC');
        $stmt->execute([currentUserId()]);
        $mySpaces = $stmt->fetchAll();
    }
    if (currentUserRole() === 'tenant') {
        $stmt = $pdo->prepare('SELECT b.*, s.title AS space_title, s.type AS space_type, s.pricing_model FROM bookings b JOIN spaces s ON b.property_id = s.id WHERE b.tenant_id = ? ORDER BY b.created_at DESC');
        $stmt->execute([currentUserId()]);
        $myBookings = $stmt->fetchAll();
    }
    if (currentUserRole() === 'admin') {
        $stmt = $pdo->prepare('SELECT s.*, u.name AS owner_name FROM spaces s JOIN users u ON s.owner_id = u.id WHERE s.status = ? ORDER BY s.created_at DESC');
        $stmt->execute(['pending']);
        $pendingSpaces = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ParkNPlace - Smart Space Rentals</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
:root{--teal:#028090;--indigo:#134074;--orange:#F58220;--teal-light:#05a3b8;--indigo-light:#1a5299;--orange-light:#ff9a42;--bg:#f4f6f9;--card:#ffffff;--text:#1a1d23;--text-muted:#6b7280;--border:#e2e5eb;--shadow:0 2px 12px rgba(19,64,116,0.08);--shadow-lg:0 8px 32px rgba(19,64,116,0.12);--radius:14px;--radius-sm:8px;--font-head:'Outfit',sans-serif;--font-body:'DM Sans',sans-serif}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:var(--font-body);background:var(--bg);color:var(--text);line-height:1.6;min-height:100vh;display:flex;flex-direction:column}
a{color:var(--teal);text-decoration:none;transition:color .2s}
a:hover{color:var(--indigo)}
img{max-width:100%;display:block}
input,select,textarea,button{font-family:var(--font-body);font-size:.95rem}
.navbar{background:var(--indigo);padding:0 24px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1000;box-shadow:0 2px 16px rgba(19,64,116,.25)}
.nav-brand{font-family:var(--font-head);font-weight:800;font-size:1.5rem;color:#fff;display:flex;align-items:center;gap:8px}
.nav-brand i{color:var(--orange);font-size:1.3rem}
.nav-links{display:flex;align-items:center;gap:4px;list-style:none}
.nav-links a,.nav-links button{color:rgba(255,255,255,.8);padding:8px 16px;border-radius:var(--radius-sm);font-weight:500;font-size:.9rem;transition:all .2s;background:none;border:none;cursor:pointer;display:flex;align-items:center;gap:6px}
.nav-links a:hover,.nav-links button:hover{background:rgba(255,255,255,.12);color:#fff}
.nav-links a.active{background:var(--orange);color:#fff}
.nav-badge{background:var(--orange);color:#fff;font-size:.7rem;font-weight:700;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center}
.btn-auth{background:var(--orange)!important;color:#fff!important;font-weight:600!important}
.btn-auth:hover{background:var(--orange-light)!important}
.main{flex:1;max-width:1280px;width:100%;margin:0 auto;padding:24px 20px}
.hero-section{background:linear-gradient(135deg,var(--indigo) 0%,var(--teal) 100%);border-radius:var(--radius);padding:40px 32px;margin-bottom:28px;color:#fff;position:relative;overflow:hidden}
.hero-section::after{content:'';position:absolute;right:-60px;top:-60px;width:260px;height:260px;background:rgba(245,130,32,.15);border-radius:50%}
.hero-section h1{font-family:var(--font-head);font-size:2.2rem;font-weight:800;margin-bottom:6px}
.hero-section p{opacity:.85;margin-bottom:20px;font-size:1.05rem}
.search-row{display:flex;gap:10px;flex-wrap:wrap}
.search-row input{flex:1;min-width:200px;padding:12px 18px;border:2px solid transparent;border-radius:var(--radius-sm);font-size:1rem;outline:none;transition:border .2s}
.search-row input:focus{border-color:var(--orange)}
.search-row button{padding:12px 24px;border:none;border-radius:var(--radius-sm);font-weight:600;cursor:pointer;transition:all .2s}
.btn-search{background:var(--orange);color:#fff}
.btn-search:hover{background:var(--orange-light);transform:translateY(-1px)}
.btn-geo{background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3)!important;white-space:nowrap}
.btn-geo:hover{background:rgba(255,255,255,.28)}
.filter-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px;align-items:center}
.filter-bar .label{font-weight:600;font-size:.85rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-right:4px}
.filter-btn{padding:7px 18px;border:2px solid var(--border);border-radius:50px;background:var(--card);color:var(--text-muted);font-weight:500;font-size:.85rem;cursor:pointer;transition:all .2s}
.filter-btn:hover{border-color:var(--teal);color:var(--teal)}
.filter-btn.active{background:var(--teal);color:#fff;border-color:var(--teal)}
.filter-divider{width:1px;height:28px;background:var(--border);margin:0 8px}
.cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px}
.space-card{background:var(--card);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);transition:all .3s ease;cursor:pointer;position:relative}
.space-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg)}
.card-img{width:100%;height:180px;object-fit:cover;background:linear-gradient(135deg,#e2e5eb,#cdd2da)}
.card-badge{position:absolute;top:12px;left:12px;padding:4px 12px;border-radius:50px;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#fff}
.badge-home{background:var(--indigo)}.badge-parking{background:var(--teal)}.badge-shop{background:var(--orange)}
.card-body{padding:16px}
.card-body h3{font-family:var(--font-head);font-size:1.05rem;font-weight:700;margin-bottom:6px;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.card-price{font-family:var(--font-head);font-size:1.3rem;font-weight:800;color:var(--orange);margin-bottom:8px}
.card-price span{font-size:.8rem;font-weight:400;color:var(--text-muted)}
.card-meta{display:flex;gap:12px;flex-wrap:wrap;font-size:.82rem;color:var(--text-muted);margin-bottom:8px}
.card-meta i{margin-right:3px;color:var(--teal)}
.card-tags{display:flex;gap:6px;flex-wrap:wrap}
.card-tag{padding:2px 10px;border-radius:50px;font-size:.72rem;font-weight:600;background:rgba(2,128,144,.08);color:var(--teal)}
.card-views{position:absolute;top:12px;right:12px;background:rgba(0,0,0,.55);color:#fff;padding:3px 10px;border-radius:50px;font-size:.75rem;font-weight:500}
.card-views i{margin-right:3px}
.detail-wrapper{display:grid;grid-template-columns:1fr 380px;gap:28px}
.detail-hero-img{width:100%;height:340px;object-fit:cover;border-radius:var(--radius);background:#e2e5eb}
.detail-info{margin-top:20px}
.detail-info h1{font-family:var(--font-head);font-size:1.8rem;font-weight:800;margin-bottom:4px}
.detail-price-tag{font-family:var(--font-head);font-size:1.5rem;font-weight:800;color:var(--orange);margin-bottom:16px}
.detail-price-tag span{font-size:.9rem;font-weight:400;color:var(--text-muted)}
.detail-features{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:20px}
.detail-feature{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center}
.detail-feature i{font-size:1.3rem;color:var(--teal);margin-bottom:6px;display:block}
.detail-feature .val{font-weight:700;font-size:1rem}
.detail-feature .lbl{font-size:.75rem;color:var(--text-muted)}
.detail-address{display:flex;align-items:flex-start;gap:8px;margin-bottom:16px;color:var(--text-muted);font-size:.95rem}
.detail-address i{color:var(--orange);margin-top:3px}
#detail-map{width:100%;height:260px;border-radius:var(--radius);margin-top:16px;border:2px solid var(--border)}
.detail-sidebar{background:var(--card);border-radius:var(--radius);padding:24px;box-shadow:var(--shadow);height:fit-content;position:sticky;top:80px}
.owner-info{display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border)}
.owner-avatar{width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,var(--indigo),var(--teal));display:flex;align-items:center;justify-content:center;color:#fff;font-family:var(--font-head);font-weight:700;font-size:1.1rem}
.owner-details .name{font-weight:700}
.owner-details .role{font-size:.8rem;color:var(--text-muted)}
.btn-book,.btn-msg{width:100%;padding:14px;border:none;border-radius:var(--radius-sm);font-weight:700;font-size:1rem;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:10px}
.btn-book{background:var(--orange);color:#fff}.btn-book:hover{background:var(--orange-light);transform:translateY(-1px)}
.btn-msg{background:var(--teal);color:#fff}.btn-msg:hover{background:var(--teal-light)}
.chat-wrapper{display:grid;grid-template-columns:320px 1fr;gap:0;background:var(--card);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);height:calc(100vh - 160px);min-height:500px}
.chat-list{border-right:1px solid var(--border);overflow-y:auto}
.chat-list-header{padding:18px 20px;font-family:var(--font-head);font-weight:700;font-size:1.15rem;border-bottom:1px solid var(--border);background:var(--card);position:sticky;top:0;z-index:2}
.chat-list-item{display:flex;align-items:center;gap:12px;padding:14px 20px;cursor:pointer;transition:background .2s;border-bottom:1px solid var(--border);position:relative}
.chat-list-item:hover{background:rgba(2,128,144,.05)}
.chat-list-item.active{background:rgba(2,128,144,.1)}
.chat-av{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,var(--indigo),var(--teal));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.9rem;flex-shrink:0}
.chat-list-item .info{flex:1;min-width:0}
.chat-list-item .info .cname{font-weight:600;font-size:.9rem}
.chat-list-item .info .cprop{font-size:.78rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chat-unread-badge{background:var(--orange);color:#fff;font-size:.68rem;font-weight:700;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center}
.chat-panel{display:flex;flex-direction:column}
.chat-panel-header{padding:16px 20px;border-bottom:1px solid var(--border);background:var(--card)}
.chat-panel-header .cp-name{font-weight:700;font-size:1rem}
.chat-panel-header .cp-prop{font-size:.8rem;color:var(--text-muted)}
.chat-messages{flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:10px;background:var(--bg)}
.msg-bubble{max-width:72%;padding:10px 16px;border-radius:16px;font-size:.92rem;line-height:1.5;word-wrap:break-word}
.msg-bubble.sent{align-self:flex-end;background:var(--teal);color:#fff;border-bottom-right-radius:4px}
.msg-bubble.received{align-self:flex-start;background:var(--card);color:var(--text);border-bottom-left-radius:4px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.msg-time{font-size:.68rem;opacity:.7;margin-top:4px}
.chat-input-area{display:flex;gap:10px;padding:14px 20px;border-top:1px solid var(--border);background:var(--card)}
.chat-input-area input{flex:1;padding:10px 16px;border:2px solid var(--border);border-radius:50px;outline:none;transition:border .2s}
.chat-input-area input:focus{border-color:var(--teal)}
.chat-input-area button{width:44px;height:44px;border:none;border-radius:50%;background:var(--teal);color:#fff;font-size:1.1rem;cursor:pointer;transition:background .2s;display:flex;align-items:center;justify-content:center}
.chat-input-area button:hover{background:var(--teal-light)}
.chat-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--text-muted);gap:10px}
.chat-empty i{font-size:3rem;opacity:.3}
.form-card{background:var(--card);border-radius:var(--radius);padding:32px;box-shadow:var(--shadow);max-width:780px;margin:0 auto}
.form-card h2{font-family:var(--font-head);font-weight:800;font-size:1.5rem;margin-bottom:24px;color:var(--indigo)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group.full{grid-column:1/-1}
.form-group label{font-weight:600;font-size:.85rem;color:var(--text-muted)}
.form-group input,.form-group select,.form-group textarea{padding:10px 14px;border:2px solid var(--border);border-radius:var(--radius-sm);outline:none;transition:border .2s;background:#fff}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--teal)}
.form-group textarea{resize:vertical;min-height:80px}
.form-group .checkbox-row{display:flex;align-items:center;gap:8px;padding:10px 0}
.form-group .checkbox-row input[type="checkbox"]{width:18px;height:18px;accent-color:var(--teal)}
.btn-submit{background:var(--orange);color:#fff;border:none;padding:14px 32px;border-radius:var(--radius-sm);font-weight:700;font-size:1rem;cursor:pointer;transition:all .2s;margin-top:20px}
.btn-submit:hover{background:var(--orange-light);transform:translateY(-1px)}
.btn-geo-space{display:inline-flex;align-items:center;justify-content:center;gap:10px;padding:14px 28px;background:linear-gradient(135deg,var(--teal),var(--indigo));color:#fff;border:none;border-radius:var(--radius-sm);font-weight:700;font-size:1.05rem;cursor:pointer;transition:all .2s;width:100%}
.btn-geo-space:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(2,128,144,.35)}
.btn-geo-space:disabled{opacity:.65;cursor:not-allowed;transform:none}
#add-map{width:100%;height:300px;border-radius:var(--radius-sm);border:2px solid var(--border);margin-top:4px}
.map-search-wrap{position:relative;margin-top:4px}
.map-search-wrap input{width:100%;padding:12px 50px 12px 16px;border:2px solid var(--border);border-radius:var(--radius-sm);outline:none;font-size:.95rem;transition:border .2s}
.map-search-wrap input:focus{border-color:var(--teal)}
.map-search-wrap button{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:var(--teal);color:#fff;border:none;border-radius:6px;padding:8px 14px;cursor:pointer;font-size:.9rem}
.map-search-wrap button:hover{background:var(--teal-light)}
.map-search-results{position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:0 0 8px 8px;max-height:220px;overflow-y:auto;z-index:500;box-shadow:0 4px 16px rgba(0,0,0,.12);display:none}
.map-search-results.active{display:block}
.map-search-item{padding:10px 16px;cursor:pointer;font-size:.88rem;border-bottom:1px solid var(--border);transition:background .15s}
.map-search-item:hover{background:rgba(2,128,144,.06)}
.map-search-item:last-child{border-bottom:none}
.location-status{padding:10px 16px;border-radius:var(--radius-sm);margin-top:8px;font-size:.88rem;font-weight:500;display:none;align-items:center;gap:8px}
.location-status.success{display:flex;background:rgba(2,128,144,.08);color:var(--teal);border:1px solid rgba(2,128,144,.15)}
.location-status.error{display:flex;background:rgba(220,38,38,.08);color:#dc2626;border:1px solid rgba(220,38,38,.15)}
.dash-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px}
.dash-card{background:var(--card);border-radius:var(--radius);padding:20px;box-shadow:var(--shadow)}
.dash-card h3{font-family:var(--font-head);font-weight:700;margin-bottom:12px;color:var(--indigo);font-size:1.1rem}
.dash-item{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);font-size:.9rem}
.dash-item:last-child{border-bottom:none}
.status-badge{padding:3px 12px;border-radius:50px;font-size:.75rem;font-weight:700;text-transform:uppercase}
.status-confirmed{background:rgba(2,128,144,.1);color:var(--teal)}
.status-pending{background:rgba(245,130,32,.1);color:var(--orange)}
.status-verified{background:rgba(2,128,144,.1);color:var(--teal)}
.btn-sm{padding:5px 14px;border:none;border-radius:var(--radius-sm);font-size:.8rem;font-weight:600;cursor:pointer;transition:all .2s}
.btn-verify{background:var(--teal);color:#fff}.btn-verify:hover{background:var(--teal-light)}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2000;align-items:center;justify-content:center;padding:20px}
.modal-overlay.active{display:flex}
.modal-box{background:var(--card);border-radius:var(--radius);padding:32px;max-width:480px;width:100%;max-height:90vh;overflow-y:auto;animation:modalIn .25s ease}
@keyframes modalIn{from{opacity:0;transform:translateY(20px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.modal-box h2{font-family:var(--font-head);font-weight:800;margin-bottom:20px;color:var(--indigo)}
.modal-box .form-group{margin-bottom:14px}
.modal-close{float:right;background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--text-muted);transition:color .2s}
.modal-close:hover{color:var(--text)}
.booking-summary{background:var(--bg);border-radius:var(--radius-sm);padding:16px;margin-bottom:20px}
.booking-summary .row{display:flex;justify-content:space-between;padding:6px 0;font-size:.9rem}
.booking-summary .row.total{font-weight:800;font-size:1.1rem;border-top:2px solid var(--border);padding-top:10px;margin-top:6px;color:var(--orange)}
.footer{background:var(--indigo);color:rgba(255,255,255,.7);padding:20px;text-align:center;font-size:.85rem;margin-top:auto}
.footer strong{color:#fff}
.flash{padding:12px 20px;border-radius:var(--radius-sm);margin-bottom:20px;font-weight:500;font-size:.9rem}
.flash-error{background:rgba(220,38,38,.1);color:#dc2626;border:1px solid rgba(220,38,38,.2)}
.flash-success{background:rgba(2,128,144,.1);color:var(--teal);border:1px solid rgba(2,128,144,.2)}
.profile-card{background:var(--card);border-radius:var(--radius);padding:32px;box-shadow:var(--shadow);max-width:520px;margin:0 auto}
.profile-card h2{font-family:var(--font-head);font-weight:800;color:var(--indigo);margin-bottom:20px}
.profile-row{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--border)}
.profile-row:last-child{border-bottom:none}
.profile-row .pk{font-weight:600;color:var(--text-muted);font-size:.9rem}
.profile-row .pv{font-weight:500}
.profile-meta{margin-top:24px;padding:14px;background:var(--bg);border-radius:var(--radius-sm);font-size:.8rem;color:var(--text-muted);text-align:center}
@media(max-width:900px){.detail-wrapper{grid-template-columns:1fr}.detail-sidebar{position:static}.chat-wrapper{grid-template-columns:1fr}.chat-list{max-height:200px}}
@media(max-width:640px){.navbar{padding:0 12px}.nav-links a,.nav-links button{padding:6px 10px;font-size:.8rem}.hero-section{padding:24px 18px}.hero-section h1{font-size:1.5rem}.form-grid{grid-template-columns:1fr}.cards-grid{grid-template-columns:1fr}}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.fade-up{animation:fadeUp .4s ease both}
.spinner{width:36px;height:36px;border:3px solid var(--border);border-top-color:var(--teal);border-radius:50%;animation:spin .7s linear infinite;margin:40px auto}
@keyframes spin{to{transform:rotate(360deg)}}
.text-center{text-align:center}.hidden{display:none!important}
</style>
</head>
<body>

<nav class="navbar">
    <a href="index.php" class="nav-brand"><i class="fas fa-location-dot"></i> ParkNPlace</a>
    <ul class="nav-links">
        <li><a href="index.php?view=home" class="<?= $view==='home'?'active':'' ?>"><i class="fas fa-compass"></i> Explore</a></li>
        <?php if (isLoggedIn()): ?>
        <li><a href="index.php?view=messages" class="<?= $view==='messages'?'active':'' ?>"><i class="fas fa-comments"></i> Messages <?php if($unread>0): ?><span class="nav-badge"><?= $unread ?></span><?php endif; ?></a></li>
        <?php if (currentUserRole()==='owner'): ?>
        <li><a href="index.php?view=add_space" class="<?= $view==='add_space'?'active':'' ?>"><i class="fas fa-plus-circle"></i> List Space</a></li>
        <?php endif; ?>
        <li><a href="index.php?view=dashboard" class="<?= $view==='dashboard'?'active':'' ?>"><i class="fas fa-th-large"></i> Dashboard</a></li>
        <li><a href="index.php?view=profile" class="<?= $view==='profile'?'active':'' ?>"><i class="fas fa-user-circle"></i> Profile</a></li>
        <li><form method="post" action="index.php" style="display:inline"><input type="hidden" name="form_action" value="logout"><button type="submit"><i class="fas fa-sign-out-alt"></i> Logout</button></form></li>
        <?php else: ?>
        <li><button class="btn-auth" onclick="openModal('loginModal')"><i class="fas fa-sign-in-alt"></i> Login</button></li>
        <li><button class="btn-auth" onclick="openModal('registerModal')" style="background:var(--teal)!important"><i class="fas fa-user-plus"></i> Register</button></li>
        <?php endif; ?>
    </ul>
</nav>

<?php if ($flashErr): ?><div class="main"><div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> <?= e($flashErr) ?></div></div><?php endif; ?>
<?php if ($flashOk): ?><div class="main"><div class="flash flash-success"><i class="fas fa-check-circle"></i> <?= e($flashOk) ?></div></div><?php endif; ?>

<div class="main">

<?php if ($view === 'home'): ?>
<section class="hero-section fade-up">
    <h1>Find Your Perfect Space</h1>
    <p>Smart rentals for parking, homes, and shops across Pune</p>
    <div class="search-row">
        <input type="text" id="searchInput" placeholder="Search by name or location..." aria-label="Search spaces">
        <button class="btn-search" onclick="searchSpaces()"><i class="fas fa-search"></i> Search</button>
        <button class="btn-geo" onclick="findNearMe()"><i class="fas fa-crosshairs"></i> Find Spaces Near My Live Location</button>
    </div>
</section>
<div class="filter-bar fade-up">
    <span class="label">Type:</span>
    <button class="filter-btn active" data-filter="type" data-value="All" onclick="setFilter(this)">All</button>
    <button class="filter-btn" data-filter="type" data-value="Home" onclick="setFilter(this)"><i class="fas fa-home"></i> Home</button>
    <button class="filter-btn" data-filter="type" data-value="Parking" onclick="setFilter(this)"><i class="fas fa-car"></i> Parking</button>
    <button class="filter-btn" data-filter="type" data-value="Shop" onclick="setFilter(this)"><i class="fas fa-store"></i> Shop</button>
    <div class="filter-divider"></div>
    <span class="label">For:</span>
    <button class="filter-btn active" data-filter="audience" data-value="All" onclick="setFilter(this)">All</button>
    <button class="filter-btn" data-filter="audience" data-value="student" onclick="setFilter(this)"><i class="fas fa-graduation-cap"></i> Student Hub</button>
    <button class="filter-btn" data-filter="audience" data-value="it_professional" onclick="setFilter(this)"><i class="fas fa-laptop-code"></i> IT Professionals</button>
    <button class="filter-btn" data-filter="audience" data-value="family" onclick="setFilter(this)"><i class="fas fa-users"></i> Family</button>
    <button class="filter-btn" data-filter="audience" data-value="couple" onclick="setFilter(this)"><i class="fas fa-heart"></i> Couples</button>
    <div class="filter-divider"></div>
    <span class="label">Sort:</span>
    <select id="sortSelect" onchange="searchSpaces()" style="padding:6px 12px;border:2px solid var(--border);border-radius:50px;font-size:.85rem;outline:none;cursor:pointer;">
        <option value="newest">Newest</option><option value="price_low">Price: Low to High</option><option value="price_high">Price: High to Low</option>
    </select>
</div>
<div id="cardsContainer" class="cards-grid"></div>
<div id="searchSpinner" class="hidden"><div class="spinner"></div></div>
<p id="noResults" class="hidden text-center" style="padding:40px;color:var(--text-muted);font-size:1.1rem;"><i class="fas fa-search" style="font-size:2rem;display:block;margin-bottom:10px;opacity:.3;"></i>No spaces found matching your criteria.</p>

<?php elseif ($view === 'property' && $property): ?>
<div class="fade-up">
    <a href="index.php?view=home" style="display:inline-flex;align-items:center;gap:6px;margin-bottom:16px;font-weight:600;color:var(--indigo);"><i class="fas fa-arrow-left"></i> Back to Explore</a>
    <div class="detail-wrapper">
        <div class="detail-main">
            <img src="<?= e($property['image_url']) ?>" alt="<?= e($property['title']) ?>" class="detail-hero-img" onerror="this.src='https://picsum.photos/seed/fallback/600/400'">
            <div class="detail-info">
                <h1><?= e($property['title']) ?></h1>
                <div class="detail-price-tag">&#8377;<?= number_format($property['price'],0) ?> <span>/ <?= e($property['pricing_model']) ?></span></div>
                <div class="detail-address"><i class="fas fa-map-marker-alt"></i> <?= e($property['address']) ?></div>
                <div class="detail-features">
                    <div class="detail-feature"><i class="fas fa-tag"></i><div class="val"><?= e($property['type']) ?></div><div class="lbl">Space Type</div></div>
                    <div class="detail-feature"><i class="fas fa-expand-arrows-alt"></i><div class="val"><?= $property['area'] ? e($property['area']).' sqft' : 'N/A' ?></div><div class="lbl">Area</div></div>
                    <?php if ($property['type']==='Home'): ?>
                    <div class="detail-feature"><i class="fas fa-bed"></i><div class="val"><?= $property['rooms'] ?? 'N/A' ?></div><div class="lbl">Rooms</div></div>
                    <div class="detail-feature"><i class="fas fa-bath"></i><div class="val"><?= $property['bathrooms'] ?? 'N/A' ?></div><div class="lbl">Bathrooms</div></div>
                    <?php elseif ($property['type']==='Parking'): ?>
                    <div class="detail-feature"><i class="fas fa-car"></i><div class="val"><?= e($property['vehicle_type']) ?></div><div class="lbl">Vehicle Type</div></div>
                    <div class="detail-feature"><i class="fas fa-bolt"></i><div class="val"><?= $property['has_ev'] ? 'Yes' : 'No' ?></div><div class="lbl">EV Charging</div></div>
                    <?php endif; ?>
                    <div class="detail-feature"><i class="fas fa-users"></i><div class="val"><?= e(ucfirst(str_replace('_',' ',$property['target_audience']))) ?></div><div class="lbl">Target</div></div>
                    <div class="detail-feature"><i class="fas fa-shield-alt"></i><div class="val">&#8377;<?= number_format($property['deposit'],0) ?></div><div class="lbl">Deposit</div></div>
                    <div class="detail-feature"><i class="fas fa-eye"></i><div class="val"><?= e($property['views']) ?></div><div class="lbl">Views</div></div>
                </div>
                <div id="detail-map"></div>
            </div>
        </div>
        <div class="detail-sidebar">
            <div class="owner-info">
                <div class="owner-avatar"><?= strtoupper(substr($property['owner_name'],0,1)) ?></div>
                <div class="owner-details">
                    <div class="name"><?= e($property['owner_name']) ?></div>
                    <div class="role">Space Owner</div>
                    <?php if ($property['owner_phone']): ?><div style="font-size:.8rem;margin-top:2px;"><i class="fas fa-phone" style="color:var(--teal);margin-right:4px;"></i><?= e($property['owner_phone']) ?></div><?php endif; ?>
                </div>
            </div>
            <?php if (isLoggedIn() && currentUserId() != $property['owner_id']): ?>
            <button class="btn-book" onclick="openBookingModal(<?= e($property['id']) ?>,'<?= e(addslashes($property['title'])) ?>',<?= e($property['price']) ?>,'<?= e($property['pricing_model']) ?>',<?= e($property['deposit']) ?>)"><i class="fas fa-bolt"></i> Book Now</button>
            <button class="btn-msg" onclick="startChat(<?= e($property['owner_id']) ?>,<?= e($property['id']) ?>)"><i class="fas fa-comment-dots"></i> Message Owner</button>
            <?php elseif (!isLoggedIn()): ?>
            <button class="btn-book" onclick="openModal('loginModal')"><i class="fas fa-bolt"></i> Login to Book</button>
            <button class="btn-msg" onclick="openModal('loginModal')"><i class="fas fa-comment-dots"></i> Login to Message</button>
            <?php else: ?>
            <p style="text-align:center;color:var(--text-muted);font-size:.9rem;">This is your own listing.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php elseif ($view === 'messages'): ?>
<?php if (!isLoggedIn()) { redirect('index.php'); } ?>
<div class="chat-wrapper fade-up">
    <div class="chat-list">
        <div class="chat-list-header"><i class="fas fa-comments" style="color:var(--teal);margin-right:6px;"></i> Conversations</div>
        <div id="conversationList"><div class="text-center" style="padding:30px;color:var(--text-muted);"><div class="spinner"></div></div></div>
    </div>
    <div class="chat-panel" id="chatPanel">
        <div id="chatEmpty" class="chat-empty">
            <i class="fas fa-comments"></i>
            <p>Select a conversation to start chatting</p>
        </div>
        <div id="chatActive" style="display:none;flex-direction:column;height:100%;">
            <div class="chat-panel-header">
                <div class="cp-name" id="chatPartnerName">-</div>
                <div class="cp-prop" id="chatPropName">-</div>
            </div>
            <div class="chat-messages" id="chatMessages"></div>
            <div class="chat-input-area">
                <input type="text" id="chatInput" placeholder="Type a message..." onkeydown="if(event.key==='Enter'){event.preventDefault();sendMessage();}">
                <button onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
            </div>
        </div>
    </div>
</div>

<?php elseif ($view === 'add_space'): ?>
<?php if (!isLoggedIn() || currentUserRole() !== 'owner') { redirect('index.php'); } ?>
<div class="form-card fade-up">
    <h2><i class="fas fa-plus-circle" style="color:var(--orange);margin-right:8px;"></i>List a New Space</h2>
    <form method="post" action="index.php">
        <input type="hidden" name="form_action" value="create_space">
        <div class="form-grid">
            <div class="form-group full"><label>Title</label><input type="text" name="title" required placeholder="e.g. Premium Parking Spot Near IT Park"></div>
            <div class="form-group"><label>Space Type</label><select name="type" id="spaceType" required onchange="toggleDynamicFields()"><option value="Home">Home / Apartment</option><option value="Parking">Parking Spot</option><option value="Shop">Shop / Commercial</option></select></div>
            <div class="form-group"><label>Target Audience</label><select name="target_audience"><option value="none">General</option><option value="student">Student</option><option value="it_professional">IT Professional</option><option value="family">Family</option><option value="couple">Couple</option></select></div>
            <div class="form-group"><label>Pricing Model</label><select name="pricing_model" id="pricingModel"><option value="monthly">Monthly</option><option value="daily">Daily</option><option value="hourly">Hourly</option></select></div>
            <div class="form-group"><label>Price (&#8377;)</label><input type="number" name="price" step="0.01" min="1" required placeholder="e.g. 500"></div>
            <div class="form-group"><label>Security Deposit (&#8377;)</label><input type="number" name="deposit" step="0.01" min="0" value="0"></div>
            <div class="form-group"><label>Area (sqft)</label><input type="number" name="area" step="0.01" min="1" placeholder="e.g. 200"></div>
            <div class="form-group home-field"><label>Rooms</label><input type="number" name="rooms" min="1" placeholder="e.g. 2"></div>
            <div class="form-group home-field"><label>Bathrooms</label><input type="number" name="bathrooms" min="1" placeholder="e.g. 1"></div>
            <div class="form-group parking-field hidden"><label>Vehicle Type</label><select name="vehicle_type"><option value="2-wheeler">2-Wheeler</option><option value="4-wheeler">4-Wheeler</option><option value="any">Any</option></select></div>
            <div class="form-group parking-field hidden"><div class="checkbox-row"><input type="checkbox" name="has_ev" id="hasEv" value="1"><label for="hasEv">EV Charging Available</label></div></div>

            <!-- ====== LOCATION SECTION (REDESIGNED) ====== -->
            <div class="form-group full" style="margin-top:8px;"><label style="font-size:1rem;color:var(--indigo);font-weight:700;"><i class="fas fa-map-marker-alt" style="color:var(--orange);margin-right:4px;"></i> Property Location</label></div>
            <div class="form-group full">
                <button type="button" id="btnUseMyLocation" class="btn-geo-space" onclick="useMyLocationForSpace()">
                    <i class="fas fa-crosshairs"></i> Use My Live Location
                </button>
                <div id="locationStatus" class="location-status"></div>
            </div>
            <div class="form-group full">
                <label>Or Search Address on Map</label>
                <div class="map-search-wrap">
                    <input type="text" id="mapSearchInput" placeholder="Search area, landmark, or street..." onkeydown="if(event.key==='Enter'){event.preventDefault();searchMapAddress();}">
                    <button type="button" onclick="searchMapAddress()"><i class="fas fa-search"></i></button>
                    <div id="mapSearchResults" class="map-search-results"></div>
                </div>
            </div>
            <div class="form-group full"><label>Address</label><textarea name="address" id="addAddress" required placeholder="Will auto-fill from map or location..."></textarea></div>
            <div class="form-group"><label>Latitude</label><input type="text" name="latitude" id="addLat" placeholder="Auto-filled" step="any"></div>
            <div class="form-group"><label>Longitude</label><input type="text" name="longitude" id="addLng" placeholder="Auto-filled" step="any"></div>
            <div class="form-group full"><label>Click map or drag marker to fine-tune exact position</label><div id="add-map"></div></div>
            <!-- ====== END LOCATION SECTION ====== -->

            <div class="form-group full"><label>Image URL (optional)</label><input type="url" name="image_url" placeholder="https://... or leave blank for auto"></div>
        </div>
        <button type="submit" class="btn-submit"><i class="fas fa-check-circle"></i> Submit Listing</button>
    </form>
</div>

<?php elseif ($view === 'dashboard'): ?>
<?php if (!isLoggedIn()) { redirect('index.php'); } ?>
<h2 class="fade-up" style="font-family:var(--font-head);font-weight:800;color:var(--indigo);margin-bottom:20px;"><i class="fas fa-th-large" style="color:var(--orange);margin-right:8px;"></i>Dashboard</h2>
<div class="dash-grid fade-up">
    <?php if (currentUserRole()==='owner' && $mySpaces): ?>
    <div class="dash-card" style="grid-column:1/-1;">
        <h3><i class="fas fa-building" style="color:var(--teal);margin-right:6px;"></i> My Listed Spaces</h3>
        <?php foreach ($mySpaces as $s): ?>
        <div class="dash-item"><div><strong><?= e($s['title']) ?></strong><div style="font-size:.8rem;color:var(--text-muted);"><?= e($s['type']) ?> &bull; &#8377;<?= number_format($s['price'],0) ?>/<?= e($s['pricing_model']) ?> &bull; <?= e($s['booking_count']) ?> bookings</div></div><span class="status-badge status-<?= e($s['status']) ?>"><?= e($s['status']) ?></span></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if (currentUserRole()==='tenant' && $myBookings): ?>
    <div class="dash-card" style="grid-column:1/-1;">
        <h3><i class="fas fa-receipt" style="color:var(--orange);margin-right:6px;"></i> My Bookings</h3>
        <?php foreach ($myBookings as $b): ?>
        <div class="dash-item"><div><strong><?= e($b['space_title']) ?></strong><div style="font-size:.8rem;color:var(--text-muted);">Booking #<?= e($b['id']) ?> &bull; &#8377;<?= number_format($b['amount'],0) ?> &bull; <?= e($b['created_at']) ?></div></div><span class="status-badge status-<?= e($b['status']) ?>"><?= e($b['status']) ?></span></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if (currentUserRole()==='admin' && $pendingSpaces): ?>
    <div class="dash-card" style="grid-column:1/-1;">
        <h3><i class="fas fa-shield-alt" style="color:var(--indigo);margin-right:6px;"></i> Pending Verifications</h3>
        <?php foreach ($pendingSpaces as $ps): ?>
        <div class="dash-item"><div><strong><?= e($ps['title']) ?></strong><div style="font-size:.8rem;color:var(--text-muted);">By <?= e($ps['owner_name']) ?> &bull; <?= e($ps['type']) ?> &bull; &#8377;<?= number_format($ps['price'],0) ?>/<?= e($ps['pricing_model']) ?></div></div><button class="btn-sm btn-verify" onclick="verifySpace(<?= e($ps['id']) ?>)"><i class="fas fa-check"></i> Verify</button></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($view === 'profile'): ?>
<?php if (!isLoggedIn()) { redirect('index.php'); } ?>
<div class="profile-card fade-up">
    <h2><i class="fas fa-user-circle" style="color:var(--teal);margin-right:8px;"></i>My Profile</h2>
    <div class="profile-row"><span class="pk">Name</span><span class="pv"><?= e(currentUserName()) ?></span></div>
    <div class="profile-row"><span class="pk">Email</span><span class="pv"><?= e($_SESSION['user_email']) ?></span></div>
    <div class="profile-row"><span class="pk">Role</span><span class="pv" style="text-transform:capitalize;"><?= e(currentUserRole()) ?></span></div>
    <div class="profile-row"><span class="pk">Phone</span><span class="pv"><?= e($_SESSION['user_phone'] ?? 'N/A') ?></span></div>
    <div class="profile-meta">Designed &amp; Developed by Dinesh Vitthal Sabale</div>
</div>
<?php endif; ?>

</div>

<footer class="footer"><strong>ParkNPlace</strong> &mdash; Smart Space Rentals &bull; Designed &amp; Developed by Dinesh Vitthal Sabale</footer>

<!-- LOGIN MODAL -->
<div class="modal-overlay" id="loginModal"><div class="modal-box"><button class="modal-close" onclick="closeModal('loginModal')">&times;</button><h2><i class="fas fa-sign-in-alt" style="color:var(--teal);margin-right:8px;"></i>Login</h2><form method="post" action="index.php"><input type="hidden" name="form_action" value="login"><div class="form-group"><label>Email</label><input type="email" name="email" required></div><div class="form-group"><label>Password</label><input type="password" name="password" required></div><button type="submit" class="btn-submit" style="width:100%;"><i class="fas fa-sign-in-alt"></i> Login</button></form><p style="margin-top:14px;text-align:center;font-size:.88rem;color:var(--text-muted);">Don't have an account? <a href="#" onclick="closeModal('loginModal');openModal('registerModal');">Register here</a></p></div></div>

<!-- REGISTER MODAL -->
<div class="modal-overlay" id="registerModal"><div class="modal-box"><button class="modal-close" onclick="closeModal('registerModal')">&times;</button><h2><i class="fas fa-user-plus" style="color:var(--orange);margin-right:8px;"></i>Register</h2><form method="post" action="index.php"><input type="hidden" name="form_action" value="register"><div class="form-group"><label>Full Name</label><input type="text" name="name" required></div><div class="form-group"><label>Email</label><input type="email" name="email" required></div><div class="form-group"><label>Password</label><input type="password" name="password" required minlength="6"></div><div class="form-group"><label>Phone</label><input type="text" name="phone" placeholder="+91..."></div><div class="form-group"><label>I am a</label><select name="role" required><option value="tenant">Tenant / Seeker</option><option value="owner">Space Owner</option></select></div><button type="submit" class="btn-submit" style="width:100%;"><i class="fas fa-user-plus"></i> Create Account</button></form></div></div>

<!-- BOOKING MODAL -->
<div class="modal-overlay" id="bookingModal"><div class="modal-box"><button class="modal-close" onclick="closeModal('bookingModal')">&times;</button><h2><i class="fas fa-bolt" style="color:var(--orange);margin-right:8px;"></i>Confirm Booking</h2><div class="booking-summary" id="bookingSummary"></div><div class="form-group"><label>Your Name</label><input type="text" id="bkTenantName" value="<?= e(currentUserName()) ?>"></div><div class="form-group"><label>Your Phone</label><input type="text" id="bkTenantPhone" value="<?= e($_SESSION['user_phone'] ?? '') ?>"></div><div class="form-group"><label id="bkDurationLabel">Duration</label><input type="number" id="bkDuration" min="1" step="1" value="1" oninput="updateBookingTotal()"></div><button class="btn-submit" style="width:100%;" onclick="confirmBooking()"><i class="fas fa-check-circle"></i> Confirm &amp; Download Invoice</button></div></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
var activeFilters={type:'All',audience:'All'};
var userLat=null,userLng=null;
var currentBooking={};
var chatState={property_id:null,other_id:null};
var chatPollTimer=null;
var convPollTimer=null;
var addMapObj=null;
var addMapMarker=null;

function openModal(id){document.getElementById(id).classList.add('active')}
function closeModal(id){document.getElementById(id).classList.remove('active')}
document.querySelectorAll('.modal-overlay').forEach(function(ov){ov.addEventListener('click',function(e){if(e.target===ov)ov.classList.remove('active')})});

function setFilter(btn){var ft=btn.dataset.filter;activeFilters[ft]=btn.dataset.value;document.querySelectorAll('.filter-btn[data-filter="'+ft+'"]').forEach(function(b){b.classList.remove('active')});btn.classList.add('active');searchSpaces()}

function searchSpaces(){
    var c=document.getElementById('cardsContainer'),sp=document.getElementById('searchSpinner'),nr=document.getElementById('noResults');
    if(!c)return;c.innerHTML='';sp.classList.remove('hidden');nr.classList.add('hidden');
    var p=new URLSearchParams({action:'search_spaces',type:activeFilters.type,audience:activeFilters.audience,search:document.getElementById('searchInput')?document.getElementById('searchInput').value:'',sort:document.getElementById('sortSelect')?document.getElementById('sortSelect').value:'newest',lat:userLat||'',lng:userLng||''});
    fetch('index.php?'+p.toString()).then(function(r){return r.json()}).then(function(d){sp.classList.add('hidden');if(!d.length){nr.classList.remove('hidden');return}c.innerHTML=d.map(function(s){return renderCard(s)}).join('')}).catch(function(){sp.classList.add('hidden')})
}

function renderCard(s){
    var bc=s.type==='Home'?'badge-home':s.type==='Parking'?'badge-parking':'badge-shop';
    var ml=s.pricing_model==='hourly'?'/hr':s.pricing_model==='daily'?'/day':'/mo';
    var al=s.target_audience.replace(/_/g,' ');
    var dl=s.distance!=null?'<span class="card-tag" style="background:rgba(245,130,32,.1);color:var(--orange);">'+parseFloat(s.distance).toFixed(1)+' km</span>':'';
    var ev=s.has_ev==1?'<span class="card-tag"><i class="fas fa-bolt"></i> EV</span>':'';
    var mi='';if(s.type==='Home'){mi=(s.rooms?s.rooms+' bed':'')+' '+(s.bathrooms?s.bathrooms+' bath':'')}else if(s.type==='Parking'){mi=s.vehicle_type!=='none'?s.vehicle_type:''}
    return '<div class="space-card" onclick="window.location=\'index.php?view=property&id='+s.id+'\'"><img src="'+escHtml(s.image_url)+'" alt="'+escHtml(s.title)+'" class="card-img" onerror="this.src=\'https://picsum.photos/seed/fb'+s.id+'/600/400\'"><span class="card-badge '+bc+'">'+escHtml(s.type)+'</span><span class="card-views"><i class="fas fa-eye"></i> '+s.views+'</span><div class="card-body"><h3>'+escHtml(s.title)+'</h3><div class="card-price">&#8377;'+numberFormat(s.price)+'<span>'+ml+'</span></div><div class="card-meta">'+(s.area?'<span><i class="fas fa-expand-arrows-alt"></i> '+s.area+' sqft</span>':'')+(mi?'<span><i class="fas fa-info-circle"></i> '+mi+'</span>':'')+'</div><div class="card-tags"><span class="card-tag">'+al+'</span>'+ev+dl+'</div></div></div>'
}

function findNearMe(){
    if(!navigator.geolocation){alert('Geolocation is not supported.');return}
    var b=document.querySelector('.btn-geo'),o=b.innerHTML;b.innerHTML='<i class="fas fa-spinner fa-spin"></i> Locating...';
    navigator.geolocation.getCurrentPosition(function(p){userLat=p.coords.latitude;userLng=p.coords.longitude;b.innerHTML='<i class="fas fa-check-circle"></i> Found!';searchSpaces();setTimeout(function(){b.innerHTML=o},3000)},function(){b.innerHTML=o;alert('Unable to get location. Please allow access.')})
}

function initDetailMap(lat,lng,title){var m=document.getElementById('detail-map');if(!m)return;var map=L.map('detail-map').setView([lat,lng],15);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'&copy; OpenStreetMap'}).addTo(map);L.marker([lat,lng]).addTo(map).bindPopup(title).openPopup();setTimeout(function(){map.invalidateSize()},300)}

// ================================================================
// ADD SPACE: USE MY LIVE LOCATION
// ================================================================
function useMyLocationForSpace(){
    if(!navigator.geolocation){showLocationStatus('error','Geolocation is not supported by your browser.');return}
    var btn=document.getElementById('btnUseMyLocation');
    var origHTML=btn.innerHTML;
    btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Detecting your location...';
    btn.disabled=true;
    showLocationStatus('','');

    navigator.geolocation.getCurrentPosition(function(pos){
        var lat=pos.coords.latitude,lng=pos.coords.longitude;
        document.getElementById('addLat').value=lat.toFixed(8);
        document.getElementById('addLng').value=lng.toFixed(8);
        if(addMapObj){
            addMapObj.setView([lat,lng],16);
            if(addMapMarker)addMapObj.removeLayer(addMapMarker);
            addMapMarker=L.marker([lat,lng],{draggable:true}).addTo(addMapObj).bindPopup('Your Location - Drag to adjust').openPopup();
            addMapMarker.on('dragend',function(ev){
                var p=ev.target.getLatLng();
                document.getElementById('addLat').value=p.lat.toFixed(8);
                document.getElementById('addLng').value=p.lng.toFixed(8);
                reverseGeocode(p.lat,p.lng);
            });
        }
        reverseGeocode(lat,lng);
        btn.innerHTML='<i class="fas fa-check-circle"></i> Location Found!';
        setTimeout(function(){btn.innerHTML=origHTML;btn.disabled=false},3000);
    },function(err){
        btn.innerHTML=origHTML;btn.disabled=false;
        var msg='Unable to get location.';
        if(err.code===1)msg='Location permission denied. Please allow in browser settings.';
        else if(err.code===2)msg='Location unavailable.';
        else if(err.code===3)msg='Location request timed out.';
        showLocationStatus('error','<i class="fas fa-exclamation-triangle"></i> '+msg);
    },{enableHighAccuracy:true,timeout:15000})
}

function reverseGeocode(lat,lng){
    fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat='+lat+'&lon='+lng+'&zoom=18&addressdetails=1')
    .then(function(r){return r.json()})
    .then(function(data){
        if(data.display_name){
            document.getElementById('addAddress').value=data.display_name;
            showLocationStatus('success','<i class="fas fa-check-circle"></i> Address auto-filled from your location');
        }else{
            showLocationStatus('success','<i class="fas fa-check-circle"></i> Coordinates set. Please fill the address manually.');
        }
    })
    .catch(function(){
        showLocationStatus('success','<i class="fas fa-check-circle"></i> Coordinates set. Please fill the address manually.');
    })
}

function showLocationStatus(type,html){
    var el=document.getElementById('locationStatus');
    if(!el)return;
    el.className='location-status';
    if(type){el.classList.add(type)}
    el.innerHTML=html;
}

// ================================================================
// ADD SPACE: MAP SEARCH (GEOCODING)
// ================================================================
var searchTimer=null;
function searchMapAddress(){
    var q=document.getElementById('mapSearchInput').value.trim();
    if(!q)return;
    var res=document.getElementById('mapSearchResults');
    res.innerHTML='<div style="padding:12px;color:var(--text-muted);font-size:.85rem;"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
    res.classList.add('active');

    fetch('https://nominatim.openstreetmap.org/search?format=json&q='+encodeURIComponent(q)+'&limit=6&countrycodes=in')
    .then(function(r){return r.json()})
    .then(function(data){
        if(!data.length){res.innerHTML='<div style="padding:12px;color:var(--text-muted);font-size:.85rem;">No results found</div>';return}
        res.innerHTML=data.map(function(item,i){
            return '<div class="map-search-item" onclick="selectMapSearchResult('+item.lat+','+item.lon+',this)" data-name="'+escAttr(item.display_name)+'">'+
                '<i class="fas fa-map-marker-alt" style="color:var(--orange);margin-right:6px;"></i>'+
                escHtml(item.display_name)+'</div>';
        }).join('');
    })
    .catch(function(){res.innerHTML='<div style="padding:12px;color:var(--text-muted);font-size:.85rem;">Search failed. Try again.</div>'})
}

function selectMapSearchResult(lat,lng,el){
    document.getElementById('addLat').value=parseFloat(lat).toFixed(8);
    document.getElementById('addLng').value=parseFloat(lng).toFixed(8);
    document.getElementById('addAddress').value=el.dataset.name;
    document.getElementById('mapSearchInput').value='';
    document.getElementById('mapSearchResults').classList.remove('active');
    if(addMapObj){
        addMapObj.setView([lat,lng],16);
        if(addMapMarker)addMapObj.removeLayer(addMapMarker);
        addMapMarker=L.marker([lat,lng],{draggable:true}).addTo(addMapObj).bindPopup('Selected Location - Drag to adjust').openPopup();
        addMapMarker.on('dragend',function(ev){
            var p=ev.target.getLatLng();
            document.getElementById('addLat').value=p.lat.toFixed(8);
            document.getElementById('addLng').value=p.lng.toFixed(8);
            reverseGeocode(p.lat,p.lng);
        });
    }
    showLocationStatus('success','<i class="fas fa-check-circle"></i> Location set from search');
}

// Close search results when clicking outside
document.addEventListener('click',function(e){
    var sr=document.getElementById('mapSearchResults');
    if(sr && !e.target.closest('.map-search-wrap')){sr.classList.remove('active')}
});

// ================================================================
// ADD SPACE: DYNAMIC FIELDS + MAP INIT
// ================================================================
function toggleDynamicFields(){
    var t=document.getElementById('spaceType').value;
    document.querySelectorAll('.home-field').forEach(function(el){el.classList.toggle('hidden',t!=='Home')});
    document.querySelectorAll('.parking-field').forEach(function(el){el.classList.toggle('hidden',t!=='Parking')});
    var pm=document.getElementById('pricingModel');
    if(t==='Parking')pm.value='hourly';else if(t==='Shop')pm.value='daily';else pm.value='monthly'
}

function initAddMap(){
    var m=document.getElementById('add-map');if(!m)return;
    addMapObj=L.map('add-map').setView([18.5204,73.8567],12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'&copy; OpenStreetMap'}).addTo(addMapObj);
    addMapObj.on('click',function(e){
        if(addMapMarker)addMapObj.removeLayer(addMapMarker);
        addMapMarker=L.marker(e.latlng,{draggable:true}).addTo(addMapObj).bindPopup('Drag to adjust').openPopup();
        document.getElementById('addLat').value=e.latlng.lat.toFixed(8);
        document.getElementById('addLng').value=e.latlng.lng.toFixed(8);
        reverseGeocode(e.latlng.lat,e.latlng.lng);
        addMapMarker.on('dragend',function(ev){
            var p=ev.target.getLatLng();
            document.getElementById('addLat').value=p.lat.toFixed(8);
            document.getElementById('addLng').value=p.lng.toFixed(8);
            reverseGeocode(p.lat,p.lng);
        });
    });
    setTimeout(function(){addMapObj.invalidateSize()},300)
}

// ================================================================
// BOOKING SYSTEM
// ================================================================
function openBookingModal(pid,title,price,model,deposit){
    currentBooking={propId:pid,title:title,price:price,model:model,deposit:deposit};
    var lm={hourly:'Hours',daily:'Days',monthly:'Months'};
    document.getElementById('bkDurationLabel').textContent='Duration ('+lm[model]+')';
    document.getElementById('bkDuration').value=1;updateBookingTotal();openModal('bookingModal')
}
function updateBookingTotal(){
    var d=parseFloat(document.getElementById('bkDuration').value)||1;
    var t=currentBooking.price*d+(currentBooking.model==='monthly'?currentBooking.deposit:0);
    var ml={hourly:'/hr',daily:'/day',monthly:'/mo'}[currentBooking.model]||'';
    document.getElementById('bookingSummary').innerHTML=
        '<div class="row"><span>Space</span><span>'+escHtml(currentBooking.title)+'</span></div>'+
        '<div class="row"><span>Rate</span><span>&#8377;'+numberFormat(currentBooking.price)+ml+'</span></div>'+
        '<div class="row"><span>Duration</span><span>'+d+' '+(currentBooking.model==='hourly'?'hour(s)':currentBooking.model==='daily'?'day(s)':'month(s)')+'</span></div>'+
        (currentBooking.model==='monthly'&&currentBooking.deposit>0?'<div class="row"><span>Security Deposit</span><span>&#8377;'+numberFormat(currentBooking.deposit)+'</span></div>':'')+
        '<div class="row total"><span>Total</span><span>&#8377;'+numberFormat(t)+'</span></div>'
}
function confirmBooking(){
    var d=document.getElementById('bkDuration').value,tn=document.getElementById('bkTenantName').value,tp=document.getElementById('bkTenantPhone').value;
    if(!tn||!tp){alert('Please fill in your name and phone.');return}
    var fd=new FormData();fd.append('property_id',currentBooking.propId);fd.append('duration',d);fd.append('tenant_name',tn);fd.append('tenant_phone',tp);
    fetch('index.php?action=create_booking',{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(data){if(data.error){alert(data.error);return}closeModal('bookingModal');generatePDF(data)}).catch(function(){alert('Booking failed.')})
}

// ================================================================
// PDF INVOICE
// ================================================================
function generatePDF(data){
    var jsPDF=window.jspdf.jsPDF,doc=new jsPDF(),pw=doc.internal.pageSize.getWidth();
    doc.setFillColor(19,64,116);doc.rect(0,0,pw,40,'F');doc.setTextColor(255,255,255);doc.setFont('helvetica','bold');doc.setFontSize(22);doc.text('ParkNPlace',20,25);doc.setFontSize(10);doc.text('Rental Invoice / Parking Token',20,34);doc.setFontSize(7);doc.setTextColor(200,210,230);doc.text('Designed & Developed by Dinesh Vitthal Sabale',pw-20,34,{align:'right'});
    doc.setTextColor(30,30,30);doc.setFontSize(11);var y=55;
    function ar(l,v){doc.setFont('helvetica','normal');doc.setTextColor(100,100,100);doc.text(l,20,y);doc.setFont('helvetica','bold');doc.setTextColor(30,30,30);doc.text(String(v),80,y);y+=10}
    ar('Booking ID','#'+data.booking_id);ar('Transaction Code','PNP-'+Date.now()+'-'+data.booking_id);ar('Property',data.title);ar('Type',data.type);ar('Pricing Model',data.model.charAt(0).toUpperCase()+data.model.slice(1));ar('Rate','\u20B9'+numberFormat(data.rate)+(data.model==='hourly'?'/hr':data.model==='daily'?'/day':'/mo'));ar('Duration',data.duration+' '+(data.model==='hourly'?'hour(s)':data.model==='daily'?'day(s)':'month(s)'));
    if(data.model==='monthly'&&data.deposit>0)ar('Security Deposit','\u20B9'+numberFormat(data.deposit));
    ar('Tenant Name',data.tname);ar('Tenant Phone',data.tphone);ar('Address',data.address||'As listed');
    y+=5;doc.setDrawColor(245,130,32);doc.setLineWidth(.8);doc.line(20,y,pw-20,y);y+=12;doc.setFontSize(14);doc.setTextColor(245,130,32);doc.text('Total Amount: \u20B9'+numberFormat(data.amount),20,y);y+=10;doc.setFontSize(9);doc.setTextColor(100,100,100);doc.text('Status: CONFIRMED',20,y);y+=8;doc.text('Booked on: '+new Date().toLocaleString(),20,y);
    var fy=doc.internal.pageSize.getHeight()-25;doc.setFillColor(2,128,144);doc.rect(0,fy,pw,25,'F');doc.setTextColor(255,255,255);doc.setFontSize(8);doc.text('ParkNPlace - Smart Space Rentals | Designed & Developed by Dinesh Vitthal Sabale',pw/2,fy+12,{align:'center'});
    doc.save('ParkNPlace_Invoice_'+data.booking_id+'.pdf')
}

// ================================================================
// CHAT SYSTEM (FULLY FIXED)
// ================================================================
function loadConversations(){
    fetch('index.php?action=get_conversations')
    .then(function(r){return r.json()})
    .then(function(data){
        var list=document.getElementById('conversationList');if(!list)return;
        if(!data.length){list.innerHTML='<div style="padding:30px;text-align:center;color:var(--text-muted);font-size:.9rem;"><i class="fas fa-inbox" style="font-size:1.5rem;display:block;margin-bottom:8px;opacity:.3;"></i>No conversations yet</div>';return}
        list.innerHTML=data.map(function(c){
            var isA=(chatState.property_id==c.property_id&&chatState.other_id==c.other_id)?' active':'';
            var ub=c.unread>0?'<span class="chat-unread-badge">'+c.unread+'</span>':'';
            return '<div class="chat-list-item'+isA+'" data-oid="'+c.other_id+'" data-pid="'+c.property_id+'" data-oname="'+escAttr(c.other_name)+'" data-ptitle="'+escAttr(c.property_title)+'" onclick="openChatFromList(this)"><div class="chat-av">'+escHtml(c.other_name).charAt(0).toUpperCase()+'</div><div class="info"><div class="cname">'+escHtml(c.other_name)+'</div><div class="cprop">'+escHtml(c.property_title)+'</div></div>'+ub+'</div>';
        }).join('');
    }).catch(function(err){console.error('Conv load error:',err)})
}

function openChatFromList(el){
    var oid=parseInt(el.dataset.oid),pid=parseInt(el.dataset.pid),oname=el.dataset.oname,ptitle=el.dataset.ptitle;
    openChatWith(oid,pid,oname,ptitle);
    document.querySelectorAll('.chat-list-item').forEach(function(i){i.classList.remove('active')});
    el.classList.add('active')
}

function openChatWith(oid,pid,oname,ptitle){
    chatState.other_id=oid;chatState.property_id=pid;
    document.getElementById('chatPartnerName').textContent=oname;
    document.getElementById('chatPropName').textContent=ptitle;
    document.getElementById('chatEmpty').style.display='none';
    document.getElementById('chatActive').style.display='flex';
    loadMessages();
    if(chatPollTimer)clearInterval(chatPollTimer);
    chatPollTimer=setInterval(loadMessages,4000)
}

function loadMessages(){
    if(!chatState.property_id||!chatState.other_id)return;
    var cuid=<?= isLoggedIn() ? currentUserId() : 0 ?>;
    fetch('index.php?action=get_messages&property_id='+chatState.property_id+'&other_id='+chatState.other_id)
    .then(function(r){return r.json()})
    .then(function(data){
        var c=document.getElementById('chatMessages');if(!c)return;
        if(!data.length){c.innerHTML='<div style="text-align:center;padding:50px 20px;color:var(--text-muted);"><i class="fas fa-comment-dots" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:12px;"></i><div style="font-size:.95rem;font-weight:500;">No messages yet</div><div style="font-size:.82rem;margin-top:4px;">Send a message to start the conversation</div></div>';return}
        var wb=c.scrollTop+c.clientHeight>=c.scrollHeight-50;
        c.innerHTML=data.map(function(m){
            var cls=m.sender_id==cuid?'sent':'received';
            return '<div class="msg-bubble '+cls+'">'+escHtml(m.message)+'<div class="msg-time">'+m.timestamp+'</div></div>'
        }).join('');
        if(wb||data.length<=2)c.scrollTop=c.scrollHeight
    }).catch(function(err){console.error('Msg load error:',err)})
}

function sendMessage(){
    var inp=document.getElementById('chatInput'),msg=inp.value.trim();
    if(!msg||!chatState.property_id||!chatState.other_id)return;
    var fd=new FormData();fd.append('property_id',chatState.property_id);fd.append('receiver_id',chatState.other_id);fd.append('message',msg);
    fetch('index.php?action=send_message',{method:'POST',body:fd})
    .then(function(r){return r.json()})
    .then(function(data){
        if(data.success){inp.value='';loadMessages();loadConversations()}
        else{alert(data.error||'Failed to send')}
    })
    .catch(function(){alert('Failed to send. Try again.')})
}

function startChat(ownerId,propId){
    window.location.href='index.php?view=messages&chat_to='+ownerId+'&chat_prop='+propId
}

function verifySpace(sid){
    var fd=new FormData();fd.append('space_id',sid);
    fetch('index.php?action=verify_space',{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){if(d.success)location.reload();else alert('Failed')})
}

// ================================================================
// UTILITIES
// ================================================================
function escHtml(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML}
function escAttr(s){return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function numberFormat(n){return parseFloat(n).toLocaleString('en-IN',{minimumFractionDigits:0,maximumFractionDigits:0})}

// ================================================================
// INIT
// ================================================================
document.addEventListener('DOMContentLoaded',function(){
    if(document.getElementById('cardsContainer'))searchSpaces();
    <?php if ($view==='property' && $property): ?>
    initDetailMap(<?= e($property['latitude']??18.5204) ?>,<?= e($property['longitude']??73.8567) ?>,'<?= e(addslashes($property['title'])) ?>');
    <?php endif; ?>
    <?php if ($view==='add_space'): ?>
    initAddMap();toggleDynamicFields();
    <?php endif; ?>
    <?php if ($view==='messages'): ?>
    loadConversations();
    // Auto-refresh conversation list every 8 seconds
    convPollTimer=setInterval(loadConversations,8000);
    // Check if navigated from property page
    var up=new URLSearchParams(window.location.search),ct=up.get('chat_to'),cp=up.get('chat_prop');
    if(ct&&cp){
        fetch('index.php?action=get_chat_info&other_id='+ct+'&property_id='+cp)
        .then(function(r){return r.json()})
        .then(function(data){
            openChatWith(parseInt(ct),parseInt(cp),data.other_name,data.property_title);
            setTimeout(loadConversations,1000)
        })
        .catch(function(err){console.error('Chat info error:',err)})
    }
    <?php endif; ?>
    document.querySelectorAll('.flash').forEach(function(el){setTimeout(function(){el.style.opacity='0'},4000);setTimeout(function(){el.remove()},4500)})
});
</script>
</body>
</html>