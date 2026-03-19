<?php
include 'config.php';
$conn = db_connect();

/* ── AJAX: fetch single article ── */
if (isset($_GET['get_article'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id   = (int)$_GET['get_article'];
    $stmt = $conn->prepare("
        SELECT id, title, content, source, category, link,
               published_date, scraped_at,
               is_political, is_election_related, is_toxic
        FROM ecn.news_articles WHERE id=? LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    echo $row ? json_encode($row, JSON_UNESCAPED_UNICODE) : json_encode(['error'=>'Not found']);
    exit;
}

/* ── AJAX: POST actions ── */
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'mark_read' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE ecn.news_articles SET is_read=1 WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute();
        echo json_encode(['ok'=>true]);

    } elseif ($action === 'mark_unread' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE ecn.news_articles SET is_read=0 WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute();
        echo json_encode(['ok'=>true]);

    } elseif ($action === 'mark_all_read') {
        $date = $_POST['date'] ?? date('Y-m-d');
        $s = $date.' 00:00:00'; $e = $date.' 23:59:59';
        $stmt = $conn->prepare("UPDATE ecn.news_articles SET is_read=1 WHERE scraped_at BETWEEN ? AND ?");
        $stmt->bind_param('ss', $s, $e); $stmt->execute();
        echo json_encode(['ok'=>true]);

    } elseif ($action === 'deactivate_site' && isset($_POST['site_name'])) {
        $name = trim($_POST['site_name']);
        // Try exact name match first
        $stmt = $conn->prepare("UPDATE ecn.news_sites SET active=0 WHERE name=? LIMIT 1");
        $stmt->bind_param('s', $name); $stmt->execute();
        if ($stmt->affected_rows > 0) {
            echo json_encode(['ok'=>true]);
        } else {
            // Fallback: LIKE match on name or base_url
            $like = '%'.$name.'%';
            $stmt2 = $conn->prepare("UPDATE ecn.news_sites SET active=0 WHERE name LIKE ? OR base_url LIKE ? LIMIT 1");
            $stmt2->bind_param('ss', $like, $like); $stmt2->execute();
            echo json_encode(['ok'=>true, 'affected'=>$stmt2->affected_rows]);
        }

    } else {
        echo json_encode(['ok'=>false]);
    }
    exit;
}

/* ── Settings ── */
$perPageAllowed = [10, 20, 30, 50, 100, 200];
$perPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $perPageAllowed)
           ? (int)$_GET['per_page'] : 20;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

/* ── Sorting ── */
$allowed_sort = ['id','title','category','source','published_date','scraped_at'];
$sort         = in_array($_GET['sort'] ?? '', $allowed_sort) ? $_GET['sort'] : 'scraped_at';
$order        = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$toggle_order = $order === 'ASC' ? 'DESC' : 'ASC';

/* ── Filters ── */
$where = []; $params = []; $types = '';

$showAll    = isset($_GET['date_range']) && $_GET['date_range'] === 'all';
$activeDate = '';

if (!$showAll) {
    $activeDate = !empty($_GET['news_date']) ? $_GET['news_date'] : date('Y-m-d');
    $dayStart   = $activeDate.' 00:00:00';
    $dayEnd     = $activeDate.' 23:59:59';
    $where[]    = "scraped_at BETWEEN ? AND ?";
    $params[]   = $dayStart; $params[] = $dayEnd; $types .= 'ss';
} else {
    if (!empty($_GET['date_from'])) { $where[] = "scraped_at >= ?"; $params[] = $_GET['date_from'].' 00:00:00'; $types .= 's'; }
    if (!empty($_GET['date_to']))   { $where[] = "scraped_at <= ?"; $params[] = $_GET['date_to'].' 23:59:59';   $types .= 's'; }
}
if (!empty($_GET['source']))   { $where[] = "source = ?";   $params[] = $_GET['source'];   $types .= 's'; }
if (!empty($_GET['category'])) { $where[] = "category = ?"; $params[] = $_GET['category']; $types .= 's'; }
if (isset($_GET['read_status']) && $_GET['read_status'] !== '') {
    $where[] = "is_read = ?"; $params[] = (int)$_GET['read_status']; $types .= 'i';
}
if (!empty($_GET['q'])) {
    $q = '%'.$_GET['q'].'%';
    $where[] = "(title LIKE ? OR source LIKE ?)";
    $params  = array_merge($params, [$q, $q]); $types .= 'ss';
}

$whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

/* ── Stats ── */
$todayStart = date('Y-m-d').' 00:00:00';
$todayEnd   = date('Y-m-d').' 23:59:59';

$stmtStats = $conn->prepare("
    SELECT COUNT(*)                                          AS grand_total,
           SUM(scraped_at BETWEEN ? AND ?)                  AS today_total,
           SUM(is_political='Yes')                          AS political_total,
           SUM(is_election_related='Yes')                   AS election_total,
           SUM(is_read=0 AND scraped_at BETWEEN ? AND ?)    AS today_unread
    FROM ecn.news_articles
");
$stmtStats->bind_param('ssss', $todayStart, $todayEnd, $todayStart, $todayEnd);
$stmtStats->execute();
$stats          = $stmtStats->get_result()->fetch_assoc();
$grandTotal     = (int)$stats['grand_total'];
$todayTotal     = (int)$stats['today_total'];
$politicalTotal = (int)$stats['political_total'];
$electionTotal  = (int)$stats['election_total'];
$todayUnread    = (int)$stats['today_unread'];

/* ── Date tab counts ── */
$d7start  = date('Y-m-d', strtotime('-6 days')).' 00:00:00';
$d7end    = date('Y-m-d').' 23:59:59';
$stmtDays = $conn->prepare("SELECT DATE(scraped_at) AS day, COUNT(*) AS cnt FROM ecn.news_articles WHERE scraped_at BETWEEN ? AND ? GROUP BY day");
$stmtDays->bind_param('ss', $d7start, $d7end); $stmtDays->execute();
$dayCounts = [];
foreach ($stmtDays->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $dayCounts[$r['day']] = (int)$r['cnt'];
$last7days = [];
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $last7days[] = ['date'=>$d, 'count'=>$dayCounts[$d]??0,
        'label'=>$i===0?'Today':($i===1?'Yesterday':date('D d', strtotime($d)))];
}

/* ── Filtered count ── */
$stmtCount = $conn->prepare("SELECT COUNT(*) AS total FROM ecn.news_articles $whereSql");
if ($params) $stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$totalRows  = (int)$stmtCount->get_result()->fetch_assoc()['total'];
$totalPages = max(1, ceil($totalRows / $perPage));

/* ── Sources/Categories (session-cached 5 min) ── */
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['src_cache']) || (time()-($_SESSION['src_ts']??0)) > 300) {
    $_SESSION['src_cache'] = $conn->query("SELECT DISTINCT source FROM ecn.news_articles WHERE source!='' ORDER BY source LIMIT 200")->fetch_all(MYSQLI_ASSOC);
    $_SESSION['src_ts'] = time();
}
if (empty($_SESSION['cat_cache']) || (time()-($_SESSION['cat_ts']??0)) > 300) {
    $_SESSION['cat_cache'] = $conn->query("SELECT DISTINCT category FROM ecn.news_articles WHERE category!='' ORDER BY category LIMIT 50")->fetch_all(MYSQLI_ASSOC);
    $_SESSION['cat_ts'] = time();
}
$sources    = $_SESSION['src_cache'];
$categories = $_SESSION['cat_cache'];

