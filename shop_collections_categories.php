<?php
// ===================================================================
// ИМПОРТ/ЭКСПОРТ ТОВАРОВ (Products Boostore.pro)
// ===================================================================
// Инструкция: https://boostore.pro/ru/docs/api-integration/#hotengine-CommerceAPI
// ===================================================================

// Auto-create config file if not exists
$configFile = __DIR__ . '/_setting_shop_collections_categories.inc';
if (!file_exists($configFile)) {
    $defaultConfig = "<?php
// ===================================================================
// НАСТРОЙКИ ИМПОРТА/ЭКСПОРТА СТАТЕЙ (Blog Boostore.pro)
// ===================================================================
// Инструкция: https://boostore.pro/ru/docs/api-integration/#hotengine-CommerceAPI
// ===================================================================
//
// === МНОГОСАЙТОВОСТЬ ===
// Каждый сайт = отдельная папка с собственными настройками
\$SITES = [
    'site.boostore.pro' => [
        'key' => '',
        'allowed_collections' => [],
        'status_mode' => '',
        'status_override' => 1,
        'date_mode' => '',
        'date_fixed' => '',
        'date_offset_base' => '',
        'date_offset_days' => 1,
        'export_collection_id' => false,
        'export_collection_id' => false,
        'export_collection_name' => true,
        'per_page' => 200,
        'send_batch_limit' => 200,
        'reference_lang' => 'pl',
        'fix_multilangid' => false,
        'fix_stock' => false,
        'fix_price' => false,
        'fix_new' => false,
        'fix_hit' => false,
        'fix_sale' => false,
        'fix_price_old' => false,
        'fix_additional' => false,
        'fix_status' => false,
        'fix_datestamp' => false,
        'import_only_named' => true,
    ],
];
";
    file_put_contents($configFile, $defaultConfig);
    chmod($configFile, 0644);
}

require $configFile;

// Auto-migrate old config format (single AUTH_KEY + API_DOMAIN → $SITES array)
if (!isset($SITES) && isset($AUTH_KEY)) {
    $SITES = [rtrim($API_DOMAIN ?? 'site.boostore.pro', '/') => ['key' => $AUTH_KEY ?? '']];
    saveConfig($configFile, $SITES);
    require $configFile;
}
if (!isset($SITES)) $SITES = ['site.boostore.pro' => ['key' => '']];

// Determine current site from GET param, fallback to active site, then first in $SITES
$currentSite = '';
if (!empty($_GET['site'])) {
    $currentSite = $_GET['site'];
    if (!isset($SITES[$currentSite])) {
        $SITES[$currentSite] = ['key' => ''];
        saveConfig($configFile, $SITES);
        require $configFile;
    }
    file_put_contents(__DIR__ . '/_active_site.inc', $currentSite, LOCK_EX);
} else {
    // Check active site config
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
$API_URL = 'https://' . $currentSite . '/api/commerce/products/collections';

// Extract per-site config into global variables (backward-compatible)
$siteCfg = $SITES[$currentSite] ?? [];
$ALLOWED_COLLECTIONS  = $siteCfg['allowed_collections'] ?? ($ALLOWED_COLLECTIONS ?? []);
$STATUS_MODE         = $siteCfg['status_mode'] ?? ($STATUS_MODE ?? '');
$STATUS_OVERRIDE     = $siteCfg['status_override'] ?? ($STATUS_OVERRIDE ?? 1);
$DATE_MODE           = $siteCfg['date_mode'] ?? ($DATE_MODE ?? '');
$DATE_FIXED          = $siteCfg['date_fixed'] ?? ($DATE_FIXED ?? '');
$DATE_OFFSET_BASE    = $siteCfg['date_offset_base'] ?? ($DATE_OFFSET_BASE ?? '');
$DATE_OFFSET_DAYS    = $siteCfg['date_offset_days'] ?? ($DATE_OFFSET_DAYS ?? 1);
$EXPORT_COLLECTION_ID   = $siteCfg['export_collection_id'] ?? ($EXPORT_COLLECTION_ID ?? false);
$EXPORT_COLLECTION_ID  = $siteCfg['export_collection_id'] ?? ($EXPORT_COLLECTION_ID ?? false);
$EXPORT_COLLECTION_NAME = $siteCfg['export_collection_name'] ?? ($EXPORT_COLLECTION_NAME ?? true);
$PER_PAGE            = $siteCfg['per_page'] ?? ($PER_PAGE ?? 200);
$SEND_BATCH_LIMIT    = $siteCfg['send_batch_limit'] ?? ($SEND_BATCH_LIMIT ?? 200);
$REFERENCE_LANG      = $siteCfg['reference_lang'] ?? 'pl';
$FIX_MULTILANGID     = $siteCfg['fix_multilangid'] ?? ($FIX_MULTILANGID ?? false);

$FIX_STATUS          = $siteCfg['fix_status'] ?? ($FIX_STATUS ?? false);
$FIX_DATESTAMP       = $siteCfg['fix_datestamp'] ?? ($FIX_DATESTAMP ?? false);
$IMPORT_ONLY_NAMED   = $siteCfg['import_only_named'] ?? ($IMPORT_ONLY_NAMED ?? true);

// Site directory (parent folder named after domain)
$SITE_DIR = __DIR__ . DIRECTORY_SEPARATOR . $currentSite;
$COLLECTIONS_DIR = $SITE_DIR . DIRECTORY_SEPARATOR . 'shop_collections';
// Ensure site directory + blog subfolder exist
if (!is_dir($COLLECTIONS_DIR)) { @mkdir($COLLECTIONS_DIR, 0777, true); }

// Helper: export $SITES array in short array syntax (full per-site config)
function sitesExport($sites) {
    $c = "[\n";
    foreach ($sites as $sDomain => $sCfg) {
        $c .= "    ".var_export($sDomain, true)." => [\n";
        $c .= "        'key' => ".var_export($sCfg['key'] ?? '', true).",\n";
        $collections = $sCfg['allowed_collections'] ?? [];
        if (!empty($collections)) {
            $c .= "        'allowed_collections' => [\n";
            foreach ($collections as $cid => $cname) {
                $c .= "            ".var_export($cid, true)." => ".var_export($cname, true).",\n";
            }
            $c .= "        ],\n";
        } else {
            $c .= "        'allowed_collections' => [],\n";
        }
        $c .= "        'status_mode' => ".var_export($sCfg['status_mode'] ?? '', true).",\n";
        $c .= "        'status_override' => ".(int)($sCfg['status_override'] ?? 1).",\n";
        $c .= "        'date_mode' => ".var_export($sCfg['date_mode'] ?? '', true).",\n";
        $c .= "        'date_fixed' => ".var_export($sCfg['date_fixed'] ?? '', true).",\n";
        $c .= "        'date_offset_base' => ".var_export($sCfg['date_offset_base'] ?? '', true).",\n";
        $c .= "        'date_offset_days' => ".(int)($sCfg['date_offset_days'] ?? 1).",\n";
        $c .= "        'export_collection_id' => ".($sCfg['export_collection_id'] ?? false ? 'true' : 'false').",\n";
        $c .= "        'export_collection_id' => ".($sCfg['export_collection_id'] ?? false ? 'true' : 'false').",\n";
        $c .= "        'export_collection_name' => ".($sCfg['export_collection_name'] ?? true ? 'true' : 'false').",\n";
        $c .= "        'per_page' => ".(int)($sCfg['per_page'] ?? 200).",\n";
        $c .= "        'send_batch_limit' => ".(int)($sCfg['send_batch_limit'] ?? 200).",\n";
        $c .= "        'reference_lang' => ".var_export($sCfg['reference_lang'] ?? 'ru', true).",\n";
        $c .= "        'fix_multilangid' => ".($sCfg['fix_multilangid'] ?? false ? 'true' : 'false').",\n";

        $c .= "        'fix_currency' => ".($sCfg['fix_currency'] ?? false ? 'true' : 'false').",\n";
        $c .= "        'fix_status' => ".($sCfg['fix_status'] ?? false ? 'true' : 'false').",\n";
        $c .= "        'fix_datestamp' => ".($sCfg['fix_datestamp'] ?? false ? 'true' : 'false').",\n";
        $c .= "        'import_only_named' => ".($sCfg['import_only_named'] ?? true ? 'true' : 'false').",\n";
        $c .= "    ],\n";
    }
    return $c . "]\n";
}
// Helper: save config file from scratch (avoids fragile regex replacement)
function saveConfig($configFile, $SITES) {
    if (!is_array($SITES) || count($SITES) === 0) return;
    file_put_contents($configFile, "<?php\n\n\$SITES = " . sitesExport($SITES) . ";\n", LOCK_EX);
}

$action = $_GET['action'] ?? '';
$apiKeyMissing = empty($AUTH_KEY);

// Handle add_site action: add new site to $SITES and save config, then redirect
if ($action === 'add_site' && !empty($_GET['site'])) {
    $newSite = trim($_GET['site']);
    if (!isset($SITES[$newSite])) {
        $apiKey = isset($_GET['api_key']) ? trim($_GET['api_key']) : '';
        $SITES[$newSite] = ['key' => $apiKey];
        saveConfig($configFile, $SITES);
    }
    $params = $_GET;
    unset($params['action']);
    unset($params['api_key']);
    $params['added'] = '1';
    header('Location: ?' . http_build_query($params));
    exit;
}

// Handle delete_site action: remove site from $SITES and save config
if ($action === 'delete_site' && !empty($_GET['site'])) {
    $delSite = trim($_GET['site']);
    if (isset($SITES[$delSite])) {
        unset($SITES[$delSite]);
        if (empty($SITES)) {
            $SITES = ['site.boostore.pro' => ['key' => '']];
        }
        saveConfig($configFile, $SITES);
    }
    $firstSite = array_key_first($SITES);
    header('Location: ?site=' . urlencode($firstSite));
    exit;
}

$siteOptions = '';
foreach ($SITES as $sDomain => $sCfg) {
    $sel = ($sDomain === $currentSite) ? ' selected' : '';
    $siteOptions .= '<option value="'.htmlspecialchars($sDomain).'"'.$sel.'>'.htmlspecialchars($sDomain).'</option>';
}
$siteOptions .= '<option value="__add__" style="color:#ff9800;font-weight:700;" data-i18n="add_site_option">+ Добавить сайт</option>';

// ---- Хлебные крошки ----
$crumbs = ['<a href="index.php" style="color:#00d4ff;text-decoration:none;font-weight:600;"><span data-i18n="home">🏠 Главная</span></a>', '<a href="?site='.urlencode($currentSite).'" style="color:#00d4ff;text-decoration:none;font-weight:600;">📝 <span data-i18n="entity_name">Коллекции</span></a>'];
if ($action === 'get') $crumbs[] = '<a href="?action=get&site='.urlencode($currentSite).'" style="color:#888;text-decoration:none;" data-i18n="import">📥 Импорт</a>';
elseif ($action === 'update') {
    $crumbs[] = '<a href="?action=update&site='.urlencode($currentSite).'" style="color:#888;text-decoration:none;" data-i18n="export">📤 Экспорт</a>';
    if (!empty($_GET['step2'])) $crumbs[] = '<span style="color:#888;" data-i18n="file_selection">📁 Выбор файлов</span>';
    if (!empty($_GET['confirm'])) $crumbs[] = '<span data-i18n="result_label" style="color:#888;">✓ Результат</span>';
}
$breadcrumb = '<div class="bcrumb" style="background:#111827;border:1px solid #1e293b;border-radius:10px;padding:10px 18px;margin-bottom:12px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:13px;color:#888;">'.implode(' <span style="color:#555;">›</span> ', $crumbs).'</div>';

$header = '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
<h1 style="margin:0 0 20px 0;">▸ <span data-i18n="title">Управление коллекциями магазина — Boostore.pro</span></h1>
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
// _get-shop_collections_categories.php — ЭКСПОРТ (получение коллекций с API)
// ===================================================================
if ($action === 'get'):
@set_time_limit(300);
@ini_set('memory_limit', '256M');

// --- Confirmation step for export ---
$getPerPage = isset($_GET['per_page']) ? max(1, min(2000, (int)$_GET['per_page'])) : (int)($PER_PAGE ?? 200);
$getSearch = isset($_GET['search']) ? (is_array($_GET['search']) ? $_GET['search'] : [trim($_GET['search'] ?? '')]) : [];
$getSearch = array_filter($getSearch, function($v) { return trim($v) !== ''; });
$getSearch = array_values($getSearch);
$getSearchStr = implode('|', $getSearch);
$getCats = isset($_GET['cat']) ? $_GET['cat'] : [];
if (!isset($_GET['confirm'])): ?>
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Импорт коллекций — Boostore.pro</title>
<style>body{background:#1a1a2e;color:#e0e0e0;font-family:'Segoe UI',sans-serif;padding:30px}.wrap{max-width:1200px;margin:0 auto;overflow:hidden}h1{font-size:22px;color:#00d4ff}.card{background:#16213e;border:1px solid #0f3460;border-radius:10px;padding:18px;margin-bottom:16px;transition:border-color .2s}.meta-info{color:#888;font-size:13px;margin-bottom:25px}.card:hover{border-color:#00d4ff}label{color:#888;font-size:13px;display:block;margin-bottom:4px}.chk-label{display:flex!important;align-items:center;gap:6px;cursor:pointer;color:#e0e0e0;font-size:13px}.chk-label input{width:auto}.chk-label code{background:#0d1b2a;padding:1px 5px;border-radius:3px;font-size:12px}input,select{padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box;transition:border-color .2s}input:focus,select:focus{outline:none;border-color:#00d4ff;box-shadow:0 0 0 2px rgba(0,212,255,.15)}.form-row{display:flex;gap:12px;margin-bottom:12px;align-items:flex-end;flex-wrap:wrap}.form-row .field{flex:1;min-width:120px}.btn{padding:10px 24px;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;transition:all .2s}.btn-primary{background:#00d4ff;color:#1a1a2e}.btn-primary:hover{background:#4dc9f6;transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,212,255,.2)}.btn-success{background:#4caf50;color:#fff}.btn-success:hover{background:#66bb6a;transform:translateY(-1px)}button{font-family:inherit}.btn:hover{color:#fff;text-decoration:none}a{color:#00d4ff;text-decoration:none;transition:color .2s}a:hover{color:#4dc9f6}.na{color:#555}.coll-row{display:flex;gap:8px;margin-bottom:6px;align-items:center}.coll-row input{flex:1}.coll-row .btn-sm{padding:3px 8px;font-size:11px;background:#f44336;color:#fff;border:none;border-radius:3px;cursor:pointer;transition:background .2s}.coll-row .btn-sm:hover{background:#d32f2f}.plaque{background:#0f3460;border:1px solid #00d4ff;border-radius:8px;padding:12px 18px;margin-bottom:16px;font-size:14px;color:#e0e0e0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}.plaque a{font-weight:600}@media(max-width:600px){body{padding:15px}.form-row{gap:8px}.form-row .field{flex:1 1 100%}.wrap{padding:0}}</style></head><body><div class="wrap">
<?php echo $header; ?>
<div class="plaque">
<span data-i18n="plaque_import">▸ <strong>Настройки импорта</strong> — получение коллекций с API</span>
<span><a href="?site=<?=urlencode($currentSite)?>" style="padding:6px 16px;background:transparent;color:#00d4ff;border:1px solid #00d4ff;border-radius:4px;text-decoration:none;font-size:13px;" data-i18n="back_home">← Назад</a></span>
</div>
<form method="get" action="?" style="margin-top:16px;">
<input type="hidden" name="action" value="get"><input type="hidden" name="confirm" value="1"><input type="hidden" name="site" value="<?=htmlspecialchars($currentSite)?>">
<div class="card">
<div class="form-row"><div class="field" style="max-width:150px;"><label data-i18n="per_page_import">Производителей за запрос</label><input type="number" name="per_page" value="<?=$getPerPage?>" min="1" max="2000"></div>
<div class="field" style="max-width:180px;"><label data-i18n="date_from">Дата с</label><input type="date" name="date_after" value="<?=htmlspecialchars($_GET['date_after']??'')?>" placeholder="ГГГГ-ММ-ДД" data-i18n-placeholder="date_format"></div>
<div class="field" style="max-width:180px;"><label data-i18n="date_to">Дата по</label><input type="date" name="date_before" value="<?=htmlspecialchars($_GET['date_before']??'')?>" placeholder="ГГГГ-ММ-ДД" data-i18n-placeholder="date_format"></div>
<div class="field" style="max-width:120px;"><label data-i18n="lang_label">Язык</label><select name="lang"><option value="" data-i18n="all_languages">все</option>
<option value="ru"<?=($_GET['lang']??'')==='ru'?' selected':''?> data-i18n="lang_ru">Русский</option>
<option value="ua"<?=($_GET['lang']??'')==='ua'?' selected':''?> data-i18n="lang_ua">Українська</option>
<option value="en"<?=($_GET['lang']??'')==='en'?' selected':''?> data-i18n="lang_en">English</option>
<option value="pl"<?=($_GET['lang']??'')==='pl'?' selected':''?> data-i18n="lang_pl">Polski</option>
<option value="de"<?=($_GET['lang']??'')==='de'?' selected':''?> data-i18n="lang_de">Deutsch</option>
<option value="fr"<?=($_GET['lang']??'')==='fr'?' selected':''?> data-i18n="lang_fr">Français</option>
<option value="es"<?=($_GET['lang']??'')==='es'?' selected':''?> data-i18n="lang_es">Español</option>
<option value="it"<?=($_GET['lang']??'')==='it'?' selected':''?> data-i18n="lang_it">Italiano</option>
<option value="kk"<?=($_GET['lang']??'')==='kk'?' selected':''?> data-i18n="lang_kk">Қазақ</option>
<option value="be"<?=($_GET['lang']??'')==='be'?' selected':''?> data-i18n="lang_be">Беларуская</option>
</select></div>
<div class="field" style="max-width:120px;"><label data-i18n="id_min_label">ID ></label><input type="number" name="id_min" value="<?=htmlspecialchars($_GET['id_min']??'')?>" min="0" placeholder="1000" data-i18n-placeholder="id_min_placeholder"></div>
<div class="field" style="max-width:120px;"><label data-i18n="id_max_label">ID <</label><input type="number" name="id_max" value="<?=htmlspecialchars($_GET['id_max']??'')?>" min="0" placeholder="5000" data-i18n-placeholder="id_max_placeholder"></div></div>
<?php for($gsi=1;$gsi<count($getSearch);$gsi++):?><input type="hidden" name="search[]" value="<?=htmlspecialchars($getSearch[$gsi])?>"><?php endfor;?>
<div style="margin-bottom:6px;"><label class="chk-label" style="display:flex!important;align-items:center;gap:6px;cursor:pointer;color:#e0e0e0;font-size:13px;padding:6px 10px;background:#0d1b2a;border:1px solid #0f3460;border-radius:6px;"><input type="hidden" name="import_only_named" value="0"><input type="checkbox" name="import_only_named" value="1"<?=(isset($_GET['import_only_named'])?!empty($_GET['import_only_named']):($IMPORT_ONLY_NAMED ?? true))?' checked':''?>> <span data-i18n="import_only_named">Только с именем (пропускать без name)</span></label></div></div>
<div class="card">
<label style="color:#888;font-size:13px;display:block;margin-bottom:6px;" data-i18n="search_import">Поиск по имени (slug)</label>
<div id="search-fields"><input type="text" name="search[]" value="<?=htmlspecialchars($getSearch ? $getSearch[0] : '')?>" placeholder="часть имени, например: shoes" data-i18n-placeholder="search_placeholder" style="margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box;"></div>
<button type="button" onclick="var p=document.getElementById('search-fields');var inp=document.createElement('input');inp.type='text';inp.name='search[]';inp.placeholder='часть имени';inp.setAttribute('data-i18n-placeholder','search_placeholder');inp.style.cssText='display:block;margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box';p.appendChild(inp);" style="padding:2px 10px;background:transparent;color:#00d4ff;border:1px dashed #00d4ff;border-radius:4px;cursor:pointer;font-size:11px;margin-top:2px;" data-i18n="btn_more">+ ЕЩЕ</button>
<button type="button" onclick="var t=prompt(_t[_lang]['prompt_values'] || 'Введите значения (каждая строка — отдельное поле):');if(t){var p=document.getElementById('search-fields');var lines=t.split('\n');for(var i=0;i<lines.length;i++){var v=lines[i].trim();if(v==='')continue;var inp=document.createElement('input');inp.type='text';inp.name='search[]';inp.value=v;inp.placeholder='часть имени';inp.setAttribute('data-i18n-placeholder','search_placeholder');inp.style.cssText='display:block;margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box';p.appendChild(inp);}}" style="padding:2px 10px;background:transparent;color:#ff9800;border:1px dashed #ff9800;border-radius:4px;cursor:pointer;font-size:11px;margin-top:2px;margin-left:4px;" data-i18n="btn_more_multi">📋 ЕЩЕ НЕСКОЛЬКО</button>
</div>
<div class="card">
<h3 style="margin:0 0 10px;font-size:15px;color:#4dc9f6;" data-i18n="coll_filter">📂 Коллекции для фильтрации</h3>
<p style="font-size:11px;color:#888;margin-bottom:10px;" data-i18n="coll_note">Если не выбрано ни одной — обрабатываются все коллекции.</p>
<div id="get-colls"><?php $collectionParentIdx=0;foreach($ALLOWED_COLLECTIONS as $cid=>$cname):?>
<div class="coll-row"><input type="text" name="cat[<?=$collectionParentIdx?>][id]" value="<?=$cid?>" placeholder="ID" data-i18n-placeholder="cat_id_placeholder" style="max-width:80px;"><input type="text" name="cat[<?=$collectionParentIdx?>][name]" value="<?=htmlspecialchars($cname)?>" placeholder="имя коллекции" data-i18n-placeholder="cat_name_placeholder"><button type="button" onclick="this.parentElement.remove()" class="btn-sm">✕</button></div>
<?php $collectionParentIdx++;endforeach;?></div>
<button type="button" onclick="var d=document.getElementById('get-colls'),i=d.children.length;d.innerHTML+='<div class=\'coll-row\'><input type=\'text\' name=\'cat['+i+'][id]\' placeholder=\'ID\' data-i18n-placeholder=\'cat_id_placeholder\' style=\'max-width:80px;\'><input type=\'text\' name=\'cat['+i+'][name]\' placeholder=\'имя коллекции\' data-i18n-placeholder=\'cat_name_placeholder\'><button type=\'button\' onclick=\'this.parentElement.remove()\' class=\'btn-sm\'>✕</button></div>';" style="padding:4px 12px;background:#0f3460;color:#00d4ff;border:1px solid #00d4ff;border-radius:4px;cursor:pointer;font-size:12px;margin-top:4px;" data-i18n="btn_add_cat">+ Добавить категорию</button>
<input type="hidden" name="colls_configured" value="1">
</div>
<div class="card">
  <h3 style="margin:0 0 10px;font-size:15px;color:#4dc9f6;" data-i18n="fix_import_title">🔧 Синхронизация полей коллекции</h3>
  <p style="font-size:11px;color:#888;margin-bottom:10px;" data-i18n="fix_import_desc">Синхронизировать поля с эталонной производителем (по slug) после сохранения</p>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:8px;">
    <div>
      <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="ref_lang_label">🌐 Язык эталонной коллекции</label>
      <select name="import_ref_lang" style="padding:6px 8px;border:1px solid #0f3460;border-radius:4px;background:#0d1b2a;color:#e0e0e0;">
        <option value="be"<?=(($_GET['import_ref_lang']??$REFERENCE_LANG)??'pl')==='be'?' selected':''?>>Белорусский (be)</option>
        <option value="de"<?=(($_GET['import_ref_lang']??$REFERENCE_LANG)??'pl')==='de'?' selected':''?>>Немецкий (de)</option>
        <option value="en"<?=(($_GET['import_ref_lang']??$REFERENCE_LANG)??'pl')==='en'?' selected':''?>>English (en)</option>
        <option value="es"<?=(($_GET['import_ref_lang']??$REFERENCE_LANG)??'pl')==='es'?' selected':''?>>Испанский (es)</option>
        <option value="fr"<?=(($_GET['import_ref_lang']??$REFERENCE_LANG)??'pl')==='fr'?' selected':''?>>Français (fr)</option>
        <option value="it"<?=(($_GET['import_ref_lang']??$REFERENCE_LANG)??'pl')==='it'?' selected':''?>>Italiano (it)</option>
        <option value="kk"<?=(($_GET['import_ref_lang']??$REFERENCE_LANG)??'pl')==='kk'?' selected':''?>>Қазақ (kk)</option>
        <option value="pl"<?=(($_GET['import_ref_lang']??$REFERENCE_LANG)??'pl')==='pl'?' selected':''?>>Polski (pl)</option>
        <option value="ru"<?=(($_GET['import_ref_lang']??$REFERENCE_LANG)??'pl')==='ru'?' selected':''?>>Русский (ru)</option>
        <option value="ua"<?=(($_GET['import_ref_lang']??$REFERENCE_LANG)??'pl')==='ua'?' selected':''?>>Українська (ua)</option>
      </select>
    </div>
  </div>
  <div style="display:flex;gap:16px;flex-wrap:wrap;">
    <label style="font-size:12px;color:#ccc;cursor:pointer;display:flex;align-items:center;gap:3px;">
      <input type="hidden" name="import_fix_multilangid" value="0">
      <input type="checkbox" name="import_fix_multilangid" value="1"<?=(isset($_GET['import_fix_multilangid'])?!empty($_GET['import_fix_multilangid']):$FIX_MULTILANGID)?' checked':''?>> multilangid
    </label>

    <label style="font-size:12px;color:#ccc;cursor:pointer;display:flex;align-items:center;gap:3px;">
      <input type="hidden" name="import_fix_status" value="0">
      <input type="checkbox" name="import_fix_status" value="1"<?=(isset($_GET['import_fix_status'])?!empty($_GET['import_fix_status']):$FIX_STATUS)?' checked':''?>> status
    </label>
      <label style="font-size:12px;color:#ccc;cursor:pointer;display:flex;align-items:center;gap:3px;">
        <input type="hidden" name="import_fix_datestamp" value="0">
        <input type="checkbox" name="import_fix_datestamp" value="1"<?=(isset($_GET['import_fix_datestamp'])?!empty($_GET['import_fix_datestamp']):$FIX_DATESTAMP)?' checked':''?>> datestamp
    </label>
  </div>
</div>
<button type="submit" class="btn btn-primary" data-i18n="btn_get">📥 СКАЧАТЬ</button>
<a href="?site=<?=urlencode($currentSite)?>" style="padding:8px 18px;background:transparent;color:#888;border:1px solid #555;border-radius:6px;text-decoration:none;font-size:13px;margin-left:8px;" data-i18n="back_home">← Назад</a>
</form>
<script>
var _lang='ru';try{_lang=localStorage.getItem('boostore_lang')||navigator.language.slice(0,2);localStorage.setItem('boostore_lang',_lang);}catch(e){}if(!['ru','en','ua'].includes(_lang))_lang='ru';
var _t={ru:{'home':'🏠 Главная','site_label':'Сайт:','entity_name':'Коллекции','import':'📥 Импорт','export':'📤 Экспорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Создано:','updated_label':'📝 Обновлено:','errors_label':'❌ Ошибок:','skipped_exist_label':'⏭ Пропущено (существуют):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Экспортировать только текст','file_selection':'📁 Выбор файлов','btn_get':'📥 СКАЧАТЬ','btn_update':'📤 ОТПРАВИТЬ','plaque_import':'▸ <strong>Настройки импорта</strong> — получение коллекций с API','plaque_export':'▸ <strong>Настройки экспорта</strong> — отправка коллекций на Boostore.pro','search_import':'Поиск по имени (slug)','coll_filter':'📂 Коллекции для фильтрации','coll_note':'Если не выбрано ни одной — обрабатываются все коллекции.','back_home':'← Назад','btn_more':'+ ЕЩЕ','btn_more_multi':'📋 ЕЩЕ НЕСКОЛЬКО','btn_add_cat':'+ Добавить категорию','per_page_import':'Производителей за запрос','date_from':'Дата с','date_to':'Дата по','lang_label':'Язык','id_min_label':'ID >','id_max_label':'ID <','id_min_placeholder':'1000','id_max_placeholder':'5000','folder_planned_chk':'Разделять planned в <code>products/planned/</code>','folder_collection_chk':'Разделять по папкам коллекций','all_languages':'все','lang_ru':'Русский','lang_en':'English','lang_ua':'Українська','lang_pl':'Polski','lang_de':'Deutsch','lang_fr':'Français','lang_es':'Español','lang_it':'Italiano','lang_kk':'Қазақ','lang_be':'Беларуская','api_docs':'API Docs','version':'v2.0','date_format':'ГГГГ-ММ-ДД','search_placeholder':'часть имени, например: shoes','cat_id_placeholder':'ID','cat_name_placeholder':'имя коллекции','prompt_values':'Введите значения (каждая строка — отдельное поле):','step_forward':'➡ ДАЛЕЕ','dry_run_label':'Dry run','filter_name':'Фильтр по имени (slug)','batch_label':'Отправить за 1 раз','ref_lang_be':'Белорусский (be)','ref_lang_en':'English (en)','ref_lang_ru':'Русский (ru)','ref_lang_ua':'Українська (ua)','ref_lang_pl':'Polski (pl)','date_mode_meta':'Из мета-данных (дата из каждого коллекции)','date_mode_fixed':'Одна дата для всех коллекций','date_mode_offset':'Смещение дат (+N дней на коллекция)','planned_notset':'— не указано (из мета-данных)','planned_0':'0 — не отложенная','planned_1':'1 — отложенная публикация','status_mode_meta':'Из мета-данных (статус из каждого коллекции)','status_mode_override':'Переопределить для всех коллекций','date_mode_label':'📅 Режим даты публикации','title':'Управление коллекциями магазина — Boostore.pro','fix_import_title':'🔧 Исправление по эталону','fix_import_desc':'Синхронизировать поля с эталонной коллекцией (по slug) после сохранения','ref_lang_label':'🌐 Язык эталонной коллекции','export_fields_label':'📋 Поля для экспорта','export_collection_id_label':'ID колекції','export_product_id_label':'Product ID','import_only_named':'Только с именем','export_collection_name_label':'Имя коллекции'},en:{'home':'🏠 Home','site_label':'Site:','entity_name':'Collections','import':'📥 Import','export':'📤 Export','completed':'✓ Completed','result_label':'✓ Result','created_label':'✅ Created:','updated_label':'📝 Updated:','errors_label':'❌ Errors:','skipped_exist_label':'⏭ Skipped (exist):','skipped_exist_label2':'⏭ Skipped','export_text_only':'📝 Export text only','file_selection':'📁 File selection','btn_get':'📥 DOWNLOAD','btn_update':'📤 UPLOAD','plaque_import':'▸ <strong>Import Settings</strong> — fetching products from API','plaque_export':'▸ <strong>Export Settings</strong> — sending products to Boostore.pro','search_import':'Search by name (slug)','coll_filter':'📂 Categories for filtering','coll_note':'If none selected, all categories are processed.','back_home':'← Back','btn_more':'+ MORE','btn_more_multi':'📋 ADD MULTIPLE','btn_add_cat':'+ Add Category','per_page_import':'Products per request','date_from':'Date from','date_to':'Date to','lang_label':'Language','folder_planned_chk':'Separate planned into <code>products/planned/</code>','folder_collection_chk':'Separate by category folders','all_languages':'all','lang_ru':'Russian','lang_en':'English','lang_ua':'Ukrainian','lang_pl':'Polish','lang_de':'German','lang_fr':'French','lang_es':'Spanish','lang_it':'Italian','lang_kk':'Kazakh','lang_be':'Belarusian','api_docs':'API Docs','version':'v2.0','date_format':'YYYY-MM-DD','search_placeholder':'part of name, e.g.: shoes','cat_id_placeholder':'ID','cat_name_placeholder':'category name','prompt_values':'Enter values (each line is a separate field):','step_forward':'➡ NEXT','dry_run_label':'Dry run','filter_name':'Filter by name (slug)','batch_label':'Send per run','ref_lang_be':'Belarusian (be)','ref_lang_en':'English (en)','ref_lang_ru':'Russian (ru)','ref_lang_ua':'Ukrainian (ua)','ref_lang_pl':'Polish (pl)','date_mode_meta':'From meta-data (date from each article)','date_mode_fixed':'Single date for all products','date_mode_offset':'Date offset (+N days per article)','planned_notset':'— not set (from meta-data)','planned_0':'0 — not planned','planned_1':'1 — planned publishing','status_mode_meta':'From meta-data (status from each article)','status_mode_override':'Override for all products','date_mode_label':'📅 Publication Date Mode','title':'Collection Management — Boostore.pro','fix_import_title':'🔧 Fix by Reference','fix_import_desc':'Sync fields with reference collection (by slug) after save','ref_lang_label':'🌐 Reference Language','export_fields_label':'📋 Fields to export','export_collection_id_label':'Collection ID','export_product_id_label':'Product ID','import_only_named':'Named only','export_collection_name_label':'Collection name'},ua:{'home':'🏠 Головна','site_label':'Сайт:','entity_name':'Колекції','import':'📥 Імпорт','export':'📤 Експорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Створено:','updated_label':'📝 Оновлено:','errors_label':'❌ Помилок:','skipped_exist_label':'⏭ Пропущено (існують):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Експортувати тільки текст','file_selection':'📁 Вибір файлів','btn_get':'📥 ПОЧАТИ ІМПОРТ','btn_update':'📤 ПОЧАТИ ЕКСПОРТ','plaque_import':'▸ <strong>Налаштування імпорту</strong> — отримання коллекций з API','plaque_export':'▸ <strong>Налаштування експорту</strong> — відправлення коллекций на Boostore.pro','search_import':'Пошук за іменем (slug)','coll_filter':'📂 Категорії для фільтрації','coll_note':'Якщо не вибрано жодної — обробляються всі категорії.','back_home':'← На головну','btn_more':'+ ЩЕ','btn_more_multi':'📋 ДОДАТИ КІЛЬКА','btn_add_cat':'+ Додати категорію','per_page_import':'Статей за запит','date_from':'Дата з','date_to':'Дата по','lang_label':'Мова','folder_planned_chk':'Розділяти planned у <code>products/planned/</code>','folder_collection_chk':'Розділяти по папках категорій','all_languages':'всі','lang_ru':'Російська','lang_en':'Англійська','lang_ua':'Українська','lang_pl':'Польська','lang_de':'Німецька','lang_fr':'Французька','lang_es':'Іспанська','lang_it':'Італійська','lang_kk':'Казахська','lang_be':'Білоруська','api_docs':'API Docs','version':'v2.0','date_format':'РРРР-ММ-ДД','search_placeholder':'частина імені, наприклад: shoes','cat_id_placeholder':'ID','cat_name_placeholder':'ім\'я категорії','prompt_values':'Введіть значення (кожен рядок — окреме поле):','step_forward':'➡ ДАЛІ','dry_run_label':'Dry run','filter_name':'Фільтр за іменем (slug)','batch_label':'Відправити за 1 раз','ref_lang_be':'Білоруська (be)','ref_lang_en':'Англійська (en)','ref_lang_ru':'Російська (ru)','ref_lang_ua':'Українська (ua)','ref_lang_pl':'Польська (pl)','date_mode_meta':'З мета-даних (дата з кожної статті)','date_mode_fixed':'Одна дата для всіх коллекций','date_mode_offset':'Зміщення дат (+N днів на статтю)','planned_notset':'— не вказано (з мета-даних)','planned_0':'0 — не відкладена','planned_1':'1 — відкладена публікація','status_mode_meta':'З мета-даних (статус з кожної статті)','status_mode_override':'Перевизначити для всіх коллекций','date_mode_label':'📅 Режим дати публікації','title':'Керування колекціями магазину — Boostore.pro','fix_import_title':'🔧 Виправлення за еталоном','fix_import_desc':'Синхронізувати поля з еталонною колекцією (по slug) після збереження','ref_lang_label':'🌐 Мова еталонної колекції','export_fields_label':'📋 Поля для експорту','export_collection_id_label':'ID колекції','export_product_id_label':'ID товару','import_only_named':'Тільки з назвою','export_collection_name_label':'Ім\'я колекції'}};
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});document.querySelectorAll('[data-i18n-placeholder]').forEach(function(el){var key=el.getAttribute('data-i18n-placeholder');if(_t[l]&&_t[l][key]!==undefined)el.placeholder=_t[l][key];});}
if(_lang!='ru'){document.addEventListener('DOMContentLoaded',function(){applyLang(_lang);});}
document.addEventListener('DOMContentLoaded',function(){var ls=document.getElementById('lang_switcher');if(ls){ls.value=_lang;ls.addEventListener('change',function(){applyLang(this.value);});}});
</script>
</div></body></html>
<?php exit; endif;

