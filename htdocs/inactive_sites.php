<?php
include 'config.php';
$conn = db_connect();

/* ── AJAX: toggle site active/inactive ── */
if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'activate' && isset($_POST['id'])) {
        $id   = (int)$_POST['id'];
        $stmt = $conn->prepare("
            UPDATE ecn.news_sites
            SET active=1, failure_count=0, last_error=NULL
            WHERE id=?
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        echo json_encode(['ok' => true]);

    } elseif ($_POST['action'] === 'deactivate' && isset($_POST['id'])) {
        $id   = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE ecn.news_sites SET active=0 WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        echo json_encode(['ok' => true]);

    } elseif ($_POST['action'] === 'activate_all') {
        $conn->query("UPDATE ecn.news_sites SET active=1, failure_count=0, last_error=NULL WHERE active=0 AND is_priority=0");
        echo json_encode(['ok' => true, 'affected' => $conn->affected_rows]);

    } elseif ($_POST['action'] === 'set_priority' && isset($_POST['id'])) {
        $id  = (int)$_POST['id'];
        $val = (int)$_POST['value'];
        $stmt = $conn->prepare("UPDATE ecn.news_sites SET is_priority=? WHERE id=?");
        $stmt->bind_param('ii', $val, $id);
        $stmt->execute();
        echo json_encode(['ok' => true]);

    } else {
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
    exit;
}

/* ── Filters ── */
$search     = trim($_GET['q']     ?? '');
$filterErr  = $_GET['error']      ?? '';   // 'yes' = only sites with errors
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 50;
$offset     = ($page - 1) * $perPage;

/* ── Stats ── */
$stmtStats = $conn->query("
    SELECT
        SUM(active=0)                  AS total_inactive,
        SUM(active=0 AND is_priority=1)AS inactive_priority,
        SUM(active=0 AND last_error IS NOT NULL AND last_error != '') AS has_error,
        SUM(active=1)                  AS total_active
    FROM ecn.news_sites
");
$stats           = $stmtStats->fetch_assoc();
$totalInactive   = (int)$stats['total_inactive'];
$inactivePriority= (int)$stats['inactive_priority'];
$hasError        = (int)$stats['has_error'];
$totalActive     = (int)$stats['total_active'];

/* ── Main query ── */
$where  = ["active = 0"];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = "(name LIKE ? OR base_url LIKE ?)";
    $q        = '%' . $search . '%';
    $params   = array_merge($params, [$q, $q]);
    $types   .= 'ss';
}
if ($filterErr === 'yes') {
    $where[] = "last_error IS NOT NULL AND last_error != ''";
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Count
$stmtCount = $conn->prepare("SELECT COUNT(*) AS n FROM ecn.news_sites $whereSql");
if ($params) $stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$totalRows  = (int)$stmtCount->get_result()->fetch_assoc()['n'];
$totalPages = max(1, ceil($totalRows / $perPage));

// Rows
$sqlParams  = array_merge($params, [$offset, $perPage]);
$sqlTypes   = $types . 'ii';
$stmtRows   = $conn->prepare("
    SELECT id, name, base_url, failure_count, last_error, last_scraped,
           is_priority, article_selector, title_selector, content_selector
    FROM ecn.news_sites
    $whereSql
    ORDER BY is_priority DESC, failure_count DESC, id ASC
    LIMIT ?, ?
");
$stmtRows->bind_param($sqlTypes, ...$sqlParams);
$stmtRows->execute();
$rows = $stmtRows->get_result()->fetch_all(MYSQLI_ASSOC);

require_once 'header.php';
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<style>
*, *::before, *::after { box-sizing: border-box; }
:root {
    --primary:       #1a56db;
    --primary-light: #e8f0fe;
    --danger:        #e02424;
    --success:       #057a55;
    --surface:       #f9fafb;
    --border:        #e5e7eb;
    --text:          #111827;
    --text-muted:    #6b7280;
    --text-light:    #9ca3af;
    --radius:        10px;
    --radius-sm:     6px;
    --shadow:        0 1px 3px rgba(0,0,0,.08);
    --shadow-md:     0 4px 16px rgba(0,0,0,.10);
}
body { margin:0; font-family:system-ui,-apple-system,sans-serif; background:var(--surface); }

.page-header {
    background:#fff; border-bottom:1px solid var(--border);
    padding:12px 16px; display:flex; align-items:center;
    justify-content:space-between; gap:8px; flex-wrap:wrap;
    position:sticky; top:0; z-index:100; box-shadow:var(--shadow);
}
.page-title   { font-size:18px; font-weight:700; color:var(--text); margin:0; }
.page-sub     { font-size:12px; color:var(--text-muted); margin-top:1px; }
.header-actions { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }

/* Stats */
.stats-bar {
    display:flex; gap:8px; flex-wrap:wrap; padding:12px 16px;
    overflow-x:auto; scrollbar-width:none;
}
.stats-bar::-webkit-scrollbar { display:none; }
.stat-card {
    background:#fff; border:1px solid var(--border); border-radius:var(--radius-sm);
    padding:10px 16px; min-width:110px; flex-shrink:0; box-shadow:var(--shadow);
}
.stat-num   { font-size:22px; font-weight:700; line-height:1; }
.stat-label { font-size:10px; color:var(--text-muted); margin-top:3px;
              font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
.stat-danger  .stat-num { color:#dc2626; }
.stat-warning .stat-num { color:#d97706; }
.stat-success .stat-num { color:var(--success); }
.stat-blue    .stat-num { color:var(--primary); }

/* Filter bar */
.filter-bar {
    background:#fff; border-bottom:1px solid var(--border);
    padding:10px 16px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;
    margin-bottom:12px;
}
.filter-bar input {
    height:34px; border:1px solid var(--border); border-radius:var(--radius-sm);
    padding:0 10px; font-size:13px; outline:none; flex:1; min-width:180px;
}
.filter-bar input:focus { border-color:var(--primary); }

/* Buttons */
.btn {
    display:inline-flex; align-items:center; gap:5px;
    padding:7px 14px; border-radius:var(--radius-sm); font-size:13px;
    font-weight:600; cursor:pointer; text-decoration:none; white-space:nowrap;
    border:1px solid transparent;
}
.btn-primary  { background:var(--primary); color:#fff; border-color:var(--primary); }
.btn-primary:hover { background:#1345b7; }
.btn-success  { background:#dcfce7; color:#166534; border-color:#86efac; }
.btn-success:hover { background:#bbf7d0; }
.btn-danger   { background:#fee2e2; color:#991b1b; border-color:#fca5a5; }
.btn-danger:hover { background:#fecaca; }
.btn-ghost    { background:#fff; color:var(--text-muted); border-color:var(--border); }
.btn-ghost:hover { background:var(--surface); }
.btn-sm { padding:4px 10px; font-size:12px; }

.pill-filter {
    padding:5px 12px; border-radius:20px; font-size:12px; font-weight:600;
    border:1px solid var(--border); background:#fff; color:var(--text-muted);
    text-decoration:none; white-space:nowrap;
}
.pill-filter:hover { background:var(--primary-light); color:var(--primary); border-color:var(--primary); }
.pill-filter.active { background:var(--primary); color:#fff; border-color:var(--primary); }

/* Toolbar */
.toolbar {
    display:flex; align-items:center; justify-content:space-between;
    padding:0 16px 8px; flex-wrap:wrap; gap:6px;
}
.record-count { font-size:13px; color:var(--text-muted); }
.record-count strong { color:var(--text); }
.pagination { display:flex; gap:3px; list-style:none; margin:0; padding:0; }
.page-link {
    display:flex; align-items:center; justify-content:center;
    min-width:32px; height:32px; padding:0 8px;
    border:1px solid var(--border); border-radius:var(--radius-sm);
    font-size:13px; color:var(--text); text-decoration:none; background:#fff;
}
.page-link:hover { background:var(--primary-light); color:var(--primary); border-color:var(--primary); }
.page-link.active { background:var(--primary); color:#fff; border-color:var(--primary); }

/* Sites list */
.sites-list { padding:0 16px; display:flex; flex-direction:column; gap:8px; }

.site-card {
    background:#fff; border:1px solid var(--border); border-radius:var(--radius);
    box-shadow:var(--shadow); overflow:hidden; transition:box-shadow .15s;
}
.site-card:hover { box-shadow:var(--shadow-md); }
.site-card.is-priority { border-left:3px solid #f59e0b; }
.site-card.is-activated { border-left:3px solid var(--success); background:#f0fdf4; }

.site-card-body {
    display:flex; align-items:flex-start; gap:12px;
    padding:12px 16px;
}

/* Checkbox */
.site-checkbox-wrap {
    display:flex; flex-direction:column; align-items:center;
    gap:4px; flex-shrink:0; padding-top:2px;
}
.site-checkbox {
    width:22px; height:22px; border-radius:5px; border:2px solid var(--border);
    background:#fff; cursor:pointer; appearance:none; -webkit-appearance:none;
    display:flex; align-items:center; justify-content:center;
    transition:all .15s; flex-shrink:0;
}
.site-checkbox:hover { border-color:var(--primary); }
.site-checkbox:checked {
    background:var(--success); border-color:var(--success);
    background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 16 16' fill='white' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M13.3 4.3l-7 7-3.3-3.3 1.4-1.4 1.9 1.9 5.6-5.6z'/%3E%3C/svg%3E");
    background-size:16px; background-repeat:no-repeat; background-position:center;
}
.site-checkbox:checked:hover { background-color:#059669; border-color:#059669; }

/* Site info */
.site-info { flex:1; min-width:0; }
.site-name {
    font-size:15px; font-weight:600; color:var(--text); margin:0 0 3px;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.site-url {
    font-size:12px; color:var(--primary); text-decoration:none;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    display:block; margin-bottom:6px;
}
.site-url:hover { text-decoration:underline; }
.site-badges { display:flex; gap:5px; flex-wrap:wrap; align-items:center; margin-bottom:6px; }

.badge {
    display:inline-flex; align-items:center; padding:2px 8px;
    border-radius:10px; font-size:11px; font-weight:700; white-space:nowrap;
}
.badge-failures { background:#fee2e2; color:#991b1b; }
.badge-priority { background:#fef3c7; color:#92400e; }
.badge-nosel    { background:#f3f4f6; color:#6b7280; }
.badge-lastseen { background:#f0f9ff; color:#0369a1; }

.site-error {
    font-size:12px; color:#dc2626; background:#fff5f5; border:1px solid #fecaca;
    border-radius:5px; padding:5px 8px; margin-top:5px;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}

/* Side actions */
.site-actions { display:flex; flex-direction:column; gap:4px; flex-shrink:0; align-items:flex-end; }

/* Priority star */
.btn-priority {
    background:none; border:none; cursor:pointer; font-size:18px;
    opacity:.3; transition:opacity .15s; padding:0; line-height:1;
}
.btn-priority:hover { opacity:.7; }
.btn-priority.active { opacity:1; }

/* Selectors indicator */
.selectors-dot {
    width:8px; height:8px; border-radius:50%; display:inline-block;
}
.sel-ok      { background:#22c55e; }
.sel-missing { background:#f87171; }

/* Empty state */
.empty-state {
    text-align:center; padding:60px 20px; color:var(--text-muted);
    background:#fff; border:1px solid var(--border); border-radius:var(--radius);
}
.empty-state p { font-size:16px; font-weight:500; color:var(--text); margin:0 0 6px; }

/* Bottom bar - bulk actions */
.bulk-bar {
    display:none; position:fixed; bottom:0; left:0; right:0;
    background:#1e293b; color:#fff; padding:12px 20px;
    align-items:center; justify-content:space-between;
    gap:10px; flex-wrap:wrap; z-index:200;
    box-shadow:0 -4px 20px rgba(0,0,0,.2);
}
.bulk-bar.visible { display:flex; }
.bulk-count { font-size:14px; font-weight:600; }

/* Responsive */
@media (min-width:640px)  {
    .page-header { padding:14px 24px; }
    .stats-bar   { padding:14px 24px; }
    .filter-bar  { padding:10px 24px; }
    .toolbar     { padding:0 24px 8px; }
    .sites-list  { padding:0 24px; }
}
@media (min-width:1024px) {
    .sites-list  { max-width:1200px; margin:0 auto; padding:0 32px; }
    .stats-bar   { max-width:1200px; margin:0 auto; }
    .filter-bar  { max-width:1200px; margin:0 auto; }
    .toolbar     { max-width:1200px; margin:0 auto; }
    .page-header { padding:14px 32px; }
}
@media (min-width:1280px) {
    .sites-list { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
}
</style>

<div class="news-page" style="padding-bottom:80px">

<!-- ── Header ── -->
<div class="page-header">
    <div>
        <h1 class="page-title">⚠ Inactive Sites</h1>
        <div class="page-sub">Sites with <code>active=0</code> — check to re-enable</div>
    </div>
    <div class="header-actions">
        <button class="btn btn-success" id="btnActivateAll">✓ Activate All</button>
        <a href="news_list.php" class="btn btn-ghost">← News Feed</a>
    </div>
</div>

<!-- ── Stats ── -->
<div class="stats-bar">
    <div class="stat-card stat-danger">
        <div class="stat-num"><?= number_format($totalInactive) ?></div>
        <div class="stat-label">Inactive</div>
    </div>
    <div class="stat-card stat-warning">
        <div class="stat-num"><?= number_format($hasError) ?></div>
        <div class="stat-label">With Errors</div>
    </div>
    <div class="stat-card stat-warning">
        <div class="stat-num"><?= number_format($inactivePriority) ?></div>
        <div class="stat-label">Priority Inactive</div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-num"><?= number_format($totalActive) ?></div>
        <div class="stat-label">Active</div>
    </div>
</div>

<!-- ── Filter bar ── -->
<form method="GET" class="filter-bar" id="filterForm">
    <input type="text" name="q" placeholder="Search name or URL…"
           value="<?= htmlspecialchars($search) ?>"
           oninput="this.form.submit()">
    <a href="?<?= http_build_query(array_merge($_GET, ['error'=>$filterErr==='yes'?'':'yes','page'=>1])) ?>"
       class="pill-filter <?= $filterErr==='yes'?'active':'' ?>">
        ⚠ Has Error
    </a>
    <a href="inactive_sites.php" class="btn btn-ghost btn-sm">Clear</a>
</form>

<!-- ── Toolbar ── -->
<?php
function renderPager($page, $totalPages, $totalRows, $perPage, $offset) {
    $from = min($offset+1, $totalRows);
    $to   = min($offset+$perPage, $totalRows);
    echo '<div class="toolbar">';
    echo "<div class='record-count'><strong>$from–$to</strong> of <strong>".number_format($totalRows)."</strong> inactive sites</div>";
    if ($totalPages > 1) {
        echo '<nav><ul class="pagination">';
        if ($page > 1) {
            echo '<li><a class="page-link" href="?'.http_build_query(array_merge($_GET,['page'=>$page-1])).'">‹</a></li>';
        }
        for ($i=max(1,$page-2); $i<=min($totalPages,$page+2); $i++) {
            $ac = $i===$page ? 'active' : '';
            echo "<li><a class='page-link $ac' href='?".http_build_query(array_merge($_GET,['page'=>$i]))."'>$i</a></li>";
        }
        if ($page < $totalPages) {
            echo '<li><a class="page-link" href="?'.http_build_query(array_merge($_GET,['page'=>$page+1])).'">›</a></li>';
        }
        echo '</ul></nav>';
    }
    echo '</div>';
}
renderPager($page, $totalPages, $totalRows, $perPage, $offset);
?>

<!-- ── Sites list ── -->
<div class="sites-list" id="sitesList">

<?php if (empty($rows)): ?>
<div class="empty-state">
    <p>🎉 No inactive sites</p>
    <small>All sites are currently active. Great!</small>
</div>
<?php else: foreach ($rows as $site):
    $hasSelectors  = $site['article_selector'] && $site['title_selector'] && $site['content_selector'];
    $isPriority    = (int)$site['is_priority'] === 1;
    $failures      = (int)$site['failure_count'];
    $lastSeen      = $site['last_scraped'] ? date('M d', strtotime($site['last_scraped'])) : 'Never';
    $cardClass     = $isPriority ? 'site-card is-priority' : 'site-card';
?>

<div class="<?= $cardClass ?>" data-id="<?= $site['id'] ?>" id="site-<?= $site['id'] ?>">
    <div class="site-card-body">

        <!-- Checkbox to activate -->
        <div class="site-checkbox-wrap">
            <input type="checkbox" class="site-checkbox"
                   data-id="<?= $site['id'] ?>"
                   title="Check to activate this site">
            <span style="font-size:9px;color:var(--text-light);text-align:center">activate</span>
        </div>

        <!-- Site info -->
        <div class="site-info">
            <div class="site-name"><?= htmlspecialchars($site['name'] ?: '—') ?></div>
            <a href="<?= htmlspecialchars($site['base_url']) ?>" target="_blank"
               class="site-url" rel="noopener"><?= htmlspecialchars($site['base_url']) ?></a>

            <div class="site-badges">
                <?php if ($failures > 0): ?>
                <span class="badge badge-failures">✗ <?= $failures ?> failure<?= $failures>1?'s':'' ?></span>
                <?php endif; ?>

                <?php if ($isPriority): ?>
                <span class="badge badge-priority">★ Priority</span>
                <?php endif; ?>

                <span title="Selectors <?= $hasSelectors?'configured':'missing' ?>">
                    <span class="selectors-dot <?= $hasSelectors?'sel-ok':'sel-missing' ?>"></span>
                    <span style="font-size:11px;color:var(--text-muted)"><?= $hasSelectors?'Selectors OK':'No selectors' ?></span>
                </span>

                <span class="badge badge-lastseen">Last: <?= $lastSeen ?></span>
            </div>

            <?php if (!empty($site['last_error'])): ?>
            <div class="site-error"><?= htmlspecialchars($site['last_error']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Actions -->
        <div class="site-actions">
            <button class="btn-priority <?= $isPriority?'active':'' ?>"
                    data-id="<?= $site['id'] ?>"
                    data-priority="<?= $isPriority?1:0 ?>"
                    title="<?= $isPriority?'Remove priority':'Set as priority' ?>">★</button>
            <button class="btn btn-success btn-sm activate-btn"
                    data-id="<?= $site['id'] ?>">
                ✓ Activate
            </button>
        </div>

    </div>
</div>

<?php endforeach; endif; ?>
</div>

<!-- ── Bottom pagination ── -->
<div style="margin-top:16px">
    <?php renderPager($page, $totalPages, $totalRows, $perPage, $offset); ?>
</div>

</div><!-- /news-page -->


<!-- ── Bulk action bar (appears when checkboxes selected) ── -->
<div class="bulk-bar" id="bulkBar">
    <div class="bulk-count" id="bulkCount">0 sites selected</div>
    <div style="display:flex;gap:8px">
        <button class="btn btn-success" id="btnActivateSelected">✓ Activate Selected</button>
        <button class="btn btn-ghost" id="btnClearSelection" style="color:#fff;border-color:#64748b">
            Cancel
        </button>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ── Post helper ── */
    function post(data) {
        return fetch('inactive_sites.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(data).toString()
        }).then(r => r.json());
    }

    /* ── Activate a single site ── */
    function activateSite(id, callback) {
        post({action: 'activate', id: id}).then(res => {
            if (res.ok) {
                const card = document.getElementById('site-' + id);
                if (card) {
                    card.classList.add('is-activated');
                    card.style.opacity = '.5';
                    card.style.pointerEvents = 'none';
                    // Fade out and remove after 1.2s
                    setTimeout(() => {
                        card.style.transition = 'all .4s';
                        card.style.maxHeight  = card.offsetHeight + 'px';
                        card.style.maxHeight  = '0';
                        card.style.overflow   = 'hidden';
                        card.style.marginBottom = '0';
                        setTimeout(() => { card.remove(); updateCount(-1); }, 400);
                    }, 800);
                }
                if (callback) callback();
            }
        });
    }

    /* ── Update the inactive count in header stats ── */
    let inactiveCount = <?= $totalInactive ?>;
    function updateCount(delta) {
        inactiveCount = Math.max(0, inactiveCount + delta);
        const el = document.querySelector('.stat-danger .stat-num');
        if (el) el.textContent = inactiveCount.toLocaleString();
    }

    /* ── Single activate button ── */
    document.querySelectorAll('.activate-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.dataset.id;
            this.textContent = '…';
            this.disabled = true;
            // Also uncheck the checkbox
            const cb = document.querySelector(`.site-checkbox[data-id="${id}"]`);
            if (cb) cb.checked = false;
            activateSite(id);
            updateBulkBar();
        });
    });

    /* ── Activate All button ── */
    document.getElementById('btnActivateAll')?.addEventListener('click', function () {
        if (!confirm('Activate ALL ' + inactiveCount + ' inactive sites?\n\nThis resets their failure count too.')) return;
        this.textContent = 'Activating…';
        this.disabled = true;
        post({action: 'activate_all'}).then(res => {
            if (res.ok) {
                document.querySelectorAll('.site-card').forEach(c => {
                    c.style.transition = 'opacity .4s';
                    c.style.opacity = '0';
                    setTimeout(() => c.remove(), 400);
                });
                updateCount(-inactiveCount);
                this.textContent = '✓ Done';
            }
        });
    });

    /* ── Checkbox selection + bulk bar ── */
    function updateBulkBar() {
        const checked = document.querySelectorAll('.site-checkbox:checked');
        const bar     = document.getElementById('bulkBar');
        const count   = document.getElementById('bulkCount');
        if (checked.length > 0) {
            bar.classList.add('visible');
            count.textContent = checked.length + ' site' + (checked.length > 1 ? 's' : '') + ' selected';
        } else {
            bar.classList.remove('visible');
        }
    }

    document.querySelectorAll('.site-checkbox').forEach(cb => {
        cb.addEventListener('change', updateBulkBar);
    });

    document.getElementById('btnClearSelection')?.addEventListener('click', function () {
        document.querySelectorAll('.site-checkbox:checked').forEach(cb => cb.checked = false);
        updateBulkBar();
    });

    document.getElementById('btnActivateSelected')?.addEventListener('click', function () {
        const checked = [...document.querySelectorAll('.site-checkbox:checked')];
        if (!checked.length) return;
        this.textContent = 'Activating…';
        this.disabled = true;
        let done = 0;
        checked.forEach(cb => {
            activateSite(cb.dataset.id, () => {
                done++;
                if (done === checked.length) {
                    document.getElementById('bulkBar').classList.remove('visible');
                    document.getElementById('btnActivateSelected').textContent = '✓ Activate Selected';
                    document.getElementById('btnActivateSelected').disabled = false;
                }
            });
        });
    });

    /* ── Priority star toggle ── */
    document.querySelectorAll('.btn-priority').forEach(btn => {
        btn.addEventListener('click', function () {
            const id       = this.dataset.id;
            const current  = this.dataset.priority === '1';
            const newVal   = current ? 0 : 1;
            post({action: 'set_priority', id: id, value: newVal}).then(res => {
                if (res.ok) {
                    this.dataset.priority = newVal;
                    this.classList.toggle('active', newVal === 1);
                    this.title = newVal === 1 ? 'Remove priority' : 'Set as priority';
                    const card = document.getElementById('site-' + id);
                    if (card) card.classList.toggle('is-priority', newVal === 1);
                }
            });
        });
    });

    /* ── Auto-submit search on Enter ── */
    document.querySelector('.filter-bar input')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); this.form.submit(); }
    });

});
</script>

<?php require_once 'footer.php'; ?>
