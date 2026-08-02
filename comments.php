<?php
// ===================================================================
// ИМПОРТ/ЭКСПОРТ ОТЗЫВОВ И КОММЕНТАРИЕВ (Comments Boostore.pro)
// ===================================================================
// Инструкция: https://boostore.pro/ru/docs/api-integration/#api-comments
// ===================================================================
// Особенности модуля:
//  - фильтр по ID родительской страницы (page_id)
//  - фильтр по типам страниц (единый список-чекбоксы, по умолчанию все)
//  - разбивка локальных файлов по подпапкам типов страниц
//  - НЕТ multilang и НЕТ исправления по эталону (для отзывов не нужно)

// Единый список типов страниц (тип системы + внутреннее имя + подпись)
$PAGE_TYPES = [
    'page'            => ['type' => 'pages', 'internal' => 'hotlist_inshop_page',       'label' => 'Страницы'],
    'product_page'    => ['type' => 'shop',  'internal' => 'shop_catalog_page',          'label' => 'Товары'],
    'blog_page'       => ['type' => 'pages', 'internal' => 'blog_catalog_page',          'label' => 'Статьи'],
    'blog_category'   => ['type' => 'pages', 'internal' => 'blog_catalog_category',      'label' => 'Категории блога'],
    'shop_category'   => ['type' => 'pages', 'internal' => 'pers_shop_catalog_category', 'label' => 'Категории магазина'],
    'shop_producer'   => ['type' => 'pages', 'internal' => 'shop_cat_producer_category', 'label' => 'Производители'],
    'shop_collection' => ['type' => 'pages', 'internal' => 'shop_cat_collection_category','label' => 'Коллекции магазина'],
];

// Auto-create config file if not exists
$configFile = __DIR__ . '/_setting_comments.inc';
if (!file_exists($configFile)) {
    $defaultConfig = "<?php
// ===================================================================
// НАСТРОЙКИ ИМПОРТА/ЭКСПОРТА ОТЗЫВОВ И КОММЕНТАРИЕВ (Comments Boostore.pro)
// ===================================================================
// Инструкция: https://boostore.pro/ru/docs/api-integration/#api-comments
// ===================================================================
// === МНОГОСАЙТОВОСТЬ ===
// Каждый сайт = отдельная папка с собственными настройками
\$SITES = [
    'site.boostore.pro' => [
        'key' => '',
        'page_types' => ['page','product_page','blog_page','blog_category','shop_category','shop_producer','shop_collection'],
        'type_folder' => true,
        'per_page' => 200,
        'send_batch_limit' => 200,
    ],
];
";
    file_put_contents($configFile, $defaultConfig);
    chmod($configFile, 0644);
}

require $configFile;
if (!isset($SITES)) $SITES = ['site.boostore.pro' => ['key' => '']];

// Determine current site
$currentSite = '';
if (!empty($_GET['site'])) {
    $currentSite = $_GET['site'];
    if (!isset($SITES[$currentSite])) {
        $SITES[$currentSite] = ['key' => '', 'page_types' => array_keys($PAGE_TYPES)];
        saveConfig($configFile, $SITES);
        require $configFile;
    }
    file_put_contents(__DIR__ . '/_active_site.inc', $currentSite, LOCK_EX);
} else {
    $activeSiteFile = __DIR__ . '/_active_site.inc';
    if (file_exists($activeSiteFile) && isset($SITES[trim(file_get_contents($activeSiteFile))])) {
        $currentSite = trim(file_get_contents($activeSiteFile));
    } else {
        $siteKeys = array_keys($SITES);
        $currentSite = $siteKeys[0] ?? '';
    }
}
$AUTH_KEY = $SITES[$currentSite]['key'] ?? '';
$API_DOMAIN = $currentSite;
$API_URL = 'https://' . $currentSite . '/api/commerce/comments';

$siteCfg = $SITES[$currentSite] ?? [];
$CONFIG_PAGE_TYPES = $siteCfg['page_types'] ?? array_keys($PAGE_TYPES);
$TYPE_FOLDER       = $siteCfg['type_folder'] ?? true;
$PER_PAGE          = $siteCfg['per_page'] ?? 200;
$SEND_BATCH_LIMIT  = $siteCfg['send_batch_limit'] ?? 200;

// Site directory + comments folder
$SITE_DIR = __DIR__ . DIRECTORY_SEPARATOR . $currentSite;
$COMMENTS_DIR = $SITE_DIR . DIRECTORY_SEPARATOR . 'comments';
if (!is_dir($COMMENTS_DIR)) { @mkdir($COMMENTS_DIR, 0777, true); }

// Helper: export $SITES
function sitesExport($sites, $pageTypesKeys) {
    $c = "[\n";
    foreach ($sites as $sDomain => $sCfg) {
        $c .= "    ".var_export($sDomain, true)." => [\n";
        $c .= "        'key' => ".var_export($sCfg['key'] ?? '', true).",\n";
        $pts = $sCfg['page_types'] ?? $pageTypesKeys;
        $c .= "        'page_types' => ".var_export($pts, true).",\n";
        $c .= "        'type_folder' => ".($sCfg['type_folder'] ?? true ? 'true' : 'false').",\n";
        $c .= "        'per_page' => ".(int)($sCfg['per_page'] ?? 200).",\n";
        $c .= "        'send_batch_limit' => ".(int)($sCfg['send_batch_limit'] ?? 200).",\n";
        $c .= "    ],\n";
    }
    return $c . "]\n";
}
function saveConfig($configFile, $SITES, $pageTypesKeys) {
    if (!is_array($SITES) || count($SITES) === 0) return;
    file_put_contents($configFile, "<?php\n\n\$SITES = " . sitesExport($SITES, $pageTypesKeys) . ";\n", LOCK_EX);
}

// Resolve friendly page_type aliases to internal DB names
function commentsResolveType($type) {
    $t = strtolower(trim((string)$type));
    $alias = [
        'page' => 'hotlist_inshop_page',
        'product' => 'shop_catalog_page',
        'product_page' => 'shop_catalog_page',
        'shop' => 'shop_catalog_page',
        'blog_page' => 'blog_catalog_page',
        'blog_category' => 'blog_catalog_category',
        'shop_category' => 'pers_shop_catalog_category',
        'shop_producer' => 'shop_cat_producer_category',
        'producer' => 'shop_cat_producer_category',
        'shop_collection' => 'shop_cat_collection_category',
        'collection' => 'shop_cat_collection_category',
    ];
    return $alias[$t] ?? $t;
}

$action = $_GET['action'] ?? '';
$apiKeyMissing = empty($AUTH_KEY);

// Handle add_site action: add new site to $SITES and save config, then redirect
if ($action === 'add_site' && !empty($_GET['site'])) {
    $newSite = trim($_GET['site']);
    if (!isset($SITES[$newSite])) {
        $SITES[$newSite] = ['key' => '', 'page_types' => array_keys($PAGE_TYPES)];
        saveConfig($configFile, $SITES, array_keys($PAGE_TYPES));
    }
    header('Location: ?site=' . urlencode($newSite));
    exit;
}

// ---- Active site + header ----
$activeSiteFile = __DIR__ . '/_active_site.inc';
$activeSite = '';
if (file_exists($activeSiteFile)) { $activeSite = trim(file_get_contents($activeSiteFile)); }
if (!empty($_GET['site'])) { $activeSite = trim($_GET['site']); }

$siteOptions = '<option value="">— выберите домен —</option>';
foreach (array_keys($SITES) as $sd) {
    $siteOptions .= '<option value="'.htmlspecialchars($sd).'"'.($sd===$currentSite?' selected':'').'>'.htmlspecialchars($sd).'</option>';
}

$crumbs = [];
$crumbs[] = '<a href="index.php" style="color:#00d4ff;text-decoration:none;font-weight:600;"><span data-i18n="home">🏠 Главная</span></a>';
if ($currentSite) {
    $crumbs[] = '<a href="?site='.urlencode($currentSite).'" style="color:#00d4ff;text-decoration:none;font-weight:600;">💬 <span data-i18n="entity_name">Отзывы</span></a>';
}
if ($action === 'get') $crumbs[] = '<a href="?action=get&site='.urlencode($currentSite).'" style="color:#888;text-decoration:none;" data-i18n="import">📥 Импорт</a>';
elseif ($action === 'update') {
    $crumbs[] = '<a href="?action=update&site='.urlencode($currentSite).'" style="color:#888;text-decoration:none;" data-i18n="export">📤 Экспорт</a>';
    if (!empty($_GET['step2'])) $crumbs[] = '<span style="color:#888;" data-i18n="file_selection">📁 Выбор файлов</span>';
    if (!empty($_GET['confirm'])) $crumbs[] = '<span data-i18n="result_label" style="color:#888;">✓ Результат</span>';
}
$breadcrumb = '<div class="bcrumb" style="background:#111827;border:1px solid #1e293b;border-radius:10px;padding:10px 18px;margin-bottom:12px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:13px;color:#888;">'.implode(' <span style="color:#555;">›</span> ', $crumbs).'</div>';

$header = '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
<h1 style="margin:0 0 20px 0;">▸ <span data-i18n="title">Управление отзывами и комментариями — Boostore.pro</span></h1>
<div style="display:flex;gap:8px;align-items:center;">
'.(file_exists(__DIR__.'/_active_site.inc')
? '<strong style="font-size:14px;color:#00d4ff;font-family:monospace;">'.htmlspecialchars($currentSite).'</strong>'
: '<select id="site_switcher" onchange=\'var s=this.value,p,a,b=location.href.split("?")[0];if(s==="__add__"){var d=prompt("Введите домен нового сайта:");if(d&&(d=d.trim())){location.href=b+"?site="+encodeURIComponent(d)+"&action=add_site";}else{this.value="'.htmlspecialchars($currentSite).'";}return;}location.href=b+"?site="+encodeURIComponent(s);\' style="padding:4px 8px;border:1px solid #0f3460;border-radius:4px;background:#0d1b2a;color:#00d4ff;font-size:12px;width:auto;font-weight:600;">
'.$siteOptions.'
</select>')
.'
<select id="lang_switcher" onchange="applyLang(this.value)" style="padding:4px 8px;border:1px solid #0f3460;border-radius:4px;background:#0d1b2a;color:#e0e0e0;font-size:12px;width:auto;">
<option value="ru" data-i18n="lang_ru">Русский</option><option value="en" data-i18n="lang_en">English</option><option value="ua" data-i18n="lang_ua">Українська</option>
</select></div>
</div> 
<div class="meta-info"><a href="index.php" style="font-size:16px;text-decoration:none;">🏠</a> &nbsp;|&nbsp; <a href="https://boostore.pro/ru/docs/api-integration/#hotengine-CommerceAPI" target="_blank" data-i18n="api_docs">API Docs</a> &nbsp;|&nbsp; <span data-i18n="version">v2.0</span> &nbsp;|&nbsp; '.date('Y-m-d H:i:s').' &nbsp;|&nbsp; <span data-i18n="site_label">Сайт:</span> <strong>'.htmlspecialchars($currentSite).'</strong></div>
'.$breadcrumb;