// Parse categories from form if submitted (colls_configured = форма с коллекциями была отправлена)
if (isset($_GET['colls_configured'])) {
    $tmpCats = [];
    foreach ($getCats as $gc) {
        $cid = (int)($gc['id'] ?? 0);
        $cnm = trim($gc['name'] ?? '');
        if ($cid > 0 || $cnm !== '') $tmpCats[$cid] = $cnm ?: 'cat_'.$cid;
    }
    $ALLOWED_COLLECTIONS = $tmpCats; // перезаписываем — даже пустым (все коллекции)
}

// Override folder structure from form if provided

$isCLI = false;
$savedProducts = [];
$total = 0;
$saved = 0;
$skipped = 0;

// Single page fetch (pagination via navigation)
$perPage = max(1, min(2000, (int)$getPerPage));
$requestedPage = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$searchQuery = $getSearchStr;
$fetchError = null;
$collections = null;
$totalItems = 0;
$totalPages = 1;

$url = $API_URL . '?per_page=' . $perPage . '&page=' . $requestedPage;
if (!empty($ALLOWED_COLLECTIONS)) {
    $collectionParentIds = implode(',', array_keys($ALLOWED_COLLECTIONS));
    $url .= '&collection_id=' . urlencode($collectionParentIds);
}
if (!empty($_GET['date_after'])) $url .= '&date_after=' . urlencode($_GET['date_after']);
if (!empty($_GET['date_before'])) $url .= '&date_before=' . urlencode($_GET['date_before']);
if (!empty($_GET['lang'])) $url .= '&lang=' . urlencode($_GET['lang']);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $AUTH_KEY, 'Content-Type: application/json'],
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_ENCODING       => '',
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_SSL_VERIFYPEER => 0,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    $apiError = null;
    if ($response !== false) {
        $json = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($json['error'])) {
            $apiError = $json['error'];
        }
    }
    if ($apiError) {
        $fetchError = "Ошибка API: {$apiError} (HTTP {$httpCode})";
    } else {
        $fetchError = "Ошибка HTTP {$httpCode}: {$error}";
    }
} else {
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $fetchError = "Ошибка парсинга JSON";
    } elseif (isset($data['error'])) {
        $fetchError = "Ошибка API: ".htmlspecialchars($data['error']);
    } elseif (isset($data['collections']) && is_array($data['collections'])) {
        $collections = $data['collections'];
        $totalItems = (int)($data['total'] ?? 0);
        $totalPages = max(1, (int)($data['total_pages'] ?? 1));
    } else {
        $fetchError = "Неожиданный формат ответа API. Ответ: ".htmlspecialchars(mb_substr($response, 0, 2000));
    }
}

if (!empty($collections) && !$fetchError) {
    // Fallback: if API returned all categories (no pagination support), slice locally
    if (count($collections) > $perPage) {
        $totalAll = count($collections);
        $offset = ($requestedPage - 1) * $perPage;
        $collections = array_slice($collections, $offset, $perPage);
        $totalItems = $totalAll;
        $totalPages = max(1, (int)ceil($totalAll / $perPage));
    }
    // Search filter by name (multi-term)
    if (!empty($getSearch)) {
        $collections = array_filter($collections, function($cat) use ($getSearch) {
            $n = $cat['collection_name'] ?? $cat['collection_title'] ?? '';
            foreach ($getSearch as $term) {
                if (mb_stripos($n, trim($term)) !== false) return true;
            }
            return false;
        });
        $collections = array_values($collections);
    }
    // ID filter (min/max)
    $idMinGet = isset($_GET['id_min']) ? (int)$_GET['id_min'] : 0;
    $idMaxGet = isset($_GET['id_max']) ? (int)$_GET['id_max'] : 0;
    if ($idMinGet > 0 || $idMaxGet > 0) {
        $collections = array_filter($collections, function($cat) use ($idMinGet, $idMaxGet) {
            $aid = (int)($cat['collection_id'] ?? 0);
            if ($idMinGet > 0 && $aid < $idMinGet) return false;
            if ($idMaxGet > 0 && $aid > $idMaxGet) return false;
            return true;
        });
        $collections = array_values($collections);
    }
    $total = count($collections);
    $baseDir = $COLLECTIONS_DIR;
    foreach ($collections as $a) {
        $collectionParentId = (int)($a['collection_parent_id'] ?? 0);
        $collectionName = $a['collection_name'] ?? '';
$id = (int)($a['collection_id'] ?? 0); $name = $a['collection_name'] ?? '';
$slug = $a['collection_name'] ?? ''; $language = $a['collection_lang'] ?? 'ru';
// Strip language suffix from name/slug if present (e.g., "slug-ua" → "slug")
$name = preg_replace('/-(ua|pl|en|ru)$/i', '', $name);
$slug = preg_replace('/-(ua|pl|en|ru)$/i', '', $slug);
// Skip products without name if import_only_named is enabled
$importOnlyNamed = isset($_GET['import_only_named']) ? !empty($_GET['import_only_named']) : ($IMPORT_ONLY_NAMED ?? true);
if ($importOnlyNamed && trim($name) === '') { $skipped++; continue; }
        $title = $a['collection_title_inpage'] ?? $a['collection_title'] ?? '';
        $metaTitle = $a['collection_title_seo_append'] ?? '';
        $metaDesc = $a['collection_meta_d'] ?? '';
        $metaKeywords = $a['collection_meta_k'] ?? '';
        $description = $a['collection_description_seo_append'] ?? '';
        $collectionButton = $a['collection_button'] ?? '';
        $status = (int)($a['collection_access'] ?? 0);
        $priority = (int)($a['collection_position']??0);
        $subdomain = (int)($a['subdomain']??0);
        $view = (int)($a['collection_view']??0);
        $settingsComments = $a['collection_settings_comments']??'';
        $settingsTags = (int)($a['collection_settings_tags']??0);
        $comments = (int)($a['collection_comments']??0);
        $settingsRating = (int)($a['collection_settings_rating']??0);
        $showTree = (int)($a['collection_show_tree']??0);
        $showPeriod = (int)($a['collection_show_period']??0);
        $rating = (int)($a['collection_rating']??0);
        $datestamp = $a['collection_datestamp']??'';
        $multilangid = $a['collection_multilangid']??''; $tags = $a['tags']??'';
        $collectionVisible = (int)($a['collection_visible']??1);
        $collectionText = $a['collection_text'] ?? '';
        $collectionImage = $a['collection_image'] ?? '';
        $collectionButton = $a['collection_button'] ?? '';
        $collectionShowTree = (int)($a['collection_show_tree']??0);
        $collectionSettingsChild = (int)($a['collection_settings_childproductshow']??0);
        $collectionSettingsStyle = (int)($a['collection_settings_style']??0);
        $collectionSettingsSibling = (int)($a['collection_settings_sibling']??0);
        $collectionGooglegoodsCatid = (int)($a['collection_googlegoods_catid']??0);
        $collectionPromuaCatid = (int)($a['collection_promua_catid']??0);
        $collectionRozetkauaTitle = $a['collection_rozetkaua_title']??'';
        $collectionMerchantProductCategory = $a['collection_merchant_product_category']??'';
        $collectionGmProductType = $a['collection_gm_product_type']??'';
        $collectionGmSizetype = (int)($a['collection_gm_sizetype']??0);
        $collectionPriceappendRozetka = $a['collection_priceappend_rozetka']??'';
        $collectionPriceappendPromua = $a['collection_priceappend_promua']??'';
        $collectionImageSettings = (int)($a['collection_image_settings']??0);
        $collectionCountProducts = (int)($a['collection_count_products']??0);
        $collectionCountSubcategories = (int)($a['collection_count_subcategories']??0);
        $collectionLevel = (int)($a['collection_level']??0);
        $collectionTree = $a['collection_tree']??'';
        $collectionTextPosition = (int)($a['collection_text_position']??0);
        $collectionAuthor = (int)($a['collection_author']??0);
        $collectionShowInlist = (int)($a['collection_visible']??0);
        $subDir = '';
        $dirPath = $baseDir.DIRECTORY_SEPARATOR.$subDir;
        if (!is_dir($dirPath)) mkdir($dirPath,0777,true);
$safeName = preg_replace('/[^a-zA-Z0-9_-]/','',$name); $safeName = trim($safeName,'-_');
if ($safeName === (string)$id) $safeName = '';
$filename = $id.($safeName!==''?'-'.$safeName:'').'-'.$language.'.html';
        $filepath = $dirPath.$filename; $relPath = $currentSite.DIRECTORY_SEPARATOR.'products'.DIRECTORY_SEPARATOR.$subDir.$filename;
        $h = '<meta name="title" content="'.htmlspecialchars($title, ENT_QUOTES).'">'."\n";
        $metaList = [
            'id'=>$id,'collection_id'=>$id,'name'=>$name,'slug'=>$slug,'collection_name'=>$collectionName,
            'collection_title'=>$title,'collection_lang'=>$language,'collection_parent_id'=>$collectionParentId,
            'collection_visible'=>$collectionVisible,'collection_show_tree'=>$collectionShowTree,
            'collection_access'=>$status,'collection_position'=>$priority,
            'collection_view'=>$view,'collection_rating'=>$rating,'collection_multilangid'=>$multilangid,
            'collection_image'=>$collectionImage,'collection_image_settings'=>$collectionImageSettings,
            'collection_level'=>$collectionLevel,'collection_tree'=>$collectionTree,
            'collection_text_position'=>$collectionTextPosition,
            'collection_count_products'=>$collectionCountProducts,'collection_count_subcategories'=>$collectionCountSubcategories,
            'collection_title_inpage'=>$title,'collection_title_seo_append'=>$metaTitle,
            'collection_description_seo_append'=>$description,'collection_meta_d'=>$metaDesc,
            'collection_meta_k'=>$metaKeywords,
            'collection_settings_comments'=>$settingsComments,'collection_settings_tags'=>$settingsTags,
            'collection_comments'=>$comments,'collection_settings_rating'=>$settingsRating,
            'collection_settings_childproductshow'=>$collectionSettingsChild,
            'collection_settings_style'=>$collectionSettingsStyle,'collection_settings_sibling'=>$collectionSettingsSibling,
            'collection_googlegoods_catid'=>$collectionGooglegoodsCatid,
            'collection_promua_catid'=>$collectionPromuaCatid,'collection_rozetkaua_title'=>$collectionRozetkauaTitle,
            'collection_merchant_product_category'=>$collectionMerchantProductCategory,
            'collection_gm_product_type'=>$collectionGmProductType,'collection_gm_sizetype'=>$collectionGmSizetype,
            'collection_priceappend_rozetka'=>$collectionPriceappendRozetka,
            'collection_priceappend_promua'=>$collectionPriceappendPromua,
            'collection_author'=>$collectionAuthor,'tags'=>$tags,
            'language'=>$language,'status'=>$status,'subdomain'=>$subdomain,
            'priority'=>$priority,'view'=>$view,'rating'=>$rating,'datestamp'=>$datestamp,
            'multilangid'=>$multilangid,
        ];
        foreach ($metaList as $k=>$v) $h .= '<meta name="'.htmlspecialchars($k).'" content="'.htmlspecialchars((string)$v).'">'."\n";
        $h .= '<!-- COLLECTION TEXT BELOW -->'."\n".$collectionText."\n";
        $h .= '<!-- COLLECTION BUTTON BELOW -->'."\n".$collectionButton."\n";
        file_put_contents($filepath,$h); $saved++;
        $savedProducts[] = ['id'=>$id,'language'=>$language,'path'=>$relPath,'title'=>$title,'slug'=>$slug,
            'producer'=>$collectionName,'status'=>$status,'descLen'=>mb_strlen($collectionText),
            'datestamp'=>$datestamp,'multilangid'=>$multilangid];
    }
}
// Auto-fix by RU (with GET overrides from import form)
$fixes = []; $fixGroups = [];
foreach ($savedProducts as $a) { $fixGroups[$a['slug']][] = $a; }
$importRefLang = $_GET['import_ref_lang'] ?? $REFERENCE_LANG;
$importFixMultilangid = isset($_GET['import_fix_multilangid']) ? !empty($_GET['import_fix_multilangid']) : $FIX_MULTILANGID;

$importFixStatus = isset($_GET['import_fix_status']) ? !empty($_GET['import_fix_status']) : $FIX_STATUS;
$importFixDatestamp = isset($_GET['import_fix_datestamp']) ? !empty($_GET['import_fix_datestamp']) : $FIX_DATESTAMP;
$fixFields = [];
if ($importFixMultilangid) $fixFields[] = 'multilangid';