/* ── Main query ── */
$sql = "SELECT id, title, link, category, source, published_date, scraped_at,
               is_political, is_election_related, is_toxic, is_read,
               local_image_path, LEFT(content,200) AS content_preview
        FROM ecn.news_articles $whereSql ORDER BY $sort $order LIMIT ?,?";
$p2 = array_merge($params, [$offset, $perPage]);
$t2 = $types.'ii';
$stmt = $conn->prepare($sql);
$stmt->bind_param($t2, ...$p2);
$stmt->execute();
$result = $stmt->get_result();

/* ── Inactive sites lookup ── */
$inactiveSites = [];
$ri = $conn->query("SELECT name FROM ecn.news_sites WHERE active=0");
if ($ri) foreach ($ri->fetch_all(MYSQLI_ASSOC) as $r) $inactiveSites[$r['name']] = true;

/* ── URL helpers ── */
function buildUrl($extra=[]) {
    $p = array_merge($_GET, $extra);
    unset($p['page']);
    return '?'.http_build_query(array_filter($p, fn($v)=>$v!==''));
}
function sortUrl($col) { global $sort,$toggle_order; return buildUrl(['sort'=>$col,'order'=>$toggle_order,'page'=>1]); }
function sortIcon($col) {
    global $sort,$order;
    if ($sort!==$col) return '<span style="opacity:.3">↕</span>';
    return $order==='ASC'?'↑':'↓';
}

/* ── BS date conversion (anchor: BS 2082/01/01 = AD 2025-04-14) ── */
function ad_to_bs($y,$m,$d) {
    static $t=null;
    if(!$t) $t=[2000=>[30,32,31,32,31,30,30,30,29,30,29,31],2001=>[31,31,32,32,31,30,30,30,29,30,30,30],2002=>[31,32,31,32,31,30,30,30,29,30,30,30],2003=>[31,32,31,32,31,30,30,30,29,30,30,30],2004=>[31,32,31,32,31,30,30,30,29,30,30,30],2005=>[31,32,31,32,31,30,30,30,29,30,30,30],2006=>[31,32,31,32,31,30,30,30,29,30,30,31],2007=>[30,32,31,32,31,30,30,30,29,30,30,30],2008=>[31,31,32,31,31,31,30,29,30,29,30,30],2009=>[31,31,32,31,31,31,30,29,30,29,30,30],2010=>[31,32,31,32,31,30,30,29,30,29,30,30],2011=>[31,32,31,32,31,30,30,29,30,29,30,30],2012=>[31,32,31,32,31,30,30,29,30,29,30,30],2013=>[31,31,31,32,31,31,29,30,29,30,29,31],2014=>[31,31,32,31,31,31,30,29,30,29,30,30],2015=>[31,32,31,32,31,30,30,29,30,29,30,30],2016=>[31,32,31,32,31,30,30,29,30,29,30,30],2017=>[31,32,31,32,31,30,30,29,30,29,30,30],2018=>[31,31,32,31,31,31,29,30,29,30,29,31],2019=>[31,31,32,31,31,31,30,29,30,29,30,30],2020=>[31,32,31,32,31,30,30,29,30,29,30,30],2021=>[31,32,31,32,31,30,30,29,30,29,30,30],2022=>[31,32,31,32,31,30,30,29,30,29,30,30],2023=>[31,31,31,32,31,31,30,29,29,30,29,31],2024=>[31,31,32,31,31,31,30,29,30,29,30,30],2025=>[31,32,31,32,31,30,30,29,30,29,30,30],2026=>[31,32,31,32,31,30,30,29,30,29,30,30],2027=>[31,32,31,32,31,30,30,29,30,29,30,30],2028=>[31,31,32,31,31,31,30,29,29,30,29,31],2029=>[31,31,32,31,31,31,30,29,30,29,30,30],2030=>[31,32,31,32,31,30,30,29,30,29,30,30],2031=>[31,32,31,32,31,30,30,29,30,29,30,30],2032=>[31,32,31,32,31,30,30,29,30,29,30,30],2033=>[31,31,31,32,31,31,29,30,29,30,29,31],2034=>[31,31,32,31,31,31,30,29,30,29,30,30],2035=>[31,32,31,32,31,30,30,29,30,29,30,30],2036=>[31,32,31,32,31,30,30,29,30,29,30,30],2037=>[31,32,31,32,31,30,30,29,30,29,30,30],2038=>[31,31,31,32,31,31,30,29,29,30,29,31],2039=>[31,31,32,31,31,31,30,29,30,29,30,30],2040=>[31,32,31,32,31,30,30,29,30,29,30,30],2041=>[31,32,31,32,31,30,30,29,30,29,30,30],2042=>[31,32,31,32,31,30,30,29,30,29,30,30],2043=>[31,31,31,32,31,31,30,29,29,30,30,30],2044=>[31,31,32,31,31,31,30,29,30,29,30,30],2045=>[31,32,31,32,31,30,30,29,30,29,30,30],2046=>[31,32,31,32,31,30,30,29,30,29,30,30],2047=>[31,32,31,32,31,30,30,29,30,29,30,30],2048=>[31,31,32,31,31,31,29,30,29,30,29,31],2049=>[31,31,32,31,31,31,30,29,30,29,30,30],2050=>[31,32,31,32,31,30,30,29,30,29,30,30],2051=>[31,32,31,32,31,30,30,29,30,29,30,30],2052=>[31,32,31,32,31,30,30,29,30,29,30,30],2053=>[31,31,32,32,31,30,30,29,30,29,30,30],2054=>[31,31,32,31,31,31,30,29,30,29,30,30],2055=>[31,32,31,32,31,30,30,29,30,29,30,30],2056=>[31,32,31,32,31,30,30,29,30,29,30,30],2057=>[31,32,31,32,31,30,30,29,30,29,30,30],2058=>[31,31,31,32,31,31,29,30,29,30,29,31],2059=>[31,31,32,31,31,31,30,29,30,29,30,30],2060=>[31,32,31,32,31,30,30,29,30,29,30,30],2061=>[31,32,31,32,31,30,30,29,30,29,30,30],2062=>[31,32,31,32,31,30,30,29,30,29,30,30],2063=>[31,31,31,32,31,31,29,30,29,30,29,31],2064=>[31,31,32,31,31,31,30,29,30,29,30,30],2065=>[31,32,31,32,31,30,30,29,30,29,30,30],2066=>[31,32,31,32,31,30,30,29,30,29,30,30],2067=>[31,32,31,32,31,30,30,29,30,29,30,30],2068=>[31,31,31,32,31,31,29,30,29,30,29,31],2069=>[31,31,32,31,31,31,30,29,30,29,30,30],2070=>[31,32,31,32,31,30,30,29,30,29,30,30],2071=>[31,32,31,32,31,30,30,29,30,29,30,30],2072=>[31,32,31,32,31,30,30,29,30,29,30,30],2073=>[31,31,31,32,31,31,29,30,29,30,30,30],2074=>[31,31,32,31,31,31,30,29,30,29,30,30],2075=>[31,32,31,32,31,30,30,29,30,29,30,30],2076=>[31,32,31,32,31,30,30,29,30,29,30,30],2077=>[31,32,31,32,31,30,30,29,30,29,30,30],2078=>[31,31,31,32,31,31,29,30,29,30,29,31],2079=>[31,31,32,31,31,31,30,29,30,29,30,30],2080=>[31,32,31,32,31,30,30,29,30,29,30,30],2081=>[31,31,31,32,31,31,29,30,29,30,29,31],2082=>[31,31,32,31,31,31,30,29,30,29,30,30],2083=>[31,32,31,32,31,30,30,29,30,29,30,30],2084=>[31,32,31,32,31,30,30,29,30,29,30,30],2085=>[31,32,31,32,31,30,30,29,30,29,30,30],2086=>[31,31,31,32,31,31,29,30,29,30,29,31],2087=>[31,31,32,31,31,31,30,29,30,29,30,30],2088=>[31,32,31,32,31,30,30,29,30,29,30,30],2089=>[31,32,31,32,31,30,30,29,30,29,30,30],2090=>[31,32,31,32,31,30,30,29,30,29,30,30]];
    $anchor=mktime(0,0,0,4,14,2025); $by=2082; $bm=1; $bd=1;
    $diff=(int)((mktime(0,0,0,$m,$d,$y)-$anchor)/86400);
    if($diff>=0){
        while($diff>0){$dim=$t[$by][$bm-1]??30;$rem=$dim-($bd-1);if($diff<$rem){$bd+=$diff;$diff=0;}else{$diff-=$rem;$bd=1;$bm++;if($bm>12){$bm=1;$by++;}}}
    }else{
        $diff=abs($diff);
        while($diff>0){$bm--;if($bm<1){$bm=12;$by--;}$dim=$t[$by][$bm-1]??30;if($diff<$dim){$bd=$dim-$diff+1;$diff=0;}else{$diff-=$dim;$bd=1;}}
    }
    if(!isset($t[$by])) return null;
    return ['year'=>$by,'month'=>$bm,'day'=>$bd];
}

