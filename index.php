<?php
// ===================================================================
// ЦЕНТР УПРАВЛЕНИЯ — Boostore.pro
// ===================================================================

// Config files list and helper to save SITES back to a config file
$configFiles = [
    __DIR__ . '/_setting_articles.inc',
    __DIR__ . '/_setting_pages.inc',
    __DIR__ . '/_setting_blocks.inc',
    __DIR__ . '/_setting_products.inc',
    __DIR__ . '/_setting_shop_categories.inc',
    __DIR__ . '/_setting_shop_producers_categories.inc',
    __DIR__ . '/_setting_shop_collections_categories.inc',
];
$configKeys = ['articles', 'pages', 'blocks', 'products', 'shop_categories', 'shop_producers', 'shop_collections'];

function saveConfigFile($path, $sites, $keyLabel) {
    ksort($sites);
    $export = "<?php\n// Settings for $keyLabel module — Boostore.pro\n\$SITES = [\n";
    foreach ($sites as $d => $c) {
        $export .= "    '" . addslashes($d) . "' => [\n";
        $export .= "        'key' => '" . addslashes($c['key'] ?? '') . "',\n";
        $export .= "        'allowed_categories' => " . var_export($c['allowed_categories'] ?? [], true) . ",\n";
        $export .= "        'planned_separate_folder' => " . var_export($c['planned_separate_folder'] ?? false, true) . ",\n";
        $export .= "        'category_folder' => " . var_export($c['category_folder'] ?? false, true) . ",\n";
        $export .= "        'status_mode' => '" . addslashes($c['status_mode'] ?? '') . "',\n";
        $export .= "        'status_override' => " . var_export($c['status_override'] ?? 1, true) . ",\n";
        $export .= "        'date_mode' => '" . addslashes($c['date_mode'] ?? '') . "',\n";
        $export .= "        'date_fixed' => '" . addslashes($c['date_fixed'] ?? '') . "',\n";
        $export .= "        'date_offset_base' => '" . addslashes($c['date_offset_base'] ?? '') . "',\n";
        $export .= "        'date_offset_days' => " . var_export($c['date_offset_days'] ?? 1, true) . ",\n";
        $export .= "        'override_planned' => '" . addslashes($c['override_planned'] ?? '') . "',\n";
        $export .= "        'export_article_id' => " . var_export($c['export_article_id'] ?? false, true) . ",\n";
        $export .= "        'export_category_id' => " . var_export($c['export_category_id'] ?? false, true) . ",\n";
        $export .= "        'export_category_name' => " . var_export($c['export_category_name'] ?? true, true) . ",\n";
        $export .= "        'per_page' => " . var_export($c['per_page'] ?? 200, true) . ",\n";
        $export .= "        'send_batch_limit' => " . var_export($c['send_batch_limit'] ?? 200, true) . ",\n";
        $export .= "        'reference_lang' => '" . addslashes($c['reference_lang'] ?? 'pl') . "',\n";
        $export .= "        'fix_multilangid' => " . var_export($c['fix_multilangid'] ?? false, true) . ",\n";
        $export .= "        'fix_planned' => " . var_export($c['fix_planned'] ?? false, true) . ",\n";
        $export .= "        'fix_status' => " . var_export($c['fix_status'] ?? false, true) . ",\n";
        $export .= "        'fix_datestamp' => " . var_export($c['fix_datestamp'] ?? false, true) . ",\n";
        $export .= "        'import_only_named' => " . var_export($c['import_only_named'] ?? true, true) . ",\n";
        $export .= "    ],\n";
    }
    $export .= "];\n";
    file_put_contents($path, $export, LOCK_EX);
}

// Handle adding a new domain
if (!empty($_POST['new_domain'])) {
    $newDomain = trim($_POST['new_domain']);
    $parsed = parse_url($newDomain);
    if (!empty($parsed['host'])) {
        $newDomain = $parsed['host'];
    } else {
        $newDomain = preg_replace('#^https?://#i', '', $newDomain);
        $newDomain = preg_replace('#/.*$#', '', $newDomain);
    }
    $newDomain = preg_replace('#^www\.#', '', $newDomain);
    foreach ($configFiles as $i => $cfg) {
        $SITES = [];
        if (file_exists($cfg)) require $cfg;
        if (!isset($SITES[$newDomain])) $SITES[$newDomain] = ['key' => ''];
        saveConfigFile($cfg, $SITES, $configKeys[$i]);
    }
    header('Location: index.php?site=' . urlencode($newDomain));
    exit;
}

// Handle deleting a domain
if (!empty($_POST['delete_domain'])) {
    $delDomain = trim($_POST['delete_domain']);
    foreach ($configFiles as $i => $cfg) {
        $SITES = [];
        if (file_exists($cfg)) require $cfg;
        if (isset($SITES[$delDomain])) {
            unset($SITES[$delDomain]);
            saveConfigFile($cfg, $SITES, $configKeys[$i]);
        }
    }
    // Clear active site if it was the deleted one
    $activeSiteFile = __DIR__ . '/_active_site.inc';
    if (file_exists($activeSiteFile) && trim(file_get_contents($activeSiteFile)) === $delDomain) {
        file_put_contents($activeSiteFile, '');
    }
    header('Location: index.php');
    exit;
}