if ($importFixStatus) $fixFields[] = 'status';
if ($importFixDatestamp) $fixFields[] = 'datestamp';
foreach ($fixGroups as $slug=>$arts) {
    if (count($arts)<2) continue;
    $refLang = $importRefLang ?: 'ru';
$ru = null; foreach ($arts as $a) { if ($a['language']===$refLang) { $ru=$a; break; } }
    if (!$ru) continue;
    foreach ($arts as $a) {
        if ($a['language']===$refLang) continue;
        $changed = false; $html = @file_get_contents(__DIR__.DIRECTORY_SEPARATOR.$a['path']);
        if ($html===false) continue;
        foreach ($fixFields as $f) {
            $old = (string)$a[$f]; $new = (string)$ru[$f];
            if ($old!==$new) {
                $html = preg_replace('/<meta name="'.preg_quote($f,'/').'" content="(.*?)">/is','<meta name="'.$f.'" content="'.htmlspecialchars($new,ENT_QUOTES,'UTF-8').'">',$html);
                $changed = true;
                foreach ($savedProducts as $k=>$sa) { if ($sa['id']===$a['id']&&$sa['language']===$a['language']) { $savedProducts[$k][$f]=$new; break; } }
            }
        }
        if ($changed) { file_put_contents(__DIR__.DIRECTORY_SEPARATOR.$a['path'],$html); $fixes[]="[{$a['language']}] #{$a['id']} — исправлен по {$refLang}"; }
    }
}
// Group by slug
$groups = [];
foreach ($savedProducts as $a) { $groups[$a['slug']][] = $a; }
?><!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title>Импорт коллекций — Boostore.pro</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#1a1a2e;color:#e0e0e0;font-family:'Segoe UI',system-ui,sans-serif;padding:30px}.wrap{max-width:1200px;margin:0 auto}
h1{font-size:22px;color:#00d4ff;margin-bottom:5px}.meta-info{color:#888;font-size:13px;margin-bottom:25px;}a{color:#00d4ff}.card,.summary-card,.sub-article{background:#16213e;border:1px solid #0f3460;border-radius:10px;margin-bottom:16px;overflow:hidden}
.card-header{background:#0f3460;padding:10px 16px;display:flex;justify-content:space-between;align-items:center}.card-header .num{font-weight:700;color:#00d4ff}
.card-body{padding:12px 16px}.meta-grid{display:grid;grid-template-columns:auto 1fr;gap:3px 14px;font-size:12px}.meta-grid .key{color:#888;white-space:nowrap}.meta-grid .val{color:#e0e0e0;word-break:break-all}
.success{color:#4caf50;font-weight:600}.error{color:#f44336;font-weight:600}.warning{color:#ff9800}.footer{text-align:center;padding:20px;color:#555;font-size:13px}.na{color:#555;font-style:italic}
.lastedit{color:#555;font-size:11px;margin-top:4px}.expand-all{margin-bottom:15px}code{background:#0d1b2a;padding:1px 5px;border-radius:3px;font-family:'Consolas',monospace;font-size:12px}
details.summary-card>summary,details.sub-article>summary{cursor:pointer;padding:10px 16px;background:#0f3460;display:flex;justify-content:space-between;align-items:center;list-style:none}
details.summary-card>summary::-webkit-details-marker,details.sub-article>summary::-webkit-details-marker{display:none}
details.summary-card>summary .arrow,details.sub-article>summary .arrow{transition:transform .2s;font-size:11px;color:#888}
details.summary-card[open]>summary .arrow,details.sub-article[open]>summary .arrow{transform:rotate(90deg)}
details.summary-card>summary:hover,details.sub-article>summary:hover{background:#1a4a7a}
details.sub-article{margin-bottom:8px;border-radius:6px}
</style>
<script>function toggleAll(o){document.querySelectorAll('details').forEach(function(d){if(o)d.setAttribute('open','');else d.removeAttribute('open')})}</script>
</head>
<body><div class="wrap"><?php echo $header; ?>
<h1 data-i18n="import_results_title">▸ Импорт коллекций — получено с API</h1>
<?php if ($fetchError): ?>
<div class="card"><div class="card-header"><span class="error" data-i18n="fetch_error">✗ Ошибка</span></div><div class="card-body"><span class="error"><?=htmlspecialchars($fetchError)?></span></div></div>
<?php else: ?>
<div class="meta-info" style="padding-top:20px; padding:bottom:20px; font-size:110%;"><span data-i18n="all_total">Всего:</span> <strong><?=$totalItems?:$total?></strong> <span data-i18n="products_count">коллекций</span> | <span data-i18n="loaded_count">Загружено:</span> <strong style="color:#4caf50"><?=$saved?></strong> | <span data-i18n="skipped_count">Пропущено:</span> <strong style="color:#888"><?=$skipped?></strong> | <span data-i18n="page_label">Страница</span> <strong><?=$requestedPage?></strong> <span data-i18n="from_label">из</span> <strong><?=$totalPages?></strong><?php if($fixes):?> | <span data-i18n="fixed_label">Исправлено:</span> <strong style="color:#ff9800"><?=count($fixes)?></strong><?php endif;?><?php if(!empty($getSearch)):?> | <span data-i18n="search_label">Поиск:</span> <strong style="color:#00d4ff"><?=htmlspecialchars(implode(', ', $getSearch))?></strong><?php endif;?></div>


<?php if ($totalPages > 1):
// Сохраняем все текущие параметры фильтрации для пагинации
$pageQp = $_GET;
unset($pageQp['p']);
$pageQueryStr = htmlspecialchars(http_build_query($pageQp));
?>
<div class="card" style="display:flex;gap:8px;align-items:center;margin-bottom:26px;flex-wrap:wrap;padding:10px;">
  <form method="get" action="?" style="display:flex;gap:6px;align-items:center;background:#0d1b2a;padding:8px 12px;border-radius:6px;border:1px solid #0f3460;">
    <input type="hidden" name="action" value="get">
    <?php
    // Пробрасываем все текущие параметры фильтрации как hidden-поля
    foreach ($pageQp as $pk => $pv) {
        if ($pk === 'action') continue;
        if (is_array($pv)) {
            foreach ($pv as $pk2 => $pv2) {
                if (is_array($pv2)) {
                    foreach ($pv2 as $pk3 => $pv3) {
                        echo '<input type="hidden" name="'.htmlspecialchars($pk).'['.htmlspecialchars($pk2).']['.htmlspecialchars($pk3).']" value="'.htmlspecialchars($pv3).'">';
                    }
                } else {
                    echo '<input type="hidden" name="'.htmlspecialchars($pk).'['.htmlspecialchars($pk2).']" value="'.htmlspecialchars($pv2).'">';
                }
            }
        } else {
            echo '<input type="hidden" name="'.htmlspecialchars($pk).'" value="'.htmlspecialchars($pv).'">';
        }
    }
    ?>
    <label style="font-size:12px;color:#888;" data-i18n="page_label">Страница:</label>
    <input type="number" name="p" value="<?=$requestedPage?>" min="1" max="<?=$totalPages?>" style="width:70px;padding:4px 6px;border:1px solid #0f3460;border-radius:4px;background:#16213e;color:#e0e0e0;font-size:13px;">
    <button type="submit" style="padding:4px 10px;background:#0f3460;color:#00d4ff;border:1px solid #00d4ff;border-radius:4px;cursor:pointer;" data-i18n="go_to_page">Перейти</button>
  </form>
  <?php if ($requestedPage > 1): ?>
    <a href="?<?=$pageQueryStr?>&amp;p=<?=$requestedPage-1?>" style="padding:4px 12px;background:#0f3460;color:#e0e0e0;border-radius:4px;text-decoration:none;font-size:13px;"><span data-i18n="prev_page">← Назад (стр.</span> <?=$requestedPage-1?>)</a>
  <?php endif; ?>
  <?php if ($requestedPage < $totalPages): ?>
    <a href="?<?=$pageQueryStr?>&amp;p=<?=$requestedPage+1?>" style="padding:4px 12px;background:#0f3460;color:#00d4ff;border-radius:4px;text-decoration:none;font-size:13px;"><span data-i18n="next_page">Далее → (стр.</span> <?=$requestedPage+1?>)</a>
  <?php endif; ?>
  <span style="font-size:11px;color:#555;"><span data-i18n="per_page_by">по</span> <?=$perPage?> <span data-i18n="products_count">коллекций</span>, <span data-i18n="all_total">всего</span> <?=$totalItems?></span>
</div>
<?php endif; ?>
<?php if($fixes):?>
<br/><div class="meta-info">
<a href="?action=get&site=<?=urlencode($currentSite)?>" class="btn btn-sm" style="padding:5px 14px;background:#0f3460;color:#00d4ff;border:1px solid #00d4ff;border-radius:4px;text-decoration:none;font-size:13px;" data-i18n="back_to_settings">← Назад к настройкам</a> &nbsp;|&nbsp; <a href="?site=<?=urlencode($currentSite)?>" style="font-size:13px;" data-i18n="back_home">Назад</a> &nbsp;</div><br/>
<div style="background:#1a3a1a;border:1px solid #ff9800;border-radius:6px;padding:10px 14px;margin-bottom:26px;font-size:12px;"><span class="warning"><span data-i18n="auto_fix_title">⚡ Авто-исправление по эталону</span> (<?=htmlspecialchars($refLang)?>):</span>
<?php foreach($fixes as $f):?><div style="margin:3px 0;color:#e0e0e0;">• <?=htmlspecialchars($f)?></div><?php endforeach;?></div>
<?php endif;?>
<?php if(!empty($descBad)):?>
<div style="background:#3e2723;border:1px solid #ff9800;border-radius:8px;padding:12px 16px;margin-bottom:20px;">
<div style="color:#ffcc80;font-weight:600;font-size:14px;margin-bottom:8px;">⚠ Разный размер контента</div>
<div style="font-size:12px;color:#e0e0e0;margin-bottom:8px;">Коллекции одной группы (одинаковый slug) имеют разный размер описания (расхождение > 50%):</div>
<table style="width:100%;font-size:12px;border-collapse:collapse;"><tr style="color:#ff9800;"><th style="padding:4px 8px;text-align:left;">slug</th><th style="padding:4px 8px;text-align:left;">мин</th><th style="padding:4px 8px;text-align:left;">макс</th><th style="padding:4px 8px;text-align:left;">коллекции</th></tr>
<?php foreach($descBad as $dg=>$dd):?>
<tr style="border-top:1px solid #555;"><td style="padding:4px 8px;"><?=htmlspecialchars($dg)?></td><td style="padding:4px 8px;"><?=$dd['min']?></td><td style="padding:4px 8px;"><?=$dd['max']?></td><td style="padding:4px 8px;"><?php $parts=[];foreach($dd['arts'] as $da){$parts[]='['.$da['language'].'] '.basename($da['path']);}echo htmlspecialchars(implode(', ',$parts));?></td></tr>
<?php endforeach;?>
</table></div>
<?php endif;?>
<div class="expand-all"><button onclick="toggleAll(true)" style="background:#0f3460;color:#00d4ff;border:1px solid #00d4ff;border-radius:4px;padding:5px 14px;cursor:pointer;" data-i18n="expand_all">▸ РАСКРЫТЬ ВСЕ</button>
<button onclick="toggleAll(false)" style="background:#0f3460;color:#888;border:1px solid #888;border-radius:4px;padding:5px 14px;cursor:pointer;" data-i18n="collapse_all">▾ СКРЫТЬ ВСЕ</button></div><br/>

<?php $descBad=[]; foreach($groups as $gs=>$garts):
$cnt=count($garts);$allOk=true;$checks=[];$first=$garts[0];
foreach(['multilangid','stock_status','price','new','featured','promotion','price_old','status','datestamp'] as $f){$v=array_unique(array_map(function($x)use($f){return (string)$x[$f];},$garts));$ok=count($v)===1;if(!$ok)$allOk=false;$checks[$f]=['ok'=>$ok,'vals'=>$v];}
$dl=array_map(function($x){return(int)$x['descLen'];},$garts);$dmin=min($dl);$dmax=max($dl);$dok=$dmin*2>=$dmax;if(!$dok)$allOk=false;$checks['descLen']=['ok'=>$dok,'vals'=>['min:'.$dmin,'max:'.$dmax]];if(!$dok)$descBad[$gs]=['min'=>$dmin,'max'=>$dmax,'arts'=>$garts];
$gst=$allOk?'success':'error';
?>
<details class="summary-card"><summary><span><span class="<?=$gst?>">📁 <?=htmlspecialchars($gs?:'—')?></span> <span style="color:#888;font-size:12px;"><span data-i18n="languages_count"><?=$cnt?> языка(ов)</span></span></span><span style="color:#888;font-size:11px;"><span class="arrow">▶</span></span></summary>
<div class="card-body"><div style="font-size:13px;margin-bottom:12px;padding:10px;background:#0d1b2a;border-radius:6px;"><div style="display:grid;grid-template-columns:auto 1fr;gap:4px 16px;font-size:12px;">
<?php foreach(['multilangid','stock_status','price','new','featured','promotion','price_old','status','datestamp','descLen'] as $f):$c=$checks[$f];?>
<span class="key"><?=$f==='descLen'?'content':$f?>:</span><span class="val"><?php if($c['ok']):?><span class="success">✓ <?=htmlspecialchars(implode(', ',$c['vals']))?></span><?php else:?><span class="error"><span data-i18n="discrepancy">✗ РАСХОЖДЕНИЕ:</span> <?=htmlspecialchars(implode(' | ',$c['vals']))?></span><?php endif;?></span>
<?php endforeach;?></div></div>
<?php foreach($garts as $a):?>
<details class="sub-article"><summary><span><span class="num">#<?=$a['id']?></span> [<?=$a['language']?>] <?=htmlspecialchars($a['path'])?></span><span style="color:#888;font-size:10px;">▶</span></summary>
<div style="padding:10px 12px;"><div class="meta-grid">
<span class="key">title:</span><span class="val"><?=htmlspecialchars(mb_substr($a['title'],0,100))?:'<span class="na">—</span>'?></span>
<span class="key">slug:</span><span class="val"><?=htmlspecialchars($a['slug'])?></span>
<span class="key">category:</span><span class="val"><?=htmlspecialchars($a['producer'])?></span>
<span class="key">multilangid:</span><span class="val"><?=htmlspecialchars($a['multilangid'])?:'<span class="na">—</span>'?></span>
<span class="key">datestamp:</span><span class="val"><?=htmlspecialchars($a['datestamp'])?:'<span class="na">—</span>'?></span>
<span class="key">status:</span><span class="val"><?=$a['status']?'<span class="success" data-i18n="status_published_short">1 (опубликовано)</span>':'<span data-i18n="status_hidden_short">0 (скрыто)</span>'?></span>
<span class="key">description:</span><span class="val"><?=$a['descLen']?> <span data-i18n="chars_count">символов</span></span>
</div><div class="lastedit">date_lastedit: <?=htmlspecialchars($a['dateLastedit'])?:'<span class="na">—</span>'?></div></div></details>
<?php endforeach;?></div></details>
<?php endforeach;?>
<div class="footer"><strong>Boostore.pro</strong> — <span data-i18n="import_complete">Импорт коллекций завершён</span></div>
<script>
(function(){
    var m = '📥 Загружено: <?=$saved?> из <?=$totalItems?:$total?>';
    if(<?=$skipped?>>0) m += ' | Пропущено: <?=$skipped?>';
    <?php if($fixes):?>m += ' | Исправлено: <?=count($fixes)?>';<?php endif;?>
    var t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#16213e;border:2px solid #0f3460;border-radius:10px;padding:14px 20px;color:#e0e0e0;font-size:14px;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.5);max-width:400px;line-height:1.5;';
    t.innerHTML = '<strong style="color:#00d4ff;">📊 Импорт завершён</strong><br>' + m;
    document.body.appendChild(t);
    setTimeout(function(){ t.style.transition = 'opacity 1s'; t.style.opacity = '0'; setTimeout(function(){ t.remove(); },1000); }, 6000);
})();
</script>
<?php endif;?>
<div style="text-align:center;margin:12px 0;"><a href="?site=<?=urlencode($currentSite)?>" style="padding:8px 18px;background:transparent;color:#888;border:1px solid #555;border-radius:6px;text-decoration:none;font-size:13px;" data-i18n="back_button">← НАЗАД</a></div>
<script>
var _lang='ru';try{_lang=localStorage.getItem('boostore_lang')||navigator.language.slice(0,2);localStorage.setItem('boostore_lang',_lang);}catch(e){}if(!['ru','en','ua'].includes(_lang))_lang='ru';
var _t={ru:{'home':'🏠 Главная','site_label':'Сайт:','entity_name':'Коллекции','import':'📥 Импорт','export':'📤 Экспорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Создано:','updated_label':'📝 Обновлено:','errors_label':'❌ Ошибок:','skipped_exist_label':'⏭ Пропущено (существуют):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Экспортировать только текст','file_selection':'📁 Выбор файлов','btn_get':'📥 СКАЧАТЬ','btn_update':'📤 ОТПРАВИТЬ','back_home':'Назад','back_button':'← НАЗАД','back_to_settings':'← Назад к настройкам','import_results_title':'▸ Импорт коллекций — получено с API','all_total':'Всего:','products_count':'коллекций','loaded_count':'Загружено:','skipped_count':'Пропущено:','page_label':'Страница','from_label':'из','search_label':'Поиск:','fixed_label':'Исправлено:','go_to_page':'Перейти','prev_page':'← Назад (стр.','next_page':'Далее → (стр.','per_page_total':'по','per_page_by':'по','expand_all':'▸ РАСКРЫТЬ ВСЕ','collapse_all':'▾ СКРЫТЬ ВСЕ','auto_fix_title':'⚡ Авто-исправление по эталону','languages_count':'языка(ов)','discrepancy':'✗ РАСХОЖДЕНИЕ:','status_published_short':'1 (опубликовано)','status_hidden_short':'0 (скрыто)','chars_count':'символов','import_complete':'Импорт коллекций завершён','fetch_error':'✗ Ошибка','verification_warn':'✓ Данные сохранены успешно','lang_ru':'Русский','lang_en':'English','lang_ua':'Українська','api_docs':'API Docs','version':'v2.0','ref_lang_be':'Белорусский (be)','ref_lang_en':'English (en)','ref_lang_ru':'Русский (ru)','ref_lang_ua':'Українська (ua)','ref_lang_pl':'Polski (pl)','date_mode_meta':'Из мета-данных (дата из каждого коллекции)','date_mode_fixed':'Одна дата для всех коллекций','date_mode_offset':'Смещение дат (+N дней на коллекция)','planned_notset':'— не указано (из мета-данных)','planned_0':'0 — не отложенная','planned_1':'1 — отложенная публикация','status_mode_meta':'Из мета-данных (статус из каждого коллекции)','status_mode_override':'Переопределить для всех коллекций','date_mode_label':'📅 Режим даты публикации','title':'Управление коллекциями магазина — Boostore.pro','fix_import_title':'🔧 Исправление по эталону','fix_import_desc':'Синхронизировать поля с эталонной коллекцией (по slug) после сохранения','ref_lang_label':'🌐 Язык эталонной коллекции','export_fields_label':'📋 Поля для экспорта','export_collection_id_label':'ID колекції','export_product_id_label':'Product ID','import_only_named':'Только с именем','export_collection_name_label':'Имя коллекции'},en:{'home':'🏠 Home','site_label':'Site:','entity_name':'Collections','import':'📥 Import','export':'📤 Export','completed':'✓ Completed','result_label':'✓ Result','created_label':'✅ Created:','updated_label':'📝 Updated:','errors_label':'❌ Errors:','skipped_exist_label':'⏭ Skipped (exist):','skipped_exist_label2':'⏭ Skipped','export_text_only':'📝 Export text only','file_selection':'📁 File selection','btn_get':'📥 DOWNLOAD','btn_update':'📤 UPLOAD','back_home':'Home','back_button':'← BACK','back_to_settings':'← Back to settings','import_results_title':'▸ Products fetched from API','all_total':'Total:','products_count':'products','loaded_count':'Loaded:','skipped_count':'Skipped:','page_label':'Page','from_label':'of','search_label':'Search:','fixed_label':'Fixed:','go_to_page':'Go','prev_page':'← Prev (pg.','next_page':'Next → (pg.','per_page_total':'per','per_page_by':'per','expand_all':'▸ EXPAND ALL','collapse_all':'▾ COLLAPSE ALL','auto_fix_title':'⚡ Auto-fix by reference','languages_count':'language(s)','discrepancy':'✗ DISCREPANCY:','status_published_short':'1 (published)','status_hidden_short':'0 (hidden)','chars_count':'chars','import_complete':'Import complete','fetch_error':'✗ Error','verification_warn':'⚠ Data saved, but length in API response differs (possible formatting differences)','lang_ru':'Russian','lang_en':'English','lang_ua':'Ukrainian','api_docs':'API Docs','version':'v2.0','ref_lang_be':'Belarusian (be)','ref_lang_en':'English (en)','ref_lang_ru':'Russian (ru)','ref_lang_ua':'Ukrainian (ua)','ref_lang_pl':'Polish (pl)','date_mode_meta':'From meta-data (date from each article)','date_mode_fixed':'Single date for all products','date_mode_offset':'Date offset (+N days per article)','planned_notset':'— not set (from meta-data)','planned_0':'0 — not planned','planned_1':'1 — planned publishing','status_mode_meta':'From meta-data (status from each article)','status_mode_override':'Override for all products','date_mode_label':'📅 Publication Date Mode','title':'Collection Management — Boostore.pro','fix_import_title':'🔧 Fix by Reference','fix_import_desc':'Sync fields with reference collection (by slug) after save','ref_lang_label':'🌐 Reference Language','export_fields_label':'📋 Fields to export','export_collection_id_label':'Collection ID','export_product_id_label':'Product ID','import_only_named':'Named only','export_collection_name_label':'Collection name'},ua:{'home':'🏠 Головна','site_label':'Сайт:','entity_name':'Колекції','import':'📥 Імпорт','export':'📤 Експорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Створено:','updated_label':'📝 Оновлено:','errors_label':'❌ Помилок:','skipped_exist_label':'⏭ Пропущено (існують):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Експортувати тільки текст','file_selection':'📁 Вибір файлів','btn_get':'📥 ПОЧАТИ ІМПОРТ','btn_update':'📤 ПОЧАТИ ЕКСПОРТ','back_home':'На головну','back_button':'← НАЗАД','back_to_settings':'← Назад до налаштувань','import_results_title':'▸ Статті отримано з API','all_total':'Всього:','products_count':'коллекций','loaded_count':'Завантажено:','skipped_count':'Пропущено:','page_label':'Сторінка','from_label':'з','search_label':'Пошук:','fixed_label':'Виправлено:','go_to_page':'Перейти','prev_page':'← Назад (стор.','next_page':'Далі → (стор.','per_page_total':'по','per_page_by':'по','expand_all':'▸ РОЗГОРНУТИ ВСІ','collapse_all':'▾ ЗГОРНУТИ ВСІ','auto_fix_title':'⚡ Авто-виправлення за еталоном','languages_count':'мова(и)','discrepancy':'✗ РОЗБІЖНІСТЬ:','status_published_short':'1 (опубліковано)','status_hidden_short':'0 (приховано)','chars_count':'символів','import_complete':'Імпорт коллекций завершено','fetch_error':'✗ Помилка','verification_warn':'⚠ Дані збережено, але довжина у відповіді API не збігається (можливі відмінності у форматуванні)','lang_ru':'Російська','lang_en':'Англійська','lang_ua':'Українська','api_docs':'API Docs','version':'v2.0','ref_lang_be':'Білоруська (be)','ref_lang_en':'Англійська (en)','ref_lang_ru':'Російська (ru)','ref_lang_ua':'Українська (ua)','ref_lang_pl':'Польська (pl)','date_mode_meta':'З мета-даних (дата з кожної статті)','date_mode_fixed':'Одна дата для всіх коллекций','date_mode_offset':'Зміщення дат (+N днів на статтю)','planned_notset':'— не вказано (з мета-даних)','planned_0':'0 — не відкладена','planned_1':'1 — відкладена публікація','status_mode_meta':'З мета-даних (статус з кожної статті)','status_mode_override':'Перевизначити для всіх коллекций','date_mode_label':'📅 Режим дати публікації','title':'Керування колекціями магазину — Boostore.pro','fix_import_title':'🔧 Виправлення за еталоном','fix_import_desc':'Синхронізувати поля з еталонною колекцією (по slug) після збереження','ref_lang_label':'🌐 Мова еталонної колекції','export_fields_label':'📋 Поля для експорту','export_collection_id_label':'ID колекції','export_product_id_label':'ID товару','import_only_named':'Тільки з назвою','export_collection_name_label':'Ім\'я колекції'}};
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});document.querySelectorAll('[data-i18n-placeholder]').forEach(function(el){var key=el.getAttribute('data-i18n-placeholder');if(_t[l]&&_t[l][key]!==undefined)el.placeholder=_t[l][key];});}
if(_lang!='ru'){document.addEventListener('DOMContentLoaded',function(){applyLang(_lang);});}
document.addEventListener('DOMContentLoaded',function(){var ls=document.getElementById('lang_switcher');if(ls){ls.value=_lang;ls.addEventListener('change',function(){applyLang(this.value);});}});
</script>
</div></body></html>
<?php exit;
// ===================================================================
// _get-shop_collections_categories.php — END
// ===================================================================
endif;

// ===================================================================
// _update-shop_collections_categories.php — ИМПОРТ (отправка коллекций на API)
// ===================================================================
if ($action === 'update'):
// GET overrides for per-export parameters (take from GET, fallback to config)
$expDateMode   = $_GET['date_mode'] ?? $DATE_MODE;
$expDateFixed  = $_GET['date_fixed'] ?? $DATE_FIXED;
$expDateOffsetBase = $_GET['date_offset_base'] ?? $DATE_OFFSET_BASE;
$expDateOffsetDays = isset($_GET['date_offset_days']) ? (int)$_GET['date_offset_days'] : (int)($DATE_OFFSET_DAYS ?? 1);
$expStatusMode = $_GET['status_mode'] ?? $STATUS_MODE;
$expStatusOverride = isset($_GET['status_override']) ? (int)$_GET['status_override'] : (int)($STATUS_OVERRIDE ?? 1);
$expProductId = !empty($_GET['export_collection_id']) && $_GET['export_collection_id'] !== '0';
$expCollectionId = !empty($_GET['export_collection_id']) && $_GET['export_collection_id'] !== '0';
$expCollectionName = empty($_GET['export_collection_name']) || $_GET['export_collection_name'] !== '0'; // по умолчанию true
$expMode = $_GET['export_mode'] ?? 'all'; // all, insert, update
$exportTextOnly = !empty($_GET['export_text_only']);

function dateToTimestamp(?string $d):?int{if(!$d)return null;if(ctype_digit($d))return(int)$d;try{return(new DateTimeImmutable($d))->getTimestamp();}catch(Exception$e){return null;}}

// Pre-scan for offset mode: build slug→date map
$slugDateMap = [];
if ($expDateMode === 'offset' && $expDateOffsetBase !== '' && $expDateOffsetDays > 0) {
    $baseTs = dateToTimestamp($expDateOffsetBase);
    if ($baseTs) {
        $prodDir2 = $COLLECTIONS_DIR;
        if (is_dir($prodDir2)) {
            $rdi2 = new RecursiveDirectoryIterator($prodDir2,RecursiveDirectoryIterator::SKIP_DOTS);
            $rii2 = new RecursiveIteratorIterator($rdi2);
            $seen = [];
            $idx = 0;
            foreach ($rii2 as $f2) {
                if ($f2->isFile() && strtolower($f2->getExtension())==='html') {
                    $h2 = @file_get_contents($f2->getPathname());
                    if ($h2 === false) continue;
                    preg_match('/<meta\s+name=["\']slug["\']\s+content=["\'](.*?)["\']/is', $h2, $sm);
                    $s = trim($sm[1] ?? '');
                    if ($s === '' || isset($seen[$s])) continue;
                    $seen[$s] = true;
                    $slugDateMap[$s] = $baseTs + $idx * $expDateOffsetDays * 86400;
                    $idx++;
                }
            }
        }
    }
}
$dryRun = isset($_GET['dry-run']);
?><!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title>Экспорт коллекций — Boostore.pro</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#1a1a2e;color:#e0e0e0;font-family:'Segoe UI',system-ui,sans-serif;padding:30px}.wrap{max-width:1200px;margin:0 auto}
h1{font-size:22px;color:#00d4ff;margin-bottom:5px}.meta-info{color:#888;font-size:13px;margin-bottom:25px}a{color:#00d4ff}.btn:hover{color:#fff;text-decoration:none}
.article{background:#16213e;border:1px solid #0f3460;border-radius:10px;margin-bottom:20px}
.article-header{background:#0f3460;padding:12px 18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.article-header .num{font-weight:700;color:#00d4ff}.article-header .file{color:#e0e0e0;font-size:14px}
.article-body{padding:15px 18px;min-width:0}.article-body details{margin-bottom:12px;overflow-x:auto}
.article-body summary{cursor:pointer;font-weight:600;color:#00d4ff;padding:6px 0}
.article-body summary:hover{color:#4dc9f6}
.meta-grid{display:grid;grid-template-columns:auto 1fr;gap:4px 18px;font-size:13px;padding:8px 0}
.meta-grid .key{color:#888;white-space:nowrap}.meta-grid .val{color:#e0e0e0;word-break:break-all}
.success{color:#4caf50;font-weight:600}.error{color:#f44336;font-weight:600}.warning{color:#ff9800}
code,pre,textarea{font-family:'Cascadia Code','Fira Code','Consolas',monospace;font-size:12px}
textarea{width:100%;max-width:100%;min-height:200px;background:#0d1b2a;color:#e0e0e0;border:1px solid #0f3460;border-radius:6px;padding:10px;resize:vertical}
textarea:focus{outline:none;border-color:#00d4ff}
.resp-block{background:#0d1b2a;border:1px solid #0f3460;border-radius:6px;padding:12px;font-size:12px;white-space:pre-wrap;overflow-x:auto}
.result-ok{border-left:4px solid #4caf50;padding-left:12px}.result-fail{border-left:4px solid #f44336;padding-left:12px}
.result-warn{border-left:4px solid #ff9800;padding-left:12px}.result-skip{border-left:4px solid #555;padding-left:12px}
.footer{text-align:center;padding:20px;color:#555;font-size:13px}.footer strong{color:#e0e0e0}
.diff{background:#0d1b2a;border:1px solid #0f3460;border-radius:6px;padding:10px;font-size:12px;margin-top:8px}
.diff .expected{color:#ff9800}.diff .got{color:#f44336}.diff-pos{color:#888;margin:4px 0}.lost{color:#f44336;font-weight:600}
.na{color:#555;font-style:italic}.inline-code{background:#0d1b2a;padding:1px 5px;border-radius:3px;font-size:12px}
hr{border:0;border-top:1px solid #0f3460;margin:12px 0}
.plaque{background:#0f3460;border:1px solid #00d4ff;border-radius:8px;padding:12px 18px;margin-bottom:16px;font-size:14px;color:#e0e0e0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}.plaque a{color:#00d4ff;text-decoration:none}.plaque a:hover{color:#4dc9f6}
.card{background:#16213e;border:1px solid #0f3460;border-radius:10px;margin-bottom:20px;overflow:hidden}.card .card-header{background:#0f3460;padding:12px 18px;font-weight:700;color:#00d4ff;font-size:15px}.card .card-body{padding:15px 18px}
</style></head>
<body><div class="wrap"><?php echo $header; ?>
<div class="plaque">
<span data-i18n="plaque_export">▸ <strong>Настройки экспорта</strong> — отправка коллекций на Boostore.pro</span>
<span><a href="?site=<?=urlencode($currentSite)?>" style="padding:6px 16px;background:transparent;color:#00d4ff;border:1px solid #00d4ff;border-radius:4px;text-decoration:none;font-size:13px;" data-i18n="back_home">← Назад</a></span>
</div>
<?php if($dryRun):?><div style="margin-bottom:12px;font-size:13px;color:#ff9800;" data-i18n="dryrun_warn">⚡ DRY RUN — запросы не отправляются</div><?php endif;?>
<?php
// --- Step 1: Search filters ---
$batchLimit = isset($_GET['batch']) ? max(1, (int)$_GET['batch']) : (int)($SEND_BATCH_LIMIT ?? 200);
$searchFilterRaw = isset($_GET['s']) ? (is_array($_GET['s']) ? $_GET['s'] : [trim($_GET['s'] ?? '')]) : [];
$searchFilter = array_filter((array)$searchFilterRaw, function($v) { return trim($v) !== ''; });
$searchFilter = array_values((array)$searchFilter);
// Show step 2 if confirm is set, otherwise show step 1 form
if (!isset($_GET['confirm'])): ?>
<script>
var _lang='ru';try{_lang=localStorage.getItem('boostore_lang')||navigator.language.slice(0,2);localStorage.setItem('boostore_lang',_lang);}catch(e){}if(!['ru','en','ua'].includes(_lang))_lang='ru';
var _t={ru:{'home':'🏠 Главная','site_label':'Сайт:','entity_name':'Коллекции','import':'📥 Импорт','export':'📤 Экспорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Создано:','updated_label':'📝 Обновлено:','errors_label':'❌ Ошибок:','skipped_exist_label':'⏭ Пропущено (существуют):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Экспортировать только текст','file_selection':'📁 Выбор файлов','filter_name':'Фильтр по имени (slug)','batch_label':'Отправить за 1 раз','lang_label':'Язык','step_forward':'➡ ДАЛЕЕ','back_home':'← Назад','dry_run_label':'Dry run','plaque_export':'▸ <strong>Настройки экспорта</strong> — отправка коллекций на Boostore.pro','dryrun_warn':'⚡ DRY RUN — запросы не отправляются','btn_more':'+ ЕЩЕ','btn_more_multi':'📋 ЕЩЕ НЕСКОЛЬКО','prompt_values':'Введите значения (каждая строка — отдельное поле):','search_placeholder':'часть имени, например: shopify','all_languages':'все','date_mode_meta':'Из мета-данных (дата из каждого коллекции)','date_mode_fixed':'Одна дата для всех коллекций','date_mode_offset':'Смещение дат (+N дней на коллекция)','date_fixed_label':'📅 Фиксированная дата','date_offset_label':'📅 Базовая дата','date_offset_days':'+ дней на статью','override_planned':'📅 Переопределить planned','planned_notset':'— не указано (из мета-данных)','planned_0':'0 — не отложенная','planned_1':'1 — отложенная публикация','status_mode_label':'🔒 Статус доступа (status)','status_mode_meta':'Из мета-данных (статус из каждого коллекции)','status_mode_override':'Переопределить для всех коллекций','status_value_label':'Значение статуса','status_published':'1 — опубликовано (доступно)','status_hidden':'0 — скрыто (недоступно)','mode_label':'🔄 Режим экспорта','mode_all':'Добавление + обновление','mode_insert':'Только добавление новых','mode_update':'Только обновление существующих','fix_export_title':'🔧 Исправление при экспорте','fix_export_desc':'Синхронизировать поля с эталонной коллекцией (по slug) при экспорте','date_mode_label':'📅 Режим даты публикации','title':'Управление коллекциями магазина — Boostore.pro','fix_import_title':'🔧 Исправление по эталону','fix_import_desc':'Синхронизировать поля с эталонной коллекцией (по slug) после сохранения','ref_lang_label':'🌐 Язык эталонной коллекции','export_fields_label':'📋 Поля для экспорта','export_collection_id_label':'ID колекції','export_product_id_label':'Product ID','import_only_named':'Только с именем','export_collection_name_label':'Имя коллекции'},en:{'home':'🏠 Home','site_label':'Site:','entity_name':'Collections','import':'📥 Import','export':'📤 Export','completed':'✓ Completed','result_label':'✓ Result','created_label':'✅ Created:','updated_label':'📝 Updated:','errors_label':'❌ Errors:','skipped_exist_label':'⏭ Skipped (exist):','skipped_exist_label2':'⏭ Skipped','export_text_only':'📝 Export text only','file_selection':'📁 File selection','filter_name':'Filter by name (slug)','batch_label':'Send per run','lang_label':'Language','id_min_label':'ID >','id_max_label':'ID <','id_min_placeholder':'1000','id_max_placeholder':'5000','step_forward':'➡ NEXT','back_home':'← Back','dry_run_label':'Dry run','plaque_export':'▸ <strong>Export Settings</strong> — sending products to Boostore.pro','dryrun_warn':'⚡ DRY RUN — no API calls sent','btn_more':'+ MORE','btn_more_multi':'📋 ADD MULTIPLE','prompt_values':'Enter values (each line is a separate field):','search_placeholder':'part of name, e.g.: shopify','all_languages':'all','date_mode_meta':'From meta-data (date from each article)','date_mode_fixed':'Single date for all products','date_mode_offset':'Date offset (+N days per article)','date_fixed_label':'📅 Fixed Date','date_offset_label':'📅 Base Date','date_offset_days':'+ days per article','override_planned':'📅 Override planned','planned_notset':'— not set (from meta-data)','planned_0':'0 — not planned','planned_1':'1 — planned publishing','status_mode_label':'🔒 Access Status (status)','status_mode_meta':'From meta-data (status from each article)','status_mode_override':'Override for all products','status_value_label':'Status Value','status_published':'1 — published (public)','status_hidden':'0 — hidden (private)','mode_label':'🔄 Export mode','mode_all':'Add + Update','mode_insert':'Add new only','mode_update':'Update existing only','fix_export_title':'🔧 Fix on Export','fix_export_desc':'Sync fields with reference collection (by slug) on export','date_mode_label':'📅 Publication Date Mode','title':'Collection Management — Boostore.pro','fix_import_title':'🔧 Fix by Reference','fix_import_desc':'Sync fields with reference collection (by slug) after save','ref_lang_label':'🌐 Reference Language','export_fields_label':'📋 Fields to export','export_collection_id_label':'Collection ID','export_product_id_label':'Product ID','import_only_named':'Named only','export_collection_name_label':'Collection name'},ua:{'home':'🏠 Головна','site_label':'Сайт:','entity_name':'Колекції','import':'📥 Імпорт','export':'📤 Експорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Створено:','updated_label':'📝 Оновлено:','errors_label':'❌ Помилок:','skipped_exist_label':'⏭ Пропущено (існують):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Експортувати тільки текст','file_selection':'📁 Вибір файлів','filter_name':'Фільтр за іменем (slug)','batch_label':'Відправити за 1 раз','lang_label':'Мова','step_forward':'➡ ДАЛІ','back_home':'← На головну','dry_run_label':'Dry run','plaque_export':'▸ <strong>Налаштування експорту</strong> — відправлення коллекций на Boostore.pro','dryrun_warn':'⚡ DRY RUN — запити не надсилаються','btn_more':'+ ЩЕ','btn_more_multi':'📋 ДОДАТИ КІЛЬКА','prompt_values':'Введіть значення (кожен рядок — окреме поле):','search_placeholder':'частина імені, наприклад: shopify','all_languages':'всі','date_mode_meta':'З мета-даних (дата з кожної статті)','date_mode_fixed':'Одна дата для всіх коллекций','date_mode_offset':'Зміщення дат (+N днів на статтю)','date_fixed_label':'📅 Фіксована дата','date_offset_label':'📅 Базова дата','date_offset_days':'+ днів на статтю','override_planned':'📅 Перевизначити planned','planned_notset':'— не вказано (з мета-даних)','planned_0':'0 — не відкладена','planned_1':'1 — відкладена публікація','status_mode_label':'🔒 Статус доступу (status)','status_mode_meta':'З мета-даних (статус з кожної статті)','status_mode_override':'Перевизначити для всіх коллекций','status_value_label':'Значення статусу','status_published':'1 — опубліковано (доступно)','status_hidden':'0 — приховано (недоступно)','mode_label':'🔄 Режим експорту','mode_all':'Додавання + оновлення','mode_insert':'Тільки додавання нових','mode_update':'Тільки оновлення існуючих','fix_export_title':'🔧 Виправлення при експорті','fix_export_desc':'Синхронізувати поля з еталонною колекцією (по slug) при експорті','date_mode_label':'📅 Режим дати публікації','title':'Керування колекціями магазину — Boostore.pro','fix_import_title':'🔧 Виправлення за еталоном','fix_import_desc':'Синхронізувати поля з еталонною колекцією (по slug) після збереження','ref_lang_label':'🌐 Мова еталонної колекції','export_fields_label':'📋 Поля для експорту','export_collection_id_label':'ID колекції','export_product_id_label':'ID товару','import_only_named':'Тільки з назвою','export_collection_name_label':'Ім\'я колекції'}};
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});document.querySelectorAll('[data-i18n-placeholder]').forEach(function(el){var key=el.getAttribute('data-i18n-placeholder');if(_t[l]&&_t[l][key]!==undefined)el.placeholder=_t[l][key];});}
if(_lang!='ru'){document.addEventListener('DOMContentLoaded',function(){applyLang(_lang);});}
document.addEventListener('DOMContentLoaded',function(){var ls=document.getElementById('lang_switcher');if(ls){ls.value=_lang;ls.addEventListener('change',function(){applyLang(this.value);});}});
</script>
<div class="card" style="padding:18px;">
<form method="get" action="?" id="export-step1" style="display:flex;flex-direction:column;gap:12px;">
  <input type="hidden" name="action" value="update">
  <input type="hidden" name="confirm" value="1">
  <input type="hidden" name="step" value="2">
  <input type="hidden" name="site" value="<?=htmlspecialchars($currentSite)?>">
  <div>
    <label style="color:#888;font-size:13px;display:block;margin-bottom:6px;" data-i18n="filter_name">Фильтр по имени (slug)</label>
    <div id="search-fields-upd"><input type="text" name="s[]" value="<?=htmlspecialchars($searchFilter ? $searchFilter[0] : '')?>" placeholder="часть имени, например: shopify" data-i18n-placeholder="search_placeholder" style="margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box;"></div>
    <?php for($sfi=1;$sfi<count($searchFilter);$sfi++):?><input type="hidden" name="s[]" value="<?=htmlspecialchars($searchFilter[$sfi])?>"><?php endfor;?>
    <button type="button" onclick="var p=document.getElementById('search-fields-upd');var inp=document.createElement('input');inp.type='text';inp.name='s[]';inp.placeholder='часть имени';inp.setAttribute('data-i18n-placeholder','search_placeholder');inp.style.cssText='display:block;margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box';p.appendChild(inp);" style="padding:2px 10px;background:transparent;color:#00d4ff;border:1px dashed #00d4ff;border-radius:4px;cursor:pointer;font-size:11px;margin-top:2px;" data-i18n="btn_more">+ ЕЩЕ</button>
    <button type="button" onclick="var t=prompt(_t[_lang]['prompt_values'] || 'Введите значения (каждая строка — отдельное поле):');if(t){var p=document.getElementById('search-fields-upd');var lines=t.split('\n');for(var i=0;i<lines.length;i++){var v=lines[i].trim();if(v==='')continue;var inp=document.createElement('input');inp.type='text';inp.name='s[]';inp.value=v;inp.placeholder='часть имени';inp.setAttribute('data-i18n-placeholder','search_placeholder');inp.style.cssText='display:block;margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box';p.appendChild(inp);}}" style="padding:2px 10px;background:transparent;color:#ff9800;border:1px dashed #ff9800;border-radius:4px;cursor:pointer;font-size:11px;margin-top:2px;margin-left:4px;" data-i18n="btn_more_multi">📋 ЕЩЕ НЕСКОЛЬКО</button>
  </div>
  <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
    <div>
      <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="batch_label">Отправить за 1 раз</label>
      <input type="number" name="batch" value="<?=$batchLimit?>" min="1" max="5000" style="width:100px;padding:6px 8px;border:1px solid #0f3460;border-radius:4px;background:#16213e;color:#e0e0e0;">
    </div>
    <div>
      <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="lang_label">Язык</label>
      <select name="lang" style="padding:6px 8px;border:1px solid #0f3460;border-radius:4px;background:#16213e;color:#e0e0e0;">
        <option value="" data-i18n="all_languages">все</option>
        <option value="ru"<?=($_GET['lang']??'')==='ru'?' selected':''?> data-i18n="lang_ru">Русский</option>
        <option value="ua"<?=($_GET['lang']??'')==='ua'?' selected':''?> data-i18n="lang_ua">Українська</option>
        <option value="en"<?=($_GET['lang']??'')==='en'?' selected':''?> data-i18n="lang_en">English</option>
        <option value="pl"<?=($_GET['lang']??'')==='pl'?' selected':''?> data-i18n="lang_pl">Polski</option>
        <option value="de"<?=($_GET['lang']??'')==='de'?' selected':''?> data-i18n="lang_de">Deutsch</option>
        <option value="fr"<?=($_GET['lang']??'')==='fr'?' selected':''?> data-i18n="lang_fr">Français</option>
        <option value="es"<?=($_GET['lang']??'')==='es'?' selected':''?> data-i18n="lang_es">Español</option>
        <option value="it"<?=($_GET['lang']??'')==='it'?' selected':''?> data-i18n="lang_it">Italiano</option>
        <option value="kk"<?=($_GET['lang']??'')==='kk'?' selected':''?> data-i18n="lang_kk">Қазақ</option>
        <option value="be"<?=($_GET['lang']??'')==='be'?' selected':''?> data-i18n="lang_be">Беларуская</option>
      </select>
    </div>
    <div style="margin-left:auto;">
      <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:#888;cursor:pointer;margin-right:8px;" data-i18n="dry_run_label"><input type="checkbox" name="dry-run" value="1"<?=isset($_GET['dry-run'])?' checked':''?>> Dry run</label>
    </div>
  </div>
  <hr style="border-color:#0f3460;margin:4px 0;">
  <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
    <div>
      <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="date_mode_label">📅 Режим даты публикации</label>
      <select name="date_mode" id="exp_date_mode" onchange="toggleExpDateFields()" style="padding:6px 8px;border:1px solid #0f3460;border-radius:4px;background:#16213e;color:#e0e0e0;">
        <option value=""<?=($_GET['date_mode']??$DATE_MODE)===''?' selected':''?> data-i18n="date_mode_meta">Из мета-данных (дата из каждого коллекции)</option>
        <option value="fixed"<?=($_GET['date_mode']??$DATE_MODE)==='fixed'?' selected':''?> data-i18n="date_mode_fixed">Одна дата для всех коллекций</option>
        <option value="offset"<?=($_GET['date_mode']??$DATE_MODE)==='offset'?' selected':''?> data-i18n="date_mode_offset">Смещение дат (+N дней на коллекция)</option>
      </select>
    </div>
    <div id="exp_date_fixed_block" style="display:<?=(($_GET['date_mode']??$DATE_MODE)==='fixed')?'block':'none'?>;">
      <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="date_fixed_label">📅 Фиксированная дата</label>
      <input type="date" name="date_fixed" value="<?=htmlspecialchars($_GET['date_fixed']??$DATE_FIXED?:date('Y-m-d'))?>" style="padding:6px 8px;border:1px solid #0f3460;border-radius:4px;background:#16213e;color:#e0e0e0;">
    </div>
    <div id="exp_date_offset_block" style="display:<?=(($_GET['date_mode']??$DATE_MODE)==='offset')?'block':'none'?>;">
      <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="date_offset_label">📅 Базовая дата</label>
      <input type="date" name="date_offset_base" value="<?=htmlspecialchars($_GET['date_offset_base']??$DATE_OFFSET_BASE?:date('Y-m-d'))?>" style="padding:6px 8px;border:1px solid #0f3460;border-radius:4px;background:#16213e;color:#e0e0e0;width:140px;">
    </div>
    <div id="exp_date_offset_days_block" style="display:<?=(($_GET['date_mode']??$DATE_MODE)==='offset')?'block':'none'?>;">
      <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="date_offset_days">+ дней на коллекция</label>
      <input type="number" name="date_offset_days" value="<?=(int)($_GET['date_offset_days']??$DATE_OFFSET_DAYS??1)?>" min="0" max="365" style="width:70px;padding:6px 8px;border:1px solid #0f3460;border-radius:4px;background:#16213e;color:#e0e0e0;">
    </div>
    <div>
      <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="status_mode_label">🔒 Статус доступа (status)</label>
      <select name="status_mode" id="exp_status_mode" onchange="toggleExpStatusFields()" style="padding:6px 8px;border:1px solid #0f3460;border-radius:4px;background:#16213e;color:#e0e0e0;">
        <option value=""<?=($_GET['status_mode']??$STATUS_MODE)===''?' selected':''?> data-i18n="status_mode_meta">Из мета-данных (статус из каждого коллекции)</option>
        <option value="override"<?=($_GET['status_mode']??$STATUS_MODE)==='override'?' selected':''?> data-i18n="status_mode_override">Переопределить для всех коллекций</option>
      </select>
    </div>
    <div id="exp_status_override_block" style="display:<?=(($_GET['status_mode']??$STATUS_MODE)==='override')?'block':'none'?>;">
      <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="status_value_label">Значение статуса</label>
      <select name="status_override" style="padding:6px 8px;border:1px solid #0f3460;border-radius:4px;background:#16213e;color:#e0e0e0;">
        <option value="1"<?=(($_GET['status_override']??$STATUS_OVERRIDE)==1)?' selected':''?> data-i18n="status_published">1 — опубликовано (доступно)</option>
        <option value="0"<?=(($_GET['status_override']??$STATUS_OVERRIDE)===0)?' selected':''?> data-i18n="status_hidden">0 — скрыто (недоступно)</option>
      </select>
    </div>
  </div>
  <hr style="border-color:#0f3460;margin:4px 0;">
  <div>
    <label style="font-size:11px;color:#888;display:block;margin-bottom:6px;" data-i18n="fix_export_title">🔧 Исправление по эталону</label>
    <div style="font-size:11px;color:#888;margin-bottom:8px;" data-i18n="fix_export_desc">Синхронизировать поля с эталонной производителем (по slug) при экспорте</div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:8px;">
      <div>
        <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="ref_lang_label">🌐 Язык эталонной коллекции</label>
        <select name="export_ref_lang" style="padding:6px 8px;border:1px solid #0f3460;border-radius:4px;background:#16213e;color:#e0e0e0;">
          <option value="be"<?=(($_GET['export_ref_lang']??$REFERENCE_LANG)??'pl')==='be'?' selected':''?>>Белорусский (be)</option>
          <option value="de"<?=(($_GET['export_ref_lang']??$REFERENCE_LANG)??'pl')==='de'?' selected':''?>>Немецкий (de)</option>
          <option value="en"<?=(($_GET['export_ref_lang']??$REFERENCE_LANG)??'pl')==='en'?' selected':''?>>English (en)</option>
          <option value="es"<?=(($_GET['export_ref_lang']??$REFERENCE_LANG)??'pl')==='es'?' selected':''?>>Испанский (es)</option>
          <option value="fr"<?=(($_GET['export_ref_lang']??$REFERENCE_LANG)??'pl')==='fr'?' selected':''?>>Français (fr)</option>
          <option value="it"<?=(($_GET['export_ref_lang']??$REFERENCE_LANG)??'pl')==='it'?' selected':''?>>Italiano (it)</option>
          <option value="kk"<?=(($_GET['export_ref_lang']??$REFERENCE_LANG)??'pl')==='kk'?' selected':''?>>Қазақ (kk)</option>
          <option value="pl"<?=(($_GET['export_ref_lang']??$REFERENCE_LANG)??'pl')==='pl'?' selected':''?>>Polski (pl)</option>
          <option value="ru"<?=(($_GET['export_ref_lang']??$REFERENCE_LANG)??'pl')==='ru'?' selected':''?>>Русский (ru)</option>
          <option value="ua"<?=(($_GET['export_ref_lang']??$REFERENCE_LANG)??'pl')==='ua'?' selected':''?>>Українська (ua)</option>
        </select>
      </div>
    </div>
    <div style="display:flex;gap:16px;flex-wrap:wrap;">
      <label style="font-size:12px;color:#ccc;cursor:pointer;display:flex;align-items:center;gap:3px;">
        <input type="hidden" name="export_fix_multilangid" value="0">
        <input type="checkbox" name="export_fix_multilangid" value="1"<?=(isset($_GET['export_fix_multilangid'])?!empty($_GET['export_fix_multilangid']):$FIX_MULTILANGID)?' checked':''?>> multilangid
      </label>

      <label style="font-size:12px;color:#ccc;cursor:pointer;display:flex;align-items:center;gap:3px;">
        <input type="hidden" name="export_fix_status" value="0">
        <input type="checkbox" name="export_fix_status" value="1"<?=(isset($_GET['export_fix_status'])?!empty($_GET['export_fix_status']):$FIX_STATUS)?' checked':''?>> status
      </label>
      <label style="font-size:12px;color:#ccc;cursor:pointer;display:flex;align-items:center;gap:3px;">
        <input type="hidden" name="export_fix_datestamp" value="0">
        <input type="checkbox" name="export_fix_datestamp" value="1"<?=(isset($_GET['export_fix_datestamp'])?!empty($_GET['export_fix_datestamp']):$FIX_DATESTAMP)?' checked':''?>> datestamp
      </label>
    </div>
  </div>
  <hr style="border-color:#0f3460;margin:4px 0;">
  <div>
    <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="export_fields_label">📋 Поля для экспорта</label>
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
      <label style="font-size:12px;color:#ccc;cursor:pointer;display:flex;align-items:center;gap:3px;">
        <input type="hidden" name="export_product_id" value="0">
        <input type="checkbox" name="export_product_id" value="1"<?=!empty($_GET['export_collection_id'])?' checked':''?> data-i18n="export_product_id"> <span data-i18n="export_product_id_label">ID коллекции</span>
      </label>
      <label style="font-size:12px;color:#ccc;cursor:pointer;display:flex;align-items:center;gap:3px;">
        <input type="hidden" name="export_collection_id" value="0">
        <input type="checkbox" name="export_collection_id" value="1"<?=!empty($_GET['export_collection_id'])?' checked':''?> data-i18n="export_collection_id"> <span data-i18n="export_collection_id_label">ID коллекции</span>
      </label>
      <label style="font-size:12px;color:#ccc;cursor:pointer;display:flex;align-items:center;gap:3px;">
        <input type="hidden" name="export_collection_name" value="0">
        <input type="checkbox" name="export_collection_name" value="1"<?=(!isset($_GET['export_collection_name'])||$_GET['export_collection_name']!=='0')?' checked':''?> data-i18n="export_collection_name"> <span data-i18n="export_collection_name_label">Имя коллекции</span>
      </label>
    </div>
  </div>
  <hr style="border-color:#0f3460;margin:4px 0;">
  <div>
    <label style="font-size:11px;color:#888;display:block;margin-bottom:6px;" data-i18n="mode_label">🔄 Режим экспорта</label>
    <div style="display:flex;gap:16px;flex-wrap:wrap;">
      <label style="font-size:13px;color:#e0e0e0;cursor:pointer;display:flex;align-items:center;gap:4px;">
        <input type="radio" name="export_mode" value="all"<?=(($_GET['export_mode']??'all')==='all'?' checked':'')?>>
        <span data-i18n="mode_all">Добавление + обновление</span>
      </label>
      <label style="font-size:13px;color:#e0e0e0;cursor:pointer;display:flex;align-items:center;gap:4px;">
        <input type="radio" name="export_mode" value="insert"<?=(($_GET['export_mode']??'')==='insert'?' checked':'')?>>
        <span data-i18n="mode_insert">Только добавление новых</span>
      </label>
      <label style="font-size:13px;color:#e0e0e0;cursor:pointer;display:flex;align-items:center;gap:4px;">
        <input type="radio" name="export_mode" value="update"<?=(($_GET['export_mode']??'')==='update'?' checked':'')?>>
        <span data-i18n="mode_update">Только обновление существующих</span>
      </label>
    </div>
  </div>
  <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
    <button type="submit" style="padding:10px 24px;background:#00d4ff;color:#1a1a2e;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;" data-i18n="step_forward">➡ ДАЛЕЕ</button>
    <a href="?site=<?=urlencode($currentSite)?>" style="padding:7px 18px;background:transparent;color:#888;border:1px solid #555;border-radius:4px;text-decoration:none;font-size:13px;" data-i18n="back_home">← Назад</a>
  </div>
  <script>
  function toggleExpDateFields(){
    var m=document.getElementById('exp_date_mode').value;
    document.getElementById('exp_date_fixed_block').style.display=(m==='fixed'?'block':'none');
    document.getElementById('exp_date_offset_block').style.display=(m==='offset'?'block':'none');
    document.getElementById('exp_date_offset_days_block').style.display=(m==='offset'?'block':'none');
  }
  function toggleExpStatusFields(){
    document.getElementById('exp_status_override_block').style.display=(document.getElementById('exp_status_mode').value==='override'?'block':'none');
  }
  </script>
</form>
</div>
<?php exit; endif;

// === Step 2: File selection (show matching files with checkboxes) ===
if (isset($_GET['step']) && $_GET['step'] === '2'):
$prodDir2 = $COLLECTIONS_DIR;
$htmlFiles2 = [];
if (is_dir($prodDir2)) {
    $rdi2 = new RecursiveDirectoryIterator($prodDir2, RecursiveDirectoryIterator::SKIP_DOTS);
    $rii2 = new RecursiveIteratorIterator($rdi2);
    foreach ($rii2 as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'html') {
            $htmlFiles2[] = $f->getPathname();
        }
    }
    sort($htmlFiles2);
}
$htmlFiles2 = array_filter($htmlFiles2, function($p) { return !in_array(basename($p), ['index.php','_setting_shop_collections_categories.inc']); });
$htmlFiles2 = array_values($htmlFiles2);
// Apply language filter
$langFilter2 = $_GET['lang'] ?? '';
if ($langFilter2 !== '') {
    $htmlFiles2 = array_filter($htmlFiles2, function($p) use ($langFilter2) {
        $h = @file_get_contents($p);
        if ($h === false) return false;
        preg_match('/<meta\s+name=["\']lang["\']\s+content=["\'](.*?)["\']/is', $h, $m);
        return trim($m[1] ?? '') === $langFilter2;
    });
    $htmlFiles2 = array_values($htmlFiles2);
}
// Apply search filter (multi-term)
$searchFilter2 = $searchFilter;
if (!empty($searchFilter2)) {
    $htmlFiles2 = array_filter($htmlFiles2, function($fp) use ($searchFilter2) {
        $bn = pathinfo($fp, PATHINFO_FILENAME);
        $fields = [$bn];
        $h = @file_get_contents($fp);
        if ($h !== false) {
            preg_match_all('/<meta\s+name=["\'](slug|name|title)["\']\s+content=["\'](.*?)["\']/is', $h, $mm, PREG_SET_ORDER);
            foreach ($mm as $mv) $fields[] = $mv[2];
        }
        foreach ($searchFilter2 as $term) {
            $t = trim($term);
            if ($t === '') continue;
            foreach ($fields as $hay) {
                if (mb_stripos($hay, $t) !== false) return true;
            }
        }
        return false;
    });
    $htmlFiles2 = array_values($htmlFiles2);
}
$totalFiles2 = count($htmlFiles2); ?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title data-i18n="step2_title">Экспорт коллекций — выбор файлов</title>
<script>var _lang='ru';try{_lang=localStorage.getItem('boostore_lang')||navigator.language.slice(0,2);localStorage.setItem('boostore_lang',_lang);}catch(e){}if(!['ru','en','ua'].includes(_lang))_lang='ru';
var _t={ru:{'home':'🏠 Главная','site_label':'Сайт:','entity_name':'Коллекции','import':'📥 Импорт','export':'📤 Экспорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Создано:','updated_label':'📝 Обновлено:','errors_label':'❌ Ошибок:','skipped_exist_label':'⏭ Пропущено (существуют):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Экспортировать только текст','file_selection':'📁 Выбор файлов','step2_title':'Экспорт коллекций — выбор файлов','step2_header':'▸ <strong>Шаг 2</strong> — выберите файлы для экспорта на сайт','back_to_filters':'← Назад к фильтрам','no_files_found':'Нет файлов, соответствующих критериям поиска','files_found':'Найдено файлов:','select_all':'☑ ВЫДЕЛИТЬ ВСЕ','deselect_all':'☐ СНЯТЬ ВСЕ','export_selected':'📤 ЭКСПОРТИРОВАТЬ ВЫДЕЛЕННЫЕ','date_mode_label':'📅 Режим даты публикации','title':'Управление коллекциями магазина — Boostore.pro','fix_import_title':'🔧 Исправление по эталону','fix_import_desc':'Синхронизировать поля с эталонной коллекцией (по slug) после сохранения','ref_lang_label':'🌐 Язык эталонной коллекции','export_fields_label':'📋 Поля для экспорта','export_collection_id_label':'ID колекції','export_product_id_label':'Product ID','import_only_named':'Только с именем','export_collection_name_label':'Имя коллекции'},en:{'home':'🏠 Home','site_label':'Site:','entity_name':'Collections','import':'📥 Import','export':'📤 Export','completed':'✓ Completed','result_label':'✓ Result','created_label':'✅ Created:','updated_label':'📝 Updated:','errors_label':'❌ Errors:','skipped_exist_label':'⏭ Skipped (exist):','skipped_exist_label2':'⏭ Skipped','export_text_only':'📝 Export text only','file_selection':'📁 File selection','step2_title':'Export — file selection','step2_header':'▸ <strong>Step 2</strong> — select files to export','back_to_filters':'← Back to filters','no_files_found':'No files matching search criteria','files_found':'Files found:','select_all':'☑ SELECT ALL','deselect_all':'☐ DESELECT ALL','export_selected':'📤 EXPORT SELECTED','date_mode_label':'📅 Publication Date Mode','title':'Collection Management — Boostore.pro','fix_import_title':'🔧 Fix by Reference','fix_import_desc':'Sync fields with reference collection (by slug) after save','ref_lang_label':'🌐 Reference Language','export_fields_label':'📋 Fields to export','export_collection_id_label':'Collection ID','export_product_id_label':'Product ID','import_only_named':'Named only','export_collection_name_label':'Collection name'},ua:{'home':'🏠 Головна','site_label':'Сайт:','entity_name':'Колекції','import':'📥 Імпорт','export':'📤 Експорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Створено:','updated_label':'📝 Оновлено:','errors_label':'❌ Помилок:','skipped_exist_label':'⏭ Пропущено (існують):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Експортувати тільки текст','file_selection':'📁 Вибір файлів','step2_title':'Експорт — вибір файлів','step2_header':'▸ <strong>Крок 2</strong> — виберіть файли для експорту','back_to_filters':'← Назад до фільтрів','no_files_found':'Немає файлів, що відповідають критеріям пошуку','files_found':'Знайдено файлів:','select_all':'☑ ВИДІЛИТИ ВСІ','deselect_all':'☐ ЗНЯТИ ВСІ','export_selected':'📤 ЕКСПОРТУВАТИ ВИДІЛЕНІ','date_mode_label':'📅 Режим дати публікації','title':'Керування колекціями магазину — Boostore.pro','fix_import_title':'🔧 Виправлення за еталоном','fix_import_desc':'Синхронізувати поля з еталонною колекцією (по slug) після збереження','ref_lang_label':'🌐 Мова еталонної колекції','export_fields_label':'📋 Поля для експорту','export_collection_id_label':'ID колекції','export_product_id_label':'ID товару','import_only_named':'Тільки з назвою','export_collection_name_label':'Ім\'я колекції'}};
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});}
if(_lang!='ru'){document.addEventListener('DOMContentLoaded',function(){applyLang(_lang);});}
document.addEventListener('DOMContentLoaded',function(){var ls=document.getElementById('lang_switcher');if(ls){ls.value=_lang;ls.addEventListener('change',function(){applyLang(this.value);});}});
</script>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#1a1a2e;color:#e0e0e0;font-family:'Segoe UI',system-ui,sans-serif;padding:30px}.wrap{max-width:1200px;margin:0 auto}
h1{font-size:22px;color:#00d4ff;margin-bottom:5px}.meta-info{color:#888;font-size:13px;margin-bottom:25px}a{color:#00d4ff;text-decoration:none}a:hover{color:#4dc9f6}
.plaque{background:#0f3460;border:1px solid #00d4ff;border-radius:8px;padding:12px 18px;margin-bottom:16px;font-size:14px;color:#e0e0e0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.plaque a{color:#00d4ff;text-decoration:none}.plaque a:hover{color:#4dc9f6}
.card{background:#16213e;border:1px solid #0f3460;border-radius:10px;margin-bottom:16px;overflow:hidden}
.card-body{padding:15px 18px}
.file-row{display:flex;align-items:center;gap:10px;padding:8px 12px;background:#16213e;border:1px solid #0f3460;border-radius:6px;margin-bottom:5px;cursor:pointer;transition:border-color .2s}.file-row:hover{border-color:#00d4ff}
.file-row input[type="checkbox"]{width:auto;cursor:pointer}
.file-lang{color:#888;font-size:11px;min-width:30px}.file-path{flex:1;font-size:13px;color:#e0e0e0;word-break:break-all}.file-title{color:#888;font-size:12px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.btn{padding:10px 24px;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;transition:all .2s;display:inline-block}.btn:hover{transform:translateY(-1px)}
.btn-primary{background:#00d4ff;color:#1a1a2e}.btn-primary:hover{box-shadow:0 4px 12px rgba(0,212,255,.2)}
.btn-success{background:#4caf50;color:#fff}.btn-success:hover{box-shadow:0 4px 12px rgba(76,175,80,.2)}
.btn-sm{padding:5px 14px;font-size:12px;border-radius:4px;cursor:pointer;border:1px solid;background:transparent}
.btn-sm.select-all{color:#00d4ff;border-color:#00d4ff}.btn-sm.select-all:hover{background:#0f3460}
.btn-sm.deselect-all{color:#888;border-color:#888}.btn-sm.deselect-all:hover{background:#0f3460}
.empty-msg{color:#888;font-size:14px;padding:20px;text-align:center}
.toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:center}
.summary-info{font-size:13px;color:#888;margin-bottom:12px}.summary-info strong{color:#00d4ff}
.coll-row{display:flex;gap:8px;margin-bottom:6px;align-items:center;flex-wrap:wrap}.coll-row input[type="checkbox"]{width:auto;cursor:pointer}.coll-row input[type="text"]{flex:1;min-width:60px}
.footer{text-align:center;padding:20px;color:#555;font-size:13px}
</style>
</head>
<body><div class="wrap"><?php echo $header; ?>
<div class="plaque">
<span data-i18n="step2_header">▸ <strong>Шаг 2</strong> — выберите файлы для экспорта</span>
<span><a href="?action=update&site=<?=urlencode($currentSite)?>" style="padding:6px 16px;background:transparent;color:#00d4ff;border:1px solid #00d4ff;border-radius:4px;text-decoration:none;font-size:13px;" data-i18n="back_to_filters">← Назад к фильтрам</a></span>
</div>
<?php if ($totalFiles2 === 0): ?>
<div class="card"><div class="card-body empty-msg" data-i18n="no_files_found">Нет файлов, соответствующих критериям поиска</div></div>
<?php else: ?>
<div class="summary-info"><span data-i18n="files_found">Найдено файлов:</span> <strong><?=$totalFiles2?></strong></div>
<form method="get" action="?" id="export-step2">
  <input type="hidden" name="action" value="update">
  <input type="hidden" name="confirm" value="1">
  <input type="hidden" name="step" value="3">
  <input type="hidden" name="site" value="<?=htmlspecialchars($_GET['site']??$currentSite)?>">
  <input type="hidden" name="batch" value="<?=(int)($_GET['batch']??$SEND_BATCH_LIMIT??200)?>">
  <input type="hidden" name="export_mode" value="<?=htmlspecialchars($_GET['export_mode']??'all')?>">
  <?php if (isset($_GET['dry-run'])): ?><input type="hidden" name="dry-run" value="1"><?php endif; ?>
  <?php if (($_GET['date_mode']??$DATE_MODE)!==''): ?><input type="hidden" name="date_mode" value="<?=htmlspecialchars($_GET['date_mode']??$DATE_MODE)?>"><?php endif; ?>
  <?php if (($_GET['date_mode']??$DATE_MODE)==='fixed'): ?><input type="hidden" name="date_fixed" value="<?=htmlspecialchars($_GET['date_fixed']??$DATE_FIXED??'')?>"><?php endif; ?>
  <?php if (($_GET['date_mode']??$DATE_MODE)==='offset'): ?><input type="hidden" name="date_offset_base" value="<?=htmlspecialchars($_GET['date_offset_base']??$DATE_OFFSET_BASE??'')?>"><input type="hidden" name="date_offset_days" value="<?=(int)($_GET['date_offset_days']??$DATE_OFFSET_DAYS??1)?>"><?php endif; ?>
  <?php if (($_GET['status_mode']??$STATUS_MODE)==='override'): ?><input type="hidden" name="status_mode" value="override"><input type="hidden" name="status_override" value="<?=(int)($_GET['status_override']??$STATUS_OVERRIDE??1)?>"><?php endif; ?>
  <?php /* forward export field checkboxes */ ?>
  <input type="hidden" name="export_product_id" value="<?=(int)(!empty($_GET['export_collection_id'])?$_GET['export_collection_id']:0)?>">
  <input type="hidden" name="export_collection_id" value="<?=(int)(!empty($_GET['export_collection_id'])?$_GET['export_collection_id']:0)?>">
  <input type="hidden" name="export_collection_name" value="<?=(int)(empty($_GET['export_collection_name'])?1:$_GET['export_collection_name'])?>">
  <?php /* forward export fix checkboxes */ ?>
  <input type="hidden" name="export_ref_lang" value="<?=htmlspecialchars($_GET['export_ref_lang']??$REFERENCE_LANG)?>">
  <input type="hidden" name="export_fix_multilangid" value="<?=(int)(!empty($_GET['export_fix_multilangid'])?1:0)?>">

  <input type="hidden" name="export_fix_status" value="<?=(int)(!empty($_GET['export_fix_status'])?1:0)?>">
  <input type="hidden" name="export_fix_datestamp" value="<?=(int)(!empty($_GET['export_fix_datestamp'])?1:0)?>">
  <input type="hidden" name="export_text_only" value="<?=(int)(!empty($_GET['export_text_only'])?1:0)?>">
  <div class="toolbar">
    <button type="button" class="btn btn-sm select-all" onclick="document.querySelectorAll('.file-chk').forEach(function(c){c.checked=true;})" data-i18n="select_all">☑ ВЫДЕЛИТЬ ВСЕ</button>
    <button type="button" class="btn btn-sm deselect-all" onclick="document.querySelectorAll('.file-chk').forEach(function(c){c.checked=false;})" data-i18n="deselect_all">☐ СНЯТЬ ВСЕ</button>
  </div>
  <div class="card" style="padding:15px 18px;margin-bottom:16px;">
  <h3 style="margin:0 0 10px;font-size:15px;color:#4dc9f6;">📂 Коллекции для фильтрации</h3>
  <p style="font-size:11px;color:#888;margin-bottom:10px;">Если не выбрано ни одной — обрабатываются все коллекции.</p>
  <div id="export-colls">
    <?php $ecIdx=0; foreach($ALLOWED_COLLECTIONS as $ecId=>$ecName): ?>
    <div class="coll-row">
      <input type="checkbox" name="export_cat[<?=$ecIdx?>][checked]" value="1" checked style="width:auto;flex:0 0 20px;">
      <input type="text" name="export_cat[<?=$ecIdx?>][id]" value="<?=$ecId?>" placeholder="ID" style="max-width:80px;padding:5px 8px;">
      <input type="text" name="export_cat[<?=$ecIdx?>][name]" value="<?=htmlspecialchars($ecName)?>" placeholder="имя коллекции" style="padding:5px 8px;">
      <button type="button" onclick="this.parentElement.remove()" style="padding:3px 8px;font-size:11px;background:#f44336;color:#fff;border:none;border-radius:3px;cursor:pointer;">✕</button>
    </div>
    <?php $ecIdx++; endforeach; ?>
  </div>
  <button type="button" onclick="addExportCatRow()" style="padding:4px 12px;background:#0f3460;color:#00d4ff;border:1px solid #00d4ff;border-radius:4px;cursor:pointer;font-size:12px;margin-top:4px;">+ Добавить категорию</button>
  <input type="hidden" name="export_colls_configured" value="1">
  </div>
  <script>
  function addExportCatRow(){var c=document.getElementById('export-colls'),i=c.children.length;var d=document.createElement('div');d.className='coll-row';d.innerHTML="<input type='checkbox' name='export_cat["+i+"][checked]' value='1' checked style='width:auto;flex:0 0 20px;'><input type='text' name='export_cat["+i+"][id]' placeholder='ID' style='max-width:80px;padding:5px 8px;'><input type='text' name='export_cat["+i+"][name]' placeholder='имя коллекции' style='padding:5px 8px;'><button type='button' onclick='this.parentElement.remove()' style='padding:3px 8px;font-size:11px;background:#f44336;color:#fff;border:none;border-radius:3px;cursor:pointer;'>✕</button>";c.appendChild(d);}
  </script>
  <?php foreach ($htmlFiles2 as $filePath):
      $relPath = str_replace(__DIR__.DIRECTORY_SEPARATOR, '', $filePath);
      $html = @file_get_contents($filePath);
      $title = ''; $lang = '';
      if ($html !== false) {
          preg_match('/<meta\s+name=["\']title["\']\s+content=["\'](.*?)["\']/is', $html, $tm);
          $title = $tm[1] ?? '';
          preg_match('/<meta\s+name=["\']lang["\']\s+content=["\'](.*?)["\']/is', $html, $lm);
          $lang = $lm[1] ?? '';
      } ?>
  <label class="file-row">
    <input type="checkbox" name="files[]" value="<?=htmlspecialchars($relPath)?>" checked class="file-chk">
    <span class="file-lang"><?=htmlspecialchars($lang)?></span>
    <span class="file-path"><?=htmlspecialchars($relPath)?></span>
    <span class="file-title"><?=htmlspecialchars(mb_substr($title, 0, 80))?></span>
  </label>
  <?php endforeach; ?>
  <div style="margin-top:12px;padding:10px 14px;background:#0d1b2a;border:1px solid #0f3460;border-radius:6px;display:flex;align-items:center;gap:8px;">
    <input type="checkbox" name="export_text_only" id="exp_text_only" value="1"<?=!empty($_GET['export_text_only'])?' checked':''?>>
    <label for="exp_text_only" style="color:#e0e0e0;font-size:13px;cursor:pointer;" data-i18n="export_text_only">📝 Экспортировать только текст (описания, заголовки)</label>
  </div>
  <div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <button type="submit" class="btn btn-success" data-i18n="export_selected">📤 ЭКСПОРТИРОВАТЬ ВЫДЕЛЕННЫЕ</button>
    <a href="?action=update&site=<?=urlencode($currentSite)?>" style="padding:7px 18px;background:transparent;color:#888;border:1px solid #555;border-radius:4px;text-decoration:none;font-size:13px;" data-i18n="back_to_filters">← Назад к фильтрам</a>
  </div>
</form>
<?php endif; ?>
</div></body></html>
<?php exit;
endif;

// === Step 3: Process selected files ===
function extractAllMeta(string $html):array{$m=[];preg_match_all('/<meta\s+name=["\']([^"\']+)["\']\s+content=["\'](.*?)["\']\s*\/?>/is',$html,$p,PREG_SET_ORDER);if(empty($p)){preg_match_all('/<meta\s+content=["\'](.*?)["\']\s+name=["\']([^"\']+)["\']\s*\/?>/is',$html,$p,PREG_SET_ORDER);foreach($p as $x)$m[trim($x[2])]=trim($x[1]);}else{foreach($p as $x)$m[trim($x[1])]=trim($x[2]);}return $m;}
function extractCollectionContent(string $html):string{
    foreach (['<!-- COLLECTION TEXT BELOW -->'] as $sep) {
        $p=mb_strpos($html,$sep); if ($p!==false) { $c=mb_substr($html,$p+mb_strlen($sep)); $p2=mb_strpos($c,'<!-- COLLECTION BUTTON BELOW -->'); if($p2!==false) $c=mb_substr($c,0,$p2); return trim($c); }
    }
    $c=preg_replace('/<\/?body[^>]*>/i','',$html); $c=preg_replace('/<\/?html[^>]*>/i','',$c);
    return trim($c);
}
function extractCollectionButton(string $html):string{
    foreach (['<!-- COLLECTION BUTTON BELOW -->'] as $sep) {
        $p=mb_strpos($html,$sep); if ($p!==false) { $c=mb_substr($html,$p+mb_strlen($sep)); return trim($c); }
    }
    return '';
}


// Load files from step 2 selection, or scan directory as fallback
$htmlFiles = [];
if (isset($_GET['files']) && is_array($_GET['files'])) {
    foreach ($_GET['files'] as $relPath) {
        $absPath = __DIR__ . DIRECTORY_SEPARATOR . $relPath;
        if (file_exists($absPath)) $htmlFiles[] = $absPath;
    }
} else {
    $prodDir=$COLLECTIONS_DIR;
    if(is_dir($prodDir)){$rdi=new RecursiveDirectoryIterator($prodDir,RecursiveDirectoryIterator::SKIP_DOTS);$rii=new RecursiveIteratorIterator($rdi);foreach($rii as $f){if($f->isFile()&&strtolower($f->getExtension())==='html')$htmlFiles[]=$f->getPathname();}sort($htmlFiles);}
    $htmlFiles=array_filter($htmlFiles,function($p){return!in_array(basename($p),['index.php','_setting_shop_collections_categories.inc']);});
    $htmlFiles=array_values($htmlFiles);
    if(!empty($_GET['lang'])){$_langFilter=$_GET['lang'];$htmlFiles=array_filter($htmlFiles,function($p)use($_langFilter){$h=@file_get_contents($p);if($h===false)return false;preg_match('/<meta\s+name=["\']lang["\']\s+content=["\'](.*?)["\']/is',$h,$m);return trim($m[1]??'')===$_langFilter;});$htmlFiles=array_values($htmlFiles);}
}
if(empty($htmlFiles)):?><h1 data-i18n="no_html_files">✕ Нет HTML-файлов</h1><p data-i18n="no_html_files_desc">В папке <code><?=htmlspecialchars($COLLECTIONS_DIR)?></code> не найдено *.html</p>
<?php else:
$articleIdx=0;$skippedCount=0;$success=0;$errors=0;$created=0;$updated=0;
// Apply search filter as fallback (only if not from step 2 selection)
if (!isset($_GET['files']) && !empty($searchFilter)) {
    $htmlFiles = array_filter($htmlFiles, function($fp) use ($searchFilter) {
        $bn = pathinfo($fp, PATHINFO_FILENAME);
        $fields = [$bn];
        $h = @file_get_contents($fp);
        if ($h !== false) {
            preg_match_all('/<meta\s+name=["\'](slug|name|title)["\']\s+content=["\'](.*?)["\']/is', $h, $mm, PREG_SET_ORDER);
            foreach ($mm as $mv) $fields[] = $mv[2];
        }
        foreach ($searchFilter as $term) {
            $t = trim($term);
            if ($t === '') continue;
            foreach ($fields as $hay) {
                if (mb_stripos($hay, $t) !== false) return true;
            }
        }
        return false;
    });
}
// Apply batch limit
$htmlFiles = array_slice($htmlFiles, 0, $batchLimit);
// Parse export category filter from step 2 (export_colls_configured), fallback to config
$activeCategories = $ALLOWED_COLLECTIONS;
if (isset($_GET['export_colls_configured'])) {
    $exportCats = [];
    if (isset($_GET['export_cat']) && is_array($_GET['export_cat'])) {
        foreach ($_GET['export_cat'] as $ec) {
            if (!isset($ec['checked']) || $ec['checked'] != '1') continue;
            $ecId = (int)($ec['id'] ?? 0);
            $ecName = trim($ec['name'] ?? '');
            if ($ecId > 0 || $ecName !== '') {
                $exportCats[$ecId] = $ecName ?: 'cat_'.$ecId;
            }
        }
    }
    $activeCategories = $exportCats; // empty = all allowed
}
$batchPayloads = []; $batchProducts = [];

// === Export fix by reference (pre-scan) ===
$expRefLang = $_GET['export_ref_lang'] ?? $REFERENCE_LANG;
$expFixMultilangid = !empty($_GET['export_fix_multilangid']);
$expFixStatus = !empty($_GET['export_fix_status']);
$expFixDatestamp = !empty($_GET['export_fix_datestamp']);
$fixFields = [];
if ($expFixMultilangid) $fixFields[] = 'multilangid';
if ($expFixStatus) $fixFields[] = 'status';
if ($expFixDatestamp) $fixFields[] = 'datestamp';
$fixMap = [];
if (!empty($fixFields)) {
    $fixScan = [];
    foreach ($htmlFiles as $fp) {
        $_h = @file_get_contents($fp);
        if ($_h === false) continue;
        $_m = extractAllMeta($_h);
        $_slug = $_m['slug'] ?? '';
        $_lang = $_m['language'] ?? 'ru';
        if ($_slug === '') continue;
        $fixScan[$_slug][] = ['file' => $fp, 'lang' => $_lang, 'meta' => $_m];
    }
    foreach ($fixScan as $_slug => $_arts) {
        if (count($_arts) < 2) continue;
        $_ref = null;
        foreach ($_arts as $_a) { if ($_a['lang'] === $expRefLang) { $_ref = $_a; break; } }
        if (!$_ref) continue;
        foreach ($_arts as $_a) {
            if ($_a['lang'] === $expRefLang) continue;
            $_fixes = [];
            foreach ($fixFields as $_f) {
                $_old = (string)($_a['meta'][$_f] ?? '');
                if ($_f === 'multilangid') {
                    $_refId = (int)($_ref['meta']['id'] ?? 0);
                    if ($_old === '' && $_refId > 0) $_fixes[$_f] = (string)$_refId;
                    continue;
                }
                $_new = (string)($_ref['meta'][$_f] ?? '');
                if ($_old !== $_new) $_fixes[$_f] = $_new;
            }
            if (!empty($_fixes)) $fixMap[$_a['file']] = $_fixes;
        }
    }
}

foreach($htmlFiles as $htmlFile):
$relPath=str_replace(__DIR__.DIRECTORY_SEPARATOR,'',$htmlFile);$articleIdx++;
$html=file_get_contents($htmlFile);$meta=extractAllMeta($html);
$title=$meta['title']??'';$metaTitle=$meta['meta_title']??'';$metaDesc=$meta['meta_description']??'';
$metaKeywords=$meta['meta_keywords']??'';$slug=$meta['slug']??$meta['collection_name']??'';$language=$meta['language']??$meta['collection_lang']??'ru';
$collectionName=$meta['collection_name']??('sitecreate_'.$language);$collectionParentId=(int)($meta['collection_id']??0);$collectionParentId=(int)($meta['collection_parent_id']??0);
$collectionButton=extractCollectionButton($html);$status=$meta['status']??'';
$priority=(int)($meta['priority']??0);$subdomain=(int)($meta['subdomain']??0);$view=(int)($meta['view']??0);
$settingsComments=$meta['settings_comments']??'';$settingsTags=(int)($meta['settings_tags']??0);
$comments=(int)($meta['comments']??0);$settingsRating=(int)($meta['settings_rating']??0);
$showTree=(string)(int)($meta['show_tree']??0);$showInlist=(int)($meta['show_inlist']??0);
$showPeriod=(int)($meta['show_period']??0);$schema=(int)($meta['schema']??6);
$collectionVisible=(int)($meta['collection_visible']??1);$collectionText=$meta['collection_text']??'';$collectionImage=$meta['collection_image']??'';
$rating=(int)($meta['rating']??0);$datestampStr=$meta['datestamp']??'';$tags=$meta['tags']??'';$password=$meta['password']??'';
$articleId=(int)($meta['id']??0);
if ($articleId===0) { preg_match('/\/(\d+)[-\/]/', $htmlFile, $m); if (!empty($m[1])) $articleId=(int)$m[1]; }
$multilangid=$meta['multilangid']??'';

// Apply export fix by reference (overrides from reference article)
if (isset($fixMap[$htmlFile])) {
    foreach ($fixMap[$htmlFile] as $_f => $_v) {
        if ($_f === 'multilangid') $multilangid = $_v;
        elseif ($_f === 'status') $status = (int)$_v;
        elseif ($_f === 'datestamp') $datestampStr = $_v;
    }
}
$collectionAllowed=empty($activeCategories);
if(!$collectionAllowed&&$collectionParentId>0&&isset($activeCategories[$collectionParentId])){$collectionAllowed=true;}
if(!$collectionAllowed&&$collectionParentId===0&&$collectionName!==''){$f=array_search($collectionName,$activeCategories,true);if($f!==false){$collectionAllowed=true;}}
if(!$collectionAllowed):$skippedCount++;?>
<div class="article"><div class="article-header"><span><span class="num">#<?=$articleIdx?></span> <span class="file"><?=htmlspecialchars($relPath)?></span></span><span class="date"><?=date('Y-m-d H:i:s')?></span></div>
<div class="article-body"><div class="result-skip"><span style="color:#888;" data-i18n="skipped_collection">⏭ Пропущен — коллекция не входит в ALLOWED_PRODUCERS</span><?php if($collectionParentId):?><br><span class="inline-code">category_id: <?=$collectionParentId?></span><?php endif;?><?php if($collectionName):?><br><span class="inline-code">category_name: <?=htmlspecialchars($collectionName)?></span><?php endif;?></div></div></div>
<?php continue; endif;
if($status===''||$status===null){$status=$expStatusMode==='override'?$expStatusOverride:1;}else{$status=(int)$status;}
// Date calculation based on mode
if ($expDateMode === 'fixed' && $expDateFixed !== '') {
    $datestamp = dateToTimestamp($expDateFixed);
} elseif ($expDateMode === 'offset' && isset($slugDateMap[$slug])) {
    $datestamp = $slugDateMap[$slug];
} else {
    $datestamp = dateToTimestamp($datestampStr);
}
$collectionText=extractCollectionContent($html);
$payload=['collection_name'=>$slug,'collection_title'=>$title,'collection_lang'=>$language,'collection_parent_id'=>$collectionParentId,'collection_visible'=>$collectionVisible,'collection_text'=>$collectionText,'collection_image'=>$collectionImage,'collection_meta_d'=>$metaDesc,'collection_meta_k'=>$metaKeywords,'collection_title_inpage'=>$title,'collection_title_seo_append'=>$metaTitle,'collection_description_seo_append'=>$metaKeywords,'collection_button'=>$collectionButton,'collection_settings_comments'=>$settingsComments,'collection_settings_tags'=>$settingsTags,'collection_comments'=>$comments,'collection_settings_rating'=>$settingsRating,'collection_password'=>$password,'collection_view'=>$view,'collection_rating'=>$rating,'collection_datestamp'=>$datestamp,'collection_multilangid'=>$multilangid,'tags'=>$tags,'slug'=>$slug];
$doDelete = (isset($meta['delete']) && strtolower($meta['delete']) === 'true');
if ($doDelete && $articleId > 0) { $payload['delete'] = true; $payload['id'] = $articleId; }
if($expMode !== 'insert') $payload['update_exists'] = true;
if($articleId>0){
    $payload['collection_id'] = $articleId;
    $payload['id'] = $articleId;
}
if($expCollectionId && $collectionParentId>0){$payload['collections']=[['id'=>$collectionParentId,'name'=>$collectionName]];}
elseif($expCollectionName && $collectionName!==''){$payload['collections']=[['name'=>$collectionName]];}
if($multilangid && $expFixMultilangid)$payload['multilangid']=$multilangid;
if($exportTextOnly){
    $keep = ['collection_id','id','update_exists','collection_name','collection_text','collection_button','collection_lang','slug'];
    $payload = array_intersect_key($payload, array_flip($keep));
}
$jsonPayload=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);$descSize=mb_strlen($collectionText);$metaCount=count($meta);
// Store for batch send
$batchPayloads[] = $payload;
$batchProducts[] = ['slug'=>$slug, 'relPath'=>$relPath, 'multilangid'=>$multilangid, 'idx'=>$articleIdx];
?>
<div class="article"><div class="article-header"><span><span class="num">#<?=$articleIdx?></span> <span class="file"><?=htmlspecialchars($relPath)?></span></span><span class="date"><?=date('Y-m-d H:i:s')?></span></div>
<div class="article-body">
<details><summary><span data-i18n="metadata_title">📋 Метаданные</span> (<?=$metaCount?> <span data-i18n="fields_count">полей</span>)</summary><div class="meta-grid">
<?php foreach($meta as $mk=>$mv):?><span class="key"><?=htmlspecialchars($mk)?>:</span><span class="val"><?=$mv!==''&&$mv!==null?htmlspecialchars((string)$mv):'<span class="na">—</span>'?></span><?php endforeach;?>
<span class="key" data-i18n="desc_size_label">размер description:</span><span class="val"><?=$descSize?> <span data-i18n="chars_count">символов</span></span>
</div></details>
<details><summary data-i18n="payload_title">📦 Отправляемые данные (payload)</summary><div class="resp-block"><?=htmlspecialchars($jsonPayload)?></div></details>
<details><summary data-i18n="description_title">📄 Description (полный текст)</summary><textarea readonly><?=htmlspecialchars($collectionText)?></textarea></details>
<?php if($dryRun):?><div class="result-warn" data-i18n="dryrun_skip">⚡ DRY RUN — запрос не отправлен</div></div></div>
<?php continue; endif; ?>
<div class="result-placeholder" data-idx="<?=$articleIdx?>"><div class="result-pending" style="color:#888;padding:8px 0;"><span data-i18n="batch_pending">⏳ Ожидание ответа пакетного запроса...</span></div></div>
</div></div>
<?php endforeach;

// === Batch API request ===
if (!empty($batchPayloads) && !$dryRun):
$batchJson = json_encode(['collections' => $batchPayloads], JSON_UNESCAPED_UNICODE);
$httpMethod = ($expMode === 'update') ? 'PUT' : 'POST';
$debug = !empty($_GET['debug']);
if ($debug) {
    echo '<div style="background:#1a1a2e;border:1px solid #ff9800;border-radius:6px;padding:12px;margin:8px 0;font-size:12px;font-family:monospace;">';
    echo '<strong>🔍 Debug cURL</strong><br>';
    echo '<b>URL:</b> ' . htmlspecialchars($API_URL) . '<br>';
    echo '<b>Method:</b> ' . $httpMethod . '<br>';
    echo '<b>Payload size:</b> ' . strlen($batchJson) . ' bytes<br>';
    echo '<b>Products:</b> ' . count($batchPayloads) . '<br>';
    echo '<b>Payload preview:</b> <pre>' . htmlspecialchars(mb_substr($batchJson, 0, 2000)) . '</pre>';
}
$ch=curl_init($API_URL);
$curlOpts = [CURLOPT_CUSTOMREQUEST=>$httpMethod,CURLOPT_POSTFIELDS=>$batchJson,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>["Authorization: Bearer ".$AUTH_KEY,"Content-Type: application/json"],CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_ENCODING=>'',CURLOPT_CONNECTTIMEOUT=>30,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_SSL_VERIFYPEER=>0];
if ($debug) $curlOpts[CURLOPT_VERBOSE] = true;
curl_setopt_array($ch, $curlOpts);
$responseFull=curl_exec($ch);$httpCode=curl_getinfo($ch,CURLINFO_HTTP_CODE);$curlError=curl_error($ch);$headerSize=curl_getinfo($ch,CURLINFO_HEADER_SIZE);$redirectUrl=curl_getinfo($ch,CURLINFO_REDIRECT_URL);$primaryIp=curl_getinfo($ch,CURLINFO_PRIMARY_IP);curl_close($ch);
if ($debug) {
    echo '<b>Primary IP:</b> ' . htmlspecialchars($primaryIp??'') . '<br>';
    echo '<b>Redirect URL:</b> ' . htmlspecialchars($redirectUrl??'') . '<br>';
    echo '<b>HTTP Code:</b> ' . $httpCode . '<br>';
}
$responseBody='';$respData=null;$batchResults=[];
if($responseFull!==false&&$responseFull!==''){$responseBody=substr((string)$responseFull,$headerSize);if($debug) echo '<b>Response headers:</b><pre>' . htmlspecialchars(substr((string)$responseFull, 0, $headerSize)) . '</pre>';$respData=json_decode($responseBody,true);}
if($curlError):
    $batchError = htmlspecialchars($curlError);
    $errCount = count($batchPayloads);
    if ($debug) echo '<b>cURL Error:</b> ' . $batchError . '<br></div>';
    echo '<div class="result-fail" style="padding:12px;margin:10px 0;background:#fee2e2;border-radius:8px;"><span class="error" data-i18n="curl_error">✗ cURL Ошибка</span><br><span>'.$batchError.'</span></div>';
    ?>
    <script>
    (function(){
        document.querySelectorAll('.result-placeholder').forEach(function(el){
            el.innerHTML = '<div class="result-fail"><span class="error">✗ Ошибка: <?=$batchError?></span></div>';
        });
    })();
    </script>
    <?php $errors = $errCount; echo $debug ? '</div>' : '';
elseif($httpCode>=200&&$httpCode<300&&is_array($respData)):
    if ($debug) { echo '<b>✅ Response OK</b><br><b>Response body:</b><pre>' . htmlspecialchars(mb_substr($responseBody, 0, 2000)) . '</pre></div>'; }
    // API can return single result or batch results array
    if (isset($respData['results']) && is_array($respData['results'])) {
        $batchResults = $respData['results'];
    } elseif (isset($respData['collections']) && is_array($respData['collections'])) {
        $batchResults = [$respData['collections']];
    } elseif (isset($respData['products']) && is_array($respData['products'])) {
        $batchResults = [$respData['products']];
    }
    // Map results by slug_search or collection_name
    $resultMap = []; $resultIdx = 0;
    foreach ($batchResults as $br) {
        $slugFull = $br['slug_search'] ?? $br['collection_name'] ?? '';
        if ($slugFull) { 
            $resultMap[$slugFull] = $br;
            $slugBase = preg_replace('/-(en|ua|pl|ru)$/', '', $slugFull);
            if ($slugBase !== $slugFull) {
                $resultMap[$slugBase] = $br;
            }
        }
        // Fallback: by position
        if (isset($batchProducts[$resultIdx])) {
            $resultMap['_pos_'.$resultIdx] = $br;
        }
        $resultIdx++;
    }
    // Build summary counts
    $summaryCreated = 0; $summaryUpdated = 0; $summaryErrors = 0; $summarySkippedExist = 0; $allResultsHtml = []; $errorDetails = []; $skippedExistDetails = [];
    foreach ($batchProducts as $baIdx => $ba):
        $slug = $ba['slug'];
        $art = $resultMap[$slug] ?? $resultMap['_pos_'.$baIdx] ?? [];
        $respId = $art['id'] ?? '?';
        $isAdded = isset($art['added']);
        $action2 = $isAdded ? 'создана' : 'обновлена';
        $skipFields = $art['skipped'] ?? [];
        $glErrors = $art['errors_global'] ?? [];
        $fieldErrors = $art['errors'] ?? [];
        $hasErrors = !empty($glErrors) || !empty($fieldErrors);
        $glErrorsStr = implode(' ', $glErrors);
        $alreadyExists = !empty($glErrors) && preg_match('/already exists/i', $glErrorsStr);
        $notFound = !empty($glErrors) && preg_match('/not found/i', $glErrorsStr);
        $isSkip = ($alreadyExists && $expMode === 'insert') || ($notFound && $expMode === 'update');
        ob_start();
        ?>
        <?php if ($hasErrors || empty($art)): ?>
            <?php if ($isSkip): $summarySkippedExist++; $skipReason = $alreadyExists ? 'уже существует' : 'не найдена'; $skippedExistDetails[] = "#{$ba['idx']} {$ba['slug']} — {$skipReason}"; ?>
            <div class="result-skip"><span style="color:#888;">⏭ <?=htmlspecialchars(ucfirst($skipReason))?> (пропущено)</span></div>
            <?php else: $summaryErrors++; $errorDetails[] = "#{$ba['idx']} {$ba['slug']}: " . implode('; ', $glErrors ?: $fieldErrors ?: ['Нет ответа']); ?>
            <div class="result-fail"><span class="error"><span data-i18n="http_error">✗ Ошибка</span></span>
            <?php if(!empty($glErrors)):?><br><span class="error" data-i18n="api_errors">✩ Ошибки API:</span><?php foreach($glErrors as $ge):?><div>• <?=htmlspecialchars($ge)?></div><?php endforeach;?><?php endif;?>
            <?php if(!empty($fieldErrors)):?><br><span class="warning" data-i18n="field_errors">⚠ Ошибки полей:</span><?php foreach($fieldErrors as $fe):?><div>• <?=htmlspecialchars(is_array($fe)?json_encode($fe,JSON_UNESCAPED_UNICODE):$fe)?></div><?php endforeach;?><?php endif;?>
            <?php if(empty($art)):?><br><span>Нет ответа для данной коллекции</span><?php endif;?>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="result-ok"><span class="success" data-i18n="<?=$isAdded?'product_created':'product_updated'?>">✓ Производитель <?=$action2?> (ID: <?=$respId?>)</span>
            <?php if($ba['multilangid']):?><br><span class="warning">🔗 multilangid: <?=htmlspecialchars($ba['multilangid'])?></span><?php endif;?>
            <?php if(!empty($skipFields)):?><br><span class="warning" data-i18n="skipped_fields">⚠ Пропущенные поля:</span><?php foreach($skipFields as $fk=>$fv):?><div><?=htmlspecialchars($fk)?>: <?=htmlspecialchars(is_array($fv)?json_encode($fv,JSON_UNESCAPED_UNICODE):$fv)?></div><?php endforeach;?><?php endif;?>
            </div>
            <details open><summary data-i18n="verification_title">🔍 Верификация</summary><div class="meta-grid">
            <span class="key" data-i18n="status_label">статус:</span><span class="val"><span class="success"><span data-i18n="product_saved">✓ Данные сохранены успешно</span><?php if(!empty($respId)):?> (ID: <?=$respId?>)<?php endif;?></span></span>
            </div></details>
        <?php if ($isAdded) $summaryCreated++; else $summaryUpdated++; endif;
        $allResultsHtml[$ba['idx']] = ob_get_clean();
    endforeach;
    ?>
    <div class="result-summary" style="padding:12px 16px;margin:10px 0;background:#1a1a2e;border-radius:8px;border:1px solid #0f3460;">
        <span style="color:#4caf50;font-weight:700;"><span data-i18n="created_label">✅ Создано:</span> <?=$summaryCreated?></span>
        <span style="color:#00d4ff;font-weight:700;margin-left:20px;"><span data-i18n="updated_label">📝 Обновлено:</span> <?=$summaryUpdated?></span>
        <span style="color:#f44336;font-weight:700;margin-left:20px;"><span data-i18n="errors_label">❌ Ошибок:</span> <?=$summaryErrors?></span>
        <span style="color:#888;font-weight:700;margin-left:20px;"><span data-i18n="skipped_exist_label">⏭ Пропущено (существуют):</span> <?=$summarySkippedExist?></span>
    </div>
    <script>
    (function(){
        var m = '<span data-i18n="created_label">✅ Создано:</span> <?=$summaryCreated?> | <span data-i18n="updated_label">📝 Обновлено:</span> <?=$summaryUpdated?>';
        if(<?=$summaryErrors?>>0) m += ' | <span data-i18n="errors_label">❌ Ошибок:</span> <?=$summaryErrors?>';
        if(<?=$summarySkippedExist?>>0) m += ' | ⏭ Пропущено: <?=$summarySkippedExist?>';
        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#16213e;border:2px solid #0f3460;border-radius:10px;padding:14px 20px;color:#e0e0e0;font-size:14px;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.5);max-width:400px;line-height:1.5;';
        t.innerHTML = '<strong style="color:#00d4ff;">📊 Экспорт завершён</strong><br>' + m;
        document.body.appendChild(t);
        setTimeout(function(){ t.style.transition = 'opacity 1s'; t.style.opacity = '0'; setTimeout(function(){ t.remove(); },1000); }, 6000);
    })();
    </script>
    <?php if (!empty($errorDetails)): ?>
    <details style="margin:10px 0;background:#2a1a1a;border:1px solid #f44336;border-radius:8px;padding:12px;">
        <summary style="color:#f44336;font-weight:600;cursor:pointer;">❌ Детали ошибок (<?=count($errorDetails)?>)</summary>
        <?php foreach($errorDetails as $ed):?><div style="padding:4px 0;font-size:12px;color:#e0e0e0;">• <?=htmlspecialchars($ed)?></div><?php endforeach;?>
    </details>
    <?php endif; ?>
    <?php if (!empty($skippedExistDetails)): ?>
    <details style="margin:10px 0;background:#1a2a1a;border:1px solid #888;border-radius:8px;padding:12px;">
        <summary style="color:#888;font-weight:600;cursor:pointer;"><span data-i18n="skipped_exist_label2">⏭ Пропущено</span> — уже существуют (<?=count($skippedExistDetails)?>)</summary>
        <?php foreach($skippedExistDetails as $sd):?><div style="padding:4px 0;font-size:12px;color:#e0e0e0;">• <?=htmlspecialchars($sd)?></div><?php endforeach;?>
    </details>
    <?php endif; ?>
    <script>
    (function(){
        var results = <?=json_encode($allResultsHtml, JSON_UNESCAPED_UNICODE)?>;
        for (var idx in results) {
            var el = document.querySelector('.result-placeholder[data-idx="'+idx+'"]');
            if (el) el.innerHTML = results[idx];
        }
    })();
    </script>
    <?php
    $created += $summaryCreated; $updated += $summaryUpdated; $success += ($summaryCreated + $summaryUpdated); $errors += $summaryErrors;
    // Show full API response
    if ($respData): ?>
    <details><summary data-i18n="api_response">📬 Ответ API</summary><div class="resp-block"><?=htmlspecialchars(json_encode($respData,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></div></details>
    <?php endif;
else:
    if ($debug) echo '<b>❌ HTTP Error '.$httpCode.'</b><br><b>Response body:</b><pre>' . htmlspecialchars(mb_substr((string)$responseBody, 0, 2000)) . '</pre></div>';
    // HTTP error — update all placeholders with error
    $errMsg = '';
    $errDetails = '';
    if($respData!==null){$errMsg=$respData['error']??$respData['message']??'';$errDetails=json_encode($respData,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);}
    elseif($responseBody!==''){$errDetails=$responseBody;}else{$errDetails='Нет ответа от сервера (HTTP '.$httpCode.')'.($curlError?' | cURL: '.$curlError:'');}
    $errCount = count($batchPayloads);
    ?>
    <div class="result-summary" style="padding:12px 16px;margin:10px 0;background:#1a1a2e;border-radius:8px;border:1px solid #0f3460;">
        <span style="color:#f44336;font-weight:700;"><span data-i18n="errors_label">❌ Ошибок:</span> <?=$errCount?></span>
    </div>
    <div class="result-fail"><span class="error"><span data-i18n="http_error">✗ Ошибка (HTTP</span> <?=$httpCode?>)</span><?php if($errMsg):?><br><span><?=htmlspecialchars($errMsg)?></span><?php endif;?></div>
    <details><summary data-i18n="api_response">📬 Ответ API</summary><div class="resp-block"><?=htmlspecialchars((string)$errDetails)?></div></details>
    <script>
    (function(){
        document.querySelectorAll('.result-placeholder').forEach(function(el){
            el.innerHTML = '<div class="result-fail"><span class="error">✗ Ошибка пакетного запроса</span></div>';
        });
    })();
    </script>
    <?php $errors = $errCount;
endif;
endif; // batch request
?>
<div class="footer"><span data-i18n="total_label">Итог:</span> <span data-i18n="processed">обработано</span> <strong><?=$articleIdx?></strong> | <span data-i18n="skipped">пропущено</span> <strong style="color:#888"><?=$skippedCount?></strong> | <span data-i18n="created">создано</span> <strong style="color:#4caf50"><?=$created?></strong> | <span data-i18n="updated">обновлено</span> <strong style="color:#00d4ff"><?=$updated?></strong> | <span data-i18n="errors">ошибок</span> <strong style="color:#f44336"><?=$errors?></strong><br><span data-i18n="completed_at">Завершено:</span> <?=date('Y-m-d H:i:s')?><br><br><a href="?action=update&site=<?=urlencode($currentSite)?>" style="padding:8px 18px;background:#0f3460;color:#00d4ff;border:1px solid #00d4ff;border-radius:6px;text-decoration:none;font-size:13px;" data-i18n="back_to_settings">← Назад к настройкам</a> &nbsp; <a href="?site=<?=urlencode($currentSite)?>" style="padding:8px 18px;background:transparent;color:#888;border:1px solid #555;border-radius:6px;text-decoration:none;font-size:13px;" data-i18n="back_home">Назад</a></div>
<?php endif;?>
<script>
var _lang='ru';try{_lang=localStorage.getItem('boostore_lang')||navigator.language.slice(0,2);localStorage.setItem('boostore_lang',_lang);}catch(e){}if(!['ru','en','ua'].includes(_lang))_lang='ru';
var _t={ru:{'home':'🏠 Главная','site_label':'Сайт:','entity_name':'Коллекции','import':'📥 Импорт','export':'📤 Экспорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Создано:','updated_label':'📝 Обновлено:','errors_label':'❌ Ошибок:','skipped_exist_label':'⏭ Пропущено (существуют):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Экспортировать только текст','file_selection':'📁 Выбор файлов','btn_get':'📥 СКАЧАТЬ','btn_update':'📤 ОТПРАВИТЬ','plaque_export':'▸ <strong>Настройки экспорта</strong> — отправка коллекций на Boostore.pro','dryrun_warn':'⚡ DRY RUN — запросы не отправляются','back_home':'Назад','back_to_settings':'← Назад к настройкам','step2_header':'▸ <strong>Шаг 2</strong> — выберите файлы для экспорта на сайт','step2_title':'Экспорт коллекций — выбор файлов','back_to_filters':'← Назад к фильтрам','no_files_found':'Нет файлов, соответствующих критериям поиска','files_found':'Найдено файлов:','select_all':'☑ ВЫДЕЛИТЬ ВСЕ','deselect_all':'☐ СНЯТЬ ВСЕ','export_selected':'📤 ЭКСПОРТИРОВАТЬ ВЫДЕЛЕННЫЕ','no_html_files':'✕ Нет HTML-файлов','no_html_files_desc':'В папке products/ не найдено *.html','skipped_collection':'⏭ Пропущен — коллекция не входит в ALLOWED_PRODUCERS','metadata_title':'📋 Метаданные','fields_count':'полей','payload_title':'📦 Отправляемые данные (payload)','description_title':'📄 Description (полный текст)','dryrun_skip':'⚡ DRY RUN — запрос не отправлен','curl_error':'✗ cURL Ошибка','product_created':'✓ Производитель создана','product_updated':'✓ Производитель обновлена','skipped_fields':'⚠ Пропущенные поля:','api_errors':'✩ Ошибки API:','field_errors':'⚠ Ошибки полей:','api_response':'📬 Ответ API','verification_title':'🔍 Верификация','sent_chars':'отправлено символов:','saved_chars':'сохранено символов:','status_label':'статус:','product_saved':'✓ Данные сохранены успешно','fetch_error':'✗ Ошибка','verification_warn':'✓ Данные сохранены успешно','http_error':'✗ Ошибка (HTTP','total_label':'Итог:','processed':'обработано','skipped':'пропущено','created':'создано','updated':'обновлено','errors':'ошибок','completed_at':'Завершено:','batch_pending':'⏳ Ожидание ответа пакетного запроса...','desc_size_label':'размер description:','chars_count':'символов','all_languages':'все','lang_ru':'Русский','lang_en':'English','lang_ua':'Українська','lang_pl':'Polski','lang_de':'Deutsch','lang_fr':'Français','lang_es':'Español','lang_it':'Italiano','lang_kk':'Қазақ','lang_be':'Беларуская','api_docs':'API Docs','version':'v2.0','date_format':'ГГГГ-ММ-ДД','search_placeholder':'часть имени, например: shoes','cat_id_placeholder':'ID','cat_name_placeholder':'имя коллекции','prompt_values':'Введите значения (каждая строка — отдельное поле):','step_forward':'➡ ДАЛЕЕ','dry_run_label':'Dry run','filter_name':'Фильтр по имени (slug)','batch_label':'Отправить за 1 раз','ref_lang_be':'Белорусский (be)','ref_lang_en':'English (en)','ref_lang_ru':'Русский (ru)','ref_lang_ua':'Українська (ua)','ref_lang_pl':'Polski (pl)','date_mode_meta':'Из мета-данных (дата из каждого коллекции)','date_mode_fixed':'Одна дата для всех коллекций','date_mode_offset':'Смещение дат (+N дней на коллекция)','planned_notset':'— не указано (из мета-данных)','planned_0':'0 — не отложенная','planned_1':'1 — отложенная публикация','status_mode_meta':'Из мета-данных (статус из каждого коллекции)','status_mode_override':'Переопределить для всех коллекций','date_mode_label':'📅 Режим даты публикации','title':'Управление коллекциями магазина — Boostore.pro','fix_import_title':'🔧 Исправление по эталону','fix_import_desc':'Синхронизировать поля с эталонной коллекцией (по slug) после сохранения','ref_lang_label':'🌐 Язык эталонной коллекции','export_fields_label':'📋 Поля для экспорта','export_collection_id_label':'ID колекції','export_product_id_label':'Product ID','import_only_named':'Только с именем','export_collection_name_label':'Имя коллекции'},en:{'home':'🏠 Home','site_label':'Site:','entity_name':'Collections','import':'📥 Import','export':'📤 Export','completed':'✓ Completed','result_label':'✓ Result','created_label':'✅ Created:','updated_label':'📝 Updated:','errors_label':'❌ Errors:','skipped_exist_label':'⏭ Skipped (exist):','skipped_exist_label2':'⏭ Skipped','export_text_only':'📝 Export text only','file_selection':'📁 File selection','btn_get':'📥 DOWNLOAD','btn_update':'📤 UPLOAD','plaque_export':'▸ <strong>Export Settings</strong> — sending products to Boostore.pro','dryrun_warn':'⚡ DRY RUN — no API calls sent','back_home':'Home','back_to_settings':'← Back to settings','step2_header':'▸ <strong>Step 2</strong> — select files to export','step2_title':'Export — file selection','back_to_filters':'← Back to filters','no_files_found':'No files matching search criteria','files_found':'Files found:','select_all':'☑ SELECT ALL','deselect_all':'☐ DESELECT ALL','export_selected':'📤 EXPORT SELECTED','no_html_files':'✕ No HTML files','no_html_files_desc':'No *.html found in products/','skipped_collection':'⏭ Skipped — category not in ALLOWED_PRODUCERS','metadata_title':'📋 Metadata','fields_count':'fields','payload_title':'📦 Payload','description_title':'📄 Description (full text)','dryrun_skip':'⚡ DRY RUN — request not sent','curl_error':'✗ cURL Error','product_created':'✓ Article created','product_updated':'✓ Article updated','skipped_fields':'⚠ Skipped fields:','api_errors':'✩ API Errors:','field_errors':'⚠ Field errors:','api_response':'📬 API Response','verification_title':'🔍 Verification','sent_chars':'chars sent:','saved_chars':'chars saved:','status_label':'status:','product_saved':'✓ Article saved','length_discrepancy':'ℹ Length discrepancy','http_error':'✗ Error (HTTP','total_label':'Total:','processed':'processed','skipped':'skipped','created':'created','updated':'updated','errors':'errors','completed_at':'Completed:','desc_size_label':'description size:','chars_count':'chars','all_languages':'all','lang_ru':'Russian','lang_en':'English','lang_ua':'Ukrainian','lang_pl':'Polish','lang_de':'German','lang_fr':'French','lang_es':'Spanish','lang_it':'Italian','lang_kk':'Kazakh','lang_be':'Belarusian','api_docs':'API Docs','version':'v2.0','date_format':'YYYY-MM-DD','search_placeholder':'part of name, e.g.: shoes','cat_id_placeholder':'ID','cat_name_placeholder':'category name','prompt_values':'Enter values (each line is a separate field):','step_forward':'➡ NEXT','dry_run_label':'Dry run','filter_name':'Filter by name (slug)','batch_label':'Send per run','ref_lang_be':'Belarusian (be)','ref_lang_en':'English (en)','ref_lang_ru':'Russian (ru)','ref_lang_ua':'Ukrainian (ua)','ref_lang_pl':'Polish (pl)','date_mode_meta':'From meta-data (date from each article)','date_mode_fixed':'Single date for all products','date_mode_offset':'Date offset (+N days per article)','planned_notset':'— not set (from meta-data)','planned_0':'0 — not planned','planned_1':'1 — planned publishing','status_mode_meta':'From meta-data (status from each article)','status_mode_override':'Override for all products','date_mode_label':'📅 Publication Date Mode','title':'Collection Management — Boostore.pro','fix_import_title':'🔧 Fix by Reference','fix_import_desc':'Sync fields with reference collection (by slug) after save','ref_lang_label':'🌐 Reference Language','export_fields_label':'📋 Fields to export','export_collection_id_label':'Collection ID','export_product_id_label':'Product ID','import_only_named':'Named only','export_collection_name_label':'Collection name'},ua:{'home':'🏠 Головна','site_label':'Сайт:','entity_name':'Колекції','import':'📥 Імпорт','export':'📤 Експорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Створено:','updated_label':'📝 Оновлено:','errors_label':'❌ Помилок:','skipped_exist_label':'⏭ Пропущено (існують):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Експортувати тільки текст','file_selection':'📁 Вибір файлів','btn_get':'📥 ПОЧАТИ ІМПОРТ','btn_update':'📤 ПОЧАТИ ЕКСПОРТ','plaque_export':'▸ <strong>Налаштування експорту</strong> — відправлення коллекций на Boostore.pro','dryrun_warn':'⚡ DRY RUN — запити не надсилаються','back_home':'На головну','back_to_settings':'← Назад до налаштувань','step2_header':'▸ <strong>Крок 2</strong> — виберіть файли для експорту','step2_title':'Експорт — вибір файлів','back_to_filters':'← Назад до фільтрів','no_files_found':'Немає файлів, що відповідають критеріям пошуку','files_found':'Знайдено файлів:','select_all':'☑ ВИДІЛИТИ ВСІ','deselect_all':'☐ ЗНЯТИ ВСІ','export_selected':'📤 ЕКСПОРТУВАТИ ВИДІЛЕНІ','no_html_files':'✕ Немає HTML-файлів','no_html_files_desc':'У папці products/ не знайдено *.html','skipped_collection':'⏭ Пропущено — категорія не входить до ALLOWED_PRODUCERS','metadata_title':'📋 Метадані','fields_count':'полів','payload_title':'📦 Дані (payload)','description_title':'📄 Опис (повний текст)','dryrun_skip':'⚡ DRY RUN — запит не надіслано','curl_error':'✗ cURL Помилка','product_created':'✓ Статтю створено','product_updated':'✓ Статтю оновлено','skipped_fields':'⚠ Пропущені поля:','api_errors':'✩ Помилки API:','field_errors':'⚠ Помилки полів:','api_response':'📬 Відповідь API','verification_title':'🔍 Верифікація','sent_chars':'надіслано символів:','saved_chars':'збережено символів:','status_label':'статус:','product_saved':'✓ Статтю збережено','length_discrepancy':'ℹ Розбіжність у довжині','http_error':'✗ Помилка (HTTP','total_label':'Підсумок:','processed':'опрацьовано','skipped':'пропущено','created':'створено','updated':'оновлено','errors':'помилок','completed_at':'Завершено:','desc_size_label':'розмір description:','chars_count':'символів','all_languages':'всі','lang_ru':'Російська','lang_en':'Англійська','lang_ua':'Українська','lang_pl':'Польська','lang_de':'Німецька','lang_fr':'Французька','lang_es':'Іспанська','lang_it':'Італійська','lang_kk':'Казахська','lang_be':'Білоруська','api_docs':'API Docs','version':'v2.0','date_format':'РРРР-ММ-ДД','search_placeholder':'частина імені, наприклад: shoes','cat_id_placeholder':'ID','cat_name_placeholder':'ім\'я категорії','prompt_values':'Введіть значення (кожен рядок — окреме поле):','step_forward':'➡ ДАЛІ','dry_run_label':'Dry run','filter_name':'Фільтр за іменем (slug)','batch_label':'Відправити за 1 раз','ref_lang_be':'Білоруська (be)','ref_lang_en':'Англійська (en)','ref_lang_ru':'Російська (ru)','ref_lang_ua':'Українська (ua)','ref_lang_pl':'Польська (pl)','date_mode_meta':'З мета-даних (дата з кожної статті)','date_mode_fixed':'Одна дата для всіх коллекций','date_mode_offset':'Зміщення дат (+N днів на статтю)','planned_notset':'— не вказано (з мета-даних)','planned_0':'0 — не відкладена','planned_1':'1 — відкладена публікація','status_mode_meta':'З мета-даних (статус з кожної статті)','status_mode_override':'Перевизначити для всіх коллекций','date_mode_label':'📅 Режим дати публікації','title':'Керування колекціями магазину — Boostore.pro','fix_import_title':'🔧 Виправлення за еталоном','fix_import_desc':'Синхронізувати поля з еталонною колекцією (по slug) після збереження','ref_lang_label':'🌐 Мова еталонної колекції','export_fields_label':'📋 Поля для експорту','export_collection_id_label':'ID колекції','export_product_id_label':'ID товару','import_only_named':'Тільки з назвою','export_collection_name_label':'Ім\'я колекції'}};
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});document.querySelectorAll('[data-i18n-placeholder]').forEach(function(el){var key=el.getAttribute('data-i18n-placeholder');if(_t[l]&&_t[l][key]!==undefined)el.placeholder=_t[l][key];});}
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});}
if(_lang!='ru'){document.addEventListener('DOMContentLoaded',function(){applyLang(_lang);});}
document.addEventListener('DOMContentLoaded',function(){var ls=document.getElementById('lang_switcher');if(ls){ls.value=_lang;ls.addEventListener('change',function(){applyLang(this.value);});}});
</script>
</div></body></html>
<?php exit;
// ===================================================================
// _update-shop_collections_categories.php — END
// ===================================================================
endif;

// ===================================================================
// DASHBOARD — Главная страница управления
// ===================================================================
function saveConfigFromPost($post) {
    global $SITES, $currentSite;
    $ts = $post['site'] ?? $currentSite;
    if (!isset($SITES[$ts])) $SITES[$ts] = [];
    // Merge key
    $doms = $post['site_domain'] ?? [];
    $keys = $post['site_key'] ?? [];
    for ($i = 0; $i < count($doms); $i++) { $d = trim($doms[$i]); if ($d === '') continue; if (!isset($SITES[$d])) $SITES[$d] = []; $SITES[$d]['key'] = $keys[$i] ?? ''; }
    // Per-site settings from form
    $cids = $post['cat_id'] ?? []; $cnms = $post['cat_name'] ?? [];
    $collections = [];
    for ($i = 0; $i < count($cids); $i++) { $id = trim($cids[$i]); $nm = trim($cnms[$i]); if ($id !== '' || $nm !== '') $collections[(int)$id] = $nm; }
    $SITES[$ts]['allowed_collections'] = $collections;
    $SITES[$ts]['status_mode'] = $post['STATUS_MODE'] ?? '';
    $SITES[$ts]['status_override'] = (int)($post['STATUS_OVERRIDE'] ?? 1);
    $SITES[$ts]['date_mode'] = $post['DATE_MODE'] ?? '';
    $SITES[$ts]['date_fixed'] = $post['DATE_FIXED'] ?? '';
    $SITES[$ts]['date_offset_base'] = $post['DATE_OFFSET_BASE'] ?? '';
    $SITES[$ts]['date_offset_days'] = (int)($post['DATE_OFFSET_DAYS'] ?? 1);
    $SITES[$ts]['export_collection_id'] = isset($post['EXPORT_PRODUCT_ID']);
    $SITES[$ts]['export_collection_id'] = isset($post['EXPORT_CATEGORY_ID']);
    $SITES[$ts]['export_collection_name'] = !isset($post['EXPORT_CATEGORY_NAME']) || !empty($post['EXPORT_CATEGORY_NAME']);
    $SITES[$ts]['per_page'] = (int)($post['PER_PAGE'] ?? 200);
    $SITES[$ts]['send_batch_limit'] = (int)($post['SEND_BATCH_LIMIT'] ?? 200);
    $SITES[$ts]['reference_lang'] = $post['REFERENCE_LANG'] ?? 'ru';
    $SITES[$ts]['fix_multilangid'] = isset($post['FIX_MULTILANGID']);

    $SITES[$ts]['fix_currency'] = isset($post['FIX_CURRENCY']);
    $SITES[$ts]['fix_status'] = isset($post['FIX_STATUS']);
    $SITES[$ts]['fix_datestamp'] = isset($post['FIX_DATESTAMP']);
    $SITES[$ts]['import_only_named'] = !isset($post['IMPORT_ONLY_NAMED']) || !empty($post['IMPORT_ONLY_NAMED']);
    // Build config content
    $c = "<?php\n// === per-site config ===\n\$SITES = [\n";
    foreach ($SITES as $sd => $sc) {
        $sk = var_export($sc['key'] ?? '', true);
        $c .= "  ".var_export($sd, true)." => ['key' => $sk, 'allowed_collections' => ";
        $acs = $sc['allowed_collections'] ?? [];
        if (empty($acs)) { $c .= "[]"; }
        else { $ca = []; foreach ($acs as $ci => $cn) { $ca[] = var_export($ci,true)."=>".var_export($cn,true); } $c .= "[".implode(",",$ca)."]"; }
        $c .= ", 'status_mode' => ".var_export($sc['status_mode']??'',true);
        $c .= ", 'status_override' => ".(int)($sc['status_override']??1);
        $c .= ", 'date_mode' => ".var_export($sc['date_mode']??'',true);
        $c .= ", 'date_fixed' => ".var_export($sc['date_fixed']??'',true);
        $c .= ", 'date_offset_base' => ".var_export($sc['date_offset_base']??'',true);
        $c .= ", 'date_offset_days' => ".(int)($sc['date_offset_days']??1);
        $c .= ", 'export_collection_id' => ".($sc['export_collection_id']??false?'true':'false');
        $c .= ", 'export_collection_id' => ".($sc['export_collection_id']??false?'true':'false');
        $c .= ", 'export_collection_name' => ".($sc['export_collection_name']??true?'true':'false');
        $c .= ", 'per_page' => ".(int)($sc['per_page']??200);
        $c .= ", 'send_batch_limit' => ".(int)($sc['send_batch_limit']??200);
        $c .= ", 'reference_lang' => ".var_export($sc['reference_lang']??'ru',true);
        $c .= ", 'fix_multilangid' => ".($sc['fix_multilangid']??false?'true':'false');

        $c .= ", 'fix_currency' => ".($sc['fix_currency']??false?'true':'false');
        $c .= ", 'fix_status' => ".($sc['fix_status']??false?'true':'false');
        $c .= ", 'fix_datestamp' => ".($sc['fix_datestamp']??false?'true':'false');
        $c .= ", 'import_only_named' => ".($sc['import_only_named']??true?'true':'false');
        $c .= "],\n";
    }
    $c .= "];\n";
    file_put_contents(__DIR__.'/_setting_shop_collections_categories.inc', $c);
}
$saveSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    saveConfigFromPost($_POST);
    $saveSuccess = true;
    require __DIR__.'/_setting_shop_collections_categories.inc';
    $currentSite = $_POST['site'] ?? (!empty($_GET['site']) ? $_GET['site'] : (array_keys($SITES)[0] ?? ''));
    $siteCfg = $SITES[$currentSite] ?? [];
    $ALLOWED_COLLECTIONS  = $siteCfg['allowed_collections'] ?? [];
    $STATUS_MODE         = $siteCfg['status_mode'] ?? '';
    $STATUS_OVERRIDE     = $siteCfg['status_override'] ?? 1;
    $DATE_MODE           = $siteCfg['date_mode'] ?? '';
    $DATE_FIXED          = $siteCfg['date_fixed'] ?? '';
    $DATE_OFFSET_BASE    = $siteCfg['date_offset_base'] ?? '';
    $DATE_OFFSET_DAYS    = $siteCfg['date_offset_days'] ?? 1;
    $EXPORT_PRODUCT_ID   = $siteCfg['export_collection_id'] ?? false;
    $EXPORT_COLLECTION_ID  = $siteCfg['export_collection_id'] ?? false;
    $EXPORT_COLLECTION_NAME = $siteCfg['export_collection_name'] ?? true;
    $PER_PAGE            = $siteCfg['per_page'] ?? 200;
    $SEND_BATCH_LIMIT    = $siteCfg['send_batch_limit'] ?? 200;
    $REFERENCE_LANG      = $siteCfg['reference_lang'] ?? 'ru';
    $FIX_MULTILANGID     = $siteCfg['fix_multilangid'] ?? false;

    $FIX_STATUS          = $siteCfg['fix_status'] ?? false;
    $FIX_DATESTAMP       = $siteCfg['fix_datestamp'] ?? false;
    $IMPORT_ONLY_NAMED   = $siteCfg['import_only_named'] ?? true;
    $AUTH_KEY = $SITES[$currentSite]['key'] ?? '';
    $apiKeyMissing = empty($AUTH_KEY);
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><title>Управление коллекциями — Boostore.pro</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#1a1a2e;color:#e0e0e0;font-family:'Segoe UI',system-ui,sans-serif;padding:30px}.wrap{max-width:1200px;margin:0 auto;overflow:hidden}
h1{font-size:22px;color:#00d4ff;margin-bottom:5px}h2{font-size:18px;color:#00d4ff;margin:25px 0 10px}h3{font-size:15px;color:#4dc9f6;margin:15px 0 8px}
.meta-info{color:#888;font-size:13px;margin-bottom:25px}a{color:#00d4ff;text-decoration:none}a:hover{color:#4dc9f6;text-decoration:none}.btn:hover{color:#fff;text-decoration:none}
.card{background:#16213e;border:1px solid #0f3460;border-radius:10px;margin-bottom:20px;overflow:hidden;transition:border-color .2s}.card:hover{border-color:#00d4ff}
.card-header{background:#0f3460;padding:12px 18px;font-weight:700;color:#00d4ff;font-size:15px}.card-body{padding:15px 18px}
.btn{display:inline-block;padding:10px 22px;border-radius:6px;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;border:none;transition:all .2s}.btn:hover{transform:translateY(-1px)}
.btn-primary{background:#00d4ff;color:#1a1a2e}.btn-primary:hover{background:#4dc9f6;box-shadow:0 4px 12px rgba(0,212,255,.2)}
.btn-success{background:#4caf50;color:#fff}.btn-success:hover{background:#66bb6a;box-shadow:0 4px 12px rgba(76,175,80,.2)}
.btn-warning{background:#ff9800;color:#fff}.btn-warning:hover{background:#ffb74d;box-shadow:0 4px 12px rgba(255,152,0,.2)}
.btn-danger{background:#f44336;color:#fff}.btn-danger:hover{background:#e53935;box-shadow:0 4px 12px rgba(244,67,54,.2)}
.btn-sm{padding:5px 12px;font-size:12px}
.btn-group{display:flex;gap:12px;flex-wrap:wrap;margin:15px 0}code,pre{font-family:'Consolas',monospace;font-size:13px}
code{background:#0d1b2a;padding:1px 5px;border-radius:3px}pre{background:#0d1b2a;border:1px solid #0f3460;border-radius:6px;padding:12px;overflow-x:auto;font-size:12px;color:#e0e0e0}
.footer{text-align:center;padding:20px;color:#555;font-size:13px}.success-msg{background:#1b5e20;border:1px solid #4caf50;border-radius:6px;padding:12px 16px;color:#a5d6a7;margin-bottom:15px}
.warn-msg{background:#3e2723;border:1px solid #ff9800;border-radius:6px;padding:12px 16px;color:#ffcc80;margin-bottom:15px}
label{display:block;color:#888;font-size:13px;margin-bottom:3px}input,textarea,select{width:100%;padding:8px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;font-family:'Segoe UI',sans-serif;transition:border-color .2s}
input:focus,textarea:focus,select:focus{outline:none;border-color:#00d4ff;box-shadow:0 0 0 2px rgba(0,212,255,.15)}.form-row{display:flex;gap:12px;margin-bottom:10px;align-items:flex-end;flex-wrap:wrap}.form-row .field{flex:1;min-width:100px}.form-row .field-sm{flex:0 0 120px}
.form-check{display:flex;align-items:center;gap:8px;margin-bottom:10px;cursor:pointer}.form-check input[type="checkbox"]{width:auto}
hr{border:0;border-top:1px solid #0f3460;margin:20px 0}table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:8px 10px;text-align:left;border-bottom:1px solid #0f3460}th{color:#00d4ff;font-weight:600;background:#0f3460}
.param-table td:first-child{color:#ff9800;white-space:nowrap}.param-table td:nth-child(2){color:#888}.param-table td:nth-child(3){color:#e0e0e0}.na{color:#555;font-style:italic}
details.card>summary{cursor:pointer;padding:12px 18px;background:#0f3460;font-weight:700;color:#00d4ff;font-size:15px;display:flex;justify-content:space-between;align-items:center;list-style:none;transition:background .2s}
details.card>summary::-webkit-details-marker{display:none}details.card>summary .arrow{transition:transform .2s;font-size:12px;color:#888}details.card[open]>summary .arrow{transform:rotate(90deg)}details.card>summary:hover{background:#1a4a7a}
@media(max-width:640px){body{padding:15px}.form-row{flex-wrap:wrap}.form-row .field{flex:1 1 100%}.btn-group{flex-direction:column}.btn-group .btn{text-align:center}}
</style>
</head>
<body><div class="wrap">
<?php echo $header; ?>
<?php if ($apiKeyMissing): ?>
<div class="warn-msg" data-i18n="warn_nokey">⚠ Необходимо указать <strong>ключ доступа API</strong> (Consumer Secret) в разделе «Конфигурация» ниже, иначе скрипты не будут работать.</div>
<div style="background:#2a1a1a;border:1px solid #f44336;border-radius:6px;padding:12px 16px;color:#ffcdd2;margin-bottom:15px;font-size:13px;" data-i18n="warn_domain">⚠ Для работы API обязательно нужно открывать его с использованием адреса вашего сайта, созданного на платформе <strong>Boostore.pro</strong>. Измените домен в конфигурации ниже на ваш (например: <strong>moy-sayt.boostore.pro</strong>).</div>
<?php endif; ?>
<?php if ($saveSuccess): ?><div class="success-msg">✓ <span data-i18n="saved">Конфигурация сохранена</span></div><?php endif; ?>
<div style="background:#0f3460;border:1px solid #00d4ff;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:14px;color:#e0e0e0;" data-i18n="plaque">
<strong>Boostore.pro</strong> — Скрипты для <strong>экспорта</strong> (скачивания) и <strong>импорта</strong> (отправки) коллекций каталога через Commerce API. Сайт: <strong><?=htmlspecialchars($currentSite)?></strong>
</div>
<details class="card"><summary><span data-i18n="instr_title">📖 Инструкция</span> <span class="arrow">▶</span></summary><div class="card-body">
<p data-i18n="instr_intro">Все настройки — в разделе «Конфигурация» ниже. Если список коллекций пуст — обрабатываются все коллекции.</p>
<h3 data-i18n="quickstart">Быстрый старт</h3>
<ol style="margin-left:18px;line-height:1.7;">
<li data-i18n="step1">Настройте <strong>ключ доступа</strong> в разделе «Настройка → Магазин → Доступ к статистике продаж»</li>
<li data-i18n="step2">Укажите ключ и URL вашего сайта в <strong>конфигурации</strong> ниже</li>
<li data-i18n="step3">Выберите коллекции коллекций для работы</li>
<li data-i18n="step4">Нажмите <strong>"СКАЧАТЬ"</strong> — коллекции скачаются в папку <code><?=htmlspecialchars($currentSite)?>/products/</code></li>
<li data-i18n="step5">При получении коллекции одной группы (одинаковый slug) сверяются с эталонной версией (выбранный язык в конфигурации). <code>multilangid</code>, <code>stock_status</code>, <code>price</code>, <code>new</code>, <code>featured</code>, <code>promotion</code>, <code>price_old</code>, <code>status</code>, <code>datestamp</code> приводятся к эталону</li>
<li data-i18n="step6">Отредактируйте HTML-файлы в <code><?=htmlspecialchars($currentSite)?>/products/</code> при необходимости</li>
<li data-i18n="step7">Нажмите <strong>"ОТПРАВИТЬ"</strong> — изменения отправятся на сайт</li>
</ol>
<h3 data-i18n="file_naming">Именование файлов</h3><p data-i18n="file_naming_desc">Шаблон: <code>{id}-{name}-{language}.html</code>. Пример: <code>123-moya-statya-ru.html</code></p>
<h3 data-i18n="file_format">Формат файла</h3><p data-i18n="file_format_desc">Мета-данные и поля коллекции в <code>&lt;meta name="..." content="..."&gt;</code> передают настройки коллекции: slug, заголовок, язык, теги, дату публикации, статус доступа, категорию, цену, наличие, описание и системные параметры. Формат: текст коллекции — после <code>&lt;!-- COLLECTION TEXT BELOW --&gt;</code>, кнопка — после <code>&lt;!-- COLLECTION BUTTON BELOW --&gt;</code></p>
<p style="color:#ff9800;font-size:12px;margin-top:6px;">💡 <strong>Удаление:</strong> добавьте <code>&lt;meta name="delete" content="true"&gt;</code> в HTML-файл и укажите <code>id</code> коллекции — при экспорте запись будет удалена с сервера.</p>
</div></details>
<div class="card"><div class="card-header" data-i18n="actions_title">⚡ Действия</div><div class="card-body">
<div class="btn-group"><a href="?action=get&site=<?=urlencode($currentSite)?>" class="btn btn-primary" data-i18n="btn_get">📥 СКАЧАТЬ</a>
<a href="?action=update&site=<?=urlencode($currentSite)?>" class="btn btn-success" data-i18n="btn_update">📤 ОТПРАВИТЬ</a>
<a href="?action=update&dry-run&site=<?=urlencode($currentSite)?>" class="btn btn-warning" data-i18n="btn_dryrun">🔍 Тест (сухая отправка)</a></div>
<div style="font-size:12px;color:#888;margin-top:8px;" data-i18n="dryrun_desc">Режим «тест» — проверяет какие коллекции будут отправлены, но сами запросы к API не выполняются.</div>
</div></div>
<?php if (!empty($_GET['added']) && $_GET['added'] === '1'): ?>
<div style="background:#1a3a1a;border:1px solid #4caf50;border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#a5d6a7;">
  ✅ Сайт <strong><?=htmlspecialchars($currentSite)?></strong> добавлен. Укажите API-ключ в настройках ниже и нажмите «Сохранить».
</div>
<?php endif; ?>
<details class="card"><summary><span data-i18n="config_title">⚙ Конфигурация</span> <span class="arrow">▶</span></summary><div class="card-body">
<form method="post">
<input type="hidden" name="site" value="<?=htmlspecialchars($currentSite)?>">
<div style="background:#1a3a1a;border:1px solid #ff9800;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:12px;" data-i18n="api_note">
⚠ Ключ доступа для текущего сайта <strong><?=htmlspecialchars($currentSite)?></strong>. Чтобы добавить новый сайт — используйте селектор сайтов вверху → <strong>«+ Добавить сайт»</strong>.
</div>
<input type="hidden" name="site_domain[]" value="<?=htmlspecialchars($currentSite)?>">
<div class="form-row"><div class="field">
  <label data-i18n="lbl_key">🔑 Ключ доступа (Consumer Secret)</label>
  <input type="text" name="site_key[]" value="<?=htmlspecialchars($AUTH_KEY)?>" style="font-family:monospace;">
</div></div>
<div style="margin-top:8px;">
  <a href="?action=delete_site&site=<?=urlencode($currentSite)?>" onclick="if(!confirm('Удалить настройки сайта «<?=htmlspecialchars($currentSite)?>»? Это действие нельзя отменить.'))return false;" style="color:#f44336;font-size:12px;text-decoration:none;border:1px solid #f44336;padding:4px 10px;border-radius:4px;display:inline-block;" data-i18n="delete_site">🗑 Удалить настройки сайта</a>
</div>
<hr><h3 data-i18n="cat_title">📂 Разрешённые коллекции</h3><p style="color:#888;font-size:12px;margin-bottom:10px;" data-i18n="cat_desc">Только коллекции этих коллекций будут получены и отправлены. Если список пуст — обрабатываются все.</p>
<div style="display:grid;grid-template-columns:100px 1fr 40px;gap:8px;align-items:center;margin-bottom:6px;font-size:12px;color:#888;"><span data-i18n="cat_id_header">ID</span><span data-i18n="cat_name_header">Имя коллекции</span><span></span></div>
<div id="cat-container"><?php $i=0;foreach($ALLOWED_COLLECTIONS as $cid=>$cname):?>
<div class="form-row coll-row"><div class="field-sm"><input type="text" name="cat_id[]" value="<?=$cid?>"></div>
<div class="field"><input type="text" name="cat_name[]" value="<?=htmlspecialchars($cname)?>"></div>
<div style="flex:0 0 40px;text-align:center;"><button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.coll-row').remove()">✕</button></div></div>
<?php $i++;endforeach;?></div>
<button type="button" class="btn btn-primary btn-sm" onclick="addCatRow()" style="margin-top:6px;" data-i18n="btn_add_cat">+ Добавить категорию</button>
<hr><h3 data-i18n="import_settings_title">📥 Настройки импорта</h3>
<div style="font-size:12px;color:#888;margin-bottom:8px;" data-i18n="import_settings_desc">Параметры, влияющие на получение и сохранение коллекций из API</div>
<h3 style="margin:10px 0 8px;font-size:14px;color:#888;" data-i18n="folder_structure">📁 Структура папок</h3>
<div class="form-check"><input type="hidden" name="IMPORT_ONLY_NAMED" value="0"><input type="checkbox" name="IMPORT_ONLY_NAMED" id="ion" value="1"<?=$IMPORT_ONLY_NAMED?' checked':''?>><label for="ion" style="display:inline;margin:0;" data-i18n="import_only_named">Только с именем (пропускать без name)</label></div>
<h3 style="margin:12px 0 8px;font-size:14px;color:#888;" data-i18n="products_count_title">📥 Количество коллекций</h3>
<div class="form-row"><div class="field" style="max-width:200px;">
<label data-i18n="per_page_label">Производителей за запрос (per_page)</label>
<div style="font-size:11px;color:#888;margin-bottom:6px;" data-i18n="per_page_desc">Сколько коллекций загружать за 1 запрос к API. Максимум 2000</div>
<input type="number" name="PER_PAGE" value="<?=(int)($PER_PAGE??200)?>" min="1" max="2000">
</div></div>
<h3 style="margin:12px 0 8px;font-size:14px;color:#888;" data-i18n="fix_export_title">🔧 Синхронизация полей коллекции</h3>
<div class="form-row"><div class="field" style="max-width:250px;">
<label data-i18n="ref_lang_label">🌐 Язык эталонной коллекции</label>
<div style="font-size:11px;color:#888;margin-bottom:6px;" data-i18n="ref_lang_desc">Коллекции этого языка считаются эталоном. При получении коллекции других языков сверяются с ним</div>
<select name="REFERENCE_LANG">
<option value="be"<?=($REFERENCE_LANG?:'pl')==='be'?' selected':''?>>Белорусский (be)</option>
<option value="de"<?=($REFERENCE_LANG?:'pl')==='de'?' selected':''?>>Немецкий (de)</option>
<option value="en"<?=($REFERENCE_LANG?:'pl')==='en'?' selected':''?>>English (en)</option>
<option value="es"<?=($REFERENCE_LANG?:'pl')==='es'?' selected':''?>>Испанский (es)</option>
<option value="fr"<?=($REFERENCE_LANG?:'pl')==='fr'?' selected':''?>>Français (fr)</option>
<option value="it"<?=($REFERENCE_LANG?:'pl')==='it'?' selected':''?>>Italiano (it)</option>
<option value="kk"<?=($REFERENCE_LANG?:'pl')==='kk'?' selected':''?>>Қазақ (kk)</option>
<option value="pl"<?=($REFERENCE_LANG?:'pl')==='pl'?' selected':''?>>Polski (pl)</option>
<option value="ru"<?=($REFERENCE_LANG?:'pl')==='ru'?' selected':''?>>Русский (ru)</option>
<option value="ua"<?=($REFERENCE_LANG?:'pl')==='ua'?' selected':''?>>Українська (ua)</option>
</select>
</div></div>
<hr><h3 data-i18n="export_settings_title">📤 Настройки экспорта</h3>
<div style="font-size:12px;color:#888;margin-bottom:8px;" data-i18n="export_settings_desc">Параметры, влияющие на отправку коллекций на сайт</div>
<div class="form-row"><div class="field" style="max-width:400px;">
<label data-i18n="date_mode_label">📅 Режим даты публикации</label>
<div style="font-size:11px;color:#888;margin-bottom:6px;" data-i18n="date_mode_desc">Как определять дату публикации коллекций при импорте</div>
<select name="DATE_MODE" id="date_mode" onchange="toggleDateFields()">
<option value=""<?=$DATE_MODE===''?' selected':''?> data-i18n="date_mode_meta">Из мета-данных (дата из каждого коллекции)</option>
<option value="fixed"<?=$DATE_MODE==='fixed'?' selected':''?> data-i18n="date_mode_fixed">Одна дата для всех коллекций</option>
<option value="offset"<?=$DATE_MODE==='offset'?' selected':''?> data-i18n="date_mode_offset">Смещение дат (+N дней на коллекция)</option>
</select>
</div></div>
<div id="date_fixed_block" style="display:<?=$DATE_MODE==='fixed'?'block':'none'?>;margin-top:-10px;">
<div class="form-row"><div class="field" style="max-width:300px;">
<label data-i18n="date_fixed_label">📅 Фиксированная дата</label>
<div style="font-size:11px;color:#888;margin-bottom:6px;" data-i18n="date_fixed_desc">Эта дата будет установлена всем коллекциям</div>
<input type="date" name="DATE_FIXED" value="<?=htmlspecialchars($DATE_FIXED?:date('Y-m-d'))?>">
</div></div>
</div>
<div id="date_offset_block" style="display:<?=$DATE_MODE==='offset'?'block':'none'?>;margin-top:-10px;">
<div class="form-row"><div class="field" style="max-width:250px;">
<label data-i18n="date_offset_label">📅 Базовая дата (от которой начинать отсчёт)</label>
<input type="date" name="DATE_OFFSET_BASE" value="<?=htmlspecialchars($DATE_OFFSET_BASE?:date('Y-m-d'))?>">
</div>
<div class="field" style="max-width:120px;">
<label data-i18n="date_offset_days">+ дней на статью</label>
<input type="number" name="DATE_OFFSET_DAYS" value="<?=(int)($DATE_OFFSET_DAYS??1)?>" min="0" max="365">
</div></div>
<div style="font-size:11px;color:#888;" data-i18n="date_offset_desc">Первая уникальная коллекция (по slug) получит базовую дату, вторая — базовую дату + N дней, и т.д. Коллекции на разных языках с одинаковым slug считаются одной производителем и получают одинаковую дату.</div>
</div>
<script>
function toggleDateFields(){
var m=document.getElementById('date_mode').value;
document.getElementById('date_fixed_block').style.display=(m==='fixed'?'block':'none');
document.getElementById('date_offset_block').style.display=(m==='offset'?'block':'none');
}
</script>
<div class="form-row"><div class="field" style="max-width:350px;">
<label data-i18n="status_mode_label">🔒 Статус доступа (status)</label>
<div style="font-size:11px;color:#888;margin-bottom:6px;" data-i18n="status_mode_desc">Режим определения статуса публикации коллекций при импорте</div>
<select name="STATUS_MODE" id="status_mode" onchange="toggleStatusFields()">
<option value=""<?=$STATUS_MODE===''?' selected':''?> data-i18n="status_mode_meta">Из мета-данных (статус из каждого коллекции)</option>
<option value="override"<?=$STATUS_MODE==='override'?' selected':''?> data-i18n="status_mode_override">Переопределить для всех коллекций</option>
</select>
</div></div>
<div id="status_override_block" style="display:<?=$STATUS_MODE==='override'?'block':'none'?>;margin-top:-10px;">
<div class="form-row"><div class="field" style="max-width:200px;">
<label data-i18n="status_value_label">Значение статуса</label>
<select name="STATUS_OVERRIDE">
<option value="1"<?=$STATUS_OVERRIDE==1?' selected':''?> data-i18n="status_published">1 — опубликовано (доступно)</option>
<option value="0"<?=$STATUS_OVERRIDE===0?' selected':''?> data-i18n="status_hidden">0 — скрыто (недоступно)</option>
</select>
</div></div>
</div>
<h3 style="margin:10px 0 8px;font-size:14px;color:#888;" data-i18n="send_limit_title">📦 Лимит отправки</h3>
<div class="form-row"><div class="field" style="max-width:200px;">
<label data-i18n="send_limit_label">Лимит отправки за 1 раз</label>
<div style="font-size:11px;color:#888;margin-bottom:6px;" data-i18n="send_limit_desc">Сколько коллекций можно отправить за один запуск. Максимум 5000</div>
<input type="number" name="SEND_BATCH_LIMIT" value="<?=(int)($SEND_BATCH_LIMIT??200)?>" min="1" max="5000">
</div></div>
<hr><h3 data-i18n="fix_title">🔄 Исправление по эталону</h3>
<div style="font-size:11px;color:#888;margin-bottom:8px;" data-i18n="fix_desc">Какие поля автоматически исправлять по эталону при получении коллекций</div>
<div class="form-check"><input type="checkbox" name="FIX_MULTILANGID" id="fm" value="1"<?=$FIX_MULTILANGID?' checked':''?>><label for="fm" style="display:inline;margin:0;" data-i18n="fix_multilangid">multilangid</label></div>

<div class="form-check"><input type="checkbox" name="FIX_STATUS" id="fs" value="1"<?=$FIX_STATUS?' checked':''?>><label for="fs" style="display:inline;margin:0;" data-i18n="fix_status">status</label></div>
<div class="form-check"><input type="checkbox" name="FIX_DATESTAMP" id="fd" value="1"<?=$FIX_DATESTAMP?' checked':''?>><label for="fd" style="display:inline;margin:0;" data-i18n="fix_datestamp">datestamp</label></div>
<script>
function toggleStatusFields(){
document.getElementById('status_override_block').style.display=(document.getElementById('status_mode').value==='override'?'block':'none');
}
</script>
<hr><button type="submit" name="save_config" class="btn btn-success" data-i18n="btn_save">💾 Сохранить конфигурацию</button>
</form>
</div></details>
<div class="footer"><strong>Boostore.pro</strong> — <span data-i18n="footer_text">Управление коллекциими каталога</span> &nbsp;|&nbsp; <a href="https://boostore.pro/ru/docs/api-integration/#hotengine-CommerceAPI" target="_blank" data-i18n="footer_docs">Документация API</a> &nbsp;|&nbsp; <a href="index.php" style="color:#00d4ff;" data-i18n="back_home">← На главную</a></div>
</div>
<script>
// ===== i18n Translations =====
var i18n = {
  ru: {
    'title':'Управление коллекциями магазина — Boostore.pro',
    'warn_nokey':'⚠ Необходимо указать ключ доступа API (Consumer Secret) в разделе «Конфигурация» ниже, иначе скрипты не будут работать.',
    'warn_domain':'⚠ Для работы API обязательно нужно открывать его с использованием адреса вашего сайта, созданного на платформе Boostore.pro. Измените домен в конфигурации ниже на ваш (например: moy-sayt.boostore.pro).',
    'saved':'Конфигурация сохранена',
    'plaque':'Boostore.pro — Скрипты для экспорта (скачивания) и импорта (отправки) коллекций каталога через Commerce API. Домен: ',
    'plaque_import':'▸ <strong>Настройки импорта</strong> — получение коллекций с API',
    'plaque_export':'▸ <strong>Настройки экспорта</strong> — отправка коллекций на Boostore.pro',
    'instr_title':'📖 Инструкция',
    'instr_intro':'Все настройки — в разделе «Конфигурация» ниже. Если список коллекций пуст — обрабатываются все коллекции.',
    'quickstart':'Быстрый старт',
    'step1':'Настройте ключ доступа в разделе «Настройка → Магазин → Доступ к статистике продаж»',
    'step2':'Укажите ключ и URL вашего сайта в конфигурации ниже',
    'step3':'Выберите коллекции коллекций для работы',
    'step4':'Нажмите «ПОЛУЧИТЬ СТАТЬИ» — коллекции скачаются в папку products/',
    'step5':'При получении коллекции одной группы (одинаковый slug) сверяются с эталонной версией (выбранный язык в конфигурации). multilangid, stock_status, price, new, featured, promotion, price_old, status, datestamp приводятся к эталону',
    'step6':'Отредактируйте HTML-файлы в products/ при необходимости',
    'step7':'Нажмите «ОТПРАВИТЬ СТАТЬИ» — изменения отправятся на сайт',
    'file_naming':'Именование файлов',
    'file_naming_desc':'Шаблон: {id}-{name}-{language}.html. Пример: 123-moya-statya-ru.html',
    'file_format':'Формат файла',
    'file_format_desc':'Мета-данные в &lt;meta name=&quot;...&quot; content=&quot;...&quot;&gt; передают настройки коллекции. Текст — после &lt;!-- COLLECTION TEXT BELOW --&gt;, кнопка — после &lt;!-- COLLECTION BUTTON BELOW --&gt;',
    'actions_title':'⚡ Действия',
    'btn_get':'📥 СКАЧАТЬ',
    'btn_update':'📤 ОТПРАВИТЬ',
    'btn_dryrun':'🔍 Тест (сухая отправка)',
    'dryrun_desc':'Режим «тест» — проверяет какие коллекции будут отправлены, но сами запросы к API не выполняются.',
    'btn_save':'💾 Сохранить конфигурацию',
    'config_title':'⚙ Конфигурация',
    'lbl_key':'🔑 Ключ доступа (Consumer Secret)',
    'lbl_url':'🌐 URL API',
    'lbl_url':'🌐 Домен сайта (например: site.boostore.pro)',
    'api_note':'⚠ Для работы API обязательно нужно открывать его с использованием адреса вашего сайта, созданного на платформе Boostore.pro. Измените домен ниже на ваш.',
    'cat_title':'📂 Разрешённые коллекции',
    'cat_desc':'Только коллекции этих коллекций будут получены и отправлены. Если список пуст — обрабатываются все.',
    'footer_text':'Управление коллекциями магазина',
    'footer_docs':'Документация API',
    'confirm_get':'Запустить получение коллекций?',
    'confirm_update':'Запустить отправку коллекций?',
    'confirm_dryrun':'Запустить пробную отправку?',
    'ref_lang_be':'Белорусский (be)',
    'ref_lang_en':'English (en)',
    'ref_lang_ru':'Русский (ru)',
    'ref_lang_ua':'Українська (ua)',
    'ref_lang_pl':'Polski (pl)',
    'date_mode_meta':'Из мета-данных (дата из каждого коллекции)',
    'date_mode_fixed':'Одна дата для всех коллекций',
    'date_mode_offset':'Смещение дат (+N дней на коллекция)',
    'planned_notset':'— не указано (из мета-данных)',
    'planned_0':'0 — не отложенная',
    'planned_1':'1 — отложенная публикация',
    'status_mode_meta':'Из мета-данных (статус из каждого коллекции)',
    'status_mode_override':'Переопределить для всех коллекций',
    'export_settings_title':'📤 Настройки экспорта',
    'export_settings_desc':'Параметры, влияющие на получение и сохранение коллекций из API',
    'folder_structure':'📁 Структура папок',
    'folder_planned':'Разделять planned в <code>products/planned/</code>',
    'folder_collection':'Разделять по папкам коллекций',
    'products_count_title':'📥 Количество коллекций',
    'per_page_label':'Производителей за запрос (per_page)',
    'per_page_desc':'Сколько коллекций загружать за 1 запрос к API. Максимум 2000',
    'fix_export_title':'🔧 Исправление при экспорте',
    'ref_lang_label':'🌐 Язык эталонной коллекции',
    'ref_lang_desc':'Коллекции этого языка считаются эталоном. При получении коллекции других языков сверяются с ним',
    'import_settings_title':'📥 Настройки импорта',
    'import_settings_desc':'Параметры, влияющие на отправку коллекций на сайт',
    'date_mode_label':'📅 Режим даты публикации',
    'date_mode_desc':'Как определять дату публикации коллекций при импорте',
    'date_fixed_label':'📅 Фиксированная дата',
    'date_fixed_desc':'Эта дата будет установлена всем коллекциям',
    'date_offset_label':'📅 Базовая дата (от которой начинать отсчёт)',
    'date_offset_days':'+ дней на статью',
    'date_offset_desc':'Первая уникальная коллекция (по slug) получит базовую дату, вторая — базовую дату + N дней, и т.д. Коллекции на разных языках с одинаковым slug считаются одной производителем и получают одинаковую дату.',
    'override_planned':'📅 Переопределить planned',
    'override_planned_desc':'Если задать — будет применено ко ВСЕМ коллекциим при импорте. Пусто — брать из мета-данных каждого коллекции',
    'status_mode_label':'🔒 Статус доступа (status)',
    'status_mode_desc':'Режим определения статуса публикации коллекций при импорте',
    'status_value_label':'Значение статуса',
    'status_published':'1 — опубликовано (доступно)',
    'status_hidden':'0 — скрыто (недоступно)',
    'send_limit_title':'📦 Лимит отправки',
    'send_limit_label':'Лимит отправки за 1 раз',
    'send_limit_desc':'Сколько коллекций можно отправить за один запуск. Максимум 5000',
    'fix_title':'🔄 Исправление по эталону',
    'fix_desc':'Какие поля автоматически исправлять по эталону при получении коллекций',
    'back_home':'Назад',
    'btn_more':'+ ЕЩЕ',
    'btn_more_multi':'📋 ЕЩЕ НЕСКОЛЬКО',
    'btn_add_cat':'+ Добавить категорию',
    'per_page_import':'Производителей за запрос',
    'date_from':'Дата с',
    'date_to':'Дата по',
    'lang_label':'Язык',
    'folder_planned_chk':'Разделять planned в <code>products/planned/</code>',
    'folder_collection_chk':'Разделять по папкам коллекций',
    'cat_id_header':'ID',
    'cat_name_header':'Имя коллекции',
    'fix_multilangid':'multilangid',
    'fix_planned':'planned',
    'fix_status':'status',
    'fix_datestamp':'datestamp',
    'import_only_named':'Только с именем','export_collection_name_label':'Имя коллекции',
    'dry_run_label':'Dry run',
    'all_languages':'все',
    'lang_ru':'Русский',
    'lang_en':'English',
    'lang_ua':'Українська',
    'lang_pl':'Polski',
    'lang_de':'Deutsch',
    'lang_fr':'Français',
    'lang_es':'Español',
    'lang_it':'Italiano',
    'lang_kk':'Қазақ',
    'lang_be':'Беларуская',
    'api_docs':'API Docs',
    'version':'v2.0',
    'date_format':'ГГГГ-ММ-ДД',
    'search_placeholder':'часть имени, например: shoes',
    'cat_id_placeholder':'ID',
    'cat_name_placeholder':'имя коллекции',
    'prompt_values':'Введите значения (каждая строка — отдельное поле):',
    'step_forward':'➡ ДАЛЕЕ',
    'filter_name':'Фильтр по имени (slug)',
    'batch_label':'Отправить за 1 раз',
    'home':'🏠 Главная',
    'entity_name':'Коллекции',
    'site_label':'Сайт:',
    'delete_site':'🗑 Удалить настройки сайта',
  },
  en: {
    'title':'Collection Management — Boostore.pro',
    'warn_nokey':'⚠ You need to specify the API access key (Consumer Secret) in the «Configuration» section below, otherwise scripts will not work.',
    'warn_domain':'⚠ API must be accessed using your site\'s domain created on the Boostore.pro platform. Change the domain in the configuration below to yours (e.g. my-site.boostore.pro).',
    'saved':'Configuration saved',
    'plaque':'Boostore.pro — Scripts for exporting (downloading) and importing (uploading) blog products via Commerce API. Domain: ',
    'plaque_import':'▸ <strong>Import Settings</strong> — fetching products from API',
    'plaque_export':'▸ <strong>Export Settings</strong> — sending products to Boostore.pro',
    'instr_title':'📖 Instructions',
    'instr_intro':'All settings are in the «Configuration» section below. If the category list is empty, all categories are processed.',
    'quickstart':'Quick Start',
    'step1':'Set up the access key in «Settings → Store → Sales statistics access»',
    'step2':'Specify the key and your site URL in the configuration below',
    'step3':'Select article categories to work with',
    'step4':'Click «START IMPORT» — products will be downloaded to the products/ folder',
    'step5':'Products in the same group (same slug) are checked against the reference language version (set in config). multilangid, planned, status, datestamp are synced to reference',
    'step6':'Edit HTML files in products/ if needed',
    'step7':'Click «START EXPORT» — changes will be uploaded to the site',
    'file_naming':'File Naming',
    'file_naming_desc':'Template: {id}-{name}-{language}.html. Example: 123-moya-statya-ru.html',
    'file_format':'File Format',
    'file_format_desc':'Meta data in &lt;meta name=&quot;...&quot; content=&quot;...&quot;&gt; carries collection settings. Text after &lt;!-- COLLECTION TEXT BELOW --&gt;, button after &lt;!-- COLLECTION BUTTON BELOW --&gt;',
    'actions_title':'⚡ Actions',
    'btn_get':'📥 DOWNLOAD',
    'btn_update':'📤 UPLOAD',
    'btn_dryrun':'🔍 Test (dry run — no API calls)',
    'dryrun_desc':'Test mode — checks which products will be sent, but no actual API requests are made.',
    'btn_save':'💾 Save Configuration',
    'config_title':'⚙ Configuration',
    'lbl_key':'🔑 Access Key (Consumer Secret)',
    'lbl_url':'🌐 API URL',
    'lbl_url':'🌐 Site domain (e.g. site.boostore.pro)',
    'api_note':'⚠ API must be accessed using your site\'s domain created on the Boostore.pro platform. Change the domain below to yours.',
    'cat_title':'📂 Allowed Categories',
    'cat_desc':'Only products from these categories will be fetched and sent. If the list is empty, all categories are processed.',
    'footer_text':'Blog Article Management',
    'footer_docs':'API Documentation',
    'confirm_get':'Start fetching products?',
    'confirm_update':'Start sending products?',
    'confirm_dryrun':'Start dry-run?',
    'ref_lang_be':'Belarusian (be)',
    'ref_lang_en':'English (en)',
    'ref_lang_ru':'Russian (ru)',
    'ref_lang_ua':'Ukrainian (ua)',
    'ref_lang_pl':'Polish (pl)',
    'date_mode_meta':'From meta-data (date from each article)',
    'date_mode_fixed':'Single date for all products',
    'date_mode_offset':'Date offset (+N days per article)',
    'planned_notset':'— not set (from meta-data)',
    'planned_0':'0 — not planned',
    'planned_1':'1 — planned publishing',
    'status_mode_meta':'From meta-data (status from each article)',
    'status_mode_override':'Override for all products',
    'export_settings_title':'📤 Export Settings',
    'export_settings_desc':'Parameters affecting fetching and saving products from the API',
    'folder_structure':'📁 Folder Structure',
    'folder_planned':'Separate planned into <code>products/planned/</code>',
    'folder_collection':'Separate by category folders',
    'products_count_title':'📥 Products Count',
    'per_page_label':'Products per request (per_page)',
    'per_page_desc':'How many products to load per API request. Max 2000',
    'fix_export_title':'🔧 Fix on Export',
    'ref_lang_label':'🌐 Reference Language',
    'ref_lang_desc':'Products in this language are considered reference. When fetching products in other languages, they are checked against it',
    'import_settings_title':'📥 Import Settings',
    'import_settings_desc':'Parameters affecting sending products to the site',
    'date_mode_label':'📅 Publication Date Mode',
    'date_mode_desc':'How to determine article publication date during import',
    'date_fixed_label':'📅 Fixed Date',
    'date_fixed_desc':'This date will be set for all products',
    'date_offset_label':'📅 Base Date (start offset from)',
    'date_offset_days':'+ days per article',
    'date_offset_desc':'The first unique article (by slug) gets the base date, the second gets base date + N days, etc. Products in different languages with the same slug count as one article and get the same date.',
    'override_planned':'📅 Override planned',
    'override_planned_desc':'If set, applies to ALL products during import. Empty — use each article\'s meta-data',
    'status_mode_label':'🔒 Access Status (status)',
    'status_mode_desc':'How to determine article publication status during import',
    'status_value_label':'Status Value',
    'status_published':'1 — published (public)',
    'status_hidden':'0 — hidden (private)',
    'send_limit_title':'📦 Send Limit',
    'send_limit_label':'Send limit per run',
    'send_limit_desc':'How many products can be sent in one run. Max 5000',
    'fix_title':'🔄 Fix by Reference',
    'fix_desc':'Which fields to auto-fix by reference when fetching products',
    'back_home':'Home',
    'btn_more':'+ MORE',
    'btn_more_multi':'📋 ADD MULTIPLE',
    'btn_add_cat':'+ Add Category',
    'per_page_import':'Products per request',
    'date_from':'Date from',
    'date_to':'Date to',
    'lang_label':'Language',
    'folder_planned_chk':'Separate planned into <code>products/planned/</code>',
    'folder_collection_chk':'Separate by category folders',
    'cat_id_header':'ID',
    'cat_name_header':'Category name',
    'fix_multilangid':'multilangid',
    'fix_planned':'planned',
    'fix_status':'status',
    'fix_datestamp':'datestamp',
    'import_only_named':'Named only','export_collection_name_label':'Collection name',
    'dry_run_label':'Dry run',
    'all_languages':'all',
    'lang_ru':'Russian',
    'lang_en':'English',
    'lang_ua':'Ukrainian',
    'lang_pl':'Polish',
    'lang_de':'German',
    'lang_fr':'French',
    'lang_es':'Spanish',
    'lang_it':'Italian',
    'lang_kk':'Kazakh',
    'lang_be':'Belarusian',
    'api_docs':'API Docs',
    'version':'v2.0',
    'date_format':'YYYY-MM-DD',
    'search_placeholder':'part of name, e.g.: shoes',
    'cat_id_placeholder':'ID',
    'cat_name_placeholder':'category name',
    'prompt_values':'Enter values (each line is a separate field):',
    'step_forward':'➡ NEXT',
    'filter_name':'Filter by name (slug)',
    'batch_label':'Send per run',
'home':'🏠 Home',
    'entity_name':'Collections',
    'site_label':'Site:',
    'delete_site':'🗑 Delete site settings',
  },
  ua: {
    'title':'Керування статтями блогу — Boostore.pro',
    'warn_nokey':'⚠ Необхідно вказати ключ доступу API (Consumer Secret) у розділі «Конфігурація» нижче, інакше скрипти не будуть працювати.',
    'warn_domain':'⚠ Для роботи API обов\'язково потрібно відкривати його з використанням адреси вашого сайту, створеного на платформі Boostore.pro. Змініть домен у конфігурації нижче на ваш (наприклад: miy-sayt.boostore.pro).',
    'saved':'Конфігурацію збережено',
    'plaque':'Boostore.pro — Скрипти для експорту (завантаження) та імпорту (відправлення) коллекций блогу через Commerce API. Домен: ',
    'plaque_import':'▸ <strong>Налаштування імпорту</strong> — отримання коллекций з API',
    'plaque_export':'▸ <strong>Налаштування експорту</strong> — відправлення коллекций на Boostore.pro',
    'instr_title':'📖 Інструкція',
    'instr_intro':'Всі налаштування — у розділі «Конфігурація» нижче. Якщо список категорій порожній, обробляються всі категорії.',
    'quickstart':'Швидкий старт',
    'step1':'Налаштуйте ключ доступу в розділі «Налаштування → Магазин → Доступ до статистики продажів»',
    'step2':'Вкажіть ключ та URL вашого сайту в конфігурації нижче',
    'step3':'Виберіть категорії коллекций для роботи',
    'step4':'Натисніть «ОТРИМАТИ СТАТТІ» — статті завантажаться в папку products/',
    'step5':'Статті однієї групи (однаковий slug) звіряються з еталонною версією (вибрана мова в конфігурації). multilangid, planned, status, datestamp приводяться до еталона',
    'step6':'Відредагуйте HTML-файли в products/ за потреби',
    'step7':'Натисніть «ВІДПРАВИТИ СТАТТІ» — зміни відправляться на сайт',
    'file_naming':'Іменування файлів',
    'file_naming_desc':'Шаблон: {id}-{name}-{language}.html. Приклад: 123-moya-statya-ru.html',
    'file_format':'Формат файлу',
    'file_format_desc':'Мета-дані в &lt;meta name=&quot;...&quot; content=&quot;...&quot;&gt; передають налаштування колекції. Текст — після &lt;!-- COLLECTION TEXT BELOW --&gt;, кнопка — після &lt;!-- COLLECTION BUTTON BELOW --&gt;',
    'actions_title':'⚡ Дії',
    'btn_get':'📥 ОТРИМАТИ СТАТТІ',
    'btn_update':'📤 ВІДПРАВИТИ СТАТТІ',
    'btn_dryrun':'🔍 Тест (сухе відправлення)',
    'dryrun_desc':'Режим «тест» — перевіряє які статті будуть відправлені, але самі запити до API не виконуються.',
    'btn_save':'💾 Зберегти конфігурацію',
    'config_title':'⚙ Конфігурація',
    'lbl_key':'🔑 Ключ доступу (Consumer Secret)',
    'lbl_url':'🌐 URL API',
    'lbl_url':'🌐 Домен сайту (наприклад: site.boostore.pro)',
    'api_note':'⚠ Для роботи API обов\'язково потрібно відкривати його з використанням адреси вашого сайту, створеного на платформі Boostore.pro. Змініть домен нижче на ваш.',
    'cat_title':'📂 Дозволені категорії',
    'cat_desc':'Тільки статті з цих категорій будуть отримані та відправлені. Якщо список порожній — обробляються всі.',
    'footer_text':'Керування статтями блогу',
    'footer_docs':'Документація API',
    'confirm_get':'Запустити отримання коллекций?',
    'confirm_update':'Запустити відправлення коллекций?',
    'confirm_dryrun':'Запустити пробне відправлення?',
    'ref_lang_be':'Білоруська (be)',
    'ref_lang_en':'Англійська (en)',
    'ref_lang_ru':'Російська (ru)',
    'ref_lang_ua':'Українська (ua)',
    'ref_lang_pl':'Польська (pl)',
    'date_mode_meta':'З мета-даних (дата з кожної статті)',
    'date_mode_fixed':'Одна дата для всіх коллекций',
    'date_mode_offset':'Зміщення дат (+N днів на статтю)',
    'planned_notset':'— не вказано (з мета-даних)',
    'planned_0':'0 — не відкладена',
    'planned_1':'1 — відкладена публікація',
    'status_mode_meta':'З мета-даних (статус з кожної статті)',
    'status_mode_override':'Перевизначити для всіх коллекций',
    'export_settings_title':'📤 Налаштування експорту',
    'export_settings_desc':'Параметри, що впливають на отримання та збереження коллекций з API',
    'folder_structure':'📁 Структура папок',
    'folder_planned':'Розділяти planned у <code>products/planned/</code>',
    'folder_collection':'Розділяти по папках категорій',
    'products_count_title':'📥 Кількість коллекций',
    'per_page_label':'Статей за запит (per_page)',
    'per_page_desc':'Скільки коллекций завантажувати за 1 запит до API. Максимум 2000',
    'fix_export_title':'🔧 Виправлення при експорті',
    'ref_lang_label':'🌐 Мова еталонної статті',
    'ref_lang_desc':'Статті цієї мови вважаються еталоном. При отриманні коллекций іншими мовами звіряються з ним',
    'import_settings_title':'📥 Налаштування імпорту',
    'import_settings_desc':'Параметри, що впливають на відправлення коллекций на сайт',
    'date_mode_label':'📅 Режим дати публікації',
    'date_mode_desc':'Як визначати дату публікації коллекций при імпорті',
    'date_fixed_label':'📅 Фіксована дата',
    'date_fixed_desc':'Ця дата буде встановлена для всіх коллекций',
    'date_offset_label':'📅 Базова дата (від якої починати відлік)',
    'date_offset_days':'+ днів на статтю',
    'date_offset_desc':'Перша унікальна стаття (по slug) отримує базову дату, друга — базову дату + N днів, і т.д. Статті різними мовами з однаковим slug вважаються однією статтею та отримують однакову дату.',
    'override_planned':'📅 Перевизначити planned',
    'override_planned_desc':'Якщо задано — застосовується до ВСІХ коллекций при імпорті. Порожньо — брати з мета-даних кожної статті',
    'status_mode_label':'🔒 Статус доступу (status)',
    'status_mode_desc':'Режим визначення статусу публікації коллекций при імпорті',
    'status_value_label':'Значення статусу',
    'status_published':'1 — опубліковано (доступно)',
    'status_hidden':'0 — приховано (недоступно)',
    'send_limit_title':'📦 Ліміт відправлення',
    'send_limit_label':'Ліміт відправлення за 1 раз',
    'send_limit_desc':'Скільки коллекций можна відправити за один запуск. Максимум 5000',
    'fix_title':'🔄 Виправлення за еталоном',
    'fix_desc':'Які поля автоматично виправляти за еталоном при отриманні коллекций',
    'back_home':'На головну',
    'btn_more':'+ ЩЕ',
    'btn_more_multi':'📋 ДОДАТИ КІЛЬКА',
    'btn_add_cat':'+ Додати категорію',
    'per_page_import':'Статей за запит',
    'date_from':'Дата з',
    'date_to':'Дата по',
    'lang_label':'Мова',
    'folder_planned_chk':'Розділяти planned у <code>products/planned/</code>',
    'folder_collection_chk':'Розділяти по папках категорій',
    'cat_id_header':'ID',
    'cat_name_header':'Ім\'я категорії',
    'fix_multilangid':'multilangid',
    'fix_planned':'planned',
    'fix_status':'status',
    'fix_datestamp':'datestamp',
    'import_only_named':'Тільки з іменем',
    'dry_run_label':'Dry run',
    'all_languages':'всі',
    'lang_ru':'Російська',
    'lang_en':'Англійська',
    'lang_ua':'Українська',
    'lang_pl':'Польська',
    'lang_de':'Німецька',
    'lang_fr':'Французька',
    'lang_es':'Іспанська',
    'lang_it':'Італійська',
    'lang_kk':'Казахська',
    'lang_be':'Білоруська',
    'api_docs':'API Docs',
    'version':'v2.0',
    'date_format':'РРРР-ММ-ДД',
    'search_placeholder':'частина імені, наприклад: shoes',
    'cat_id_placeholder':'ID',
    'cat_name_placeholder':'ім\'я категорії',
    'prompt_values':'Введіть значення (кожен рядок — окреме поле):',
    'step_forward':'➡ ДАЛІ',
    'filter_name':'Фільтр за іменем (slug)',
    'batch_label':'Відправити за 1 раз',
    'home':'🏠 Головна',
    'entity_name':'Колекції',
    'site_label':'Сайт:',
    'delete_site':'🗑 Видалити налаштування сайту',
  }
};
function applyLang(lang) {
  var t = i18n[lang] || i18n.en;
  document.querySelectorAll('[data-i18n]').forEach(function(el) {
    var key = el.getAttribute('data-i18n');
    if (t[key] !== undefined) el.innerHTML = t[key];
  });
  document.querySelectorAll('[data-i18n-placeholder]').forEach(function(el) {
    var key = el.getAttribute('data-i18n-placeholder');
    if (t[key] !== undefined) el.placeholder = t[key];
  });
  try { localStorage.setItem('boostore_lang', lang); } catch(e){}
}
(function(){
  var lang = 'ru';
  try {
    var s = (navigator.language || navigator.userLanguage || '').substr(0,2);
    if (i18n[s]) lang = s; else lang = 'en';
    var saved = localStorage.getItem('boostore_lang');
    if (saved && i18n[saved]) lang = saved;
  } catch(e){ lang = 'en'; }
  document.getElementById('lang_switcher').value = lang;
  applyLang(lang);
})();
function addCatRow(){var d=document.getElementById('cat-container'),div=document.createElement('div');div.className='form-row coll-row';div.innerHTML='<div class="field-sm"><input type="text" name="cat_id[]" value="" placeholder="ID" data-i18n-placeholder="cat_id_placeholder"></div><div class="field"><input type="text" name="cat_name[]" value="" placeholder="name" data-i18n-placeholder="cat_name_placeholder"></div><div style="flex:0 0 40px;text-align:center;"><button type="button" class="btn btn-danger btn-sm" onclick="this.closest(\'.coll-row\').remove()">✕</button></div>';d.appendChild(div);}

</script>
</body>
</html>