function format_pub($ts) {
    if (!$ts) return null;
    $time = date('H:i', $ts);
    $ad   = date('Y-m-d', $ts);
    $bs   = ad_to_bs((int)date('Y',$ts),(int)date('n',$ts),(int)date('j',$ts));
    $bsStr = $bs ? 'BS '.$bs['year'].'-'.sprintf('%02d',$bs['month']).'-'.sprintf('%02d',$bs['day']) : '';
    return ['ad'=>$ad,'time'=>$time,'bs'=>$bsStr];
}

require_once 'header.php';
?>

<style>
*,*::before,*::after{box-sizing:border-box}
:root{
    --primary:#1a56db;--primary-light:#e8f0fe;
    --danger:#dc2626;--success:#057a55;
    --surface:#f9fafb;--border:#e5e7eb;
    --text:#111827;--text-muted:#6b7280;--text-light:#9ca3af;
    --radius:10px;--radius-sm:6px;
    --shadow:0 1px 3px rgba(0,0,0,.08);--shadow-md:0 4px 16px rgba(0,0,0,.10);
}
body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:var(--surface)}
.news-page{padding:0 0 60px}

.page-header{background:#fff;border-bottom:1px solid var(--border);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;position:sticky;top:0;z-index:100;box-shadow:var(--shadow)}
.page-title{font-size:18px;font-weight:700;color:var(--text);margin:0}
.page-sub{font-size:12px;color:var(--text-muted);margin-top:1px}
.header-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}