// Read all configs to extract domain lists
$articlesSites = [];
$pagesSites = [];
$blocksSites = [];
$productsSites = [];
$shopCategoriesSites = [];
$shopProducersSites = [];
$shopCollectionsSites = [];

$articlesCfg = __DIR__ . '/_setting_articles.inc';
$pagesCfg = __DIR__ . '/_setting_pages.inc';
$blocksCfg = __DIR__ . '/_setting_blocks.inc';
$productsCfg = __DIR__ . '/_setting_products.inc';
$shopCategoriesCfg = __DIR__ . '/_setting_shop_categories.inc';
$shopProducersCfg = __DIR__ . '/_setting_shop_producers_categories.inc';
$shopCollectionsCfg = __DIR__ . '/_setting_shop_collections_categories.inc';

if (file_exists($articlesCfg)) {
    $SITES = [];
    require $articlesCfg;
    if (isset($SITES)) $articlesSites = $SITES;
}
if (file_exists($pagesCfg)) {
    $SITES = [];
    require $pagesCfg;
    if (isset($SITES)) $pagesSites = $SITES;
}
if (file_exists($blocksCfg)) {
    $SITES = [];
    require $blocksCfg;
    if (isset($SITES)) $blocksSites = $SITES;
}
if (file_exists($productsCfg)) {
    $SITES = [];
    require $productsCfg;
    if (isset($SITES)) $productsSites = $SITES;
}
if (file_exists($shopCategoriesCfg)) {
    $SITES = [];
    require $shopCategoriesCfg;
    if (isset($SITES)) $shopCategoriesSites = $SITES;
}
if (file_exists($shopProducersCfg)) {
    $SITES = [];
    require $shopProducersCfg;
    if (isset($SITES)) $shopProducersSites = $SITES;
}
if (file_exists($shopCollectionsCfg)) {
    $SITES = [];
    require $shopCollectionsCfg;
    if (isset($SITES)) $shopCollectionsSites = $SITES;
}

// Collect all unique domains across all configs
$allDomains = array_keys(array_merge($articlesSites, $pagesSites, $blocksSites, $productsSites, $shopCategoriesSites, $shopProducersSites, $shopCollectionsSites));
$allDomains = array_unique($allDomains);
sort($allDomains);

// Build JS lookup: which domains exist in which config
$siteLookup = [];
foreach ($articlesSites as $d => $c) { $siteLookup[$d][] = 'articles'; }
foreach ($pagesSites as $d => $c) { $siteLookup[$d][] = 'pages'; }
foreach ($blocksSites as $d => $c) { $siteLookup[$d][] = 'blocks'; }
foreach ($productsSites as $d => $c) { $siteLookup[$d][] = 'products'; }
foreach ($shopCategoriesSites as $d => $c) { $siteLookup[$d][] = 'shop_categories'; }
foreach ($shopProducersSites as $d => $c) { $siteLookup[$d][] = 'shop_producers'; }
foreach ($shopCollectionsSites as $d => $c) { $siteLookup[$d][] = 'shop_collections'; }

// Auto-add missing domains to runtime configs so links work for any selected site
foreach ($allDomains as $domain) {
    if (!array_key_exists($domain, $articlesSites)) { $articlesSites[$domain] = ['key' => '']; }
    if (!array_key_exists($domain, $pagesSites))    { $pagesSites[$domain]    = ['key' => '']; }
    if (!array_key_exists($domain, $blocksSites))   { $blocksSites[$domain]   = ['key' => '']; }
    if (!array_key_exists($domain, $productsSites)) { $productsSites[$domain] = ['key' => '']; }
    if (!array_key_exists($domain, $shopCategoriesSites)) { $shopCategoriesSites[$domain] = ['key' => '']; }
    if (!array_key_exists($domain, $shopProducersSites)) { $shopProducersSites[$domain] = ['key' => '']; }
    if (!array_key_exists($domain, $shopCollectionsSites)) { $shopCollectionsSites[$domain] = ['key' => '']; }
}
// Rebuild siteLookup after auto-add
$siteLookup = [];
foreach ($articlesSites as $d => $c) { $siteLookup[$d][] = 'articles'; }
foreach ($pagesSites as $d => $c) { $siteLookup[$d][] = 'pages'; }
foreach ($blocksSites as $d => $c) { $siteLookup[$d][] = 'blocks'; }
foreach ($productsSites as $d => $c) { $siteLookup[$d][] = 'products'; }
foreach ($shopCategoriesSites as $d => $c) { $siteLookup[$d][] = 'shop_categories'; }
foreach ($shopProducersSites as $d => $c) { $siteLookup[$d][] = 'shop_producers'; }
foreach ($shopCollectionsSites as $d => $c) { $siteLookup[$d][] = 'shop_collections'; }