// ===================================================================
// IMPORT (action=get)
// ===================================================================
if ($action === 'get'):

@set_time_limit(300); @ini_set('memory_limit','256M');

$getPerPage = isset($_GET['per_page']) ? min(2000, (int)$_GET['per_page']) : $PER_PAGE;
$getPageIds = isset($_GET['page_id']) ? array_values(array_filter(array_map('intval', (array)$_GET['page_id']))) : [];
$getTypes   = isset($_GET['types']) && is_array($_GET['types']) ? array_map('strval', $_GET['types']) : $CONFIG_PAGE_TYPES;
$typeFolder = isset($_GET['type_folder']) ? ((int)$_GET['type_folder']===1) : $TYPE_FOLDER;

// Step 1: settings form
if (empty($getPageIds) && empty($_GET['confirm'])): ?>

<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title data-i18n="title">Управление отзывами — Boostore.pro</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#1a1a2e;color:#e0e0e0;font-family:'Segoe UI',system-ui,sans-serif;padding:30px}.wrap{max-width:960px;margin:0 auto}
h1{font-size:22px;color:#00d4ff;margin-bottom:5px}.meta-info{color:#888;font-size:13px;margin-bottom:25px}a{color:#00d4ff}.btn:hover{color:#fff;text-decoration:none}
.card{background:#16213e;border:1px solid #0f3460;border-radius:10px;padding:20px 24px;margin-bottom:20px}
label{display:block;margin:0 0 6px;color:#888;font-size:13px}input[type=text],input[type=number],select{width:100%;padding:9px 12px;border:1px solid #0f3460;border-radius:6px;background:#0d1b2a;color:#e0e0e0;font-size:14px;margin-bottom:14px;box-sizing:border-box}
input:focus,select:focus{outline:none;border-color:#00d4ff}.hint{color:#555;font-size:12px;margin:-10px 0 14px}
.chk{display:flex;align-items:center;gap:8px;margin:0 0 8px} .chk input{width:auto;margin:0}
.btn{display:inline-block;padding:12px 28px;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;text-decoration:none;background:#00d4ff;color:#0a0e1a}
.btn:hover{background:#4dc9f6;box-shadow:0 4px 16px rgba(0,212,255,.3)}
</style></head>
<body><div class="wrap"><?php echo $header; ?>
<div class="card">
<h3 style="margin-bottom:16px;color:#e0e0e0;" data-i18n="import_settings">▸ <strong>Настройки импорта отзывов/комментариев</strong></h3>
<form method="get" action="">
  <input type="hidden" name="action" value="get">
  <input type="hidden" name="site" value="<?=htmlspecialchars($currentSite)?>">
  <input type="hidden" name="confirm" value="1">

  <label data-i18n="page_id_label">ID страниц/товаров (page_id, пусто — все):</label>
  <div id="page-id-fields">
    <?php if(empty($getPageIds)): ?>
    <input type="number" name="page_id[]" value="" placeholder="например: 489311" style="margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box;">
    <?php else: ?>
    <?php foreach($getPageIds as $gid): ?>
    <input type="number" name="page_id[]" value="<?=$gid?>" placeholder="например: 489311" style="margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box;">
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <button type="button" onclick="var p=document.getElementById('page-id-fields');var inp=document.createElement('input');inp.type='number';inp.name='page_id[]';inp.placeholder='например: 489311';inp.style.cssText='display:block;margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box';p.appendChild(inp);" style="padding:2px 10px;background:transparent;color:#00d4ff;border:1px dashed #00d4ff;border-radius:4px;cursor:pointer;font-size:11px;margin-top:2px;" data-i18n="btn_more">+ ЕЩЕ</button>
  <button type="button" onclick="var t=prompt(_t[_lang]['prompt_values'] || 'Введите ID (каждая строка — отдельный ID):');if(t){var p=document.getElementById('page-id-fields');var lines=t.split('\n');for(var i=0;i<lines.length;i++){var v=lines[i].trim();if(v==='')continue;var inp=document.createElement('input');inp.type='number';inp.name='page_id[]';inp.value=v;inp.placeholder='например: 489311';inp.style.cssText='display:block;margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box';p.appendChild(inp);}}" style="padding:2px 10px;background:transparent;color:#ff9800;border:1px dashed #ff9800;border-radius:4px;cursor:pointer;font-size:11px;margin-top:2px;margin-left:4px;" data-i18n="btn_more_multi">📋 ЕЩЕ НЕСКОЛЬКО</button>

  <label data-i18n="types_label">Типы страниц:</label>
  <div id="types-wrap">
    <?php foreach($PAGE_TYPES as $pt => $info): ?>
    <label class="chk">
      <input type="checkbox" name="types[]" value="<?=$pt?>"<?=in_array($pt, $getTypes)?' checked':''?>> <?=htmlspecialchars($info['label'])?>
    </label>
    <?php endforeach; ?>
  </div>

  <label data-i18n="per_page_label">Отзывов за запрос:</label>
  <input type="number" name="per_page" value="<?=$getPerPage?>" min="1" max="2000">

  <label data-i18n="type_folder_label">Разделять по папкам типов страниц:</label>
  <select name="type_folder">
    <option value="1"<?=$typeFolder?' selected':''?>>Да</option>
    <option value="0"<?=!$typeFolder?' selected':''?>>Нет (все в одной папке comments/)</option>
  </select>

  <button type="submit" class="btn" data-i18n="btn_get">📥 СКАЧАТЬ ОТЗЫВЫ</button>
</form>
</div>
<script>
var _lang='ru';try{_lang=localStorage.getItem('boostore_lang')||navigator.language.slice(0,2);localStorage.setItem('boostore_lang',_lang);}catch(e){}if(!['ru','en','ua'].includes(_lang))_lang='ru';
var _t={ru:{'title':'Управление отзывами — Boostore.pro','import_settings':'Настройки импорта отзывов/комментариев','page_id_label':'ID страниц/товаров (page_id, пусто — все):','types_label':'Типы страниц:','per_page_label':'Отзывов за запрос:','type_folder_label':'Разделять по папкам типов страниц:','btn_get':'📥 СКАЧАТЬ ОТЗЫВЫ','btn_more':'+ ЕЩЕ','btn_more_multi':'📋 ЕЩЕ НЕСКОЛЬКО','prompt_values':'Введите ID (каждая строка — отдельный ID):','lang_ru':'Русский','lang_en':'English','lang_ua':'Українська','import':'📥 Импорт','export':'📤 Экспорт','site_label':'Сайт:'},en:{'title':'Manage Reviews — Boostore.pro','import_settings':'Review/comment import settings','page_id_label':'Page/product IDs (page_id, empty — all):','types_label':'Page types:','per_page_label':'Reviews per request:','type_folder_label':'Separate by page-type folders:','btn_get':'📥 DOWNLOAD REVIEWS','btn_more':'+ MORE','btn_more_multi':'📋 ADD MULTIPLE','prompt_values':'Enter IDs (each line is a separate ID):','lang_ru':'Russian','lang_en':'English','lang_ua':'Ukrainian','import':'📥 Import','export':'📤 Export','site_label':'Site:'},ua:{'title':'Управління відгуками — Boostore.pro','import_settings':'Налаштування імпорту відгуків/коментарів','page_id_label':'ID сторінок/товарів (page_id, порожньо — всі):','types_label':'Типи сторінок:','per_page_label':'Відгуків за запит:','type_folder_label':'Розділяти по папках типів сторінок:','btn_get':'📥 ЗАВАНТАЖИТИ ВІДГУКИ','btn_more':'+ ЩЕ','btn_more_multi':'📋 ДОДАТИ КІЛЬКА','prompt_values':'Введіть ID (кожен рядок — окремий ID):','lang_ru':'Російська','lang_en':'English','lang_ua':'Українська','import':'📥 Імпорт','export':'📤 Експорт','site_label':'Сайт:'}};
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});document.getElementById('lang_switcher').value=l;}
if(_lang!='ru'){document.addEventListener('DOMContentLoaded',function(){applyLang(_lang);});}
document.addEventListener('DOMContentLoaded',function(){var ls=document.getElementById('lang_switcher');if(ls){ls.value=_lang;ls.addEventListener('change',function(){applyLang(this.value);});}});
</script>
</div></body></html>
<?php exit; endif;

// ===================================================================
// Выполнение импорта (по каждому выбранному типу страниц)
// ===================================================================
$total = 0; $saved = 0; $skipped = 0;
$savedComments = [];
$baseDir = $COMMENTS_DIR;
$fetchErrors = [];

foreach ($getTypes as $pt) {
    if (!isset($PAGE_TYPES[$pt])) continue;
    $ptInfo = $PAGE_TYPES[$pt];
    $ptType = $ptInfo['type'];
    $ptInternal = $ptInfo['internal'];

    // Page IDs to fetch: provided list, or empty = all
    $idsToFetch = !empty($getPageIds) ? $getPageIds : [0];

    foreach ($idsToFetch as $pid) {
        $curlUrl = $API_URL . '?type=' . urlencode($ptType) . '&page_type=' . urlencode($pt) . '&per_page=' . intval($getPerPage);
        if ($pid > 0) $curlUrl .= '&page_id=' . intval($pid);

        $ch = curl_init($curlUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer ".$AUTH_KEY, "Content-Type: application/json"],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            $decoded = json_decode((string)$response, true);
            $apiErr = is_array($decoded) ? ($decoded['error'] ?? $decoded['message'] ?? '') : '';
            $fetchErrors[] = "[{$PAGE_TYPES[$pt]['label']}] " . ($apiErr !== '' ? "Ошибка API: {$apiErr} (HTTP {$httpCode})" : "Ошибка HTTP {$httpCode}: {$curlError}");
            continue;
        }
        $data = json_decode($response, true);
        if (!is_array($data)) { $fetchErrors[] = "[{$PAGE_TYPES[$pt]['label']}] Ошибка парсинга JSON"; continue; }
        if (!empty($data['error'])) { $fetchErrors[] = "[{$PAGE_TYPES[$pt]['label']}] Ошибка API: ".$data['error']; continue; }
        $comments = (isset($data['comments']) && is_array($data['comments'])) ? $data['comments'] : [];
        $total += count($comments);

    foreach ($comments as $comment) {
        $cid       = (int)($comment['id'] ?? 0);
        $parentId  = (int)($comment['parent_id'] ?? 0);
        $pageId    = (int)($comment['page_id'] ?? 0);
        $pageType  = trim((string)($comment['page_type'] ?? $ptInternal));
        if ($pageType === '') $pageType = $ptInternal;
        $authorName  = (string)($comment['author_name'] ?? '');
        $authorEmail = (string)($comment['author_email'] ?? '');
        $authorUserid= (int)($comment['author_userid'] ?? 0);
        $rating    = (int)($comment['rating'] ?? 0);
        $text      = (string)($comment['text'] ?? '');
        $textGood  = (string)($comment['text_good'] ?? '');
        $textBad   = (string)($comment['text_bad'] ?? '');
        $hide      = (int)($comment['hide'] ?? 0);
        $datestump = (int)($comment['datestump'] ?? time());
        $ip        = (string)($comment['ip'] ?? '');
        $positnum  = (int)($comment['positnum'] ?? 0);

        // Build subdir by page type
        $subDir = '';
        if ($typeFolder) { $subDir = $pt . DIRECTORY_SEPARATOR; }
        $dirPath = $baseDir . DIRECTORY_SEPARATOR . $subDir;
        if (!is_dir($dirPath)) { @mkdir($dirPath, 0777, true); }

        $filename = $cid . '-page-' . $pageId . '-p' . $parentId . '.html';
        $filepath = $dirPath . $filename;
        $relPath = $currentSite . '/comments/' . $subDir . $filename;

        $metaList = [
            'id' => $cid, 'parent_id' => $parentId, 'page_id' => $pageId, 'page_type' => $pageType,
            'author_userid' => $authorUserid, 'author_name' => $authorName, 'author_email' => $authorEmail,
            'rating' => $rating, 'hide' => $hide, 'datestump' => $datestump, 'ip' => $ip, 'positnum' => $positnum,
        ];
        $h = '';
        foreach ($metaList as $k => $v) {
            $h .= '<meta name="'.htmlspecialchars($k).'" content="'.htmlspecialchars((string)$v).'">'."\n";
        }
        $h .= '<meta name="delete" content="false">'."\n";
        $h .= '<!-- CONTENT SEPARATOR BELOW -->'."\n";
        $h .= '<div class="comment-text">' . $text . '</div>'."\n";
        $h .= '<!-- TEXT_GOOD -->' . $textGood . "\n";
        $h .= '<!-- TEXT_BAD -->' . $textBad . "\n";

        file_put_contents($filepath, $h);
        $saved++;
        $savedComments[] = ['id'=>$cid, 'parent_id'=>$parentId, 'path'=>$relPath, 'rating'=>$rating, 'page_type'=>$pageType];
    }
    } // foreach idsToFetch
} // foreach getTypes

$totalItems = $total;
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title data-i18n="import_results_title">Импорт отзывов — результат</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#1a1a2e;color:#e0e0e0;font-family:'Segoe UI',system-ui,sans-serif;padding:30px}.wrap{max-width:1200px;margin:0 auto}
h1{font-size:22px;color:#00d4ff;margin-bottom:5px}.meta-info{color:#888;font-size:13px;margin-bottom:25px}a{color:#00d4ff}
.card{background:#16213e;border:1px solid #0f3460;border-radius:10px;margin-bottom:16px;overflow:hidden}
.card-header{background:#0f3460;padding:10px 16px;display:flex;justify-content:space-between;align-items:center}
.card-body{padding:12px 16px}.meta-grid{display:grid;grid-template-columns:auto 1fr;gap:3px 14px;font-size:12px}.meta-grid .key{color:#888}.meta-grid .val{color:#e0e0e0;word-break:break-all}
.summary-card{background:#0f3460;border-radius:10px;padding:14px 20px;margin-bottom:16px;font-size:14px}
</style></head>
<body><div class="wrap"><?php echo $header; ?>
<div class="meta-info" style="padding-top:10px;">
  <span data-i18n="all_total">Всего отзывов:</span> <strong style="color:#00d4ff"><?=$total?></strong> |
  <span data-i18n="loaded_count">Сохранено:</span> <strong style="color:#4caf50"><?=$saved?></strong> |
  <span data-i18n="skipped_count">Пропущено:</span> <strong style="color:#888"><?=$skipped?></strong> |
  <span data-i18n="filter_label">Фильтр:</span> <strong style="color:#ff9800">page_id=<?=!empty($getPageIds)?implode(',', $getPageIds):'все'?></strong>
</div>
<?php if(!empty($fetchErrors)): ?>
<div class="card"><span style="color:#f44336;"><?php foreach($fetchErrors as $fe){ echo htmlspecialchars($fe).'<br>'; } ?></span></div>
<?php endif; ?>
<?php if(!empty($savedComments)): ?>
<?php foreach($savedComments as $sc): ?>
<div class="card">
  <div class="card-header"><span><span class="num" style="color:#00d4ff;font-weight:700;">#<?=$sc['id']?></span> <span class="file" style="color:#e0e0e0;"><?=htmlspecialchars($sc['path'])?></span></span></div>
  <div class="card-body"><div class="meta-grid">
    <span class="key">parent_id:</span><span class="val"><?=$sc['parent_id']?></span>
    <span class="key">rating:</span><span class="val"><?=$sc['rating']?></span>
    <span class="key">page_type:</span><span class="val"><?=htmlspecialchars($sc['page_type'])?></span>
  </div></div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div></body></html>
<?php exit; endif;

// ===================================================================
// EXPORT (action=update)
// ===================================================================
if ($action === 'update'):

$exportTypes = isset($_GET['types']) && is_array($_GET['types']) ? array_map('strval', $_GET['types']) : $CONFIG_PAGE_TYPES;
$exportMode    = $_GET['export_mode'] ?? 'all'; // all, insert, update
$dryRun        = isset($_GET['dry-run']);
$exportId      = !isset($_GET['export_id']) || (int)$_GET['export_id'] === 1;
$typeFolder    = isset($_GET['type_folder']) ? ((int)$_GET['type_folder']===1) : $TYPE_FOLDER;

$getPageIdFilters = isset($_GET['page_id']) ? array_values(array_filter(array_map('intval', (array)$_GET['page_id']))) : [];

// Step 1: settings form
if (!isset($_GET['step'])): ?>

<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title data-i18n="title">Экспорт отзывов — Boostore.pro</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#1a1a2e;color:#e0e0e0;font-family:'Segoe UI',system-ui,sans-serif;padding:30px}.wrap{max-width:960px;margin:0 auto}
h1{font-size:22px;color:#00d4ff;margin-bottom:5px}.meta-info{color:#888;font-size:13px;margin-bottom:25px}a{color:#00d4ff}
.card{background:#16213e;border:1px solid #0f3460;border-radius:10px;padding:20px 24px;margin-bottom:20px}
label{display:block;margin:0 0 6px;color:#888;font-size:13px}input[type=number],select{width:100%;padding:9px 12px;border:1px solid #0f3460;border-radius:6px;background:#0d1b2a;color:#e0e0e0;font-size:14px;margin-bottom:14px;box-sizing:border-box}
.chk{display:flex;align-items:center;gap:8px;margin:0 0 8px} .chk input{width:auto;margin:0}
.btn{display:inline-block;padding:12px 28px;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;background:#00d4ff;color:#0a0e1a;text-decoration:none}
.btn:hover{background:#4dc9f6}
</style></head>
<body><div class="wrap"><?php echo $header; ?>
<div class="card">
<h3 style="margin-bottom:16px;" data-i18n="export_settings">▸ <strong>Настройки экспорта отзывов/комментариев</strong></h3>
<?php if($dryRun):?><div style="margin-bottom:12px;font-size:13px;color:#ff9800;" data-i18n="dryrun_warn">⚡ DRY RUN — запросы не отправляются</div><?php endif;?>
<form method="get" action="">
  <input type="hidden" name="action" value="update">
  <input type="hidden" name="site" value="<?=htmlspecialchars($currentSite)?>">
  <input type="hidden" name="step" value="2">

  <label data-i18n="page_id_label">Фильтр по ID страниц/товаров (page_id, пусто — все):</label>
  <div id="exp-page-id-fields">
    <?php if(empty($getPageIdFilters)): ?>
    <input type="number" name="page_id[]" value="" placeholder="например: 489311" style="margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box;">
    <?php else: ?>
    <?php foreach($getPageIdFilters as $gid): ?>
    <input type="number" name="page_id[]" value="<?=$gid?>" placeholder="например: 489311" style="margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box;">
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <button type="button" onclick="var p=document.getElementById('exp-page-id-fields');var inp=document.createElement('input');inp.type='number';inp.name='page_id[]';inp.placeholder='например: 489311';inp.style.cssText='display:block;margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box';p.appendChild(inp);" style="padding:2px 10px;background:transparent;color:#00d4ff;border:1px dashed #00d4ff;border-radius:4px;cursor:pointer;font-size:11px;margin-top:2px;" data-i18n="btn_more">+ ЕЩЕ</button>
  <button type="button" onclick="var t=prompt(_t[_lang]['prompt_values'] || 'Введите ID (каждая строка — отдельный ID):');if(t){var p=document.getElementById('exp-page-id-fields');var lines=t.split('\n');for(var i=0;i<lines.length;i++){var v=lines[i].trim();if(v==='')continue;var inp=document.createElement('input');inp.type='number';inp.name='page_id[]';inp.value=v;inp.placeholder='например: 489311';inp.style.cssText='display:block;margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box';p.appendChild(inp);}}" style="padding:2px 10px;background:transparent;color:#ff9800;border:1px dashed #ff9800;border-radius:4px;cursor:pointer;font-size:11px;margin-top:2px;margin-left:4px;" data-i18n="btn_more_multi">📋 ЕЩЕ НЕСКОЛЬКО</button>

  <label data-i18n="types_label">Типы страниц:</label>
  <div id="types-wrap">
    <?php foreach($PAGE_TYPES as $pt => $info): ?>
    <label class="chk">
      <input type="checkbox" name="types[]" value="<?=$pt?>"<?=in_array($pt, $exportTypes)?' checked':''?>> <?=htmlspecialchars($info['label'])?>
    </label>
    <?php endforeach; ?>
  </div>

  <label data-i18n="type_folder_label">Разделять по папкам типов страниц:</label>
  <select name="type_folder">
    <option value="1"<?=$typeFolder?' selected':''?>>Да</option>
    <option value="0"<?=!$typeFolder?' selected':''?>>Нет</option>
  </select>

  <label><input type="checkbox" name="dry-run" value="1"<?=$dryRun?' checked':''?>> <span data-i18n="dry_run_label">Dry run</span></label>

  <h3 style="margin:14px 0 8px;color:#e0e0e0;" data-i18n="export_fields_title">📋 Поля для экспорта</h3>
  <label class="chk"><input type="hidden" name="export_id" value="0"><input type="checkbox" name="export_id" value="1"<?=$exportId?' checked':''?>> <span data-i18n="export_id_label">ID</span></label>

  <label style="font-size:11px;color:#888;display:block;margin-bottom:6px;" data-i18n="mode_label">🔄 Режим экспорта</label>
  <div style="display:flex;gap:16px;flex-wrap:wrap;">
    <label style="font-size:13px;color:#e0e0e0;cursor:pointer;display:flex;align-items:center;gap:4px;"><input type="radio" name="export_mode" value="all"<?=$exportMode==='all'?' checked':''?>> <span data-i18n="mode_all">Добавление + обновление</span></label>
    <label style="font-size:13px;color:#e0e0e0;cursor:pointer;display:flex;align-items:center;gap:4px;"><input type="radio" name="export_mode" value="insert"<?=$exportMode==='insert'?' checked':''?>> <span data-i18n="mode_insert">Только добавление новых</span></label>
    <label style="font-size:13px;color:#e0e0e0;cursor:pointer;display:flex;align-items:center;gap:4px;"><input type="radio" name="export_mode" value="update"<?=$exportMode==='update'?' checked':''?>> <span data-i18n="mode_update">Только обновление существующих</span></label>
  </div>

  <button type="submit" class="btn" data-i18n="step_forward">➡ ДАЛЕЕ</button>
</form>
</div>
<script>
var _lang='ru';try{_lang=localStorage.getItem('boostore_lang')||navigator.language.slice(0,2);localStorage.setItem('boostore_lang',_lang);}catch(e){}if(!['ru','en','ua'].includes(_lang))_lang='ru';
var _t={ru:{'title':'Экспорт отзывов — Boostore.pro','export_settings':'Настройки экспорта отзывов/комментариев','page_id_label':'Фильтр по ID страниц/товаров (page_id, пусто — все):','types_label':'Типы страниц:','btn_more':'+ ЕЩЕ','btn_more_multi':'📋 ЕЩЕ НЕСКОЛЬКО','prompt_values':'Введите ID (каждая строка — отдельный ID):','type_folder_label':'Разделять по папкам типов страниц:','dry_run_label':'Dry run','export_fields_title':'Поля для экспорта','export_id_label':'ID','mode_label':'Режим экспорта','mode_all':'Добавление + обновление','mode_insert':'Только добавление','mode_update':'Только обновление','step_forward':'➡ ДАЛЕЕ','dryrun_warn':'⚡ DRY RUN — запросы не отправляются','lang_ru':'Русский','lang_en':'English','lang_ua':'Українська','import':'📥 Импорт','export':'📤 Экспорт','site_label':'Сайт:'},en:{'title':'Export Reviews — Boostore.pro','export_settings':'Review/comment export settings','page_id_label':'Filter by page/product IDs (page_id, empty — all):','types_label':'Page types:','btn_more':'+ MORE','btn_more_multi':'📋 ADD MULTIPLE','prompt_values':'Enter IDs (each line is a separate ID):','type_folder_label':'Separate by page-type folders:','dry_run_label':'Dry run','export_fields_title':'Fields to export','export_id_label':'ID','mode_label':'Export mode','mode_all':'Add + Update','mode_insert':'Add new only','mode_update':'Update existing only','step_forward':'➡ NEXT','dryrun_warn':'⚡ DRY RUN — no API calls sent','lang_ru':'Russian','lang_en':'English','lang_ua':'Ukrainian','import':'📥 Import','export':'📤 Export','site_label':'Site:'},ua:{'title':'Експорт відгуків — Boostore.pro','export_settings':'Налаштування експорту відгуків/коментарів','page_id_label':'Фільтр за ID сторінок/товарів (page_id, порожньо — всі):','types_label':'Типи сторінок:','btn_more':'+ ЩЕ','btn_more_multi':'📋 ДОДАТИ КІЛЬКА','prompt_values':'Введіть ID (кожен рядок — окремий ID):','type_folder_label':'Розділяти по папках типів сторінок:','dry_run_label':'Dry run','export_fields_title':'Поля для експорту','export_id_label':'ID','mode_label':'Режим експорту','mode_all':'Додавання + оновлення','mode_insert':'Тільки додавання','mode_update':'Тільки оновлення','step_forward':'➡ ДАЛІ','dryrun_warn':'⚡ DRY RUN — запити не надсилаються','lang_ru':'Російська','lang_en':'English','lang_ua':'Українська','import':'📥 Імпорт','export':'📤 Експорт','site_label':'Сайт:'}};
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});document.getElementById('lang_switcher').value=l;}
if(_lang!='ru'){document.addEventListener('DOMContentLoaded',function(){applyLang(_lang);});}
document.addEventListener('DOMContentLoaded',function(){var ls=document.getElementById('lang_switcher');if(ls){ls.value=_lang;ls.addEventListener('change',function(){applyLang(this.value);});}});
</script>
</div></body></html>
<?php exit; endif;

// ===================================================================
// Step 2: file selection
// ===================================================================
$htmlFiles = [];
if (!isset($_GET['all_files']) && isset($_GET['files']) && is_array($_GET['files'])) {
    foreach ($_GET['files'] as $fp) {
        $absPath = __DIR__ . DIRECTORY_SEPARATOR . $fp;
        if (file_exists($absPath)) $htmlFiles[] = $absPath;
    }
} else {
    foreach ($exportTypes as $pt) {
        if (!isset($PAGE_TYPES[$pt])) continue;
        $scanDir = $typeFolder ? ($COMMENTS_DIR . DIRECTORY_SEPARATOR . $pt) : $COMMENTS_DIR;
        if (is_dir($scanDir)) {
            $rdi = new RecursiveDirectoryIterator($scanDir, RecursiveDirectoryIterator::SKIP_DOTS);
            $rii = new RecursiveIteratorIterator($rdi);
            foreach ($rii as $f) {
                if ($f->isFile() && strtolower($f->getExtension()) === 'html') $htmlFiles[] = $f->getPathname();
            }
        }
    }
    $htmlFiles = array_unique($htmlFiles);
    sort($htmlFiles);
}

// filter by page_id (list)
if (!empty($getPageIdFilters)) {
    $htmlFiles = array_filter($htmlFiles, function($fp) use ($getPageIdFilters) {
        $h = @file_get_contents($fp);
        if ($h === false) return false;
        preg_match('/<meta name="page_id" content="(\d+)">/', $h, $m);
        return in_array((int)($m[1] ?? 0), $getPageIdFilters, true);
    });
    $htmlFiles = array_values($htmlFiles);
}

$totalFiles2 = count($htmlFiles); ?>
<?php if (isset($_GET['step']) && (int)$_GET['step'] === 2): ?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title data-i18n="step2_title">Экспорт отзывов — выбор файлов</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#1a1a2e;color:#e0e0e0;font-family:'Segoe UI',system-ui,sans-serif;padding:30px}.wrap{max-width:1100px;margin:0 auto}
h1{font-size:22px;color:#00d4ff;margin-bottom:5px}.meta-info{color:#888;font-size:13px;margin-bottom:25px}a{color:#00d4ff}
.card{background:#16213e;border:1px solid #0f3460;border-radius:10px;padding:16px 20px;margin-bottom:12px}
.file-row{display:flex;align-items:center;gap:10px;padding:8px 4px;border-bottom:1px solid #0f3460}
.file-row:last-child{border-bottom:none}.file-row input{margin:0}.file-row .fp{font-family:'Consolas',monospace;font-size:12px;color:#e0e0e0;word-break:break-all}
.btn{display:inline-block;padding:12px 28px;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;background:#00d4ff;color:#0a0e1a;text-decoration:none}
</style></head>
<body><div class="wrap"><?php echo $header; ?>
<div class="meta-info" style="padding-top:10px;"><span data-i18n="files_found">Найдено файлов:</span> <strong style="color:#00d4ff"><?=$totalFiles2?></strong></div>
<form method="get" action="" id="export-step2">
  <input type="hidden" name="action" value="update">
  <input type="hidden" name="site" value="<?=htmlspecialchars($currentSite)?>">
  <input type="hidden" name="step" value="3">
  <input type="hidden" name="export_mode" value="<?=htmlspecialchars($exportMode)?>">
  <input type="hidden" name="type_folder" value="<?=$typeFolder?'1':'0'?>">
  <input type="hidden" name="export_id" value="<?=$exportId?'1':'0'?>">
  <?php if($dryRun):?><input type="hidden" name="dry-run" value="1"><?php endif;?>
  <?php foreach($exportTypes as $pt): ?><input type="hidden" name="types[]" value="<?=htmlspecialchars($pt)?>"><?php endforeach; ?>
  <?php foreach($getPageIdFilters as $gid): ?><input type="hidden" name="page_id[]" value="<?=$gid?>"><?php endforeach; ?>

<div class="card">
<?php if(empty($htmlFiles)): ?>
  <p style="color:#888;" data-i18n="no_files_found">Нет файлов, соответствующих критериям</p>
<?php else: ?>
  <div style="margin-bottom:10px;"><button type="button" onclick="document.querySelectorAll('.file-chk').forEach(c=>c.checked=true)" style="padding:4px 12px;background:transparent;color:#00d4ff;border:1px solid #00d4ff;border-radius:4px;cursor:pointer;" data-i18n="select_all">☑ ВЫДЕЛИТЬ ВСЕ</button>
  <button type="button" onclick="document.querySelectorAll('.file-chk').forEach(c=>c.checked=false)" style="padding:4px 12px;background:transparent;color:#888;border:1px solid #555;border-radius:4px;cursor:pointer;margin-left:6px;" data-i18n="deselect_all">☐ СНЯТЬ ВСЕ</button>
  <label style="display:inline-flex;align-items:center;gap:6px;margin-left:10px;cursor:pointer;color:#00d4ff;font-size:13px;"><input type="checkbox" name="all_files" value="1" onchange="if(this.checked){document.querySelectorAll('.file-chk').forEach(function(c){c.checked=false;});}"> <span data-i18n="all_files">📦 Все файлы</span></label></div>
  <?php foreach($htmlFiles as $fp): $relPath = str_replace(__DIR__.DIRECTORY_SEPARATOR, '', $fp); ?>
  <div class="file-row">
    <input type="checkbox" name="files[]" value="<?=htmlspecialchars($relPath)?>" checked class="file-chk">
    <span class="fp"><?=htmlspecialchars($relPath)?></span>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>

<div style="margin-top:10px;"><button type="submit" class="btn" data-i18n="export_selected">📤 ЭКСПОРТИРОВАТЬ ВЫДЕЛЕННЫЕ</button></div>
</form>
<script>
var _lang='ru';try{_lang=localStorage.getItem('boostore_lang')||navigator.language.slice(0,2);localStorage.setItem('boostore_lang',_lang);}catch(e){}if(!['ru','en','ua'].includes(_lang))_lang='ru';
var _t={ru:{'step2_title':'Экспорт отзывов — выбор файлов','files_found':'Найдено файлов:','no_files_found':'Нет файлов, соответствующих критериям','select_all':'☑ ВЫДЕЛИТЬ ВСЕ','deselect_all':'☐ СНЯТЬ ВСЕ','export_selected':'📤 ЭКСПОРТИРОВАТЬ ВЫДЕЛЕННЫЕ','lang_ru':'Русский','lang_en':'English','lang_ua':'Українська','import':'📥 Импорт','export':'📤 Экспорт','site_label':'Сайт:'},en:{'step2_title':'Export Reviews — file selection','files_found':'Files found:','no_files_found':'No files matching criteria','select_all':'☑ SELECT ALL','deselect_all':'☐ DESELECT ALL','export_selected':'📤 EXPORT SELECTED','lang_ru':'Russian','lang_en':'English','lang_ua':'Ukrainian','import':'📥 Import','export':'📤 Export','site_label':'Site:'},ua:{'step2_title':'Експорт відгуків — вибір файлів','files_found':'Знайдено файлів:','no_files_found':'Немає файлів, що відповідають критеріям','select_all':'☑ ВИДІЛИТИ ВСІ','deselect_all':'☐ ЗНЯТИ ВСІ','export_selected':'📤 ЕКСПОРТУВАТИ ВИДІЛЕНІ','lang_ru':'Російська','lang_en':'English','lang_ua':'Українська','import':'📥 Імпорт','export':'📤 Експорт','site_label':'Сайт:'}};
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});document.getElementById('lang_switcher').value=l;}
if(_lang!='ru'){document.addEventListener('DOMContentLoaded',function(){applyLang(_lang);});}
document.addEventListener('DOMContentLoaded',function(){var ls=document.getElementById('lang_switcher');if(ls){ls.value=_lang;ls.addEventListener('change',function(){applyLang(this.value);});}});
</script>
</div></body></html>
<?php exit; endif;

// Helper functions shared (defined before step 3 which uses them)
if (!function_exists('extractAllMeta')) {
    function extractAllMeta(string $html):array {
        $m=[]; preg_match_all('/<meta\s+name=["\']([^"\']+)["\']\s+content=["\'](.*?)["\']\s*\/?>/is',$html,$p,PREG_SET_ORDER);
        if(empty($p)){ preg_match_all('/<meta\s+content=["\'](.*?)["\']\s+name=["\']([^"\']+)["\']\s*\/?>/is',$html,$p,PREG_SET_ORDER); foreach($p as $x)$m[trim($x[2])]=html_entity_decode(trim($x[1])); }
        else { foreach($p as $x)$m[trim($x[1])]=html_entity_decode(trim($x[2])); }
        return $m;
    }
}
if (!function_exists('extractContent')) {
    function extractContent(string $html):string {
        $markers = ['<!-- CONTENT SEPARATOR BELOW -->', '<!-- ARTICLE SEPARATOR BELOW -->', '<-- РАЗДЕЛИТЕЛЬ СТАТЬЯ НИЖЕ --!>', '<!-- PAGE SEPARATOR BELOW -->'];
        $pos = false;
        foreach ($markers as $mk) { $p = strpos($html, $mk); if ($p !== false) { $pos = $p + strlen($mk); break; } }
        if ($pos !== false) { return trim(substr($html, $pos)); }
        $html = preg_replace('/<body[^>]*>/is', '', $html);
        $html = preg_replace('/<\/body>/is', '', $html);
        $html = preg_replace('/<html[^>]*>/is', '', $html);
        $html = preg_replace('/<\/html>/is', '', $html);
        return trim($html);
    }
}

// ===================================================================
// Step 3: process & send
// ===================================================================
$batchLimit = isset($_GET['batch']) ? max(1, (int)$_GET['batch']) : $SEND_BATCH_LIMIT;
$htmlFiles = array_slice($htmlFiles, 0, $batchLimit);

$articleIdx = 0; $success = 0; $errors = 0; $updated = 0;
$batchPayloads = [];
$batchArticles = [];
$allResultsHtml = [];

foreach ($htmlFiles as $htmlFile) {
    $articleIdx++;
    $relPath = str_replace(__DIR__.DIRECTORY_SEPARATOR, '', $htmlFile);
    $html = file_get_contents($htmlFile);
    $meta = extractAllMeta($html);

    $cid = (int)($meta['id'] ?? 0);
    $parentId = (int)($meta['parent_id'] ?? 0);
    $pageId = (int)($meta['page_id'] ?? 0);
    $pageTypeInternal = trim((string)($meta['page_type'] ?? ''));
    // map internal to friendly key for type param
    $friendly = 'page';
    $typeFor = 'pages';
    foreach ($PAGE_TYPES as $pt => $info) { if ($info['internal'] === $pageTypeInternal) { $friendly = $pt; $typeFor = $info['type']; break; } }

    $authorName = (string)($meta['author_name'] ?? '');
    $authorEmail = (string)($meta['author_email'] ?? '');
    $authorUserid = (int)($meta['author_userid'] ?? 0);
    $rating = (int)($meta['rating'] ?? 0);
    $hide = (int)($meta['hide'] ?? 0);

    $text = extractContent($html);
    $textGood = '';
    $textBad = '';
    if (preg_match('/<!-- TEXT_GOOD -->(.*?)(?=<!-- TEXT_BAD -->|$)/s', $html, $m)) $textGood = trim($m[1]);
    if (preg_match('/<!-- TEXT_BAD -->(.*)$/s', $html, $m)) $textBad = trim($m[1]);

    $payload = [
        'type' => $typeFor,
        'page_type' => $friendly,
        'page_id' => $pageId,
        'parent_id' => $parentId,
        'author_name' => $authorName,
        'author_email' => $authorEmail,
        'author_userid' => $authorUserid,
        'rating' => $rating,
        'text' => $text,
        'text_good' => $textGood,
        'text_bad' => $textBad,
        'hide' => $hide,
    ];
    if ($exportId && $cid > 0) {
        $payload['id'] = $cid;
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $batchPayloads[] = $payload;
    $batchArticles[] = ['id'=>$cid, 'relPath'=>$relPath, 'idx'=>$articleIdx, 'rating'=>$rating, 'page_type'=>$friendly, 'meta'=>$meta, 'jsonPayload'=>$jsonPayload, 'text'=>$text];

    ob_start(); ?>
    <div class="article"><div class="article-header"><span><span class="num">#<?=$articleIdx?></span> <span class="file"><?=htmlspecialchars($relPath)?></span></span><span class="date"><?=date('Y-m-d H:i:s')?></span></div>
    <div class="article-body">
    <details><summary><span data-i18n="metadata_title">📋 Метаданные</span> (<?=count($meta)?> <span data-i18n="fields_count">полей</span>)</summary><div class="meta-grid">
    <?php foreach($meta as $mk=>$mv):?><span class="key"><?=htmlspecialchars($mk)?>:</span><span class="val"><?=$mv!==''&&$mv!==null?htmlspecialchars((string)$mv):'<span class="na">—</span>'?></span><?php endforeach;?>
    </div></details>
    <details><summary data-i18n="payload_title">📦 Отправляемые данные (payload)</summary><div class="resp-block"><?=htmlspecialchars($jsonPayload)?></div></details>
    <details><summary data-i18n="text_title">📝 Текст отзыва</summary><textarea readonly><?=htmlspecialchars($text)?></textarea></details>
    <?php if($dryRun):?><div class="result-warn" data-i18n="dryrun_skip">⚡ DRY RUN — запрос не отправлен</div></div></div>
    <?php $allResultsHtml[$articleIdx] = ob_get_clean(); continue; endif; ?>
    <div class="result-placeholder" data-idx="<?=$articleIdx?>"><div class="result-pending" style="color:#888;padding:8px 0;"><span data-i18n="batch_pending">⏳ Ожидание ответа пакетного запроса...</span></div></div>
    </div></div>
    <?php $allResultsHtml[$articleIdx] = ob_get_clean();
}

// === Batch API request ===
// Existing comments (have id) must be sent with PUT (update), new ones (no id) with POST (create).
// Split payloads into two batches and merge results back by index.
$batchResults = []; $respData = null; $curlError = ''; $httpCode = 0;

$createItems = []; $updateItems = []; // each: ['idx' => n, 'payload' => arr]
foreach ($batchPayloads as $_pos => $_pl) {
    $_hasId = $exportId && !empty($_pl['id']) && (int)$_pl['id'] > 0;
    $doUpdate = $_hasId && $exportMode !== 'insert';
    if ($doUpdate) { $updateItems[] = ['idx' => $_pos, 'payload' => $_pl]; }
    else { $createItems[] = ['idx' => $_pos, 'payload' => $_pl]; }
}

function _comments_send_batch($API_URL, $AUTH_KEY, $exportTypes, $items, $httpMethod) {
    $resp = ['results' => [], 'httpCode' => 0, 'curlError' => '', 'respData' => null];
    if (empty($items)) return $resp;
    $payloads = array_column($items, 'payload');
    $json = json_encode(['comments' => $payloads], JSON_UNESCAPED_UNICODE);
    $url = $API_URL . '?type=' . urlencode($exportTypes[0] ?? 'pages') . '&page_type=' . urlencode($exportTypes[0] ?? 'page');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $httpMethod,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer ".$AUTH_KEY, "Content-Type: application/json"],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 60,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
    ]);
    $responseBody = curl_exec($ch);
    $resp['httpCode'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $resp['curlError'] = curl_error($ch);
    curl_close($ch);
    if ($responseBody !== false && $responseBody !== '') { $resp['respData'] = json_decode($responseBody, true); }
    $data = is_array($resp['respData']) ? $resp['respData'] : [];
    $rlist = [];
    if (isset($data['results']) && is_array($data['results'])) { $rlist = $data['results']; }
    elseif (isset($data['comments']) && is_array($data['comments'])) { $rlist = [$data['comments']]; }
    foreach ($rlist as $_i => $_r) {
        if (isset($items[$_i]['idx'])) { $resp['results'][$items[$_i]['idx']] = $_r; }
    }
    return $resp;
}

if (!empty($batchPayloads) && !$dryRun):
    $createResp = _comments_send_batch($API_URL, $AUTH_KEY, $exportTypes, $createItems, 'POST');
    $updateResp = _comments_send_batch($API_URL, $AUTH_KEY, $exportTypes, $updateItems, 'PUT');

    // Merge per-index results
    foreach ($createResp['results'] as $_idx => $_r) { $batchResults[$_idx] = $_r; }
    foreach ($updateResp['results'] as $_idx => $_r) { $batchResults[$_idx] = $_r; }

    // Collect errors / success across both batches
    $errors = 0; $success = 0; $updated = 0;
    if ($createResp['curlError']) { $errors += count($createItems); }
    if ($updateResp['curlError']) { $errors += count($updateItems); }
    if (empty($createResp['curlError']) && $createResp['httpCode'] >= 200 && $createResp['httpCode'] < 300) { $success += count($createItems); }
    else { $errors += count($createItems); }
    if (empty($updateResp['curlError']) && $updateResp['httpCode'] >= 200 && $updateResp['httpCode'] < 300) { $success += count($updateItems); }
    else { $errors += count($updateItems); }
    $respData = ($createResp['respData'] ?? null) ?: ($updateResp['respData'] ?? null);
    $curlError = $createResp['curlError'] ?: $updateResp['curlError'];
    $httpCode = $createResp['httpCode'] ?: $updateResp['httpCode'];
else:
    $success = count($batchPayloads);
    if ($dryRun) { $errors = 0; }
endif;

// Map per-item results by position (index) to article entries
$summaryCreated = 0; $summaryUpdated = 0; $summaryErrors = 0; $errorDetails = [];
foreach ($batchArticles as $baIdx => $ba) {
    $art = $batchResults[$baIdx] ?? [];
    $glErrors = $art['errors_global'] ?? [];
    $glErrorsStr = is_array($glErrors) ? implode(' ', $glErrors) : (string)$glErrors;
    $fieldErrors = $art['errors'] ?? [];
    $hasErrors = !empty($glErrorsStr) || !empty($fieldErrors) || ($httpCode>=200 && $httpCode<300 && !$dryRun && empty($art) && empty($batchResults));
    $respId = $art['id'] ?? $art['comment_id'] ?? '?';
    $skipFields = $art['skipped'] ?? [];

    if (!empty($curlError) || ($httpCode>0 && ($httpCode<200 || $httpCode>=300)) || $hasErrors) {
        $summaryErrors++;
        $errorDetails[] = "#{$ba['idx']} {$ba['relPath']}: " . implode('; ', is_array($glErrors)?$glErrors:($glErrors?[$glErrors]:[])) ?: ($curlError ?: 'Нет ответа');
        $badge = '<div class="result-fail"><span class="error"><span data-i18n="http_error">✗ Ошибка</span></span>';
        if (!empty($curlError)) $badge .= '<br><span class="error">✗ cURL: '.htmlspecialchars($curlError).'</span>';
        if (!empty($glErrors)) $badge .= '<br><span class="error" data-i18n="api_errors">✩ Ошибки API:</span>'.implode(' ', array_map(fn($g)=>'<div>• '.htmlspecialchars((string)$g).'</div>',(array)$glErrors));
        if (!empty($fieldErrors)) $badge .= '<br><span class="warning" data-i18n="field_errors">⚠ Ошибки полей:</span>'.implode(' ', array_map(fn($f)=>'<div>• '.htmlspecialchars(is_array($f)?json_encode($f,JSON_UNESCAPED_UNICODE):(string)$f).'</div>',(array)$fieldErrors));
        $badge .= '</div>';
        $verification = '';
    } else {
        $success++; $summaryUpdated++;
        $badge = '<div class="result-ok"><span class="success" data-i18n="comment_saved">✓ Отзыв сохранён (ID: '.htmlspecialchars((string)$respId).')</span>';
        if (!empty($skipFields)) $badge .= '<br><span class="warning" data-i18n="skipped_fields">⚠ Пропущенные поля:</span>'.implode(' ', array_map(fn($fk,$fv)=>'<div>'.htmlspecialchars((string)$fk).': '.htmlspecialchars(is_array($fv)?json_encode($fv,JSON_UNESCAPED_UNICODE):(string)$fv).'</div>',array_keys($skipFields),array_values($skipFields)));
        $badge .= '</div>';
        $verification = '<details open><summary data-i18n="verification_title">🔍 Верификация</summary><div class="meta-grid"><span class="key" data-i18n="status_label">статус:</span><span class="val"><span class="success" data-i18n="comment_saved">✓ Данные сохранены успешно</span>'.(!empty($respId)?' (ID: '.htmlspecialchars((string)$respId).')':'').'</span></div></details>';
    }
    $allResultsHtml[$ba['idx']] = str_replace('<div class="result-placeholder" data-idx="'.$ba['idx'].'"><div class="result-pending" style="color:#888;padding:8px 0;"><span data-i18n="batch_pending">⏳ Ожидание ответа пакетного запроса...</span></div></div>', $badge.$verification, $allResultsHtml[$ba['idx']] ?? '');
}

$summaryCreated = $success; $summaryErrors = count($errorDetails);
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title data-i18n="export_results_title">Экспорт отзывов — результат</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#1a1a2e;color:#e0e0e0;font-family:'Segoe UI',system-ui,sans-serif;padding:30px}.wrap{max-width:1200px;margin:0 auto}
h1{font-size:22px;color:#00d4ff;margin-bottom:5px}.meta-info{color:#888;font-size:13px;margin-bottom:25px}a{color:#00d4ff}
.summary-card{background:#0f3460;border-radius:10px;padding:14px 20px;margin-bottom:16px;font-size:14px}
.card{background:#16213e;border:1px solid #0f3460;border-radius:10px;margin-bottom:12px;padding:12px 16px}
.article{background:#16213e;border:1px solid #0f3460;border-radius:10px;margin-bottom:12px;overflow:hidden}
.article-header{display:flex;justify-content:space-between;align-items:center;background:#0f3460;padding:8px 14px;font-size:12px}
.article-header .num{color:#00d4ff;font-weight:700;margin-right:8px}.article-header .file{font-family:Consolas,monospace;color:#e0e0e0}.article-header .date{color:#888}
.article-body{padding:12px 16px}.article-body summary{cursor:pointer;font-weight:600;color:#00d4ff;padding:6px 0}.article-body summary:hover{color:#4dc9f6}
.meta-grid{display:grid;grid-template-columns:220px 1fr;gap:4px 12px;font-size:12px;padding:8px 0}
.meta-grid .key{color:#888}.meta-grid .val{color:#e0e0e0;word-break:break-all}.meta-grid .na{color:#555}
.resp-block{background:#0d1b2a;border:1px solid #0f3460;border-radius:6px;padding:10px 12px;font-family:Consolas,monospace;font-size:12px;white-space:pre-wrap;word-break:break-all;color:#9fdcff}
.article-body textarea{width:100%;height:120px;background:#0d1b2a;border:1px solid #0f3460;border-radius:6px;color:#e0e0e0;font-family:Consolas,monospace;font-size:12px;padding:8px;box-sizing:border-box;margin-top:4px}
.result-ok{color:#4caf50}.result-fail{color:#f44336}.result-warn{color:#ff9800;padding:8px 0}.result-ok .success,.result-fail .error{font-weight:600}
.result-placeholder{color:#888;padding:8px 0}
</style></head>
<body><div class="wrap"><?php echo $header; ?>
<div class="summary-card">
  <span style="color:#4caf50;font-weight:700;"><span data-i18n="created_label">✅ Успешно:</span> <?=$summaryCreated?></span>
  <span style="color:#00d4ff;font-weight:700;margin-left:20px;"><span data-i18n="updated_label">📝 Обработано:</span> <?=$articleIdx?></span>
  <span style="color:#f44336;font-weight:700;margin-left:20px;"><span data-i18n="errors_label">❌ Ошибок:</span> <?=$summaryErrors?></span>
  <?php if($dryRun):?><br><span style="color:#ff9800;" data-i18n="dryrun_warn">⚡ DRY RUN — запросы не отправлялись</span><?php endif;?>
</div>
<?php if(!empty($curlError)): ?><div class="card"><span style="color:#f44336;">✗ cURL Ошибка: <?=htmlspecialchars($curlError)?></span></div><?php endif; ?>
<?php if(isset($respData) && is_array($respData) && !empty($respData['error'])): ?><div class="card"><span style="color:#f44336;">✗ API: <?=htmlspecialchars(is_array($respData['error'])?json_encode($respData['error']):$respData['error'])?></span></div><?php endif; ?>
<?php if(!empty($errorDetails)): ?><div class="card"><span style="color:#f44336;"><?=nl2br(htmlspecialchars(implode("\n", $errorDetails)))?></span></div><?php endif; ?>
<?php echo implode("\n", $allResultsHtml); ?>
<div class="footer" style="text-align:center;padding:20px;color:#555;font-size:12px;margin-top:16px;"><?=date('Y-m-d H:i:s')?></div>
<script>
(function(){
    var m = '<span data-i18n="created_label">✅ Успешно:</span> <?=$summaryCreated?> | <span data-i18n="updated_label">📝 Обработано:</span> <?=$articleIdx?>';
    if(<?=$summaryErrors?>>0) m += ' | <span data-i18n="errors_label">❌ Ошибок:</span> <?=$summaryErrors?>';
    var t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#16213e;border:2px solid #0f3460;border-radius:10px;padding:14px 20px;color:#e0e0e0;font-size:14px;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.5);max-width:400px;line-height:1.5;';
    t.innerHTML = '<strong style="color:#00d4ff;">📊 Экспорт завершён</strong><br>' + m;
    document.body.appendChild(t);
    setTimeout(function(){ t.style.transition = 'opacity 1s'; t.style.opacity = '0'; setTimeout(function(){ t.remove(); },1000); }, 6000);
})();
</script>
</div></body></html>
<?php exit; endif;

// ===================================================================
// DASHBOARD / CONFIGURATION (action empty)
// ===================================================================
// POST save_config
$saveSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $domains = isset($_POST['site_domain']) ? (array)$_POST['site_domain'] : [];
    $keys = isset($_POST['site_key']) ? (array)$_POST['site_key'] : [];
    foreach ($domains as $i => $d) {
        $d = trim(strip_tags((string)$d));
        if ($d === '') continue;
        $SITES[$d] = ['key' => trim(strip_tags((string)($keys[$i] ?? '')))];
        $SITES[$d]['page_types'] = isset($_POST['page_types']) && is_array($_POST['page_types']) ? array_map('strval', $_POST['page_types']) : array_keys($PAGE_TYPES);
        $SITES[$d]['type_folder'] = isset($_POST['TYPE_FOLDER']);
        $SITES[$d]['per_page'] = isset($_POST['PER_PAGE']) ? max(1, (int)$_POST['PER_PAGE']) : 200;
        $SITES[$d]['send_batch_limit'] = isset($_POST['SEND_BATCH_LIMIT']) ? max(1, (int)$_POST['SEND_BATCH_LIMIT']) : 200;
    }
    saveConfig($configFile, $SITES, array_keys($PAGE_TYPES));
    $saveSuccess = true;
    require $configFile;
    $currentSite = $_POST['site'] ?? $currentSite;
    $siteCfg = $SITES[$currentSite] ?? [];
    $AUTH_KEY = $siteCfg['key'] ?? '';
    $CONFIG_PAGE_TYPES = $siteCfg['page_types'] ?? array_keys($PAGE_TYPES);
    $TYPE_FOLDER = $siteCfg['type_folder'] ?? true;
    $PER_PAGE = $siteCfg['per_page'] ?? 200;
    $SEND_BATCH_LIMIT = $siteCfg['send_batch_limit'] ?? 200;
    $apiKeyMissing = empty($AUTH_KEY);
}

// Add new site
if (!empty($_POST['new_domain'])) {
    $nd = trim($_POST['new_domain']);
    $parsed = parse_url($nd);
    if (!empty($parsed['host'])) { $nd = $parsed['host']; }
    else { $nd = preg_replace('#^https?://#i', '', $nd); $nd = preg_replace('#/.*$#', '', $nd); }
    $nd = preg_replace('#^www\.#', '', $nd);
    if ($nd !== '' && !isset($SITES[$nd])) {
        $SITES[$nd] = ['key' => '', 'page_types' => array_keys($PAGE_TYPES)];
        saveConfig($configFile, $SITES, array_keys($PAGE_TYPES));
        header('Location: ?site=' . urlencode($nd));
        exit;
    }
}
// Delete site
if (!empty($_POST['delete_domain'])) {
    $dd = trim($_POST['delete_domain']);
    if (isset($SITES[$dd])) {
        unset($SITES[$dd]);
        saveConfig($configFile, $SITES, array_keys($PAGE_TYPES));
    }
    if (file_exists($activeSiteFile) && trim(file_get_contents($activeSiteFile)) === $dd) { file_put_contents($activeSiteFile, ''); }
    header('Location: ?');
    exit;
}

$apiKeyOk = !empty($AUTH_KEY);
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title data-i18n="title">Управление отзывами — Boostore.pro</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#1a1a2e;color:#e0e0e0;font-family:'Segoe UI',system-ui,sans-serif;padding:30px}.wrap{max-width:1000px;margin:0 auto}
h1{font-size:22px;color:#00d4ff;margin-bottom:5px}.meta-info{color:#888;font-size:13px;margin-bottom:25px}a{color:#00d4ff}
.card{background:#16213e;border:1px solid #0f3460;border-radius:10px;margin-bottom:20px;overflow:hidden}.card .card-header{background:#0f3460;padding:12px 18px;font-weight:700;color:#00d4ff;font-size:15px}.card .card-body{padding:15px 18px}
label{display:block;margin:0 0 6px;color:#888;font-size:13px}input[type=text],input[type=number],select{width:100%;padding:9px 12px;border:1px solid #0f3460;border-radius:6px;background:#0d1b2a;color:#e0e0e0;font-size:14px;margin-bottom:14px;box-sizing:border-box}
.chk{display:flex;align-items:center;gap:8px;margin:0 0 8px} .chk input{width:auto;margin:0}
.btn{display:inline-block;padding:10px 22px;border-radius:6px;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;border:none;transition:all .2s}.btn:hover{transform:translateY(-1px)}
.btn-primary{background:#00d4ff;color:#1a1a2e}.btn-primary:hover{background:#4dc9f6;box-shadow:0 4px 12px rgba(0,212,255,.2)}
.btn-success{background:#4caf50;color:#fff}.btn-success:hover{background:#66bb6a;box-shadow:0 4px 12px rgba(76,175,80,.2)}
.btn-warning{background:#ff9800;color:#fff}.btn-warning:hover{background:#ffb74d;box-shadow:0 4px 12px rgba(255,152,0,.2)}
.btn-save{background:#4caf50;color:#fff}
.btn-group{display:flex;gap:8px;flex-wrap:wrap}
details.card>summary{cursor:pointer;padding:12px 18px;background:#0f3460;display:flex;justify-content:space-between;align-items:center;font-weight:700;color:#00d4ff;font-size:15px}
details.card>summary::-webkit-details-marker{display:none}
details.card>summary .arrow{transition:transform .2s;font-size:11px;color:#888}
details.card[open]>summary .arrow{transform:rotate(90deg)}
details.card>summary:hover{background:#1a4a7a}
</style></head>
<body><div class="wrap"><?php echo $header; ?>
<?php if(!$apiKeyOk): ?>
<div class="warn-msg" style="background:#2a1a1a;border:1px solid #f44336;border-radius:6px;padding:12px 16px;color:#ffcdd2;margin-bottom:15px;font-size:13px;" data-i18n="warn_nokey">⚠ Необходимо указать <strong>ключ доступа API</strong> (Consumer Secret) в разделе «Конфигурация» ниже, иначе скрипты не будут работать.</div>
<?php endif; ?>
<?php if($saveSuccess): ?><div style="background:#1b5e20;border:1px solid #2e7d32;border-radius:8px;padding:12px 18px;margin-bottom:16px;color:#81c784;" data-i18n="saved">✓ Конфигурация сохранена</div><?php endif; ?>
<div style="background:#0f3460;border:1px solid #00d4ff;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:14px;color:#e0e0e0;">
<strong>Boostore.pro</strong> — <span data-i18n="plaque">Скрипты для экспорта (скачивания) и импорта (отправки) отзывов и комментариев через Commerce API. Сайт:</span> <strong><?=htmlspecialchars($currentSite)?></strong>
</div>
<details class="card"><summary style="cursor:pointer;"><span data-i18n="instr_title">📖 Инструкция</span> <span class="arrow">▶</span></summary><div class="card-body">
<p data-i18n="instr_intro">Все настройки — в разделе «Конфигурация» ниже. Если список типов страниц пуст — обрабатываются все типы.</p>
<h3 data-i18n="quickstart">Быстрый старт</h3>
<ol style="margin-left:18px;line-height:1.7;">
<li data-i18n="step1">Настройте <strong>ключ доступа</strong> в разделе «Настройка → Магазин → Доступ к статистике продаж»</li>
<li data-i18n="step2">Укажите ключ и URL вашего сайта в <strong>конфигурации</strong> ниже</li>
<li data-i18n="step3">Выберите типы страниц (или оставьте все) и при необходимости укажите ID страниц/товаров</li>
<li data-i18n="step4">Нажмите <strong>"СКАЧАТЬ"</strong> — отзывы скачаются в папку <code><?=htmlspecialchars($currentSite)?>/comments/</code></li>
<li data-i18n="step6">Отредактируйте HTML-файлы в <code><?=htmlspecialchars($currentSite)?>/comments/</code> при необходимости</li>
<li data-i18n="step7">Нажмите <strong>"ОТПРАВИТЬ"</strong> — изменения отправятся на сайт</li>
</ol>
<h3 data-i18n="file_naming">Именование файлов</h3><p data-i18n="file_naming_desc">Шаблон: <code>{id}-page-{page_id}-p{parent_id}.html</code>. Пример: <code>64592-page-14093-p0.html</code></p>
<h3 data-i18n="file_format">Формат файла</h3><p data-i18n="file_format_desc">Мета-данные в <code>&lt;meta name="..." content="..."&gt;</code> передают настройки отзыва: id, parent_id, page_id, page_type, автор, рейтинг, hide. Текст — после <code>&lt;!-- CONTENT SEPARATOR BELOW --&gt;</code></p>
</div></details>
<div class="card"><div class="card-header" data-i18n="actions_title">⚡ Действия</div><div class="card-body">
<div class="btn-group"><a href="?action=get&site=<?=urlencode($currentSite)?>" class="btn btn-primary" data-i18n="btn_get">📥 СКАЧАТЬ</a>
<a href="?action=update&site=<?=urlencode($currentSite)?>" class="btn btn-success" data-i18n="btn_update">📤 ОТПРАВИТЬ</a>
<a href="?action=update&dry-run&site=<?=urlencode($currentSite)?>" class="btn btn-warning" data-i18n="btn_dryrun">🔍 Тест (сухая отправка)</a></div>
<div style="font-size:12px;color:#888;margin-top:8px;" data-i18n="dryrun_desc">Режим «тест» — проверяет какие отзывы будут отправлены, но сами запросы к API не выполняются.</div>
</div></div>
<details class="card"><summary><span data-i18n="config_title">⚙ Конфигурация</span> <span class="arrow">▶</span></summary><div class="card-body">
<form method="post">
  <input type="hidden" name="site" value="<?=htmlspecialchars($currentSite)?>">
  <input type="hidden" name="site_domain[]" value="<?=htmlspecialchars($currentSite)?>">
<div style="background:#1a3a1a;border:1px solid #ff9800;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:12px;" data-i18n="api_note">
⚠ Ключ доступа для текущего сайта <strong><?=htmlspecialchars($currentSite)?></strong>. Чтобы добавить новый сайт — используйте селектор сайтов вверху → <strong>«+ Добавить сайт»</strong>.
</div>

  <label data-i18n="key_label">🔑 Ключ (Consumer Secret):</label>
  <input type="text" name="site_key[]" value="<?=htmlspecialchars($AUTH_KEY)?>" placeholder="ваш ключ">

  <h3 style="margin:12px 0 8px;color:#e0e0e0;">📂 Типы страниц (по умолчанию)</h3>
  <?php foreach($PAGE_TYPES as $pt => $info): ?>
  <label class="chk"><input type="checkbox" name="page_types[]" value="<?=$pt?>"<?=in_array($pt, $CONFIG_PAGE_TYPES)?' checked':''?>> <?=htmlspecialchars($info['label'])?></label>
  <?php endforeach; ?>

  <h3 style="margin:12px 0 8px;color:#e0e0e0;">📥 Настройки</h3>
  <label><input type="checkbox" name="TYPE_FOLDER" value="1"<?=$TYPE_FOLDER?' checked':''?>> <span data-i18n="type_folder_label">Разделять по папкам типов страниц</span></label>
  <label data-i18n="per_page_label">Отзывов за запрос:</label>
  <input type="number" name="PER_PAGE" value="<?=$PER_PAGE?>" min="1" max="2000">
  <label data-i18n="batch_label">Отправить за 1 раз:</label>
  <input type="number" name="SEND_BATCH_LIMIT" value="<?=$SEND_BATCH_LIMIT?>" min="1" max="5000">

  <button type="submit" name="save_config" class="btn btn-save" data-i18n="save">💾 Сохранить конфигурацию</button>
</form>
</div></details>
<script>
var _lang='ru';try{_lang=localStorage.getItem('boostore_lang')||navigator.language.slice(0,2);localStorage.setItem('boostore_lang',_lang);}catch(e){}if(!['ru','en','ua'].includes(_lang))_lang='ru';
var _t={ru:{'title':'Управление отзывами — Boostore.pro','home':'🏠 Главная','entity_name':'💬 Отзывы','api_docs':'API Docs','version':'v2.0','desc':'Импорт/экспорт отзывов и комментариев (страницы, статьи, категории, товары, производители, коллекции).','desc2':'Импорт — по ID страницы/товара (page_id) + фильтр по типам страниц. Экспорт — загрузка локальных файлов обратно на API. Разбивка по папкам типов страниц.','import':'📥 Импорт','export':'📤 Экспорт','warn_nokey':'⚠ Необходимо указать ключ доступа API (Consumer Secret) в разделе «Конфигурация» ниже, иначе скрипты не будут работать.','plaque':'Скрипты для экспорта (скачивания) и импорта (отправки) отзывов и комментариев через Commerce API. Сайт:','instr_title':'📖 Инструкция','instr_intro':'Все настройки — в разделе «Конфигурация» ниже. Если список типов страниц пуст — обрабатываются все типы.','quickstart':'Быстрый старт','step1':'Настройте ключ доступа в разделе «Настройка → Магазин → Доступ к статистике продаж»','step2':'Укажите ключ и URL вашего сайта в конфигурации ниже','step3':'Выберите типы страниц (или оставьте все) и при необходимости укажите ID страниц/товаров','step4':'Нажмите "СКАЧАТЬ" — отзывы скачаются в папку','step6':'Отредактируйте HTML-файлы при необходимости','step7':'Нажмите "ОТПРАВИТЬ" — изменения отправятся на сайт','file_naming':'Именование файлов','file_naming_desc':'Шаблон: {id}-page-{page_id}-p{parent_id}.html. Пример: 64592-page-14093-p0.html','file_format':'Формат файла','file_format_desc':'Мета-данные в <meta name="..." content="..."> передают настройки отзыва: id, parent_id, page_id, page_type, автор, рейтинг, hide. Текст — после разделителя контента.','actions_title':'⚡ Действия','btn_get':'📥 СКАЧАТЬ','btn_update':'📤 ОТПРАВИТЬ','btn_dryrun':'🔍 Тест (сухая отправка)','dryrun_desc':'Режим «тест» — проверяет какие отзывы будут отправлены, но сами запросы к API не выполняются.','export_results_title':'Экспорт отзывов — результат','metadata_title':'📋 Метаданные','fields_count':'полей','payload_title':'📦 Отправляемые данные (payload)','text_title':'📝 Текст отзыва','batch_pending':'⏳ Ожидание ответа пакетного запроса...','comment_saved':'✓ Отзыв сохранён','verification_title':'🔍 Верификация','status_label':'статус:','skipped_fields':'⚠ Пропущенные поля:','api_errors':'✩ Ошибки API:','field_errors':'⚠ Ошибки полей:','http_error':'✗ Ошибка','dryrun_skip':'⚡ DRY RUN — запрос не отправлен','created_label':'✅ Успешно:','updated_label':'📝 Обработано:','errors_label':'❌ Ошибок:','config_title':'⚙ Конфигурация','api_note':'⚠ Ключ доступа для текущего сайта <strong>{site}</strong>. Чтобы добавить новый сайт — используйте селектор сайтов вверху → <strong>«+ Добавить сайт»</strong>.','key_label':'🔑 Ключ (Consumer Secret):','type_folder_label':'Разделять по папкам типов страниц','per_page_label':'Отзывов за запрос:','batch_label':'Отправить за 1 раз:','save':'💾 Сохранить конфигурацию','saved':'✓ Конфигурация сохранена','lang_ru':'Русский','lang_en':'English','lang_ua':'Українська','site_label':'Сайт:'},en:{'title':'Manage Reviews — Boostore.pro','home':'🏠 Home','entity_name':'💬 Reviews','api_docs':'API Docs','version':'v2.0','desc':'Import/export reviews and comments (pages, articles, categories, products, producers, collections).','desc2':'Import — by page/product ID (page_id) + page-type filter. Export — upload local files back to the API. Split by page-type folders.','import':'📥 Import','export':'📤 Export','warn_nokey':'⚠ You must specify the API access key (Consumer Secret) in the «Configuration» section below, otherwise scripts will not work.','plaque':'Scripts for export (download) and import (upload) of reviews and comments via Commerce API. Site:','instr_title':'📖 Instructions','instr_intro':'All settings are in the «Configuration» section below. If the page-types list is empty — all types are processed.','quickstart':'Quick start','step1':'Set up the access key in «Settings → Store → Sales Statistics Access»','step2':'Enter the key and your site URL in the configuration below','step3':'Select page types (or keep all) and optionally enter page/product IDs','step4':'Click "DOWNLOAD" — reviews are downloaded to the folder','step6':'Edit the HTML files if necessary','step7':'Click "SEND" — changes are sent to the site','file_naming':'File naming','file_naming_desc':'Pattern: {id}-page-{page_id}-p{parent_id}.html. Example: 64592-page-14093-p0.html','file_format':'File format','file_format_desc':'Meta-data in <meta name="..." content="..."> carry review settings: id, parent_id, page_id, page_type, author, rating, hide. Text — after the content separator.','actions_title':'⚡ Actions','btn_get':'📥 DOWNLOAD','btn_update':'📤 SEND','btn_dryrun':'🔍 Test (dry run)','dryrun_desc':'The "test" mode checks which reviews will be sent, but no API requests are actually made.','export_results_title':'Export Reviews — result','metadata_title':'📋 Metadata','fields_count':'fields','payload_title':'📦 Payload','text_title':'📝 Review text','batch_pending':'⏳ Waiting for batch response...','comment_saved':'✓ Review saved','verification_title':'🔍 Verification','status_label':'status:','skipped_fields':'⚠ Skipped fields:','api_errors':'✩ API errors:','field_errors':'⚠ Field errors:','http_error':'✗ Error','dryrun_skip':'⚡ DRY RUN — request not sent','created_label':'✅ Success:','updated_label':'📝 Processed:','errors_label':'❌ Errors:','config_title':'⚙ Configuration','api_note':'⚠ Access key for the current site <strong>{site}</strong>. To add a new site, use the site selector above → <strong>«+ Add Site»</strong>.','key_label':'🔑 Key (Consumer Secret):','type_folder_label':'Separate by page-type folders','per_page_label':'Reviews per request:','batch_label':'Send per run:','save':'💾 Save configuration','saved':'✓ Configuration saved','lang_ru':'Russian','lang_en':'English','lang_ua':'Ukrainian','site_label':'Site:'},ua:{'title':'Управління відгуками — Boostore.pro','home':'🏠 Головна','entity_name':'💬 Відгуки','api_docs':'API Docs','version':'v2.0','desc':'Імпорт/експорт відгуків та коментарів (сторінки, статті, категорії, товари, виробники, колекції).','desc2':'Імпорт — за ID сторінки/товару (page_id) + фільтр за типами сторінок. Експорт — завантаження локальних файлів назад в API. Розбивка по папках типів сторінок.','import':'📥 Імпорт','export':'📤 Експорт','warn_nokey':'⚠ Необхідно вказати ключ доступу API (Consumer Secret) у розділі «Конфігурація» нижче, інакше скрипти не працюватимуть.','plaque':'Скрипти для експорту (завантаження) та імпорту (відправлення) відгуків і коментарів через Commerce API. Сайт:','instr_title':'📖 Інструкція','instr_intro':'Всі налаштування — у розділі «Конфігурація» нижче. Якщо список типів сторінок порожній — обробляються всі типи.','quickstart':'Швидкий старт','step1':'Налаштуйте ключ доступу у розділі «Налаштування → Магазин → Доступ до статистики продажів»','step2':'Вкажіть ключ та URL вашого сайту в конфігурації нижче','step3':'Виберіть типи сторінок (або залиште всі) та за потреби вкажіть ID сторінок/товарів','step4':'Натисніть "ЗАВАНТАЖИТИ" — відгуки завантажаться у папку','step6':'Відредагуйте HTML-файли за потреби','step7':'Натисніть "ВІДПРАВИТИ" — зміни відправляться на сайт','file_naming':'Найменування файлів','file_naming_desc':'Шаблон: {id}-page-{page_id}-p{parent_id}.html. Приклад: 64592-page-14093-p0.html','file_format':'Формат файлу','file_format_desc':'Мета-дані в <meta name="..." content="..."> передають налаштування відгуку: id, parent_id, page_id, page_type, автор, рейтинг, hide. Текст — після розділювача контенту.','actions_title':'⚡ Дії','btn_get':'📥 ЗАВАНТАЖИТИ','btn_update':'📤 ВІДПРАВИТИ','btn_dryrun':'🔍 Тест (суха відправка)','dryrun_desc':'Режим «тест» — перевіряє, які відгуки будуть відправлені, але самі запити до API не виконуються.','export_results_title':'Експорт відгуків — результат','metadata_title':'📋 Метадані','fields_count':'полів','payload_title':'📦 Дані, що відправляються (payload)','text_title':'📝 Текст відгуку','batch_pending':'⏳ Очікування відповіді пакетного запиту...','comment_saved':'✓ Відгук збережено','verification_title':'🔍 Верифікація','status_label':'статус:','skipped_fields':'⚠ Пропущені поля:','api_errors':'✩ Помилки API:','field_errors':'⚠ Помилки полів:','http_error':'✗ Помилка','dryrun_skip':'⚡ DRY RUN — запит не відправлено','created_label':'✅ Успішно:','updated_label':'📝 Опрацьовано:','errors_label':'❌ Помилок:','config_title':'⚙ Конфігурація','api_note':'⚠ Ключ доступу для поточного сайту <strong>{site}</strong>. Щоб додати новий сайт — використовуйте селектор сайтів угорі → <strong>«+ Додати сайт»</strong>.','key_label':'🔑 Ключ (Consumer Secret):','type_folder_label':'Розділяти по папках типів сторінок','per_page_label':'Відгуків за запит:','batch_label':'Відправити за 1 раз:','save':'💾 Зберегти конфігурацію','saved':'✓ Конфігурацію збережено','lang_ru':'Російська','lang_en':'English','lang_ua':'Українська','site_label':'Сайт:'}};
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});document.getElementById('lang_switcher').value=l;}
if(_lang!='ru'){document.addEventListener('DOMContentLoaded',function(){applyLang(_lang);});}
document.addEventListener('DOMContentLoaded',function(){var ls=document.getElementById('lang_switcher');if(ls){ls.value=_lang;ls.addEventListener('change',function(){applyLang(this.value);});}});
</script>
</div></body></html>