.stats-bar{display:flex;gap:8px;padding:12px 16px;overflow-x:auto;scrollbar-width:none;flex-wrap:nowrap}
.stats-bar::-webkit-scrollbar{display:none}
.stat-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 14px;min-width:90px;flex-shrink:0;box-shadow:var(--shadow)}
.stat-num{font-size:20px;font-weight:700;color:var(--text);line-height:1}
.stat-label{font-size:10px;color:var(--text-muted);margin-top:3px;font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.stat-card.today .stat-num{color:var(--primary)}
.stat-card.unread .stat-num{color:#d97706}
.stat-card.unread{cursor:pointer}
.stat-card.unread:hover{box-shadow:var(--shadow-md)}
.stat-card.political .stat-num{color:var(--success)}
.stat-card.election .stat-num{color:#7c3aed}

.date-tabs{display:flex;gap:6px;padding:0 16px 10px;overflow-x:auto;scrollbar-width:none;align-items:center}
.date-tabs::-webkit-scrollbar{display:none}
.date-tab{display:flex;flex-direction:column;align-items:center;padding:6px 12px;border-radius:var(--radius-sm);border:1px solid var(--border);background:#fff;text-decoration:none;color:var(--text);font-size:12px;font-weight:600;white-space:nowrap;flex-shrink:0;box-shadow:var(--shadow);transition:all .15s}
.date-tab:hover{background:var(--primary-light);border-color:var(--primary);color:var(--primary)}
.date-tab.active{background:var(--primary);border-color:var(--primary);color:#fff}
.tab-label{font-size:12px;font-weight:700}
.tab-count{font-size:10px;opacity:.75;margin-top:1px}
.date-tab-all{padding:6px 12px;border-radius:var(--radius-sm);border:1px solid var(--border);background:#fff;text-decoration:none;color:var(--text-muted);font-size:12px;font-weight:600;white-space:nowrap;flex-shrink:0;box-shadow:var(--shadow);margin-left:4px}
.date-tab-all:hover{border-color:var(--primary);color:var(--primary);background:var(--primary-light)}
.date-tab-all.active{background:#1e293b;border-color:#1e293b;color:#fff}

.cat-bar{display:flex;gap:6px;padding:0 16px 10px;overflow-x:auto;scrollbar-width:none}
.cat-bar::-webkit-scrollbar{display:none}
.cat-btn{padding:5px 11px;border-radius:20px;font-size:12px;font-weight:600;border:1px solid var(--border);background:#fff;color:var(--text-muted);text-decoration:none;white-space:nowrap;flex-shrink:0;transition:all .15s}
.cat-btn:hover{background:var(--primary-light);color:var(--primary);border-color:var(--primary)}
.cat-btn-active{background:#1e293b !important;color:#fff !important;border-color:#1e293b !important}
.cat-btn-politics.cat-btn-active{background:#6d28d9 !important;border-color:#6d28d9 !important}
.cat-btn-economy.cat-btn-active{background:#92400e !important;border-color:#92400e !important}
.cat-btn-sports.cat-btn-active{background:#166534 !important;border-color:#166534 !important}
.cat-btn-technology.cat-btn-active{background:#1e40af !important;border-color:#1e40af !important}
.cat-btn-health.cat-btn-active{background:#9d174d !important;border-color:#9d174d !important}
.cat-btn-education.cat-btn-active{background:#065f46 !important;border-color:#065f46 !important}
.cat-btn-entertainment.cat-btn-active{background:#713f12 !important;border-color:#713f12 !important}
.cat-btn-environment.cat-btn-active{background:#064e3b !important;border-color:#064e3b !important}
.cat-btn-international.cat-btn-active{background:#3730a3 !important;border-color:#3730a3 !important}
.cat-btn-crime.cat-btn-active{background:#991b1b !important;border-color:#991b1b !important}
.cat-btn-business.cat-btn-active{background:#78350f !important;border-color:#78350f !important}

.filter-panel{background:#fff;border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:12px 16px;margin-bottom:10px}
.filter-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
.filter-group{display:flex;flex-direction:column;gap:3px}
.filter-group label{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px}
.filter-group input,.filter-group select{height:36px;border:1px solid var(--border);border-radius:var(--radius-sm);padding:0 10px;font-size:14px;color:var(--text);background:var(--surface);outline:none;width:100%}
.filter-group input:focus,.filter-group select:focus{border-color:var(--primary)}
.filter-group.grow{flex:1;min-width:160px}
.filter-section-title{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.flag-pills{display:flex;gap:5px;flex-wrap:wrap}
.pill{padding:4px 11px;border-radius:20px;font-size:12px;font-weight:600;border:1px solid var(--border);background:var(--surface);color:var(--text-muted);cursor:pointer;text-decoration:none;white-space:nowrap;display:inline-flex;align-items:center;gap:4px}
.pill:hover{background:var(--primary-light);border-color:var(--primary);color:var(--primary)}
.pill.active{background:var(--primary);border-color:var(--primary);color:#fff}
.pill.active-unread{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
.btn-filter{height:36px;padding:0 16px;border-radius:var(--radius-sm);border:none;background:var(--primary);color:#fff;font-size:13px;font-weight:600;cursor:pointer}
.btn-reset{height:36px;padding:0 12px;border-radius:var(--radius-sm);border:1px solid var(--border);background:#fff;color:var(--text-muted);font-size:13px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
.active-filters{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}
.filter-tag{background:var(--primary-light);color:var(--primary);border:1px solid #bfdbfe;border-radius:20px;padding:2px 9px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px}
.filter-tag a{color:inherit;text-decoration:none;font-size:14px;line-height:1}

.btn-sm-action{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:5px;font-size:12px;font-weight:600;border:1px solid;cursor:pointer;text-decoration:none;white-space:nowrap;background:none}
.btn-view{background:var(--primary-light);color:var(--primary);border-color:#bfdbfe}
.btn-view:hover{background:var(--primary);color:#fff}
.btn-unread-filter{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:var(--radius-sm);border:2px solid var(--border);background:#fff;color:var(--text-muted);font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s}
.btn-unread-filter:hover{border-color:var(--primary);background:var(--primary-light);color:var(--primary)}
.btn-unread-filter.active{border-color:var(--primary);background:var(--primary);color:#fff}
.btn-unread-filter.active .unread-badge{background:#fff;color:var(--primary)}
.btn-mark-all-read{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:var(--radius-sm);border:1px solid #86efac;background:#dcfce7;color:#166534;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap}
.btn-mark-all-read:hover{background:#bbf7d0}
.unread-badge{display:inline-flex;align-items:center;justify-content:center;min-width:17px;height:17px;padding:0 4px;background:var(--primary);color:#fff;border-radius:20px;font-size:10px;font-weight:700;margin-left:3px}

.sort-bar{display:flex;gap:6px;align-items:center;padding:0 16px 10px;flex-wrap:wrap}
.sort-label{font-size:12px;color:var(--text-muted);font-weight:600}
.sort-link{padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600;border:1px solid var(--border);background:#fff;color:var(--text-muted);text-decoration:none;display:inline-flex;align-items:center;gap:3px}
.sort-link:hover{background:var(--primary-light);color:var(--primary);border-color:var(--primary)}
.sort-link.active{background:#1e293b;color:#fff;border-color:#1e293b}
.toolbar{display:flex;align-items:center;justify-content:space-between;padding:0 16px 8px;flex-wrap:wrap;gap:6px}
.record-count{font-size:13px;color:var(--text-muted)}
.record-count strong{color:var(--text)}
.pagination{display:flex;gap:3px;list-style:none;margin:0;padding:0;flex-wrap:wrap}
.page-link{display:flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;color:var(--text);text-decoration:none;background:#fff}
.page-link:hover{background:var(--primary-light);color:var(--primary);border-color:var(--primary)}
.page-link.active{background:var(--primary);color:#fff;border-color:var(--primary)}

.news-list{padding:0 16px;display:flex;flex-direction:column;gap:10px}
.news-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;transition:box-shadow .15s}
.news-card:hover{box-shadow:var(--shadow-md)}
.news-card.is-read{background:#fafafa}
.news-card.is-unread{border-left:3px solid var(--primary)}
.news-card.is-toxic{background:#fff5f5;border-left:3px solid #f87171}
.card-body{display:flex;gap:12px;padding:12px 14px;align-items:flex-start}
.card-thumb{width:80px;height:64px;flex-shrink:0;border-radius:var(--radius-sm);overflow:hidden;background:var(--border)}
.card-thumb img{width:100%;height:100%;object-fit:cover;cursor:pointer;display:block}
.card-thumb-placeholder{width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#f1f5f9;color:#cbd5e1;font-size:24px}
.card-content{flex:1;min-width:0}
.card-meta{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:5px}
.card-source{font-size:12px;font-weight:700;color:var(--primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px}
.card-source.inactive-source{color:#dc2626}
.card-source.inactive-source::after{content:" ⚠";font-size:10px;opacity:.8}
.card-category{font-size:11px;font-weight:600;padding:2px 7px;border-radius:10px;background:#f1f5f9;color:#475569;white-space:nowrap}
.cat-Politics{background:#ede9fe;color:#6d28d9}
.cat-Economy{background:#fef3c7;color:#92400e}
.cat-Sports{background:#dcfce7;color:#166534}
.cat-Technology{background:#dbeafe;color:#1e40af}
.cat-Health{background:#fce7f3;color:#9d174d}
.cat-Education{background:#d1fae5;color:#065f46}
.cat-Entertainment{background:#fef9c3;color:#713f12}
.cat-Environment{background:#d1fae5;color:#064e3b}
.cat-International{background:#e0e7ff;color:#3730a3}
.cat-Crime{background:#fee2e2;color:#991b1b}
.cat-Business{background:#fef3c7;color:#78350f}
.cat-General{background:#f1f5f9;color:#475569}
.card-title{font-size:15px;font-weight:700;line-height:1.45;color:var(--text);margin:0 0 5px;cursor:pointer;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.card-title:hover{color:var(--primary)}
.is-read .card-title{font-weight:500;color:var(--text-muted)}
.unread-dot{display:inline-block;width:7px;height:7px;background:var(--primary);border-radius:50%;margin-right:6px;vertical-align:middle}
.card-preview{font-size:13px;color:var(--text-muted);line-height:1.5;margin-bottom:7px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.is-read .card-preview{opacity:.7}
.card-footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:4px}
.card-dates{display:flex;flex-direction:column;gap:1px}
.card-date-pub{font-size:12px;color:var(--text);font-weight:600;display:flex;align-items:center;gap:5px;flex-wrap:wrap}
.card-date-bs{font-size:11px;color:#6366f1}
.card-date-scraped{font-size:11px;color:var(--text-light)}
.card-actions{display:flex;gap:5px;align-items:center;flex-shrink:0}
.badge{display:inline-flex;align-items:center;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap}
.badge-pol{background:#dcfce7;color:#166534}
.badge-elec{background:#ede9fe;color:#6d28d9}
.badge-link{background:var(--primary-light);color:var(--primary);font-size:11px;padding:2px 7px;border-radius:10px;text-decoration:none;font-weight:600}
.badge-link:hover{background:var(--primary);color:#fff}
.btn-toggle-read{width:28px;height:28px;border-radius:var(--radius-sm);border:1px solid var(--border);background:#fff;color:var(--text-muted);font-size:13px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0;padding:0}
.btn-toggle-read:hover{background:var(--primary-light);border-color:var(--primary);color:var(--primary)}
.btn-toggle-read.is-read{background:#dcfce7;border-color:#86efac;color:#166534}
.btn-deactivate-site{width:28px;height:28px;border-radius:var(--radius-sm);border:1px solid #fca5a5;background:#fff5f5;color:#dc2626;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0;padding:0;text-decoration:none}
.btn-deactivate-site:hover{background:#fee2e2;border-color:#f87171}

.empty-state{text-align:center;padding:60px 20px;background:#fff;border:1px solid var(--border);border-radius:var(--radius)}
.empty-state svg{width:48px;height:48px;opacity:.3;margin:0 auto 12px;display:block}
.empty-state p{font-size:15px;font-weight:500;color:var(--text);margin:0 0 4px}

.lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;align-items:center;justify-content:center}
.lightbox.open{display:flex}
.lightbox img{max-width:94vw;max-height:88vh;border-radius:8px}
.lightbox-close{position:fixed;top:16px;right:20px;color:#fff;font-size:30px;cursor:pointer;background:none;border:none;line-height:1}
#newsModal .modal-header{background:var(--primary)}
#newsModal .modal-title{color:#fff;font-size:16px}
.btn-close-white{filter:brightness(0) invert(1)}
.news-meta{display:flex;gap:14px;flex-wrap:wrap;font-size:14px;color:var(--text-muted);margin-bottom:14px}
.news-meta strong{color:var(--text)}
.news-content{line-height:1.9;font-size:16px;color:var(--text);white-space:pre-line}

@media(min-width:640px){
    .page-header{padding:14px 24px}.stats-bar{padding:14px 24px}
    .date-tabs{padding:0 24px 10px}.cat-bar{padding:0 24px 10px}
    .filter-panel{padding:14px 24px}.sort-bar{padding:0 24px 10px}
    .toolbar{padding:0 24px 8px}.news-list{padding:0 24px}
    .card-thumb{width:96px;height:72px}
}
@media(min-width:1024px){
    .page-header{padding:14px 32px}
    .stats-bar,.date-tabs,.cat-bar,.sort-bar,.toolbar{max-width:1200px;margin-left:auto;margin-right:auto}
    .stats-bar{padding:14px 32px}.date-tabs{padding:0 32px 10px}.cat-bar{padding:0 32px 10px}
    .sort-bar{padding:0 32px 10px}.toolbar{padding:0 32px 8px}
    .filter-panel{max-width:1200px;margin:0 auto 10px;padding:14px 32px}
    .news-list{max-width:1200px;margin:0 auto;padding:0 32px}
    .card-body{padding:14px 18px;gap:16px}.card-thumb{width:110px;height:80px}
    .card-title{font-size:16px}
}
@media(min-width:1280px){
    .news-list{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .news-card{height:100%}
}
</style>

<div class="news-page">

<!-- Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">📰 News Feed</h1>
        <div class="page-sub">Scraped from Nepali news sources</div>
    </div>
    <div class="header-actions">
        <a href="<?= buildUrl(['read_status'=>($_GET['read_status']??'')==='0'?'':'0','page'=>1]) ?>"
           class="btn-unread-filter <?= ($_GET['read_status']??'')==='0'?'active':'' ?>">
            ● Unread
            <?php if($todayUnread>0): ?><span class="unread-badge"><?= number_format($todayUnread) ?></span><?php endif; ?>
        </a>
        <button class="btn-mark-all-read" id="btnMarkAllRead" data-date="<?= $activeDate?:date('Y-m-d') ?>">✓ All Read</button>
        <a href="inactive_sites.php" class="btn-sm-action" style="border-color:#fca5a5;color:#991b1b;background:#fff5f5;font-size:12px">⚠ Inactive</a>
        <a href="news_list.php" class="btn-sm-action" style="border-color:var(--border);color:var(--text-muted);font-size:12px">↺</a>
    </div>
</div>

<!-- Stats -->
<div class="stats-bar">
    <div class="stat-card"><div class="stat-num"><?= number_format($grandTotal) ?></div><div class="stat-label">Total</div></div>
    <div class="stat-card today"><div class="stat-num"><?= number_format($todayTotal) ?></div><div class="stat-label">Today</div></div>
    <div class="stat-card unread" onclick="window.location='<?= buildUrl(['read_status'=>'0','page'=>1]) ?>'">
        <div class="stat-num" id="unreadCount"><?= number_format($todayUnread) ?></div>
        <div class="stat-label">Unread</div>
    </div>
    <div class="stat-card political"><div class="stat-num"><?= number_format($politicalTotal) ?></div><div class="stat-label">Political</div></div>
    <div class="stat-card election"><div class="stat-num"><?= number_format($electionTotal) ?></div><div class="stat-label">Election</div></div>
    <div class="stat-card"><div class="stat-num"><?= number_format($totalRows) ?></div><div class="stat-label">Filtered</div></div>
</div>

<!-- Date tabs -->
<div class="date-tabs">
    <?php foreach($last7days as $day):
        $isAct=!$showAll&&$activeDate===$day['date'];
        $tUrl='?'.http_build_query(array_filter(array_merge($_GET,['news_date'=>$day['date'],'date_range'=>'','date_from'=>'','date_to'=>'','page'=>1]),fn($v)=>$v!==''));
    ?>
    <a href="<?= $tUrl ?>" class="date-tab <?= $isAct?'active':'' ?>">
        <span class="tab-label"><?= htmlspecialchars($day['label']) ?></span>
        <span class="tab-count"><?= number_format($day['count']) ?></span>
    </a>
    <?php endforeach;
    $allUrl='?'.http_build_query(array_filter(array_merge($_GET,['date_range'=>'all','news_date'=>'','date_from'=>'','date_to'=>'','page'=>1]),fn($v)=>$v!==''));
    ?>
    <a href="<?= $allUrl ?>" class="date-tab-all <?= $showAll?'active':'' ?>">📅 All</a>
</div>

<!-- Category bar -->
<?php
$allCats=['Politics'=>'🏛','Economy'=>'💰','Sports'=>'⚽','Technology'=>'💻','Health'=>'🏥',
          'Education'=>'📚','Entertainment'=>'🎬','Environment'=>'🌿','International'=>'🌍',
          'Crime'=>'🚔','Business'=>'🏢','General'=>'📰'];
$activeCat=$_GET['category']??'';
?>
<div class="cat-bar">
    <a href="<?= buildUrl(['category'=>'','page'=>1]) ?>" class="cat-btn <?= $activeCat===''?'cat-btn-active':'' ?>">All</a>
    <?php foreach($allCats as $cat=>$icon): $slug=strtolower(preg_replace('/[^a-z]/i','',$cat)); ?>
    <a href="<?= buildUrl(['category'=>$activeCat===$cat?'':$cat,'page'=>1]) ?>"
       class="cat-btn cat-btn-<?= $slug ?> <?= $activeCat===$cat?'cat-btn-active':'' ?>">
        <?= $icon ?> <?= $cat ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Filter panel -->
<div class="filter-panel">
<form method="GET" id="filterForm">
    <div class="filter-row">
        <div class="filter-group grow">
            <label>Search</label>
            <input type="text" name="q" placeholder="Title or source…" value="<?= htmlspecialchars($_GET['q']??'') ?>">
        </div>
        <div class="filter-group">
            <label>Source</label>
            <select name="source" style="min-width:140px">
                <option value="">All Sources</option>
                <?php foreach($sources as $s): ?>
                <option value="<?= htmlspecialchars($s['source']) ?>" <?= ($_GET['source']??'')===$s['source']?'selected':'' ?>><?= htmlspecialchars($s['source']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Category</label>
            <select name="category" style="min-width:120px">
                <option value="">All</option>
                <?php foreach($categories as $c): ?>
                <option value="<?= htmlspecialchars($c['category']) ?>" <?= ($_GET['category']??'')===$c['category']?'selected':'' ?>><?= htmlspecialchars($c['category']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if($showAll): ?>
        <div class="filter-group">
            <label>From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($_GET['date_from']??'') ?>" style="width:140px">
        </div>
        <div class="filter-group">
            <label>To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($_GET['date_to']??'') ?>" style="width:140px">
        </div>
        <?php endif; ?>
        <div class="filter-group">
            <label>Per Page</label>
            <select name="per_page" style="width:85px">
                <?php foreach([10,20,30,50,100,200] as $sz): ?>
                <option value="<?= $sz ?>" <?= $perPage===$sz?'selected':'' ?>><?= $sz ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group" style="justify-content:flex-end">
            <label>&nbsp;</label>
            <div style="display:flex;gap:5px">
                <button type="submit" class="btn-filter">Filter</button>
                <a href="news_list.php" class="btn-reset">↺</a>
            </div>
        </div>
    </div>
    <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
        <div>
            <div class="filter-section-title">Read Status</div>
            <div class="flag-pills">
                <a href="<?= buildUrl(['read_status'=>'','page'=>1]) ?>"
                   class="pill <?= !isset($_GET['read_status'])||$_GET['read_status']===''?'active':'' ?>">All</a>
                <a href="<?= buildUrl(['read_status'=>'0','page'=>1]) ?>"
                   class="pill <?= ($_GET['read_status']??'')==='0'?'active-unread':'' ?>">
                    ● Unread <?= $todayUnread>0?'<span class="unread-badge">'.$todayUnread.'</span>':'' ?>
                </a>
                <a href="<?= buildUrl(['read_status'=>'1','page'=>1]) ?>"
                   class="pill <?= ($_GET['read_status']??'')==='1'?'active':'' ?>">✓ Read</a>
            </div>
        </div>
    </div>
    <?php
    $tags=[];
    if(!empty($_GET['q']))         $tags[]=['Search',   htmlspecialchars($_GET['q']),         buildUrl(['q'=>''])];
    if(!empty($_GET['source']))    $tags[]=['Source',   htmlspecialchars($_GET['source']),    buildUrl(['source'=>''])];
    if(!empty($_GET['category']))  $tags[]=['Category', htmlspecialchars($_GET['category']),  buildUrl(['category'=>''])];
    if(!empty($_GET['date_from'])) $tags[]=['From',     htmlspecialchars($_GET['date_from']), buildUrl(['date_from'=>''])];
    if(!empty($_GET['date_to']))   $tags[]=['To',       htmlspecialchars($_GET['date_to']),   buildUrl(['date_to'=>''])];
    if(!empty($tags)): ?>
    <div class="active-filters">
        <span style="font-size:11px;color:var(--text-muted);font-weight:600">Active:</span>
        <?php foreach($tags as $tag): ?>
        <span class="filter-tag"><?= $tag[0] ?>: <strong><?= $tag[1] ?></strong><a href="<?= $tag[2] ?>">×</a></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</form>
</div>

<!-- Sort bar -->
<div class="sort-bar">
    <span class="sort-label">Sort:</span>
    <a href="<?= sortUrl('scraped_at') ?>"     class="sort-link <?= $sort==='scraped_at'?'active':'' ?>">Scraped <?= sortIcon('scraped_at') ?></a>
    <a href="<?= sortUrl('published_date') ?>" class="sort-link <?= $sort==='published_date'?'active':'' ?>">Published <?= sortIcon('published_date') ?></a>
    <a href="<?= sortUrl('source') ?>"         class="sort-link <?= $sort==='source'?'active':'' ?>">Source <?= sortIcon('source') ?></a>
    <a href="<?= sortUrl('title') ?>"          class="sort-link <?= $sort==='title'?'active':'' ?>">Title <?= sortIcon('title') ?></a>
</div>

<!-- Toolbar & pagination -->
<?php
function renderPager($page,$totalPages,$totalRows,$perPage,$offset) {
    $from=min($offset+1,$totalRows); $to=min($offset+$perPage,$totalRows);
    echo '<div class="toolbar">';
    echo '<div class="record-count"><strong>'.number_format($from).'–'.number_format($to).'</strong> of <strong>'.number_format($totalRows).'</strong></div>';
    if($totalPages>1) {
        echo '<nav><ul class="pagination">';
        if($page>1){ echo '<li><a class="page-link" href="?'.http_build_query(array_merge($_GET,['page'=>1])).'">«</a></li><li><a class="page-link" href="?'.http_build_query(array_merge($_GET,['page'=>$page-1])).'">‹</a></li>'; }
        for($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++) echo '<li><a class="page-link '.($i===$page?'active':'').'" href="?'.http_build_query(array_merge($_GET,['page'=>$i])).'">'.$i.'</a></li>';
        if($page<$totalPages){ echo '<li><a class="page-link" href="?'.http_build_query(array_merge($_GET,['page'=>$page+1])).'">›</a></li><li><a class="page-link" href="?'.http_build_query(array_merge($_GET,['page'=>$totalPages])).'">»</a></li>'; }
        echo '</ul></nav>';
    }
    echo '</div>';
}
renderPager($page,$totalPages,$totalRows,$perPage,$offset);
?>

<!-- Cards -->
<div class="news-list">
<?php
if($result->num_rows>0): while($row=$result->fetch_assoc()):
    $isPol      = $row['is_political']==='Yes';
    $isElec     = $row['is_election_related']==='Yes';
    $isToxic    = $row['is_toxic']==='Yes';
    $isRead     = (int)($row['is_read']??0)===1;
    $srcInactive= isset($inactiveSites[$row['source']]);
    $cardCls    = 'news-card '.($isToxic?'is-toxic':($isRead?'is-read':'is-unread'));
    $scraped    = strtotime($row['scraped_at']);
    $pub        = $row['published_date']?strtotime($row['published_date']):null;
    $pf         = $pub?format_pub($pub):null;
    $catCls     = 'card-category cat-'.preg_replace('/[^a-zA-Z]/','',($row['category']?:'General'));
?>
<div class="<?= $cardCls ?>" data-id="<?= $row['id'] ?>" data-read="<?= $isRead?1:0 ?>">
<div class="card-body">
    <div class="card-thumb">
        <?php if(!empty($row['local_image_path'])): ?>
        <img src="uploads/<?= htmlspecialchars($row['local_image_path']) ?>" alt="" loading="lazy" onclick="openLightbox(this.src)">
        <?php else: ?><div class="card-thumb-placeholder">📰</div><?php endif; ?>
    </div>
    <div class="card-content">
        <div class="card-meta">
            <span class="card-source <?= $srcInactive?'inactive-source':'' ?>" <?= $srcInactive?'title="This site is currently inactive"':'' ?>>
                <?= htmlspecialchars($row['source']) ?>
            </span>
            <?php if(!empty($row['category'])):
                foreach(array_map('trim', explode(',', $row['category'])) as $singleCat):
                    if($singleCat === '') continue;
                    $sCatCls  = 'card-category cat-'.preg_replace('/[^a-zA-Z]/','',($singleCat?:'General'));
                    $catUrl   = buildUrl(['category'=> $activeCat===$singleCat ? '' : $singleCat, 'page'=>1]);
            ?>
            <a href="<?= htmlspecialchars($catUrl) ?>"
               class="<?= $sCatCls ?>"
               title="Filter: <?= htmlspecialchars($singleCat) ?>"
               style="text-decoration:none;cursor:pointer;position:relative;z-index:3"
               onclick="event.stopPropagation()">
                <?= htmlspecialchars($singleCat) ?>
            </a>
            <?php endforeach; endif; ?>
            <?php if($isPol): ?>
            <a href="<?= htmlspecialchars(buildUrl(['is_political'=>($_GET['is_political']??'')==='Yes'?'':'Yes','page'=>1])) ?>"
               class="badge badge-pol"
               style="text-decoration:none;cursor:pointer;position:relative;z-index:3"
               onclick="event.stopPropagation()"
               title="Filter political news">Pol</a>
            <?php endif; ?>
            <?php if($isElec): ?>
            <a href="<?= htmlspecialchars(buildUrl(['is_election'=>($_GET['is_election']??'')==='Yes'?'':'Yes','page'=>1])) ?>"
               class="badge badge-elec"
               style="text-decoration:none;cursor:pointer;position:relative;z-index:3"
               onclick="event.stopPropagation()"
               title="Filter election news">Elec</a>
            <?php endif; ?>
        </div>
        <div class="card-title news-modal-link" data-id="<?= $row['id'] ?>">
            <?php if(!$isRead): ?><span class="unread-dot"></span><?php endif; ?>
            <?= htmlspecialchars($row['title']) ?>
        </div>
        <?php if(!empty($row['content_preview'])): ?>
        <div class="card-preview"><?= htmlspecialchars($row['content_preview']) ?></div>
        <?php endif; ?>
        <div class="card-footer">
            <div class="card-dates">
                <?php if($pf): ?>
                <div class="card-date-pub">
                    <?= $pf['ad'] ?>
                    <?php if($pf['time']!=='00:00'): ?>
                    <span style="font-weight:400;color:var(--text-muted);font-size:11px"><?= $pf['time'] ?></span>
                    <?php endif; ?>
                    <?php if($pf['bs']): ?><span class="card-date-bs">(<?= $pf['bs'] ?>)</span><?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="card-date-scraped">🕐 <?= date('m-d H:i',$scraped) ?></div>
            </div>
            <div class="card-actions">
                <a href="<?= htmlspecialchars($row['link']) ?>" target="_blank" class="badge-link" rel="noopener noreferrer">↗</a>
                <button class="btn-sm-action btn-view news-modal-link" data-id="<?= $row['id'] ?>" style="font-size:12px">View</button>
                <button class="btn-toggle-read <?= $isRead?'is-read':'' ?>"
                        data-id="<?= $row['id'] ?>" data-read="<?= $isRead?1:0 ?>"
                        title="<?= $isRead?'Mark unread':'Mark read' ?>"><?= $isRead?'✓':'●' ?></button>
                <?php if(!$srcInactive): ?>
                <button class="btn-deactivate-site" data-source="<?= htmlspecialchars($row['source']) ?>" title="Deactivate site">✕</button>
                <?php else: ?>
                <a href="inactive_sites.php" class="btn-deactivate-site" style="background:#f1f5f9;border-color:#e2e8f0;color:#94a3b8" title="Already inactive">✕</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>
<?php endwhile; else: ?>
<div class="empty-state">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
    </svg>
    <p>No articles found</p>
    <small>Try adjusting your filters or <a href="news_list.php">reset</a>.</small>
</div>
<?php endif; ?>
</div>

<div style="margin-top:16px"><?php renderPager($page,$totalPages,$totalRows,$perPage,$offset); ?></div>
</div>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <button class="lightbox-close" onclick="closeLightbox()">×</button>
    <img id="lightboxImg" src="" alt="">
</div>

<!-- Modal -->
<div class="modal fade" id="newsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--primary)">
        <h5 class="modal-title" id="newsModalTitle" style="color:#fff;font-size:16px">Loading…</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="newsModalBody">
        <div style="text-align:center;padding:40px"><div class="spinner-border text-primary"></div></div>
      </div>
      <div class="modal-footer">
        <button id="modalOpenLink" onclick="if(this.dataset.href)window.open(this.dataset.href,'_blank')"
                class="btn btn-outline-primary btn-sm" data-href="">Open Source ↗</button>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
function openLightbox(src){document.getElementById('lightboxImg').src=src;document.getElementById('lightbox').classList.add('open')}
function closeLightbox(){document.getElementById('lightbox').classList.remove('open');document.getElementById('lightboxImg').src=''}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeLightbox()});

document.addEventListener('DOMContentLoaded',function(){
    const bsModal=new bootstrap.Modal(document.getElementById('newsModal'));
    const modalTitle=document.getElementById('newsModalTitle');
    const modalBody=document.getElementById('newsModalBody');
    const modalLink=document.getElementById('modalOpenLink');

    function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}

    // BS conversion — anchor BS 2082/01/01 = AD 2025-04-14
    const BD={2075:[31,32,31,32,31,30,30,29,30,29,30,30],2076:[31,32,31,32,31,30,30,29,30,29,30,30],2077:[31,32,31,32,31,30,30,29,30,29,30,30],2078:[31,31,31,32,31,31,29,30,29,30,29,31],2079:[31,31,32,31,31,31,30,29,30,29,30,30],2080:[31,32,31,32,31,30,30,29,30,29,30,30],2081:[31,31,31,32,31,31,29,30,29,30,29,31],2082:[31,31,32,31,31,31,30,29,30,29,30,30],2083:[31,32,31,32,31,30,30,29,30,29,30,30],2084:[31,32,31,32,31,30,30,29,30,29,30,30],2085:[31,32,31,32,31,30,30,29,30,29,30,30]};
    function adToBs(str){
        if(!str)return'';
        const[y,m,d]=str.substring(0,10).split('-').map(Number);
        try{
            let diff=Math.round((new Date(Date.UTC(y,m-1,d))-new Date(Date.UTC(2025,3,14)))/86400000);
            let by=2082,bm=1,bd=1;
            if(diff>=0){while(diff>0){const dim=(BD[by]||[])[bm-1]||30,rem=dim-bd+1;if(diff<rem){bd+=diff;diff=0}else{diff-=rem;bd=1;bm++;if(bm>12){bm=1;by++}}}}
            else{diff=Math.abs(diff);while(diff>0){bm--;if(bm<1){bm=12;by--}const dim=(BD[by]||[])[bm-1]||30;if(diff<dim){bd=dim-diff+1;diff=0}else{diff-=dim;bd=1}}}
            return`BS ${by}-${String(bm).padStart(2,'0')}-${String(bd).padStart(2,'0')}`;
        }catch(e){return''}
    }

    // Open modal
    document.querySelectorAll('.news-modal-link').forEach(el=>{
        el.addEventListener('click',function(e){
            // Don't intercept clicks that originated from a category badge link
            if(e.target.closest('.card-category')) return;
            e.preventDefault();
            const id=this.dataset.id;
            modalTitle.textContent='Loading…';
            modalBody.innerHTML='<div style="text-align:center;padding:40px"><div class="spinner-border text-primary"></div></div>';
            modalLink.dataset.href='';
            bsModal.show();
            fetch('news_list.php?get_article='+encodeURIComponent(id))
            .then(r=>r.json()).then(data=>{
                modalTitle.textContent=data.title||'Article';
                modalLink.dataset.href=data.link||'';
                modalLink.style.display=data.link?'':'none';
                const pubRaw=data.published_date||'';
                const pubTimeRaw=pubRaw.length>10?pubRaw.substring(11,16):'';
                const pubTime=pubTimeRaw==='00:00'?'':pubTimeRaw;
                const pubAD=pubRaw.substring(0,10);
                const pubBs=adToBs(pubAD);
                const tLabel=pubTime?`<span style="color:var(--text-muted);font-size:13px"> ${esc(pubTime)}</span>`:(pubTimeRaw==='00:00'?`<span style="color:#d1d5db;font-size:12px;font-style:italic"> (time unknown)</span>`:'');
                const pubDate=pubAD?`<strong>${esc(pubAD)}</strong>${tLabel}`+(pubBs?` <span style="color:#6366f1;font-size:12px">(${esc(pubBs)})</span>`:''): '—';
                const pol=data.is_political==='Yes',elec=data.is_election_related==='Yes',toxic=data.is_toxic==='Yes';
                modalBody.innerHTML=`
                    <div class="news-meta"><span>📰 <strong>Source:</strong> ${esc(data.source||'—')}</span><span>📂 <strong>Category:</strong> ${esc(data.category||'—')}</span></div>
                    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:14px;padding:10px 14px;background:#f8faff;border-radius:6px;font-size:13px">
                        <span>📅 <strong>Published:</strong> ${pubDate}</span>
                        <span style="color:var(--text-muted)">🕐 <strong>Scraped:</strong> ${esc((data.scraped_at||'').substring(0,16))}</span>
                    </div>
                    <div style="display:flex;gap:5px;margin-bottom:14px">
                        ${pol?'<span class="badge badge-pol">Political</span>':''}
                        ${elec?'<span class="badge badge-elec">Election</span>':''}
                        ${toxic?'<span class="badge" style="background:#fee2e2;color:#991b1b">Toxic</span>':''}
                    </div><hr>
                    <div class="news-content">${esc(data.content||'No content available.')}</div>`;
                markRead(id);
            }).catch(()=>{modalBody.innerHTML='<div style="color:var(--danger);padding:20px">Failed to load.</div>'});
        });
    });

    function markRead(id){
        const c=document.querySelector(`.news-card[data-id="${id}"]`);
        if(!c||c.dataset.read==='1')return;
        c.dataset.read='1';c.classList.remove('is-unread');c.classList.add('is-read');
        c.querySelector('.unread-dot')?.remove();
        const b=c.querySelector('.btn-toggle-read');
        if(b){b.dataset.read='1';b.classList.add('is-read');b.textContent='✓';b.title='Mark unread'}
        upd(-1);
        fetch('news_list.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=mark_read&id=${id}`});
    }
    function markUnread(id){
        const c=document.querySelector(`.news-card[data-id="${id}"]`);
        if(!c)return;
        c.dataset.read='0';c.classList.remove('is-read');c.classList.add('is-unread');
        const t=c.querySelector('.card-title');
        if(t&&!t.querySelector('.unread-dot')){const dot=document.createElement('span');dot.className='unread-dot';t.prepend(dot)}
        const b=c.querySelector('.btn-toggle-read');
        if(b){b.dataset.read='0';b.classList.remove('is-read');b.textContent='●';b.title='Mark read'}
        upd(+1);
        fetch('news_list.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=mark_unread&id=${id}`});
    }
    function upd(d){const el=document.getElementById('unreadCount');if(el)el.textContent=Math.max(0,(parseInt(el.textContent.replace(/,/g,''))||0)+d).toLocaleString()}

    document.addEventListener('click',function(e){
        const b=e.target.closest('.btn-toggle-read');
        if(!b)return;e.preventDefault();
        b.dataset.read==='1'?markUnread(b.dataset.id):markRead(b.dataset.id);
    });

    document.getElementById('btnMarkAllRead')?.addEventListener('click',function(){
        const date=this.dataset.date;this.disabled=true;this.textContent='Marking…';
        fetch('news_list.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=mark_all_read&date=${encodeURIComponent(date)}`})
        .then(r=>r.json()).then(()=>{document.querySelectorAll('.news-card.is-unread').forEach(c=>markRead(c.dataset.id));this.style.display='none'});
    });

    // Deactivate site — no confirm
    document.addEventListener('click',function(e){
        const b=e.target.closest('.btn-deactivate-site[data-source]');
        if(!b)return;
        const src=b.dataset.source;b.textContent='…';b.disabled=true;
        fetch('news_list.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=deactivate_site&site_name=${encodeURIComponent(src)}`})
        .then(r=>r.json()).then(()=>{
            document.querySelectorAll('.card-source').forEach(el=>{if(el.textContent.trim()===src){el.classList.add('inactive-source');el.title='This site is currently inactive'}});
            const lnk=document.createElement('a');lnk.href='inactive_sites.php';lnk.className='btn-deactivate-site';lnk.title='Already inactive';lnk.textContent='✕';lnk.style.cssText='background:#f1f5f9;border-color:#e2e8f0;color:#94a3b8';b.replaceWith(lnk);
        }).catch(()=>{b.textContent='✕';b.disabled=false});
    });

    document.querySelector('select[name="per_page"]')?.addEventListener('change',()=>document.getElementById('filterForm').submit());
});
</script>

<?php require_once 'footer.php'; ?>