// ---- Активный сайт ----
$activeSiteFile = __DIR__ . '/_active_site.inc';
$activeSite = '';
if (file_exists($activeSiteFile)) {
    $activeSite = trim(file_get_contents($activeSiteFile));
}
// Если в URL указан site, сохраняем как активный
if (!empty($_GET['site'])) {
    $activeSite = trim($_GET['site']);
    file_put_contents($activeSiteFile, $activeSite, LOCK_EX);
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Центр управления — Boostore.pro</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:linear-gradient(135deg,#0a0e1a 0%,#1a1a2e 50%,#16213e 100%);color:#e0e0e0;font-family:'Segoe UI',system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.wrap{max-width:960px;width:100%}
.header{text-align:center;margin-bottom:40px}
.header h1{font-size:32px;color:#00d4ff;font-weight:700;letter-spacing:-0.5px;margin-bottom:8px}
.header p{color:#888;font-size:14px}
.header .badge{display:inline-block;background:#0f3460;color:#4dc9f6;padding:4px 14px;border-radius:20px;font-size:12px;margin-top:10px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:32px}
.card{background:#16213e;border:1px solid #0f3460;border-radius:16px;padding:28px;transition:all .3s;position:relative;overflow:hidden}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;transition:all .3s}
.card.articles::before{background:linear-gradient(90deg,#00d4ff,#4dc9f6)}
.card.pages::before{background:linear-gradient(90deg,#ff9800,#ffb74d)}
.card:hover{border-color:#00d4ff;transform:translateY(-4px);box-shadow:0 12px 40px rgba(0,0,0,.4)}
.card.pages:hover{border-color:#ff9800}
.card-icon{font-size:48px;margin-bottom:12px}
.card h2{font-size:22px;margin-bottom:8px;font-weight:600}
.card.articles h2{color:#00d4ff}
.card.pages h2{color:#ff9800}
.card p{color:#888;font-size:13px;margin-bottom:16px;line-height:1.5}
.card .btn{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s}
.card.articles .btn{background:#00d4ff;color:#0a0e1a}
.card.articles .btn:hover{background:#4dc9f6;box-shadow:0 4px 16px rgba(0,212,255,.3);transform:translateY(-2px)}
.card.pages .btn{background:#ff9800;color:#0a0e1a}
.card.pages .btn:hover{background:#ffb74d;box-shadow:0 4px 16px rgba(255,152,0,.3);transform:translateY(-2px)}
.card .btn .arrow{font-size:18px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 22px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-pri{background:#00d4ff;color:#0a0e1a}.btn-pri:hover{background:#4dc9f6;transform:translateY(-2px)}
.sites-section{background:#16213e;border:1px solid #0f3460;border-radius:12px;padding:24px;margin-top:8px}
.sites-section h3{font-size:16px;color:#888;margin-bottom:16px;font-weight:500}
.sites-section h3 span{color:#e0e0e0}
.sites-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.site-group{background:#0d1b2a;border:1px solid #0f3460;border-radius:10px;padding:16px;transition:border-color .2s}
.site-group:hover{border-color:#00d4ff}
.site-group h4{font-size:13px;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.site-group h4 .label{font-weight:400}
.site-group h4.articles-label{color:#00d4ff}
.site-group h4.pages-label{color:#ff9800}
.site-list{list-style:none;padding:0}
.site-list li{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #0f3460;font-size:13px}
.site-list li:last-child{border-bottom:none}
.site-list li .domain{color:#e0e0e0;font-family:'Consolas',monospace;font-size:12px;word-break:break-all}
.site-list li .key-status{font-size:11px;padding:2px 8px;border-radius:10px}
.site-list li .key-status.ok{background:#1b5e20;color:#81c784}
.site-list li .key-status.missing{background:#3e2723;color:#ef9a9a}
.empty-msg{color:#555;font-size:13px;font-style:italic;padding:4px 0}
.footer{text-align:center;padding:20px;color:#444;font-size:12px;margin-top:16px}
.footer a{color:#00d4ff;text-decoration:none}
@media(max-width:640px){.grid{grid-template-columns:1fr}.sites-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
<div class="header">
<h1><span data-i18n="title">⚙ Центр управления</span></h1>
<p><span data-i18n="subtitle">Импорт и экспорт данных через API Boostore.pro</span></p>
<span class="badge">📡 Commerce API v2.0</span> <span class="badge" style="background:#1b5e20;">v1.7</span>
<select id="lang_switcher" onchange="applyLang(this.value)" style="margin-top:12px;padding:6px 14px;border:1px solid #0f3460;border-radius:6px;background:#0d1b2a;color:#e0e0e0;font-size:13px;cursor:pointer;">
<option value="ru" data-i18n="lang_ru">Русский</option>
<option value="en" data-i18n="lang_en">English</option>
<option value="ua" data-i18n="lang_ua">Українська</option>
</select>
</div>

<div style="background:#16213e;border:1px solid #0f3460;border-radius:12px;padding:20px 24px;margin-bottom:24px;text-align:center;">
<div style="font-size:13px;color:#888;margin-bottom:8px;font-weight:600;letter-spacing:1px;text-transform:uppercase;"><span data-i18n="site_selection">🎯 ВЫБОР САЙТА</span></div>
<?php if (!empty($allDomains)): ?>
<form method="get" action="" style="display:flex;gap:10px;justify-content:center;align-items:center;flex-wrap:wrap;">
<select name="site" id="site_selector" onchange="this.form.submit()" style="width:100%;max-width:400px;padding:12px 16px;background:#0d1b2a;border:1px solid #00d4ff;border-radius:8px;color:#e0e0e0;font-size:15px;font-weight:600;text-align:center;cursor:pointer;appearance:auto;">
<option value=""><span data-i18n="select_domain">— выберите домен —</span></option>
<?php foreach ($allDomains as $d): ?>
<option value="<?=htmlspecialchars($d)?>"<?=$activeSite===$d?' selected':''?>><?=htmlspecialchars($d)?></option>
<?php endforeach; ?>
</select>
</form>
<div id="site_links" style="margin-top:12px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;"></div>
<?php else: ?>
<p style="color:#888;font-size:14px;margin:8px 0;"><span data-i18n="no_sites">Нет настроенных сайтов</span> — добавьте новый домен ниже</p>
<?php endif; ?>
</div>

<div class="grid">
<div class="card articles">
<div class="card-icon">📝</div>
<h2><span data-i18n="articles">Статьи блога</span></h2>
<p><span data-i18n="articles_desc">Управление статьями блога: импорт с API, экспорт на сервер,<br>многоязычность, категории, фильтрация</span></p>
<a href="blog.php?site=<?=htmlspecialchars($activeSite ?: (array_key_first($articlesSites) ?: 'site.boostore.pro'))?>" class="btn">
<span data-i18n="go_to">📥 Перейти</span> <span class="arrow">→</span>
</a>
</div>

<div class="card pages">
<div class="card-icon">📄</div>
<h2><span data-i18n="pages">Страницы</span></h2>
<p><span data-i18n="pages_desc">Управление страницами сайта: импорт с API, экспорт на сервер,<br>многоязычность, гибкие настройки дат</span></p>
<a href="pages.php?site=<?=htmlspecialchars($activeSite ?: (array_key_first($pagesSites) ?: 'site.boostore.pro'))?>" class="btn">
<span data-i18n="go_to">📥 Перейти</span> <span class="arrow">→</span>
</a>
</div>

<div class="card pages" style="--card-accent:#9c27b0;">
<div class="card-icon">🧩</div>
<h2 style="color:#ce93d8;"><span data-i18n="blocks">Блоки/Меню</span></h2>
<p><span data-i18n="blocks_desc">Управление блоками и меню сайта: импорт с API,<br>экспорт на сервер, позиции, видимость</span></p>
<a href="blocks.php?site=<?=htmlspecialchars($activeSite ?: (array_key_first($blocksSites) ?: 'site.boostore.pro'))?>" class="btn" style="background:#9c27b0;color:#fff;">
<span data-i18n="go_to">📥 Перейти</span> <span class="arrow">→</span>
</a>
</div>

<div class="card" style="--card-accent:#4caf50;">
<div class="card-icon" style="font-size:48px;margin-bottom:12px;">📦</div>
<h2 style="color:#4caf50;"><span data-i18n="products">Товары</span></h2>
<p><span data-i18n="products_desc">Управление товарами: импорт с API, экспорт на сервер,<br>многосекционный контент (описание, вкладки), цены, SKU</span></p>
<a href="products.php?site=<?=htmlspecialchars($activeSite ?: (array_key_first($productsSites) ?: 'site.boostore.pro'))?>" class="btn" style="background:#4caf50;color:#fff;">
<span data-i18n="go_to">📥 Перейти</span> <span class="arrow">→</span>
</a>
</div>

<div class="card" style="--card-accent:#ff6f00;">
<div class="card-icon" style="font-size:48px;margin-bottom:12px;">📂</div>
<h2 style="color:#ffa726;"><span data-i18n="shop_categories">Категории магазина</span></h2>
<p><span data-i18n="shop_categories_desc">Управление категориями товаров: импорт с API, экспорт на сервер,<br>иерархия, родительские категории, изображения</span></p>
<a href="shop_categories.php?site=<?=htmlspecialchars($activeSite ?: (array_key_first($shopCategoriesSites) ?: 'site.boostore.pro'))?>" class="btn" style="background:#ff6f00;color:#fff;">
<span data-i18n="go_to">📥 Перейти</span> <span class="arrow">→</span>
</a>
</div>

<div class="card" style="--card-accent:#7c4dff;">
<div class="card-icon" style="font-size:48px;margin-bottom:12px;">🏭</div>
<h2 style="color:#b388ff;"><span data-i18n="shop_producers">Производители</span></h2>
<p><span data-i18n="shop_producers_desc">Управление производителями товаров: импорт с API, экспорт на сервер,<br>иерархия, родительские производители, изображения</span></p>
<a href="shop_producers_categories.php?site=<?=htmlspecialchars($activeSite ?: (array_key_first($shopProducersSites) ?: 'site.boostore.pro'))?>" class="btn" style="background:#7c4dff;color:#fff;">
<span data-i18n="go_to">📥 Перейти</span> <span class="arrow">→</span>
</a>
</div>

<div class="card" style="--card-accent:#00bcd4;">
<div class="card-icon" style="font-size:48px;margin-bottom:12px;">📚</div>
<h2 style="color:#4dd0e1;"><span data-i18n="shop_collections">Коллекции</span></h2>
<p><span data-i18n="shop_collections_desc">Управление коллекциями товаров: импорт с API, экспорт на сервер,<br>иерархия, родительские коллекции, изображения</span></p>
<a href="shop_collections_categories.php?site=<?=htmlspecialchars($activeSite ?: (array_key_first($shopCollectionsSites) ?: 'site.boostore.pro'))?>" class="btn" style="background:#00bcd4;color:#0a0e1a;">
<span data-i18n="go_to">📥 Перейти</span> <span class="arrow">→</span>
</a>
</div>
</div>

<div class="sites-section">
<h3><span data-i18n="connected_sites">🔗 Подключенные сайты</span> <span data-i18n="domains_label">(домены)</span></h3>
<div class="sites-grid">
<div class="site-group">
<h4 class="articles-label"><span class="label" data-i18n="articles">📝 Статьи</span></h4>
<?php if (empty($articlesSites)): ?>
<p class="empty-msg" data-i18n="no_sites">Нет настроенных сайтов</p>
<?php else: ?>
<ul class="site-list">
<?php foreach ($articlesSites as $domain => $cfg): ?>
<li>
<a href="blog.php?site=<?=urlencode($domain)?>" class="domain" style="color:#e0e0e0;text-decoration:none;font-family:'Consolas',monospace;font-size:12px;word-break:break-all;"><?=htmlspecialchars($domain)?></a>
<span class="key-status <?=empty($cfg['key'])?'missing':'ok'?>"><?=empty($cfg['key'])?'<span data-i18n="no_key">✕ нет ключа</span>':'<span data-i18n="has_key">✓ ключ есть</span>'?></span>
<form method="post" action="" style="display:inline" onsubmit="return confirm('Удалить сайт <?=htmlspecialchars($domain)?> из всех разделов?')"><input type="hidden" name="delete_domain" value="<?=htmlspecialchars($domain)?>"><button type="submit" style="background:none;border:none;color:#f44336;cursor:pointer;font-size:16px;padding:0 4px;" title="Удалить">✕</button></form>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div>

<div class="site-group">
<h4 class="pages-label"><span class="label" data-i18n="pages">📄 Страницы</span></h4>
<?php if (empty($pagesSites)): ?>
<p class="empty-msg" data-i18n="no_sites">Нет настроенных сайтов</p>
<?php else: ?>
<ul class="site-list">
<?php foreach ($pagesSites as $domain => $cfg): ?>
<li>
<a href="pages.php?site=<?=urlencode($domain)?>" class="domain" style="color:#e0e0e0;text-decoration:none;font-family:'Consolas',monospace;font-size:12px;word-break:break-all;"><?=htmlspecialchars($domain)?></a>
<span class="key-status <?=empty($cfg['key'])?'missing':'ok'?>"><?=empty($cfg['key'])?'<span data-i18n="no_key">✕ нет ключа</span>':'<span data-i18n="has_key">✓ ключ есть</span>'?></span>
<form method="post" action="" style="display:inline" onsubmit="return confirm('Удалить сайт <?=htmlspecialchars($domain)?> из всех разделов?')"><input type="hidden" name="delete_domain" value="<?=htmlspecialchars($domain)?>"><button type="submit" style="background:none;border:none;color:#f44336;cursor:pointer;font-size:16px;padding:0 4px;" title="Удалить">✕</button></form>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div>

<div class="site-group">
<h4 class="pages-label" style="color:#ce93d8;"><span class="label" data-i18n="blocks">🧩 Блоки/Меню</span></h4>
<?php if (empty($blocksSites)): ?>
<p class="empty-msg" data-i18n="no_sites">Нет настроенных сайтов</p>
<?php else: ?>
<ul class="site-list">
<?php foreach ($blocksSites as $domain => $cfg): ?>
<li>
<a href="blocks.php?site=<?=urlencode($domain)?>" class="domain" style="color:#e0e0e0;text-decoration:none;font-family:'Consolas',monospace;font-size:12px;word-break:break-all;"><?=htmlspecialchars($domain)?></a>
<span class="key-status <?=empty($cfg['key'])?'missing':'ok'?>"><?=empty($cfg['key'])?'<span data-i18n="no_key">✕ нет ключа</span>':'<span data-i18n="has_key">✓ ключ есть</span>'?></span>
<form method="post" action="" style="display:inline" onsubmit="return confirm('Удалить сайт <?=htmlspecialchars($domain)?> из всех разделов?')"><input type="hidden" name="delete_domain" value="<?=htmlspecialchars($domain)?>"><button type="submit" style="background:none;border:none;color:#f44336;cursor:pointer;font-size:16px;padding:0 4px;" title="Удалить">✕</button></form>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div>

<div class="site-group">
<h4 class="pages-label" style="color:#4caf50;"><span class="label" data-i18n="products">📦 Товары</span></h4>
<?php if (empty($productsSites)): ?>
<p class="empty-msg" data-i18n="no_sites">Нет настроенных сайтов</p>
<?php else: ?>
<ul class="site-list">
<?php foreach ($productsSites as $domain => $cfg): ?>
<li>
<a href="products.php?site=<?=urlencode($domain)?>" class="domain" style="color:#e0e0e0;text-decoration:none;font-family:'Consolas',monospace;font-size:12px;word-break:break-all;"><?=htmlspecialchars($domain)?></a>
<span class="key-status <?=empty($cfg['key'])?'missing':'ok'?>"><?=empty($cfg['key'])?'<span data-i18n="no_key">✕ нет ключа</span>':'<span data-i18n="has_key">✓ ключ есть</span>'?></span>
<form method="post" action="" style="display:inline" onsubmit="return confirm('Удалить сайт <?=htmlspecialchars($domain)?> из всех разделов?')"><input type="hidden" name="delete_domain" value="<?=htmlspecialchars($domain)?>"><button type="submit" style="background:none;border:none;color:#f44336;cursor:pointer;font-size:16px;padding:0 4px;" title="Удалить">✕</button></form>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div>

<div class="site-group">
<h4 class="pages-label" style="color:#ffa726;"><span class="label" data-i18n="shop_categories">📂 Категории</span></h4>
<?php if (empty($shopCategoriesSites)): ?>
<p class="empty-msg" data-i18n="no_sites">Нет настроенных сайтов</p>
<?php else: ?>
<ul class="site-list">
<?php foreach ($shopCategoriesSites as $domain => $cfg): ?>
<li>
<a href="shop_categories.php?site=<?=urlencode($domain)?>" class="domain" style="color:#e0e0e0;text-decoration:none;font-family:'Consolas',monospace;font-size:12px;word-break:break-all;"><?=htmlspecialchars($domain)?></a>
<span class="key-status <?=empty($cfg['key'])?'missing':'ok'?>"><?=empty($cfg['key'])?'<span data-i18n="no_key">✕ нет ключа</span>':'<span data-i18n="has_key">✓ ключ есть</span>'?></span>
<form method="post" action="" style="display:inline" onsubmit="return confirm('Удалить сайт <?=htmlspecialchars($domain)?> из всех разделов?')"><input type="hidden" name="delete_domain" value="<?=htmlspecialchars($domain)?>"><button type="submit" style="background:none;border:none;color:#f44336;cursor:pointer;font-size:16px;padding:0 4px;" title="Удалить">✕</button></form>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div>

<div class="site-group">
<h4 class="pages-label" style="color:#b388ff;"><span class="label" data-i18n="shop_producers">🏭 Производители</span></h4>
<?php if (empty($shopProducersSites)): ?>
<p class="empty-msg" data-i18n="no_sites">Нет настроенных сайтов</p>
<?php else: ?>
<ul class="site-list">
<?php foreach ($shopProducersSites as $domain => $cfg): ?>
<li>
<a href="shop_producers_categories.php?site=<?=urlencode($domain)?>" class="domain" style="color:#e0e0e0;text-decoration:none;font-family:'Consolas',monospace;font-size:12px;word-break:break-all;"><?=htmlspecialchars($domain)?></a>
<span class="key-status <?=empty($cfg['key'])?'missing':'ok'?>"><?=empty($cfg['key'])?'<span data-i18n="no_key">✕ нет ключа</span>':'<span data-i18n="has_key">✓ ключ есть</span>'?></span>
<form method="post" action="" style="display:inline" onsubmit="return confirm('Удалить сайт <?=htmlspecialchars($domain)?> из всех разделов?')"><input type="hidden" name="delete_domain" value="<?=htmlspecialchars($domain)?>"><button type="submit" style="background:none;border:none;color:#f44336;cursor:pointer;font-size:16px;padding:0 4px;" title="Удалить">✕</button></form>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div>

<div class="site-group">
<h4 class="pages-label" style="color:#4dd0e1;"><span class="label" data-i18n="shop_collections">📚 Коллекции</span></h4>
<?php if (empty($shopCollectionsSites)): ?>
<p class="empty-msg" data-i18n="no_sites">Нет настроенных сайтов</p>
<?php else: ?>
<ul class="site-list">
<?php foreach ($shopCollectionsSites as $domain => $cfg): ?>
<li>
<a href="shop_collections_categories.php?site=<?=urlencode($domain)?>" class="domain" style="color:#e0e0e0;text-decoration:none;font-family:'Consolas',monospace;font-size:12px;word-break:break-all;"><?=htmlspecialchars($domain)?></a>
<span class="key-status <?=empty($cfg['key'])?'missing':'ok'?>"><?=empty($cfg['key'])?'<span data-i18n="no_key">✕ нет ключа</span>':'<span data-i18n="has_key">✓ ключ есть</span>'?></span>
<form method="post" action="" style="display:inline" onsubmit="return confirm('Удалить сайт <?=htmlspecialchars($domain)?> из всех разделов?')"><input type="hidden" name="delete_domain" value="<?=htmlspecialchars($domain)?>"><button type="submit" style="background:none;border:none;color:#f44336;cursor:pointer;font-size:16px;padding:0 4px;" title="Удалить">✕</button></form>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div>
</div>
</div>

<div style="background:#16213e;border:1px solid #0f3460;border-radius:12px;padding:20px 24px;margin-top:24px;margin-bottom:24px;text-align:center;">
<div style="font-size:13px;color:#888;margin-bottom:12px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">➕ <span data-i18n="add_site">Добавить сайт</span></div>
<form method="post" action="" style="display:flex;gap:10px;justify-content:center;align-items:center;flex-wrap:wrap;" onsubmit="var v=this.querySelector('[name=new_domain]').value.trim();if(!v||!v.includes('.')){alert('Введите домен или URL (например: site.com)');return false;}">
<input type="text" name="new_domain" placeholder="site.com или https://site.com" style="padding:10px 14px;background:#0d1b2a;border:1px solid #00d4ff;border-radius:8px;color:#e0e0e0;font-size:14px;width:260px;text-align:center;">
<button type="submit" class="btn btn-pri" style="padding:10px 20px;font-size:14px;">➕ <span data-i18n="add_site">Добавить сайт</span></button>
</form>
</div>

<div class="footer">
<strong>Boostore.pro</strong> — <?=date('Y-m-d H:i:s')?> &nbsp;|&nbsp; <a href="https://boostore.pro/ru/docs/api-integration/#hotengine-CommerceAPI" target="_blank"><span data-i18n="api_docs">Документация API</span></a>
</div>
</div>
<script>
var siteData = <?=json_encode($siteLookup)?>;
var linksHtml = {
  articles: '<a href="blog.php?site=%s" class="btn" style="background:#00d4ff;color:#0a0e1a;padding:8px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;"><span data-i18n="link_articles">📝 Статьи</span> &rarr;</a>',
  pages: '<a href="pages.php?site=%s" class="btn" style="background:#ff9800;color:#0a0e1a;padding:8px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;"><span data-i18n="link_pages">📄 Страницы</span> &rarr;</a>',
  blocks: '<a href="blocks.php?site=%s" class="btn" style="background:#9c27b0;color:#fff;padding:8px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;"><span data-i18n="link_blocks">🧩 Блоки</span> &rarr;</a>',
  products: '<a href="products.php?site=%s" class="btn" style="background:#4caf50;color:#fff;padding:8px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;"><span data-i18n="link_products">📦 Товари</span> &rarr;</a>',
  shop_categories: '<a href="shop_categories.php?site=%s" class="btn" style="background:#ff6f00;color:#fff;padding:8px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;"><span data-i18n="link_shop_categories">📂 Категории</span> &rarr;</a>',
  shop_producers: '<a href="shop_producers_categories.php?site=%s" class="btn" style="background:#7c4dff;color:#fff;padding:8px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;"><span data-i18n="link_shop_producers">🏭 Виробники</span> &rarr;</a>',
  shop_collections: '<a href="shop_collections_categories.php?site=%s" class="btn" style="background:#00bcd4;color:#0a0e1a;padding:8px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;"><span data-i18n="link_shop_collections">📚 Колекції</span> &rarr;</a>'
};
var labels = {articles:'Статьи', pages:'Страницы', blocks:'Блоки/Меню', products:'Товары', shop_categories:'Категории', shop_producers:'Производители', shop_collections:'Коллекции'};
function navigateSite(domain) {
  var el = document.getElementById('site_links');
  if (!domain) { el.innerHTML = ''; return; }
  var modules = siteData[domain];
  if (!modules || modules.length === 0) {
    el.innerHTML = '<span style="color:#f44336;font-size:14px;" data-i18n="setup_warning">⚠ Сначала укажите настройки и API ключ для этого домена в соответствующем разделе</span>';
    return;
  }
  var html = '<span style="color:#888;font-size:13px;margin-right:4px;" data-i18n="navigate">Перейти:</span>';
  modules.forEach(function(m) {
    html += linksHtml[m].replace('%s', encodeURIComponent(domain));
  });
  el.innerHTML = html;
  if(typeof applyLang!=='undefined'&&typeof _lang!=='undefined')applyLang(_lang);
}
var sel = document.getElementById('site_selector');
if (sel) {
  sel.addEventListener('change', function() { navigateSite(this.value); });
  if (sel.value) navigateSite(sel.value);
}

var _lang = 'ru';
try { _lang = localStorage.getItem('boostore_lang') || navigator.language.slice(0,2); localStorage.setItem('boostore_lang', _lang); } catch(e) {}
if (!['ru','en','ua'].includes(_lang)) _lang = 'ru';

var _t = {
  ru: {'title':'⚙ Центр управления','subtitle':'Импорт и экспорт данных через API Boostore.pro','site_selection':'🎯 ВЫБОР САЙТА','select_domain':'— выберите домен —','articles':'Статьи блога','pages':'Страницы','blocks':'Блоки/Меню','products':'Товары','shop_categories':'Категории магазина','shop_producers':'Производители','shop_collections':'Коллекции','articles_desc':'Управление статьями блога: импорт с API, экспорт на сервер,<br>многоязычность, категории, фильтрация','pages_desc':'Управление страницами сайта: импорт с API, экспорт на сервер,<br>многоязычность, гибкие настройки дат','blocks_desc':'Управление блоками и меню сайта: импорт с API,<br>экспорт на сервер, позиции, видимость','products_desc':'Управление товарами: импорт с API, экспорт на сервер,<br>многосекционный контент (описание, вкладки), цены, SKU','shop_categories_desc':'Управление категориями товаров: импорт с API, экспорт на сервер,<br>иерархия, родительские категории, изображения','shop_producers_desc':'Управление производителями товаров: импорт с API, экспорт на сервер,<br>иерархия, родительские производители, изображения','shop_collections_desc':'Управление коллекциями товаров: импорт с API, экспорт на сервер,<br>иерархия, родительские коллекции, изображения','go_to':'📥 Перейти','link_articles':'📝 Статьи','link_pages':'📄 Страницы','link_blocks':'🧩 Блоки','link_products':'📦 Товары','link_shop_categories':'📂 Категории','link_shop_producers':'🏭 Производители','link_shop_collections':'📚 Коллекции','connected_sites':'🔗 Подключенные сайты','domains_label':'(домены)','no_sites':'Нет настроенных сайтов','no_key':'✕ нет ключа','has_key':'✓ ключ есть','navigate':'Перейти:','setup_warning':'⚠ Сначала укажите настройки и API ключ для этого домена в соответствующем разделе','add_site':'Добавить сайт','api_docs':'Документация API','lang_ru':'Русский','lang_en':'English','lang_ua':'Українська'},
  en: {'title':'⚙ Control Center','subtitle':'Import and export data via Boostore.pro API','site_selection':'🎯 SITE SELECTION','select_domain':'— select domain —','articles':'Blog Articles','pages':'Pages','blocks':'Blocks/Menus','products':'Products','shop_categories':'Shop Categories','shop_producers':'Producers','shop_collections':'Collections','articles_desc':'Manage blog articles: import from API, export to server,<br>multilingual, categories, filtering','pages_desc':'Manage site pages: import from API, export to server,<br>multilingual, flexible date settings','blocks_desc':'Manage blocks and menus: import from API,<br>export to server, positions, visibility','products_desc':'Manage products: import from API, export to server,<br>multi-section content (description, tabs), prices, SKU','shop_categories_desc':'Manage shop categories: import from API, export to server,<br>hierarchy, parent categories, images','shop_producers_desc':'Manage producers: import from API, export to server,<br>hierarchy, parent producers, images','shop_collections_desc':'Manage collections: import from API, export to server,<br>hierarchy, parent collections, images','go_to':'📥 Go','link_articles':'📝 Articles','link_pages':'📄 Pages','link_blocks':'🧩 Blocks','link_products':'📦 Products','link_shop_categories':'📂 Categories','link_shop_producers':'🏭 Producers','link_shop_collections':'📚 Collections','connected_sites':'🔗 Connected Sites','domains_label':'(domains)','no_sites':'No configured sites','no_key':'✕ no key','has_key':'✓ has key','navigate':'Go to:','setup_warning':'⚠ First configure settings and API key for this domain in the appropriate section','add_site':'Add Site','api_docs':'API Documentation','lang_ru':'Russian','lang_en':'English','lang_ua':'Ukrainian'},
  ua: {'title':'⚙ Центр управління','subtitle':'Імпорт та експорт даних через API Boostore.pro','site_selection':'🎯 ВИБІР САЙТУ','select_domain':'— виберіть домен —','articles':'Статті блогу','pages':'Сторінки','blocks':'Блоки/Меню','products':'Товари','shop_categories':'Категорії магазину','shop_producers':'Виробники','shop_collections':'Колекції','articles_desc':'Управління статтями блогу: імпорт з API, експорт на сервер,<br>багатомовність, категорії, фільтрація','pages_desc':'Управління сторінками сайту: імпорт з API, експорт на сервер,<br>багатомовність, гнучкі налаштування дат','blocks_desc':'Управління блоками та меню: імпорт з API,<br>експорт на сервер, позиції, видимість','products_desc':'Управління товарами: імпорт з API, експорт на сервер,<br>багатосекційний контент (опис, вкладки), ціни, SKU','shop_categories_desc':'Управління категоріями товарів: імпорт з API, експорт на сервер,<br>ієрархія, батьківські категорії, зображення','shop_producers_desc':'Управління виробниками товарів: імпорт з API, експорт на сервер,<br>ієрархія, батьківські виробники, зображення','shop_collections_desc':'Управління колекціями товарів: імпорт з API, експорт на сервер,<br>ієрархія, батьківські колекції, зображення','go_to':'📥 Перейти','link_articles':'📝 Статті','link_pages':'📄 Сторінки','link_blocks':'🧩 Блоки','link_products':'📦 Товари','link_shop_categories':'📂 Категорії','link_shop_producers':'🏭 Виробники','link_shop_collections':'📚 Колекції','connected_sites':'🔗 Підключені сайти','domains_label':'(домени)','no_sites':'Немає налаштованих сайтів','no_key':'✕ немає ключа','has_key':'✓ ключ є','navigate':'Перейти:','setup_warning':'⚠ Спочатку вкажіть налаштування та API ключ для цього домену у відповідному розділі','add_site':'Додати сайт','api_docs':'Документація API','lang_ru':'Російська','lang_en':'English','lang_ua':'Українська'}
};

function applyLang(l) {
  try { localStorage.setItem('boostore_lang', l); } catch(e) {}
  _lang = l;
  document.querySelectorAll('[data-i18n]').forEach(function(el){
    var key = el.getAttribute('data-i18n');
    if (_t[l] && _t[l][key] !== undefined) el.innerHTML = _t[l][key];
  });
  document.getElementById('lang_switcher').value = l;
}

if (_lang != 'ru') { document.addEventListener('DOMContentLoaded', function(){ applyLang(_lang); }); }
document.addEventListener('DOMContentLoaded', function(){
  var ls = document.getElementById('lang_switcher');
  if (ls) { ls.value = _lang; ls.addEventListener('change', function(){ applyLang(this.value); }); }
});
</script>
</body>
</html>
