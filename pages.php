<?php
// ===================================================================
// ИМПОРТ/ЭКСПОРТ СТРАНИЦ (Pages Boostore.pro)
// ===================================================================
// Инструкция: https://boostore.pro/ru/docs/api-integration/#hotengine-CommerceAPI
// ===================================================================

// Auto-create config file if not exists
$configFile = __DIR__ . '/_setting_pages.inc';
if (!file_exists($configFile)) {
    $defaultConfig = "<?php
// ===================================================================
// НАСТРОЙКИ ИМПОРТА/ЭКСПОРТА СТРАНИЦ (Pages Boostore.pro)
// ===================================================================
// Инструкция: https://boostore.pro/ru/docs/api-integration/#hotengine-CommerceAPI
// ===================================================================
//
// === МНОГОСАЙТОВОСТЬ ===
// Каждый сайт = отдельная папка с собственными настройками
\$SITES = [
    'site.boostore.pro' => [
        'key' => '',
        'status_mode' => '',
        'status_override' => 1,
        'date_mode' => '',
        'date_fixed' => '',
        'date_offset_base' => '',
        'date_offset_days' => 1,
        'per_page' => 200,
        'send_batch_limit' => 200,
        'reference_lang' => 'ru',
        'fix_multilangid' => false,
        'fix_status' => false,
        'fix_datestamp' => false,
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
$API_URL = 'https://' . $currentSite . '/api/commerce/pages';

// Extract per-site config into global variables (backward-compatible)
$siteCfg = $SITES[$currentSite] ?? [];
$STATUS_MODE         = $siteCfg['status_mode'] ?? ($STATUS_MODE ?? '');
$STATUS_OVERRIDE     = $siteCfg['status_override'] ?? ($STATUS_OVERRIDE ?? 1);
$DATE_MODE           = $siteCfg['date_mode'] ?? ($DATE_MODE ?? '');
$DATE_FIXED          = $siteCfg['date_fixed'] ?? ($DATE_FIXED ?? '');
$DATE_OFFSET_BASE    = $siteCfg['date_offset_base'] ?? ($DATE_OFFSET_BASE ?? '');
$DATE_OFFSET_DAYS    = $siteCfg['date_offset_days'] ?? ($DATE_OFFSET_DAYS ?? 1);
$PER_PAGE            = $siteCfg['per_page'] ?? ($PER_PAGE ?? 200);
$SEND_BATCH_LIMIT    = $siteCfg['send_batch_limit'] ?? ($SEND_BATCH_LIMIT ?? 200);
$REFERENCE_LANG      = $siteCfg['reference_lang'] ?? 'pl';
$FIX_MULTILANGID     = $siteCfg['fix_multilangid'] ?? ($FIX_MULTILANGID ?? false);
$FIX_STATUS          = $siteCfg['fix_status'] ?? ($FIX_STATUS ?? false);
$FIX_DATESTAMP       = $siteCfg['fix_datestamp'] ?? ($FIX_DATESTAMP ?? false);

// Site directory (parent folder named after domain)
$SITE_DIR = __DIR__ . DIRECTORY_SEPARATOR . $currentSite;
$PAGES_DIR = $SITE_DIR . DIRECTORY_SEPARATOR . 'pages';
// Ensure site directory + pages subfolder exist
if (!is_dir($PAGES_DIR)) { @mkdir($PAGES_DIR, 0777, true); }

// Helper: export $SITES array in short array syntax (full per-site config)
function sitesExport($sites) {
    $c = "[\n";
    foreach ($sites as $sDomain => $sCfg) {
        $c .= "    ".var_export($sDomain, true)." => [\n";
        $c .= "        'key' => ".var_export($sCfg['key'] ?? '', true).",\n";
        $c .= "        'status_mode' => ".var_export($sCfg['status_mode'] ?? '', true).",\n";
        $c .= "        'status_override' => ".(int)($sCfg['status_override'] ?? 1).",\n";
        $c .= "        'date_mode' => ".var_export($sCfg['date_mode'] ?? '', true).",\n";
        $c .= "        'date_fixed' => ".var_export($sCfg['date_fixed'] ?? '', true).",\n";
        $c .= "        'date_offset_base' => ".var_export($sCfg['date_offset_base'] ?? '', true).",\n";
        $c .= "        'date_offset_days' => ".(int)($sCfg['date_offset_days'] ?? 1).",\n";
        $c .= "        'per_page' => ".(int)($sCfg['per_page'] ?? 200).",\n";
        $c .= "        'send_batch_limit' => ".(int)($sCfg['send_batch_limit'] ?? 200).",\n";
        $c .= "        'reference_lang' => ".var_export($sCfg['reference_lang'] ?? 'ru', true).",\n";
        $c .= "        'fix_multilangid' => ".($sCfg['fix_multilangid'] ?? false ? 'true' : 'false').",\n";
        $c .= "        'fix_status' => ".($sCfg['fix_status'] ?? false ? 'true' : 'false').",\n";
        $c .= "        'fix_datestamp' => ".($sCfg['fix_datestamp'] ?? false ? 'true' : 'false').",\n";
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
$crumbs = ['<a href="index.php" style="color:#00d4ff;text-decoration:none;font-weight:600;"><span data-i18n="home">🏠 Главная</span></a>', '<a href="?site='.urlencode($currentSite).'" style="color:#00d4ff;text-decoration:none;font-weight:600;">📄 <span data-i18n="entity_name">Страницы</span></a>'];
if ($action === 'get') $crumbs[] = '<a href="?action=get&site='.urlencode($currentSite).'" style="color:#888;text-decoration:none;" data-i18n="import">📥 Импорт</a>';
elseif ($action === 'update') {
    $crumbs[] = '<a href="?action=update&site='.urlencode($currentSite).'" style="color:#888;text-decoration:none;" data-i18n="export">📤 Экспорт</a>';
    if (!empty($_GET['step2'])) $crumbs[] = '<span style="color:#888;" data-i18n="file_selection">📁 Выбор файлов</span>';
    if (!empty($_GET['confirm'])) $crumbs[] = '<span data-i18n="result_label" style="color:#888;">✓ Результат</span>';
}
$breadcrumb = '<div class="bcrumb" style="background:#111827;border:1px solid #1e293b;border-radius:10px;padding:10px 18px;margin-bottom:12px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:13px;color:#888;">'.implode(' <span style="color:#555;">›</span> ', $crumbs).'</div>';

$header = '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
<h1 style="margin:0 0 20px 0;">▸ <span data-i18n="title">Управление страницами — Boostore.pro</span></h1>
<div style="display:flex;gap:8px;align-items:center;">
'.(file_exists(__DIR__.'/_active_site.inc')
? '<strong style="font-size:14px;color:#ff9800;font-family:monospace;">'.htmlspecialchars($currentSite).'</strong>'
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
// _get-pages.php — ИМПОРТ (получение страниц с API)
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
if (!isset($_GET['confirm'])): ?>
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Импорт страниц — Boostore.pro</title>
<style>body{background:#1a1a2e;color:#e0e0e0;font-family:'Segoe UI',sans-serif;padding:30px}.wrap{max-width:1200px;margin:0 auto;overflow:hidden}h1{font-size:22px;color:#00d4ff}.card{background:#16213e;border:1px solid #0f3460;border-radius:10px;padding:18px;margin-bottom:16px;transition:border-color .2s}.meta-info{color:#888;font-size:13px;margin-bottom:25px}.card:hover{border-color:#00d4ff}label{color:#888;font-size:13px;display:block;margin-bottom:4px}.chk-label{display:flex!important;align-items:center;gap:6px;cursor:pointer;color:#e0e0e0;font-size:13px}.chk-label input{width:auto}.chk-label code{background:#0d1b2a;padding:1px 5px;border-radius:3px;font-size:12px}input,select{padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box;transition:border-color .2s}input:focus,select:focus{outline:none;border-color:#00d4ff;box-shadow:0 0 0 2px rgba(0,212,255,.15)}.form-row{display:flex;gap:12px;margin-bottom:12px;align-items:flex-end;flex-wrap:wrap}.form-row .field{flex:1;min-width:120px}.btn{padding:10px 24px;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;transition:all .2s}.btn-primary{background:#00d4ff;color:#1a1a2e}.btn-primary:hover{background:#4dc9f6;transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,212,255,.2)}.btn-success{background:#4caf50;color:#fff}.btn-success:hover{background:#66bb6a;transform:translateY(-1px)}button{font-family:inherit}.btn:hover{color:#fff;text-decoration:none}a{color:#00d4ff;text-decoration:none;transition:color .2s}a:hover{color:#4dc9f6}.na{color:#555}.cat-row{display:flex;gap:8px;margin-bottom:6px;align-items:center}.cat-row input{flex:1}.cat-row .btn-sm{padding:3px 8px;font-size:11px;background:#f44336;color:#fff;border:none;border-radius:3px;cursor:pointer;transition:background .2s}.cat-row .btn-sm:hover{background:#d32f2f}.plaque{background:#0f3460;border:1px solid #00d4ff;border-radius:8px;padding:12px 18px;margin-bottom:16px;font-size:14px;color:#e0e0e0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}.plaque a{font-weight:600}@media(max-width:600px){body{padding:15px}.form-row{gap:8px}.form-row .field{flex:1 1 100%}.wrap{padding:0}}</style></head><body><div class="wrap">
<?php echo $header; ?>
<div class="plaque">
<span data-i18n="plaque_import">▸ <strong>Настройки импорта</strong> — получение страниц с API</span>
<span><a href="?site=<?=urlencode($currentSite)?>" style="padding:6px 16px;background:transparent;color:#00d4ff;border:1px solid #00d4ff;border-radius:4px;text-decoration:none;font-size:13px;" data-i18n="back_home">← Назад</a></span>
</div>
<form method="get" action="?" style="margin-top:16px;">
<input type="hidden" name="action" value="get"><input type="hidden" name="confirm" value="1"><input type="hidden" name="site" value="<?=htmlspecialchars($currentSite)?>">
<div class="card">
<div class="form-row"><div class="field" style="max-width:150px;"><label data-i18n="per_page_import">Страниц за запрос</label><input type="number" name="per_page" value="<?=$getPerPage?>" min="1" max="2000"></div>
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
</div>
<div class="card">
<label style="color:#888;font-size:13px;display:block;margin-bottom:6px;" data-i18n="search_import">Поиск по имени (slug)</label>
<div id="search-fields"><input type="text" name="search[]" value="<?=htmlspecialchars($getSearch ? $getSearch[0] : '')?>" placeholder="часть имени, например: shoes" data-i18n-placeholder="search_placeholder" style="margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box;"></div>
<button type="button" onclick="var p=document.getElementById('search-fields');var inp=document.createElement('input');inp.type='text';inp.name='search[]';inp.placeholder='часть имени';inp.setAttribute('data-i18n-placeholder','search_placeholder');inp.style.cssText='display:block;margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box';p.appendChild(inp);" style="padding:2px 10px;background:transparent;color:#00d4ff;border:1px dashed #00d4ff;border-radius:4px;cursor:pointer;font-size:11px;margin-top:2px;" data-i18n="btn_more">+ ЕЩЕ</button>
<button type="button" onclick="var t=prompt(_t[_lang]['prompt_values'] || 'Введите значения (каждая строка — отдельное поле):');if(t){var p=document.getElementById('search-fields');var lines=t.split('\n');for(var i=0;i<lines.length;i++){var v=lines[i].trim();if(v==='')continue;var inp=document.createElement('input');inp.type='text';inp.name='search[]';inp.value=v;inp.placeholder='часть имени';inp.setAttribute('data-i18n-placeholder','search_placeholder');inp.style.cssText='display:block;margin-bottom:4px;padding:7px 10px;border:1px solid #0f3460;border-radius:5px;background:#0d1b2a;color:#e0e0e0;font-size:13px;width:100%;box-sizing:border-box';p.appendChild(inp);}}" style="padding:2px 10px;background:transparent;color:#ff9800;border:1px dashed #ff9800;border-radius:4px;cursor:pointer;font-size:11px;margin-top:2px;margin-left:4px;" data-i18n="btn_more_multi">📋 ЕЩЕ НЕСКОЛЬКО</button>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;font-size:15px;color:#4dc9f6;" data-i18n="fix_import_title">🔧 Исправление по эталону</h3>
  <p style="font-size:11px;color:#888;margin-bottom:10px;" data-i18n="fix_import_desc">Синхронизировать поля с эталонной статьёй (по slug) после сохранения</p>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:8px;">
    <div>
      <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="ref_lang_label">🌐 Язык эталонной страницы</label>
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
var _t={ru:{'home':'🏠 Главная','site_label':'Сайт:','entity_name':'Страницы','import':'📥 Импорт','export':'📤 Экспорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Создано:','updated_label':'📝 Обновлено:','errors_label':'❌ Ошибок:','skipped_exist_label':'⏭ Пропущено (существуют):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Экспортировать только текст','file_selection':'📁 Выбор файлов','btn_get':'📥 СКАЧАТЬ','btn_update':'📤 ОТПРАВИТЬ','plaque_import':'▸ <strong>Настройки импорта</strong> — получение страниц с API','plaque_export':'▸ <strong>Настройки экспорта</strong> — отправка страниц на Boostore.pro','search_import':'Поиск по имени (slug)','back_home':'← Назад','btn_more':'+ ЕЩЕ','btn_more_multi':'📋 ЕЩЕ НЕСКОЛЬКО','per_page_import':'Страниц за запрос','date_from':'Дата с','date_to':'Дата по','lang_label':'Язык','id_min_label':'ID >','id_max_label':'ID <','id_min_placeholder':'1000','id_max_placeholder':'5000','all_languages':'все','lang_ru':'Русский','lang_en':'English','lang_ua':'Українська','lang_pl':'Polski','lang_de':'Deutsch','lang_fr':'Français','lang_es':'Español','lang_it':'Italiano','lang_kk':'Қазақ','lang_be':'Беларуская','api_docs':'API Docs','version':'v2.0','date_format':'ГГГГ-ММ-ДД','search_placeholder':'часть имени, например: shoes','cat_id_placeholder':'ID','cat_name_placeholder':'имя категории','prompt_values':'Введите значения (каждая строка — отдельное поле):','step_forward':'➡ ДАЛЕЕ','dry_run_label':'Dry run','filter_name':'Фильтр по имени (slug)','batch_label':'Отправить за 1 раз','ref_lang_be':'Белорусский (be)','ref_lang_en':'English (en)','ref_lang_ru':'Русский (ru)','ref_lang_ua':'Українська (ua)','ref_lang_pl':'Polski (pl)','date_mode_meta':'Из мета-данных (дата из каждой страницы)','date_mode_fixed':'Одна дата для всех страниц','date_mode_offset':'Смещение дат (+N дней на страницу)','status_mode_meta':'Из мета-данных (статус из каждой страницы)','status_mode_override':'Переопределить для всех страниц','date_mode_label':'📅 Режим даты публикации','title':'Управление страницами — Boostore.pro','fix_import_title':'🔧 Исправление по эталону','fix_import_desc':'Синхронизировать поля с эталонной страницей (по slug) после сохранения','ref_lang_label':'🌐 Язык эталонной страницы','export_fields_label':'📋 Поля для экспорта','import_only_named':'Только с именем'},en:{'home':'🏠 Home','site_label':'Site:',
    'delete_site':'🗑 Delete site settings','entity_name':'Pages','import':'📥 Import','export':'📤 Export','completed':'✓ Completed','result_label':'✓ Result','created_label':'✅ Created:','updated_label':'📝 Updated:','errors_label':'❌ Errors:','skipped_exist_label':'⏭ Skipped (exist):','skipped_exist_label2':'⏭ Skipped','export_text_only':'📝 Export text only','file_selection':'📁 File selection','btn_get':'📥 DOWNLOAD','btn_update':'📤 UPLOAD','plaque_import':'▸ <strong>Import Settings</strong> — fetching pages from API','plaque_export':'▸ <strong>Export Settings</strong> — sending pages to Boostore.pro','search_import':'Search by name (slug)','back_home':'← Back','btn_more':'+ MORE','btn_more_multi':'📋 ADD MULTIPLE','per_page_import':'Pages per request','date_from':'Date from','date_to':'Date to','lang_label':'Language','all_languages':'all','lang_ru':'Russian','lang_en':'English','lang_ua':'Ukrainian','lang_pl':'Polish','lang_de':'German','lang_fr':'French','lang_es':'Spanish','lang_it':'Italian','lang_kk':'Kazakh','lang_be':'Belarusian','api_docs':'API Docs','version':'v2.0','date_format':'YYYY-MM-DD','search_placeholder':'part of name, e.g.: shoes','cat_id_placeholder':'ID','cat_name_placeholder':'category name','prompt_values':'Enter values (each line is a separate field):','step_forward':'➡ NEXT','dry_run_label':'Dry run','filter_name':'Filter by name (slug)','batch_label':'Send per run','ref_lang_be':'Belarusian (be)','ref_lang_en':'English (en)','ref_lang_ru':'Russian (ru)','ref_lang_ua':'Ukrainian (ua)','ref_lang_pl':'Polish (pl)','date_mode_meta':'From meta-data (date from each page)','date_mode_fixed':'Single date for all pages','date_mode_offset':'Date offset (+N days per page)','planned_notset':'— not set (from meta-data)','planned_0':'0 — not planned','planned_1':'1 — planned publishing','status_mode_meta':'From meta-data (status from each page)','status_mode_override':'Override for all pages','date_mode_label':'📅 Publication Date Mode','title':'Pages Management — Boostore.pro','fix_import_title':'🔧 Fix by Reference','fix_import_desc':'Sync fields with reference page (by slug) after save','ref_lang_label':'🌐 Reference Language','export_fields_label':'📋 Fields to export','import_only_named':'Named only'},ua:{'home':'🏠 Головна','site_label':'Сайт:','entity_name':'Сторінки','import':'📥 Імпорт','export':'📤 Експорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Створено:','updated_label':'📝 Оновлено:','errors_label':'❌ Помилок:','skipped_exist_label':'⏭ Пропущено (існують):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Експортувати тільки текст','file_selection':'📁 Вибір файлів','btn_get':'📥 ПОЧАТИ ІМПОРТ','btn_update':'📤 ПОЧАТИ ЕКСПОРТ','plaque_import':'▸ <strong>Налаштування імпорту</strong> — отримання статей з API','plaque_export':'▸ <strong>Налаштування експорту</strong> — відправлення статей на Boostore.pro','search_import':'Пошук за іменем (slug)','cat_filter':'📂 Категорії для фільтрації','cat_note':'Якщо не вибрано жодної — обробляються всі категорії.','back_home':'← На головну','btn_more':'+ ЩЕ','btn_more_multi':'📋 ДОДАТИ КІЛЬКА','btn_add_cat':'+ Додати категорію','per_page_import':'Статей за запит','date_from':'Дата з','date_to':'Дата по','lang_label':'Мова','folder_planned_chk':'Розділяти planned у <code>blog/planned/</code>','folder_category_chk':'Розділяти по папках категорій','all_languages':'всі','lang_ru':'Російська','lang_en':'Англійська','lang_ua':'Українська','lang_pl':'Польська','lang_de':'Німецька','lang_fr':'Французька','lang_es':'Іспанська','lang_it':'Італійська','lang_kk':'Казахська','lang_be':'Білоруська','api_docs':'API Docs','version':'v2.0','date_format':'РРРР-ММ-ДД','search_placeholder':'частина імені, наприклад: shoes','cat_id_placeholder':'ID','cat_name_placeholder':'ім\'я категорії','prompt_values':'Введіть значення (кожен рядок — окреме поле):','step_forward':'➡ ДАЛІ','dry_run_label':'Dry run','filter_name':'Фільтр за іменем (slug)','batch_label':'Відправити за 1 раз','ref_lang_be':'Білоруська (be)','ref_lang_en':'Англійська (en)','ref_lang_ru':'Російська (ru)','ref_lang_ua':'Українська (ua)','ref_lang_pl':'Польська (pl)','date_mode_meta':'З мета-даних (дата з кожної статті)','date_mode_fixed':'Одна дата для всіх статей','date_mode_offset':'Зміщення дат (+N днів на статтю)','planned_notset':'— не вказано (з мета-даних)','planned_0':'0 — не відкладена','planned_1':'1 — відкладена публікація','status_mode_meta':'З мета-даних (статус з кожної статті)','status_mode_override':'Перевизначити для всіх статей','date_mode_label':'📅 Режим дати публікації','title':'Керування сторінками — Boostore.pro','fix_import_title':'🔧 Виправлення за еталоном','fix_import_desc':'Синхронізувати поля з еталонною сторінкою (по slug) після збереження','ref_lang_label':'🌐 Мова еталонної сторінки','export_fields_label':'📋 Поля для експорту','import_only_named':'Тільки з назвою'}};
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});document.querySelectorAll('[data-i18n-placeholder]').forEach(function(el){var key=el.getAttribute('data-i18n-placeholder');if(_t[l]&&_t[l][key]!==undefined)el.placeholder=_t[l][key];});}
if(_lang!='ru'){document.addEventListener('DOMContentLoaded',function(){applyLang(_lang);});}
document.addEventListener('DOMContentLoaded',function(){var ls=document.getElementById('lang_switcher');if(ls){ls.value=_lang;ls.addEventListener('change',function(){applyLang(this.value);});}});
</script>
</div></body></html>
<?php exit; endif;



$isCLI = false;
$savedPages = [];
$total = 0;
$saved = 0;
$skipped = 0;

// Single page fetch (pagination via navigation)
$perPage = max(1, min(2000, (int)$getPerPage));
$requestedPage = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$searchQuery = $getSearchStr;
$fetchError = null;
$pages = null;
$totalItems = 0;
$totalPages = 1;

$url = $API_URL . '?per_page=' . $perPage . '&page=' . $requestedPage;

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
    } elseif (isset($data['pages']) && is_array($data['pages'])) {
        $pages = $data['pages'];
        $totalItems = (int)($data['total'] ?? 0);
        $totalPages = max(1, (int)($data['total_pages'] ?? 1));
    } else {
        $fetchError = "Неожиданный формат ответа API. Ответ: ".htmlspecialchars(mb_substr($response, 0, 2000));
    }
}

if ($pages !== null && !$fetchError) {
    // Fallback: if API returned all articles (no pagination support), slice locally
    if (count($pages) > $perPage) {
        $totalAll = count($pages);
        $offset = ($requestedPage - 1) * $perPage;
        $pages = array_slice($pages, $offset, $perPage);
        $totalItems = $totalAll;
        $totalPages = max(1, (int)ceil($totalAll / $perPage));
    }
    // Search filter by name/slug (multi-term)
    if (!empty($getSearch)) {
        $pages = array_filter($pages, function($art) use ($getSearch) {
            $n = ($art['name'] ?? $art['slug'] ?? '');
            foreach ($getSearch as $term) {
                if (mb_stripos($n, trim($term)) !== false) return true;
            }
            return false;
        });
        $pages = array_values($pages);
    }
    // ID filter (min/max)
    $idMinGet = isset($_GET['id_min']) ? (int)$_GET['id_min'] : 0;
    $idMaxGet = isset($_GET['id_max']) ? (int)$_GET['id_max'] : 0;
    if ($idMinGet > 0 || $idMaxGet > 0) {
        $pages = array_filter($pages, function($art) use ($idMinGet, $idMaxGet) {
            $aid = (int)($art['id'] ?? 0);
            if ($idMinGet > 0 && $aid < $idMinGet) return false;
            if ($idMaxGet > 0 && $aid > $idMaxGet) return false;
            return true;
        });
        $pages = array_values($pages);
    }
    $total = count($pages);
    $baseDir = $PAGES_DIR;
    foreach ($pages as $a) {
$id = (int)($a['id'] ?? 0); $name = $a['name'] ?? $a['slug'] ?? (string)$id;
$slug = $a['slug'] ?? $name; $language = $a['language'] ?? 'ru';
// Strip language suffix from name/slug if present (e.g., "slug-ua" → "slug")
$name = preg_replace('/-(ua|pl|en|ru)$/i', '', $name);
$slug = preg_replace('/-(ua|pl|en|ru)$/i', '', $slug);
        $title = $a['title'] ?? ''; $metaTitle = $a['meta_title'] ?? '';
        $metaDesc = $a['meta_description'] ?? ''; $metaKeywords = $a['meta_keywords'] ?? '';
        $description = $a['description'] ?? ''; $shortDesc = $a['short_description'] ?? '';
        $status = array_key_exists('status',$a) ? (int)$a['status'] : 1;
        $priority = (int)($a['priority']??0); $subdomain = (int)($a['subdomain']??0);
        $view = (int)($a['view']??0); $settingsComments = $a['settings_comments']??'';
        $settingsTags = (int)($a['settings_tags']??0); $comments = (int)($a['comments']??0);
        $settingsRating = (int)($a['settings_rating']??0); $password = $a['password']??'';
        $showTree = (int)($a['show_tree']??0); $showInlist = (int)($a['show_inlist']??0);
        $show = (int)($a['show']??0); $schema = (int)($a['schema']??6);
        $rating = (int)($a['rating']??0);
        $datestamp = $a['datestamp']??''; $dateLastedit = $a['date_lastedit']??'';
        // Randomize time if datestamp has 00:00 (midnight) — natural-looking publication schedule between 06:00-23:00
        if ($datestamp !== '' && $datestamp !== null) {
            $randSec = 21600 + ((hexdec(substr(md5($slug),0,7)) % 61200));
            if (ctype_digit((string)$datestamp)) {
                $dt = (int)$datestamp;
                if ($dt % 86400 === 0) $datestamp = $dt + $randSec;
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}([ T]00:00(:00)?)?$/', $datestamp)) {
                $baseDate = substr($datestamp, 0, 10);
                $datestamp = $baseDate . ' ' . gmdate('H:i:s', $randSec);
            }
        }
        $multilangid = $a['multilangid']??''; $tags = $a['tags']??'';
        $catTree = $a['cat_tree']??''; $author = $a['author']??'';
        $metaHtml = $a['meta_html']??'';
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/','',$name); $safeName = trim($safeName,'-_');
        if ($safeName==='') $safeName = (string)$id;
        $filename = $id.'-'.$safeName.'-'.$language.'.html';
        $filepath = $baseDir.DIRECTORY_SEPARATOR.$filename; $relPath = $currentSite.DIRECTORY_SEPARATOR.'pages'.DIRECTORY_SEPARATOR.$filename;
        $h = '<meta name="title" content="'.htmlspecialchars($title, ENT_QUOTES).'">'."\n";
        $metaList = [
            'id'=>$id,'name'=>$name,'slug'=>$slug,'meta_title'=>$metaTitle,
            'meta_description'=>$metaDesc,'meta_keywords'=>$metaKeywords,'language'=>$language,
            'status'=>$status,'short_description'=>$shortDesc,
            'priority'=>$priority,'subdomain'=>$subdomain,'view'=>$view,'settings_comments'=>$settingsComments,
            'settings_tags'=>$settingsTags,'comments'=>$comments,'settings_rating'=>$settingsRating,
            'password'=>$password,'show_tree'=>$showTree,'show_inlist'=>$showInlist,'show'=>$show,
            'schema'=>$schema,'rating'=>$rating,'datestamp'=>$datestamp,
            'date_lastedit'=>$dateLastedit,'multilangid'=>$multilangid,'tags'=>$tags,
            'meta_html'=>$metaHtml,'cat_tree'=>$catTree,'author'=>$author,
        ];
        foreach ($metaList as $k=>$v) $h .= '<meta name="'.htmlspecialchars($k).'" content="'.htmlspecialchars((string)$v).'">'."\n";
        $h .= '<meta name="delete" content="false">'."\n";
        $h .= '<!-- CONTENT SEPARATOR BELOW -->'."\n".$description."\n";
        file_put_contents($filepath,$h); $saved++;
        $savedPages[] = ['id'=>$id,'language'=>$language,'path'=>$relPath,'title'=>$title,'slug'=>$slug,
            'show'=>$show,'catTree'=>$catTree,'author'=>$author,
            'status'=>$status,'descLen'=>mb_strlen($description),
            'datestamp'=>$datestamp,'dateLastedit'=>$dateLastedit,'multilangid'=>$multilangid];
    }
}
// Auto-fix by RU (with GET overrides from import form)
$fixes = []; $fixGroups = [];
foreach ($savedPages as $a) { $fixGroups[$a['slug']][] = $a; }
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
                foreach ($savedPages as $k=>$sa) { if ($sa['id']===$a['id']&&$sa['language']===$a['language']) { $savedPages[$k][$f]=$new; break; } }
            }
        }
        if ($changed) { file_put_contents(__DIR__.DIRECTORY_SEPARATOR.$a['path'],$html); $fixes[]="[{$a['language']}] #{$a['id']} — исправлен по {$refLang}"; }
    }
}
// Group by slug
$groups = [];
foreach ($savedPages as $a) { $groups[$a['slug']][] = $a; }
?><!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title>Импорт страниц — Boostore.pro</title>
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
<h1 data-i18n="import_results_title">▸ Импорт страниц — получено с API</h1>
<?php if ($fetchError): ?>
<div class="card"><div class="card-header"><span class="error" data-i18n="fetch_error">✗ Ошибка</span></div><div class="card-body"><span class="error"><?=htmlspecialchars($fetchError)?></span></div></div>
<?php else: ?>
<div class="meta-info" style="padding-top:20px; padding:bottom:20px; font-size:110%;"><span data-i18n="all_total">Всего:</span> <strong><?=$totalItems?:$total?></strong> <span data-i18n="pages_count">страниц</span> | <span data-i18n="loaded_count">Загружено:</span> <strong style="color:#4caf50"><?=$saved?></strong> | <span data-i18n="skipped_count">Пропущено:</span> <strong style="color:#888"><?=$skipped?></strong> | <span data-i18n="page_label">Страница</span> <strong><?=$requestedPage?></strong> <span data-i18n="from_label">из</span> <strong><?=$totalPages?></strong><?php if($fixes):?> | <span data-i18n="fixed_label">Исправлено:</span> <strong style="color:#ff9800"><?=count($fixes)?></strong><?php endif;?><?php if(!empty($getSearch)):?> | <span data-i18n="search_label">Поиск:</span> <strong style="color:#00d4ff"><?=htmlspecialchars(implode(', ', $getSearch))?></strong><?php endif;?></div>


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
  <span style="font-size:11px;color:#555;"><span data-i18n="per_page_by">по</span> <?=$perPage?> <span data-i18n="pages_count">страниц</span>, <span data-i18n="all_total">всего</span> <?=$totalItems?></span>
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
<div style="font-size:12px;color:#e0e0e0;margin-bottom:8px;">Статьи одной группы (одинаковый slug) имеют разный размер описания (расхождение > 50%):</div>
<table style="width:100%;font-size:12px;border-collapse:collapse;"><tr style="color:#ff9800;"><th style="padding:4px 8px;text-align:left;">slug</th><th style="padding:4px 8px;text-align:left;">мин</th><th style="padding:4px 8px;text-align:left;">макс</th><th style="padding:4px 8px;text-align:left;">страницы</th></tr>
<?php foreach($descBad as $dg=>$dd):?>
<tr style="border-top:1px solid #555;"><td style="padding:4px 8px;"><?=htmlspecialchars($dg)?></td><td style="padding:4px 8px;"><?=$dd['min']?></td><td style="padding:4px 8px;"><?=$dd['max']?></td><td style="padding:4px 8px;"><?php $parts=[];foreach($dd['arts'] as $da){$parts[]='['.$da['language'].'] '.basename($da['path']);}echo htmlspecialchars(implode(', ',$parts));?></td></tr>
<?php endforeach;?>
</table></div>
<?php endif;?>
<div class="expand-all"><button onclick="toggleAll(true)" style="background:#0f3460;color:#00d4ff;border:1px solid #00d4ff;border-radius:4px;padding:5px 14px;cursor:pointer;" data-i18n="expand_all">▸ РАСКРЫТЬ ВСЕ</button>
<button onclick="toggleAll(false)" style="background:#0f3460;color:#888;border:1px solid #888;border-radius:4px;padding:5px 14px;cursor:pointer;" data-i18n="collapse_all">▾ СКРЫТЬ ВСЕ</button></div><br/>




<?php $descBad=[]; foreach($groups as $gs=>$garts):
$cnt=count($garts);$allOk=true;$checks=[];$first=$garts[0];
foreach(['multilangid','status','datestamp'] as $f){$v=array_unique(array_map(function($x)use($f){return (string)$x[$f];},$garts));$ok=count($v)===1;if(!$ok)$allOk=false;$checks[$f]=['ok'=>$ok,'vals'=>$v];}
$dl=array_map(function($x){return(int)$x['descLen'];},$garts);$dmin=min($dl);$dmax=max($dl);$dok=$dmin*2>=$dmax;if(!$dok)$allOk=false;$checks['descLen']=['ok'=>$dok,'vals'=>['min:'.$dmin,'max:'.$dmax]];if(!$dok)$descBad[$gs]=['min'=>$dmin,'max'=>$dmax,'arts'=>$garts];
$gst=$allOk?'success':'error';
?>
<details class="summary-card"><summary><span><span class="<?=$gst?>">📁 <?=htmlspecialchars($gs?:'—')?></span> <span style="color:#888;font-size:12px;"><span data-i18n="languages_count"><?=$cnt?> языка(ов)</span></span></span><span style="color:#888;font-size:11px;"><span class="arrow">▶</span></span></summary>
<div class="card-body"><div style="font-size:13px;margin-bottom:12px;padding:10px;background:#0d1b2a;border-radius:6px;"><div style="display:grid;grid-template-columns:auto 1fr;gap:4px 16px;font-size:12px;">
<?php foreach(['multilangid','status','datestamp','descLen'] as $f):$c=$checks[$f];?>
<span class="key"><?=$f==='descLen'?'content':$f?>:</span><span class="val"><?php if($c['ok']):?><span class="success">✓ <?=htmlspecialchars(implode(', ',$c['vals']))?></span><?php else:?><span class="error"><span data-i18n="discrepancy">✗ РАСХОЖДЕНИЕ:</span> <?=htmlspecialchars(implode(' | ',$c['vals']))?></span><?php endif;?></span>
<?php endforeach;?></div></div>
<?php foreach($garts as $a):?>
<details class="sub-article"><summary><span><span class="num">#<?=$a['id']?></span> [<?=$a['language']?>] <?=htmlspecialchars($a['path'])?></span><span style="color:#888;font-size:10px;">▶</span></summary>
<div style="padding:10px 12px;"><div class="meta-grid">
<span class="key">title:</span><span class="val"><?=htmlspecialchars(mb_substr($a['title'],0,100))?:'<span class="na">—</span>'?></span>
<span class="key">slug:</span><span class="val"><?=htmlspecialchars($a['slug'])?></span>
<span class="key">multilangid:</span><span class="val"><?=htmlspecialchars($a['multilangid'])?:'<span class="na">—</span>'?></span>
<span class="key">datestamp:</span><span class="val"><?=htmlspecialchars($a['datestamp'])?:'<span class="na">—</span>'?></span>
<span class="key">status:</span><span class="val"><?=$a['status']?'<span class="success" data-i18n="status_published_short">1 (опубликовано)</span>':'<span data-i18n="status_hidden_short">0 (скрыто)</span>'?></span>
<span class="key">description:</span><span class="val"><?=$a['descLen']?> <span data-i18n="chars_count">символов</span></span>
</div><div class="lastedit">date_lastedit: <?=htmlspecialchars($a['dateLastedit'])?:'<span class="na">—</span>'?></div></div></details>
<?php endforeach;?></div></details>
<?php endforeach;?>
<div class="footer"><strong>Boostore.pro</strong> — <span data-i18n="import_complete">Импорт страниц завершён</span></div>
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
var _t={ru:{'home':'🏠 Главная','site_label':'Сайт:','entity_name':'Страницы','import':'📥 Импорт','export':'📤 Экспорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Создано:','updated_label':'📝 Обновлено:','errors_label':'❌ Ошибок:','skipped_exist_label':'⏭ Пропущено (существуют):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Экспортировать только текст','file_selection':'📁 Выбор файлов','btn_get':'📥 СКАЧАТЬ','btn_update':'📤 ОТПРАВИТЬ','back_home':'Назад','back_button':'← НАЗАД','back_to_settings':'← Назад к настройкам','import_results_title':'▸ Импорт страниц — получено с API','all_total':'Всего:','pages_count':'страниц','loaded_count':'Загружено:','skipped_count':'Пропущено:','page_label':'Страница','from_label':'из','search_label':'Поиск:','fixed_label':'Исправлено:','go_to_page':'Перейти','prev_page':'← Назад (стр.','next_page':'Далее → (стр.','per_page_total':'по','per_page_by':'по','expand_all':'▸ РАСКРЫТЬ ВСЕ','collapse_all':'▾ СКРЫТЬ ВСЕ','auto_fix_title':'⚡ Авто-исправление по эталону','languages_count':'языка(ов)','discrepancy':'✗ РАСХОЖДЕНИЕ:','status_published_short':'1 (опубликовано)','status_hidden_short':'0 (скрыто)','chars_count':'символов','import_complete':'Импорт страниц завершён','fetch_error':'✗ Ошибка','lang_ru':'Русский','lang_en':'English','lang_ua':'Українська','api_docs':'API Docs','version':'v2.0','ref_lang_be':'Белорусский (be)','ref_lang_en':'English (en)','ref_lang_ru':'Русский (ru)','ref_lang_ua':'Українська (ua)','ref_lang_pl':'Polski (pl)','date_mode_meta':'Из мета-данных (дата из каждой страницы)','date_mode_fixed':'Одна дата для всех страниц','date_mode_offset':'Смещение дат (+N дней на страницу)','status_mode_meta':'Из мета-данных (статус из каждой страницы)','status_mode_override':'Переопределить для всех страниц','date_mode_label':'📅 Режим даты публикации','title':'Управление страницами — Boostore.pro','fix_import_title':'🔧 Исправление по эталону','fix_import_desc':'Синхронизировать поля с эталонной страницей (по slug) после сохранения','ref_lang_label':'🌐 Язык эталонной страницы','export_fields_label':'📋 Поля для экспорта','import_only_named':'Только с именем'},en:{'home':'🏠 Home','site_label':'Site:',
    'delete_site':'🗑 Delete site settings','entity_name':'Pages','import':'📥 Import','export':'📤 Export','completed':'✓ Completed','result_label':'✓ Result','created_label':'✅ Created:','updated_label':'📝 Updated:','errors_label':'❌ Errors:','skipped_exist_label':'⏭ Skipped (exist):','skipped_exist_label2':'⏭ Skipped','export_text_only':'📝 Export text only','file_selection':'📁 File selection','btn_get':'📥 DOWNLOAD','btn_update':'📤 UPLOAD','back_home':'Home','back_button':'← BACK','back_to_settings':'← Back to settings','import_results_title':'▸ Pages fetched from API','all_total':'Total:','pages_count':'pages','loaded_count':'Loaded:','skipped_count':'Skipped:','page_label':'Page','from_label':'of','search_label':'Search:','fixed_label':'Fixed:','go_to_page':'Go','prev_page':'← Prev (pg.','next_page':'Next → (pg.','per_page_total':'per','per_page_by':'per','expand_all':'▸ EXPAND ALL','collapse_all':'▾ COLLAPSE ALL','auto_fix_title':'⚡ Auto-fix by reference','languages_count':'language(s)','discrepancy':'✗ DISCREPANCY:','status_published_short':'1 (published)','status_hidden_short':'0 (hidden)','chars_count':'chars','import_complete':'Import complete','fetch_error':'✗ Error','verification_warn':'⚠ Data saved, but length in API response differs (possible formatting differences)','lang_ru':'Russian','lang_en':'English','lang_ua':'Ukrainian','api_docs':'API Docs','version':'v2.0','ref_lang_be':'Belarusian (be)','ref_lang_en':'English (en)','ref_lang_ru':'Russian (ru)','ref_lang_ua':'Ukrainian (ua)','ref_lang_pl':'Polish (pl)','date_mode_meta':'From meta-data (date from each page)','date_mode_fixed':'Single date for all pages','date_mode_offset':'Date offset (+N days per page)','planned_notset':'— not set (from meta-data)','planned_0':'0 — not planned','planned_1':'1 — planned publishing','status_mode_meta':'From meta-data (status from each page)','status_mode_override':'Override for all pages','date_mode_label':'📅 Publication Date Mode','title':'Pages Management — Boostore.pro','fix_import_title':'🔧 Fix by Reference','fix_import_desc':'Sync fields with reference page (by slug) after save','ref_lang_label':'🌐 Reference Language','export_fields_label':'📋 Fields to export','import_only_named':'Named only'},ua:{'home':'🏠 Головна','site_label':'Сайт:','entity_name':'Сторінки','import':'📥 Імпорт','export':'📤 Експорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Створено:','updated_label':'📝 Оновлено:','errors_label':'❌ Помилок:','skipped_exist_label':'⏭ Пропущено (існують):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Експортувати тільки текст','file_selection':'📁 Вибір файлів','btn_get':'📥 ПОЧАТИ ІМПОРТ','btn_update':'📤 ПОЧАТИ ЕКСПОРТ','back_home':'На головну','back_button':'← НАЗАД','back_to_settings':'← Назад до налаштувань','import_results_title':'▸ Статті отримано з API','all_total':'Всього:','pages_count':'страниц','loaded_count':'Завантажено:','skipped_count':'Пропущено:','page_label':'Сторінка','from_label':'з','search_label':'Пошук:','fixed_label':'Виправлено:','go_to_page':'Перейти','prev_page':'← Назад (стор.','next_page':'Далі → (стор.','per_page_total':'по','per_page_by':'по','expand_all':'▸ РОЗГОРНУТИ ВСІ','collapse_all':'▾ ЗГОРНУТИ ВСІ','auto_fix_title':'⚡ Авто-виправлення за еталоном','languages_count':'мова(и)','discrepancy':'✗ РОЗБІЖНІСТЬ:','status_published_short':'1 (опубліковано)','status_hidden_short':'0 (приховано)','chars_count':'символів','import_complete':'Імпорт статей завершено','fetch_error':'✗ Помилка','verification_warn':'⚠ Дані збережено, але довжина у відповіді API не збігається (можливі відмінності у форматуванні)','lang_ru':'Російська','lang_en':'Англійська','lang_ua':'Українська','api_docs':'API Docs','version':'v2.0','ref_lang_be':'Білоруська (be)','ref_lang_en':'Англійська (en)','ref_lang_ru':'Російська (ru)','ref_lang_ua':'Українська (ua)','ref_lang_pl':'Польська (pl)','date_mode_meta':'З мета-даних (дата з кожної статті)','date_mode_fixed':'Одна дата для всіх статей','date_mode_offset':'Зміщення дат (+N днів на статтю)','planned_notset':'— не вказано (з мета-даних)','planned_0':'0 — не відкладена','planned_1':'1 — відкладена публікація','status_mode_meta':'З мета-даних (статус з кожної статті)','status_mode_override':'Перевизначити для всіх статей','date_mode_label':'📅 Режим дати публікації','title':'Керування сторінками — Boostore.pro','fix_import_title':'🔧 Виправлення за еталоном','fix_import_desc':'Синхронізувати поля з еталонною сторінкою (по slug) після збереження','ref_lang_label':'🌐 Мова еталонної сторінки','export_fields_label':'📋 Поля для експорту','import_only_named':'Тільки з назвою'}};
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});document.querySelectorAll('[data-i18n-placeholder]').forEach(function(el){var key=el.getAttribute('data-i18n-placeholder');if(_t[l]&&_t[l][key]!==undefined)el.placeholder=_t[l][key];});}
if(_lang!='ru'){document.addEventListener('DOMContentLoaded',function(){applyLang(_lang);});}
document.addEventListener('DOMContentLoaded',function(){var ls=document.getElementById('lang_switcher');if(ls){ls.value=_lang;ls.addEventListener('change',function(){applyLang(this.value);});}});
</script>
</div></body></html>
<?php exit;
// ===================================================================
// _get-pages.php — END
// ===================================================================
endif;

// ===================================================================
// _update-pages.php — ЭКСПОРТ (отправка страниц на API)
// ===================================================================
if ($action === 'update'):
// GET overrides for per-export parameters (take from GET, fallback to config)
$expDateMode   = $_GET['date_mode'] ?? $DATE_MODE;
$expDateFixed  = $_GET['date_fixed'] ?? $DATE_FIXED;
$expDateOffsetBase = $_GET['date_offset_base'] ?? $DATE_OFFSET_BASE;
$expDateOffsetDays = isset($_GET['date_offset_days']) ? (int)$_GET['date_offset_days'] : (int)($DATE_OFFSET_DAYS ?? 1);
$expStatusMode = $_GET['status_mode'] ?? $STATUS_MODE;
$expStatusOverride = isset($_GET['status_override']) ? (int)$_GET['status_override'] : (int)($STATUS_OVERRIDE ?? 1);
$expMode = $_GET['export_mode'] ?? 'all'; // all, insert, update
$exportTextOnly = !empty($_GET['export_text_only']);

function dateToTimestamp(?string $d):?int{if(!$d)return null;if(ctype_digit($d))return(int)$d;try{return(new DateTimeImmutable($d))->getTimestamp();}catch(Exception$e){return null;}}

// Pre-scan for offset mode: build slug→date map
$slugDateMap = [];
if ($expDateMode === 'offset' && $expDateOffsetBase !== '' && $expDateOffsetDays > 0) {
    $baseTs = dateToTimestamp($expDateOffsetBase);
    if ($baseTs) {
        $pagesDir2 = $PAGES_DIR;
        if (is_dir($pagesDir2)) {
            $rdi2 = new RecursiveDirectoryIterator($pagesDir2,RecursiveDirectoryIterator::SKIP_DOTS);
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
<head><meta charset="UTF-8"><title>Экспорт страниц — Boostore.pro</title>
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
<span data-i18n="plaque_export">▸ <strong>Настройки экспорта</strong> — отправка страниц на Boostore.pro</span>
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
var _t={ru:{'home':'🏠 Главная','site_label':'Сайт:','entity_name':'Страницы','import':'📥 Импорт','export':'📤 Экспорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Создано:','updated_label':'📝 Обновлено:','errors_label':'❌ Ошибок:','skipped_exist_label':'⏭ Пропущено (существуют):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Экспортировать только текст','file_selection':'📁 Выбор файлов','filter_name':'Фильтр по имени (slug)','batch_label':'Отправить за 1 раз','lang_label':'Язык','step_forward':'➡ ДАЛЕЕ','back_home':'← Назад','dry_run_label':'Dry run','plaque_export':'▸ <strong>Настройки экспорта</strong> — отправка страниц на Boostore.pro','dryrun_warn':'⚡ DRY RUN — запросы не отправляются','btn_more':'+ ЕЩЕ','btn_more_multi':'📋 ЕЩЕ НЕСКОЛЬКО','prompt_values':'Введите значения (каждая строка — отдельное поле):','search_placeholder':'часть имени, например: shopify','all_languages':'все','date_mode_meta':'Из мета-данных (дата из каждой страницы)','date_mode_fixed':'Одна дата для всех страниц','date_mode_offset':'Смещение дат (+N дней на страницу)','date_fixed_label':'📅 Фиксированная дата','date_offset_label':'📅 Базовая дата','date_offset_days':'+ дней на страницу','status_mode_label':'🔒 Статус доступа (status)','status_mode_meta':'Из мета-данных (статус из каждой страницы)','status_mode_override':'Переопределить для всех страниц','status_value_label':'Значение статуса','status_published':'1 — опубликовано (доступно)','status_hidden':'0 — скрыто (недоступно)','mode_label':'🔄 Режим экспорта','mode_all':'Добавление + обновление','mode_insert':'Только добавление новых','mode_update':'Только обновление существующих','fix_export_title':'🔧 Исправление при экспорте','fix_export_desc':'Синхронизировать поля с эталонной страницей (по slug) при экспорте','date_mode_label':'📅 Режим даты публикации','title':'Управление страницами — Boostore.pro','fix_import_title':'🔧 Исправление по эталону','fix_import_desc':'Синхронизировать поля с эталонной страницей (по slug) после сохранения','ref_lang_label':'🌐 Язык эталонной страницы','export_fields_label':'📋 Поля для экспорта','import_only_named':'Только с именем'},en:{'home':'🏠 Home','site_label':'Site:',
    'delete_site':'🗑 Delete site settings','entity_name':'Pages','import':'📥 Import','export':'📤 Export','completed':'✓ Completed','result_label':'✓ Result','created_label':'✅ Created:','updated_label':'📝 Updated:','errors_label':'❌ Errors:','skipped_exist_label':'⏭ Skipped (exist):','skipped_exist_label2':'⏭ Skipped','export_text_only':'📝 Export text only','file_selection':'📁 File selection','filter_name':'Filter by name (slug)','batch_label':'Send per run','lang_label':'Language','id_min_label':'ID >','id_max_label':'ID <','id_min_placeholder':'1000','id_max_placeholder':'5000','step_forward':'➡ NEXT','back_home':'← Back','dry_run_label':'Dry run','plaque_export':'▸ <strong>Export Settings</strong> — sending pages to Boostore.pro','dryrun_warn':'⚡ DRY RUN — no API calls sent','btn_more':'+ MORE','btn_more_multi':'📋 ADD MULTIPLE','prompt_values':'Enter values (each line is a separate field):','search_placeholder':'part of name, e.g.: shopify','all_languages':'all','date_mode_meta':'From meta-data (date from each page)','date_mode_fixed':'Single date for all pages','date_mode_offset':'Date offset (+N days per page)','date_fixed_label':'📅 Fixed Date','date_offset_label':'📅 Base Date','date_offset_days':'+ days per page','override_planned':'📅 Override planned','planned_notset':'— not set (from meta-data)','planned_0':'0 — not planned','planned_1':'1 — planned publishing','status_mode_label':'🔒 Access Status (status)','status_mode_meta':'From meta-data (status from each page)','status_mode_override':'Override for all pages','status_value_label':'Status Value','status_published':'1 — published (public)','status_hidden':'0 — hidden (private)','mode_label':'🔄 Export mode','mode_all':'Add + Update','mode_insert':'Add new only','mode_update':'Update existing only','fix_export_title':'🔧 Fix on Export','fix_export_desc':'Sync fields with reference page (by slug) on export','date_mode_label':'📅 Publication Date Mode','title':'Pages Management — Boostore.pro','fix_import_title':'🔧 Fix by Reference','fix_import_desc':'Sync fields with reference page (by slug) after save','ref_lang_label':'🌐 Reference Language','export_fields_label':'📋 Fields to export','import_only_named':'Named only'},ua:{'home':'🏠 Головна','site_label':'Сайт:','entity_name':'Сторінки','import':'📥 Імпорт','export':'📤 Експорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Створено:','updated_label':'📝 Оновлено:','errors_label':'❌ Помилок:','skipped_exist_label':'⏭ Пропущено (існують):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Експортувати тільки текст','file_selection':'📁 Вибір файлів','filter_name':'Фільтр за іменем (slug)','batch_label':'Відправити за 1 раз','lang_label':'Мова','step_forward':'➡ ДАЛІ','back_home':'← На головну','dry_run_label':'Dry run','plaque_export':'▸ <strong>Налаштування експорту</strong> — відправлення статей на Boostore.pro','dryrun_warn':'⚡ DRY RUN — запити не надсилаються','btn_more':'+ ЩЕ','btn_more_multi':'📋 ДОДАТИ КІЛЬКА','prompt_values':'Введіть значення (кожен рядок — окреме поле):','search_placeholder':'частина імені, наприклад: shopify','all_languages':'всі','date_mode_meta':'З мета-даних (дата з кожної статті)','date_mode_fixed':'Одна дата для всіх статей','date_mode_offset':'Зміщення дат (+N днів на статтю)','date_fixed_label':'📅 Фіксована дата','date_offset_label':'📅 Базова дата','date_offset_days':'+ днів на статтю','override_planned':'📅 Перевизначити planned','planned_notset':'— не вказано (з мета-даних)','planned_0':'0 — не відкладена','planned_1':'1 — відкладена публікація','status_mode_label':'🔒 Статус доступу (status)','status_mode_meta':'З мета-даних (статус з кожної статті)','status_mode_override':'Перевизначити для всіх статей','status_value_label':'Значення статусу','status_published':'1 — опубліковано (доступно)','status_hidden':'0 — приховано (недоступно)','mode_label':'🔄 Режим експорту','mode_all':'Додавання + оновлення','mode_insert':'Тільки додавання нових','mode_update':'Тільки оновлення існуючих','fix_export_title':'🔧 Виправлення при експорті','fix_export_desc':'Синхронізувати поля з еталонною сторінкою (по slug) при експорті','date_mode_label':'📅 Режим дати публікації','title':'Керування сторінками — Boostore.pro','fix_import_title':'🔧 Виправлення за еталоном','fix_import_desc':'Синхронізувати поля з еталонною сторінкою (по slug) після збереження','ref_lang_label':'🌐 Мова еталонної сторінки','export_fields_label':'📋 Поля для експорту','import_only_named':'Тільки з назвою'}};
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
        <option value=""<?=($_GET['date_mode']??$DATE_MODE)===''?' selected':''?> data-i18n="date_mode_meta">Из мета-данных (дата из каждой страницы)</option>
        <option value="fixed"<?=($_GET['date_mode']??$DATE_MODE)==='fixed'?' selected':''?> data-i18n="date_mode_fixed">Одна дата для всех страниц</option>
        <option value="offset"<?=($_GET['date_mode']??$DATE_MODE)==='offset'?' selected':''?> data-i18n="date_mode_offset">Смещение дат (+N дней на страницу)</option>
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
      <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="date_offset_days">+ дней на страницу</label>
      <input type="number" name="date_offset_days" value="<?=(int)($_GET['date_offset_days']??$DATE_OFFSET_DAYS??1)?>" min="0" max="365" style="width:70px;padding:6px 8px;border:1px solid #0f3460;border-radius:4px;background:#16213e;color:#e0e0e0;">
    </div>

    <div>
      <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="status_mode_label">🔒 Статус доступа (status)</label>
      <select name="status_mode" id="exp_status_mode" onchange="toggleExpStatusFields()" style="padding:6px 8px;border:1px solid #0f3460;border-radius:4px;background:#16213e;color:#e0e0e0;">
        <option value=""<?=($_GET['status_mode']??$STATUS_MODE)===''?' selected':''?> data-i18n="status_mode_meta">Из мета-данных (статус из каждой страницы)</option>
        <option value="override"<?=($_GET['status_mode']??$STATUS_MODE)==='override'?' selected':''?> data-i18n="status_mode_override">Переопределить для всех страниц</option>
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
    <div style="font-size:11px;color:#888;margin-bottom:8px;" data-i18n="fix_export_desc">Синхронизировать поля с эталонной статьёй (по slug) при экспорте</div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:8px;">
      <div>
        <label style="font-size:11px;color:#888;display:block;margin-bottom:3px;" data-i18n="ref_lang_label">🌐 Язык эталонной страницы</label>
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
$pagesDir2 = $PAGES_DIR;
$htmlFiles2 = [];
if (is_dir($pagesDir2)) {
    $rdi2 = new RecursiveDirectoryIterator($pagesDir2, RecursiveDirectoryIterator::SKIP_DOTS);
    $rii2 = new RecursiveIteratorIterator($rdi2);
    foreach ($rii2 as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'html') {
            $htmlFiles2[] = $f->getPathname();
        }
    }
    sort($htmlFiles2);
}
$htmlFiles2 = array_filter($htmlFiles2, function($p) { return !in_array(basename($p), ['index.php','_setting_pages.inc']); });
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
<head><meta charset="UTF-8"><title data-i18n="step2_title">Экспорт страниц — выбор файлов</title>
<script>var _lang='ru';try{_lang=localStorage.getItem('boostore_lang')||navigator.language.slice(0,2);localStorage.setItem('boostore_lang',_lang);}catch(e){}if(!['ru','en','ua'].includes(_lang))_lang='ru';
var _t={ru:{'home':'🏠 Главная','site_label':'Сайт:','entity_name':'Страницы','import':'📥 Импорт','export':'📤 Экспорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Создано:','updated_label':'📝 Обновлено:','errors_label':'❌ Ошибок:','skipped_exist_label':'⏭ Пропущено (существуют):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Экспортировать только текст','file_selection':'📁 Выбор файлов','step2_title':'Экспорт страниц — выбор файлов','step2_header':'▸ <strong>Шаг 2</strong> — выберите файлы для экспорта на сайт','back_to_filters':'← Назад к фильтрам','no_files_found':'Нет файлов, соответствующих критериям поиска','files_found':'Найдено файлов:','select_all':'☑ ВЫДЕЛИТЬ ВСЕ','deselect_all':'☐ СНЯТЬ ВСЕ','export_selected':'📤 ЭКСПОРТИРОВАТЬ ВЫДЕЛЕННЫЕ','date_mode_label':'📅 Режим даты публикации','title':'Управление страницами — Boostore.pro','fix_import_title':'🔧 Исправление по эталону','fix_import_desc':'Синхронизировать поля с эталонной страницей (по slug) после сохранения','ref_lang_label':'🌐 Язык эталонной страницы','export_fields_label':'📋 Поля для экспорта','import_only_named':'Только с именем'},en:{'home':'🏠 Home','site_label':'Site:',
    'delete_site':'🗑 Delete site settings','entity_name':'Pages','import':'📥 Import','export':'📤 Export','completed':'✓ Completed','result_label':'✓ Result','created_label':'✅ Created:','updated_label':'📝 Updated:','errors_label':'❌ Errors:','skipped_exist_label':'⏭ Skipped (exist):','skipped_exist_label2':'⏭ Skipped','export_text_only':'📝 Export text only','file_selection':'📁 File selection','step2_title':'Export — file selection','step2_header':'▸ <strong>Step 2</strong> — select files to export','back_to_filters':'← Back to filters','no_files_found':'No files matching search criteria','files_found':'Files found:','select_all':'☑ SELECT ALL','deselect_all':'☐ DESELECT ALL','export_selected':'📤 EXPORT SELECTED','date_mode_label':'📅 Publication Date Mode','title':'Pages Management — Boostore.pro','fix_import_title':'🔧 Fix by Reference','fix_import_desc':'Sync fields with reference page (by slug) after save','ref_lang_label':'🌐 Reference Language','export_fields_label':'📋 Fields to export','import_only_named':'Named only'},ua:{'home':'🏠 Головна','site_label':'Сайт:','entity_name':'Сторінки','import':'📥 Імпорт','export':'📤 Експорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Створено:','updated_label':'📝 Оновлено:','errors_label':'❌ Помилок:','skipped_exist_label':'⏭ Пропущено (існують):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Експортувати тільки текст','file_selection':'📁 Вибір файлів','step2_title':'Експорт — вибір файлів','step2_header':'▸ <strong>Крок 2</strong> — виберіть файли для експорту','back_to_filters':'← Назад до фільтрів','no_files_found':'Немає файлів, що відповідають критеріям пошуку','files_found':'Знайдено файлів:','select_all':'☑ ВИДІЛИТИ ВСІ','deselect_all':'☐ ЗНЯТИ ВСІ','export_selected':'📤 ЕКСПОРТУВАТИ ВИДІЛЕНІ','date_mode_label':'📅 Режим дати публікації','title':'Керування сторінками — Boostore.pro','fix_import_title':'🔧 Виправлення за еталоном','fix_import_desc':'Синхронізувати поля з еталонною сторінкою (по slug) після збереження','ref_lang_label':'🌐 Мова еталонної сторінки','export_fields_label':'📋 Поля для експорту','import_only_named':'Тільки з назвою'}};
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
.cat-row{display:flex;gap:8px;margin-bottom:6px;align-items:center;flex-wrap:wrap}.cat-row input[type="checkbox"]{width:auto;cursor:pointer}.cat-row input[type="text"]{flex:1;min-width:60px}
.footer{text-align:center;padding:20px;color:#555;font-size:13px}
</style>
</head>
<body><div class="wrap"><?php echo $header; ?>
<div class="plaque">
<span data-i18n="step2_header">▸ <strong>Шаг 2</strong> — выберите файлы для экспорта на сайт</span>
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
function extractContent(string $html):string{
    foreach (['<!-- CONTENT SEPARATOR BELOW -->','<!-- ARTICLE SEPARATOR BELOW -->','<-- РАЗДЕЛИТЕЛЬ СТАТЬЯ НИЖЕ --!>','<!-- PAGE SEPARATOR BELOW -->'] as $sep) {
        $p=mb_strpos($html,$sep); if ($p!==false) { $c=mb_substr($html,$p+mb_strlen($sep)); return trim($c); }
    }
    $c=preg_replace('/<\/?body[^>]*>/i','',$html); $c=preg_replace('/<\/?html[^>]*>/i','',$c);
    return trim($c);
}

// Load files from step 2 selection, or scan directory as fallback
$htmlFiles = [];
if (isset($_GET['files']) && is_array($_GET['files'])) {
    foreach ($_GET['files'] as $relPath) {
        $absPath = __DIR__ . DIRECTORY_SEPARATOR . $relPath;
        if (file_exists($absPath)) $htmlFiles[] = $absPath;
    }
} else {
    $pagesDir=$PAGES_DIR;
    if(is_dir($pagesDir)){$rdi=new RecursiveDirectoryIterator($pagesDir,RecursiveDirectoryIterator::SKIP_DOTS);$rii=new RecursiveIteratorIterator($rdi);foreach($rii as $f){if($f->isFile()&&strtolower($f->getExtension())==='html')$htmlFiles[]=$f->getPathname();}sort($htmlFiles);}
    $skipFiles=['index.php','_setting_pages.inc'];$skipNames=['llm','webmanifest','robots'];$htmlFiles=array_filter($htmlFiles,function($p)use($skipFiles,$skipNames){$bn=basename($p);if(in_array($bn,$skipFiles))return false;$fn=pathinfo($bn,PATHINFO_FILENAME);if(in_array(strtolower($fn),$skipNames))return false;return true;});
    $htmlFiles=array_values($htmlFiles);
    if(!empty($_GET['lang'])){$_langFilter=$_GET['lang'];$htmlFiles=array_filter($htmlFiles,function($p)use($_langFilter){$h=@file_get_contents($p);if($h===false)return false;preg_match('/<meta\s+name=["\']lang["\']\s+content=["\'](.*?)["\']/is',$h,$m);return trim($m[1]??'')===$_langFilter;});$htmlFiles=array_values($htmlFiles);}
}
if(empty($htmlFiles)):?><h1 data-i18n="no_html_files">✕ Нет HTML-файлов</h1><p data-i18n="no_html_files_desc">В папке <code><?=htmlspecialchars($PAGES_DIR)?></code> не найдено *.html</p>
<?php // Note: $PAGES_DIR variable used above
else:
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
$batchPayloads = []; $batchArticles = [];

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
        $_grp = $_slug !== '' ? $_slug : ($_m['name'] ?? '');
        if ($_grp === '') continue;
        $fixScan[$_grp][] = ['file' => $fp, 'lang' => $_lang, 'meta' => $_m];
    }
    foreach ($fixScan as $_slug => $_arts) {
        $_ref = null;
        foreach ($_arts as $_a) { if ($_a['lang'] === $expRefLang) { $_ref = $_a; break; } }
        if (!$_ref) continue;
        $_refId = (int)($_ref['meta']['id'] ?? 0);
        // Reference (refLang) article: multilangid = its own id if empty/0
        if ($_refId > 0 && in_array('multilangid', $fixFields, true)) {
            $_refOld = (string)($_ref['meta']['multilangid'] ?? '');
            if ($_refOld === '' || (int)$_refOld === 0) $fixMap[$_ref['file']] = ['multilangid' => (string)$_refId];
        }
        // Other languages in the same slug group: multilangid = reference id, other fields sync to reference
        foreach ($_arts as $_a) {
            if ($_a['lang'] === $expRefLang) continue;
            $_fixes = [];
            foreach ($fixFields as $_f) {
                $_old = (string)($_a['meta'][$_f] ?? '');
                if ($_f === 'multilangid') {
                    if ($_refId > 0 && ($_old === '' || (int)$_old === 0)) $_fixes[$_f] = (string)$_refId;
                    continue;
                }
                $_new = (string)($_ref['meta'][$_f] ?? '');
                if ($_old !== $_new) $_fixes[$_f] = $_new;
            }
            if (!empty($_fixes)) {
                if (isset($fixMap[$_a['file']])) $fixMap[$_a['file']] = array_merge($fixMap[$_a['file']], $_fixes);
                else $fixMap[$_a['file']] = $_fixes;
            }
        }
    }
}

foreach($htmlFiles as $htmlFile):
$relPath=str_replace(__DIR__.DIRECTORY_SEPARATOR,'',$htmlFile);$articleIdx++;
$html=file_get_contents($htmlFile);$meta=extractAllMeta($html);
$title=$meta['title']??'';$metaTitle=$meta['meta_title']??'';$metaDesc=$meta['meta_description']??'';
$metaKeywords=$meta['meta_keywords']??'';$slug=$meta['slug']??'';$language=$meta['language']??'ru';
if(in_array(strtolower($slug),['llm','webmanifest','robots'])){$skippedCount++;continue;}
$shortDesc=$meta['short_description']??'';$status=$meta['status']??'';
$priority=(int)($meta['priority']??0);$subdomain=(int)($meta['subdomain']??0);$view=(int)($meta['view']??0);
$settingsComments=$meta['settings_comments']??'';$settingsTags=(int)($meta['settings_tags']??0);
$comments=(int)($meta['comments']??0);$settingsRating=(int)($meta['settings_rating']??0);
$password=$meta['password']??'';$showTree=(string)(int)($meta['show_tree']??0);$showInlist=(int)($meta['show_inlist']??0);
$show=(int)($meta['show']??0);$schema=(int)($meta['schema']??6);
$rating=(int)($meta['rating']??0);$datestampStr=$meta['datestamp']??'';$tags=$meta['tags']??'';
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
    // Persist fixed fields back into the HTML file (only actual meta tags present)
    $_htmlNew = !$dryRun ? @file_get_contents($htmlFile) : false;
    if ($_htmlNew !== false) {
        $_changed = false;
        foreach ($fixMap[$htmlFile] as $_f => $_v) {
            if (!preg_match('/<meta\s+name=["\']'.preg_quote($_f,'/').'["\']\s+content=["\'][^"\']*["\']\s*\/?>/is', $_htmlNew)) continue;
            $_htmlNew = preg_replace('/<meta\s+name=["\']'.preg_quote($_f,'/').'["\']\s+content=["\'][^"\']*["\']\s*\/?>/is', '<meta name="'.$_f.'" content="'.htmlspecialchars($_v,ENT_QUOTES,'UTF-8').'">', $_htmlNew, 1);
            $_changed = true;
        }
        if ($_changed) file_put_contents($htmlFile, $_htmlNew, LOCK_EX);
    }
}
if($status===''||$status===null){$status=$expStatusMode==='override'?$expStatusOverride:1;}else{$status=(int)$status;}
// Date calculation based on mode
if ($expDateMode === 'fixed' && $expDateFixed !== '') {
    $datestamp = dateToTimestamp($expDateFixed);
} elseif ($expDateMode === 'offset' && isset($slugDateMap[$slug])) {
    $datestamp = $slugDateMap[$slug];
} else {
    $datestamp = dateToTimestamp($datestampStr);
}
$description=extractContent($html);
$payload=['title'=>$title,'meta_title'=>$metaTitle,'meta_description'=>$metaDesc,'meta_keywords'=>$metaKeywords,'tags'=>$tags,'description'=>$description,'short_description'=>$shortDesc,'name'=>$slug,'slug'=>$slug,'slug_search'=>$slug,'language'=>$language,'status'=>$status,'datestamp'=>$datestamp,'schema'=>$schema,'priority'=>$priority,'view'=>$view,'settings_comments'=>$settingsComments,'settings_tags'=>$settingsTags,'comments'=>$comments,'settings_rating'=>$settingsRating,'password'=>$password,'show_tree'=>$showTree,'rating'=>$rating];
$doDelete = (isset($meta['delete']) && strtolower($meta['delete']) === 'true');
if ($doDelete && $articleId > 0) { $payload['delete'] = true; $payload['id'] = $articleId; }
if($expMode !== 'insert') $payload['update_exists'] = true;
if ($articleId>0){
    $payload['id'] = $articleId;
} elseif ($articleId === 0 && ctype_digit($slug)) {
    $payload['id'] = (int)$slug;
}
if($multilangid && $expFixMultilangid)$payload['multilangid']=$multilangid;
if($exportTextOnly){
    $keep = ['id','update_exists','delete','title','description','name','slug','language'];
    $payload = array_intersect_key($payload, array_flip($keep));
}
$jsonPayload=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);$descSize=mb_strlen($description);$metaCount=count($meta);
// Store for batch send
$batchPayloads[] = $payload;
$batchArticles[] = ['slug'=>$slug, 'relPath'=>$relPath, 'multilangid'=>$multilangid, 'idx'=>$articleIdx];
?>
<div class="article"><div class="article-header"><span><span class="num">#<?=$articleIdx?></span> <span class="file"><?=htmlspecialchars($relPath)?></span></span><span class="date"><?=date('Y-m-d H:i:s')?></span></div>
<div class="article-body">
<details><summary><span data-i18n="metadata_title">📋 Метаданные</span> (<?=$metaCount?> <span data-i18n="fields_count">полей</span>)</summary><div class="meta-grid">
<?php foreach($meta as $mk=>$mv):?><span class="key"><?=htmlspecialchars($mk)?>:</span><span class="val"><?=$mv!==''&&$mv!==null?htmlspecialchars((string)$mv):'<span class="na">—</span>'?></span><?php endforeach;?>
<span class="key" data-i18n="desc_size_label">размер description:</span><span class="val"><?=$descSize?> <span data-i18n="chars_count">символов</span></span>
</div></details>
<details><summary data-i18n="payload_title">📦 Отправляемые данные (payload)</summary><div class="resp-block"><?=htmlspecialchars($jsonPayload)?></div></details>
<details><summary data-i18n="description_title">📄 Description (полный текст)</summary><textarea readonly><?=htmlspecialchars($description)?></textarea></details>
<?php if($dryRun):?><div class="result-warn" data-i18n="dryrun_skip">⚡ DRY RUN — запрос не отправлен</div></div></div>
<?php continue; endif; ?>
<div class="result-placeholder" data-idx="<?=$articleIdx?>"><div class="result-pending" style="color:#888;padding:8px 0;"><span data-i18n="batch_pending">⏳ Ожидание ответа пакетного запроса...</span></div></div>
</div></div>
<?php endforeach;

// === Batch API request ===
if (!empty($batchPayloads) && !$dryRun):
$batchJson = json_encode(['pages' => $batchPayloads], JSON_UNESCAPED_UNICODE);
$httpMethod = ($expMode === 'update') ? 'PUT' : 'POST';
$ch=curl_init($API_URL);
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$httpMethod,CURLOPT_POSTFIELDS=>$batchJson,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>["Authorization: Bearer ".$AUTH_KEY,"Content-Type: application/json"],CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_ENCODING=>'',CURLOPT_CONNECTTIMEOUT=>60,CURLOPT_TIMEOUT=>300,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_SSL_VERIFYPEER=>0]);
$responseFull=curl_exec($ch);$httpCode=curl_getinfo($ch,CURLINFO_HTTP_CODE);$curlError=curl_error($ch);$headerSize=curl_getinfo($ch,CURLINFO_HEADER_SIZE);curl_close($ch);
$responseBody='';$respData=null;$batchResults=[];
if($responseFull!==false&&$responseFull!==''){$responseBody=substr((string)$responseFull,$headerSize);$respData=json_decode($responseBody,true);}
if($curlError):
    $batchError = htmlspecialchars($curlError);
    $errCount = count($batchPayloads);
    echo '<div class="result-fail" style="padding:12px;margin:10px 0;background:#fee2e2;border-radius:8px;"><span class="error" data-i18n="curl_error">✗ cURL Ошибка</span><br><span>'.$batchError.'</span></div>';
    ?>
    <script>
    (function(){
        document.querySelectorAll('.result-placeholder').forEach(function(el){
            el.innerHTML = '<div class="result-fail"><span class="error">✗ Ошибка: <?=$batchError?></span></div>';
        });
    })();
    </script>
    <?php $errors = $errCount;
elseif($httpCode>=200&&$httpCode<300&&is_array($respData)):
    // API can return single result or batch results array
    if (isset($respData['results']) && is_array($respData['results'])) {
        $batchResults = $respData['results'];
    } elseif (isset($respData['pages']) && is_array($respData['pages'])) {
        $batchResults = [$respData['pages']];
    }
    // Map results by slug_search (returned by API with lang suffix, e.g. "slug-en")
    $resultMap = []; $resultIdx = 0;
    foreach ($batchResults as $br) {
        $slugFull = $br['slug_search'] ?? '';
        if ($slugFull) { 
            $resultMap[$slugFull] = $br;
            // Also map by base slug (strip language suffix like -en, -ua, -pl, -ru)
            $slugBase = preg_replace('/-(en|ua|pl|ru)$/', '', $slugFull);
            if ($slugBase !== $slugFull) {
                $resultMap[$slugBase] = $br;
            }
        }
        // Fallback: by position
        if (isset($batchArticles[$resultIdx])) {
            $resultMap['_pos_'.$resultIdx] = $br;
        }
        $resultIdx++;
    }
    // Build summary counts
    $summaryCreated = 0; $summaryUpdated = 0; $summaryErrors = 0; $summarySkippedExist = 0; $allResultsHtml = []; $errorDetails = []; $skippedExistDetails = [];
    foreach ($batchArticles as $baIdx => $ba):
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
            <?php if(empty($art)):?><br><span>Нет ответа для данной страницы</span><?php endif;?>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="result-ok"><span class="success" data-i18n="<?=$isAdded?'page_created':'page_updated'?>">✓ <?=$action2?> (ID: <?=$respId?>)</span>
            <?php if($ba['multilangid']):?><br><span class="warning">🔗 multilangid: <?=htmlspecialchars($ba['multilangid'])?></span><?php endif;?>
            <?php if(!empty($skipFields)):?><br><span class="warning" data-i18n="skipped_fields">⚠ Пропущенные поля:</span><?php foreach($skipFields as $fk=>$fv):?><div><?=htmlspecialchars($fk)?>: <?=htmlspecialchars(is_array($fv)?json_encode($fv,JSON_UNESCAPED_UNICODE):$fv)?></div><?php endforeach;?><?php endif;?>
            </div>
            <details open><summary data-i18n="verification_title">🔍 Верификация</summary><div class="meta-grid">
            <span class="key" data-i18n="status_label">статус:</span><span class="val"><span class="success"><span data-i18n="page_saved">✓ Данные сохранены успешно</span><?php if(!empty($respId)):?> (ID: <?=$respId?>)<?php endif;?></span></span>
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
var _t={ru:{'home':'🏠 Главная','site_label':'Сайт:','entity_name':'Страницы','import':'📥 Импорт','export':'📤 Экспорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Создано:','updated_label':'📝 Обновлено:','errors_label':'❌ Ошибок:','skipped_exist_label':'⏭ Пропущено (существуют):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Экспортировать только текст','file_selection':'📁 Выбор файлов','btn_get':'📥 СКАЧАТЬ','btn_update':'📤 ОТПРАВИТЬ','plaque_export':'▸ <strong>Настройки экспорта</strong> — отправка страниц на Boostore.pro','dryrun_warn':'⚡ DRY RUN — запросы не отправляются','back_home':'Назад','back_to_settings':'← Назад к настройкам','step2_header':'▸ <strong>Шаг 2</strong> — выберите файлы для экспорта на сайт','step2_title':'Экспорт страниц — выбор файлов','back_to_filters':'← Назад к фильтрам','no_files_found':'Нет файлов, соответствующих критериям поиска','files_found':'Найдено файлов:','select_all':'☑ ВЫДЕЛИТЬ ВСЕ','deselect_all':'☐ СНЯТЬ ВСЕ','export_selected':'📤 ЭКСПОРТИРОВАТЬ ВЫДЕЛЕННЫЕ','no_html_files':'✕ Нет HTML-файлов','no_html_files_desc':'В папке pages/ не найдено *.html','metadata_title':'📋 Метаданные','fields_count':'полей','payload_title':'📦 Отправляемые данные (payload)','description_title':'📄 Description (полный текст)','dryrun_skip':'⚡ DRY RUN — запрос не отправлен','curl_error':'✗ cURL Ошибка','page_created':'✓ Страница создана','page_updated':'✓ Страница обновлена','skipped_fields':'⚠ Пропущенные поля:','api_errors':'✩ Ошибки API:','field_errors':'⚠ Ошибки полей:','api_response':'📬 Ответ API','verification_title':'🔍 Верификация','sent_chars':'отправлено символов:','saved_chars':'сохранено символов:','status_label':'статус:','page_saved':'✓ Данные сохранены успешно','fetch_error':'✗ Ошибка','http_error':'✗ Ошибка (HTTP','total_label':'Итог:','processed':'обработано','skipped':'пропущено','created':'создано','updated':'обновлено','errors':'ошибок','completed_at':'Завершено:','batch_pending':'⏳ Ожидание ответа пакетного запроса...','desc_size_label':'размер description:','chars_count':'символов','all_languages':'все','lang_ru':'Русский','lang_en':'English','lang_ua':'Українська','lang_pl':'Polski','lang_de':'Deutsch','lang_fr':'Français','lang_es':'Español','lang_it':'Italiano','lang_kk':'Қазақ','lang_be':'Беларуская','api_docs':'API Docs','version':'v2.0','date_format':'ГГГГ-ММ-ДД','search_placeholder':'часть имени, например: shoes','cat_id_placeholder':'ID','cat_name_placeholder':'имя категории','prompt_values':'Введите значения (каждая строка — отдельное поле):','step_forward':'➡ ДАЛЕЕ','dry_run_label':'Dry run','filter_name':'Фильтр по имени (slug)','batch_label':'Отправить за 1 раз','ref_lang_be':'Белорусский (be)','ref_lang_en':'English (en)','ref_lang_ru':'Русский (ru)','ref_lang_ua':'Українська (ua)','ref_lang_pl':'Polski (pl)','date_mode_meta':'Из мета-данных (дата из каждой страницы)','date_mode_fixed':'Одна дата для всех страниц','date_mode_offset':'Смещение дат (+N дней на страницу)','status_mode_meta':'Из мета-данных (статус из каждой страницы)','status_mode_override':'Переопределить для всех страниц','date_mode_label':'📅 Режим даты публикации','title':'Управление страницами — Boostore.pro','fix_import_title':'🔧 Исправление по эталону','fix_import_desc':'Синхронизировать поля с эталонной страницей (по slug) после сохранения','ref_lang_label':'🌐 Язык эталонной страницы','export_fields_label':'📋 Поля для экспорта','import_only_named':'Только с именем'},en:{'home':'🏠 Home','site_label':'Site:',
    'delete_site':'🗑 Delete site settings','entity_name':'Pages','import':'📥 Import','export':'📤 Export','completed':'✓ Completed','result_label':'✓ Result','created_label':'✅ Created:','updated_label':'📝 Updated:','errors_label':'❌ Errors:','skipped_exist_label':'⏭ Skipped (exist):','skipped_exist_label2':'⏭ Skipped','export_text_only':'📝 Export text only','file_selection':'📁 File selection','btn_get':'📥 DOWNLOAD','btn_update':'📤 UPLOAD','plaque_export':'▸ <strong>Export Settings</strong> — sending pages to Boostore.pro','dryrun_warn':'⚡ DRY RUN — no API calls sent','back_home':'Home','back_to_settings':'← Back to settings','step2_header':'▸ <strong>Step 2</strong> — select files to export','step2_title':'Export — file selection','back_to_filters':'← Back to filters','no_files_found':'No files matching search criteria','files_found':'Files found:','select_all':'☑ SELECT ALL','deselect_all':'☐ DESELECT ALL','export_selected':'📤 EXPORT SELECTED','no_html_files':'✕ No HTML files','no_html_files_desc':'No *.html found in blog/','skipped_category':'⏭ Skipped — category not in ALLOWED_CATEGORIES','metadata_title':'📋 Metadata','fields_count':'fields','payload_title':'📦 Payload','description_title':'📄 Description (full text)','dryrun_skip':'⚡ DRY RUN — request not sent','curl_error':'✗ cURL Error','article_created':'✓ Article created','article_updated':'✓ Article updated','skipped_fields':'⚠ Skipped fields:','api_errors':'✩ API Errors:','field_errors':'⚠ Field errors:','api_response':'📬 API Response','verification_title':'🔍 Verification','sent_chars':'chars sent:','saved_chars':'chars saved:','status_label':'status:','article_saved':'✓ Article saved','length_discrepancy':'ℹ Length discrepancy','http_error':'✗ Error (HTTP','total_label':'Total:','processed':'processed','skipped':'skipped','created':'created','updated':'updated','errors':'errors','completed_at':'Completed:','desc_size_label':'description size:','chars_count':'chars','all_languages':'all','lang_ru':'Russian','lang_en':'English','lang_ua':'Ukrainian','lang_pl':'Polish','lang_de':'German','lang_fr':'French','lang_es':'Spanish','lang_it':'Italian','lang_kk':'Kazakh','lang_be':'Belarusian','api_docs':'API Docs','version':'v2.0','date_format':'YYYY-MM-DD','search_placeholder':'part of name, e.g.: shoes','cat_id_placeholder':'ID','cat_name_placeholder':'category name','prompt_values':'Enter values (each line is a separate field):','step_forward':'➡ NEXT','dry_run_label':'Dry run','filter_name':'Filter by name (slug)','batch_label':'Send per run','ref_lang_be':'Belarusian (be)','ref_lang_en':'English (en)','ref_lang_ru':'Russian (ru)','ref_lang_ua':'Ukrainian (ua)','ref_lang_pl':'Polish (pl)','date_mode_meta':'From meta-data (date from each page)','date_mode_fixed':'Single date for all pages','date_mode_offset':'Date offset (+N days per page)','planned_notset':'— not set (from meta-data)','planned_0':'0 — not planned','planned_1':'1 — planned publishing','status_mode_meta':'From meta-data (status from each page)','status_mode_override':'Override for all pages','date_mode_label':'📅 Publication Date Mode','title':'Pages Management — Boostore.pro','fix_import_title':'🔧 Fix by Reference','fix_import_desc':'Sync fields with reference page (by slug) after save','ref_lang_label':'🌐 Reference Language','export_fields_label':'📋 Fields to export','import_only_named':'Named only'},ua:{'home':'🏠 Головна','site_label':'Сайт:','entity_name':'Сторінки','import':'📥 Імпорт','export':'📤 Експорт','completed':'✓ Результат','result_label':'✓ Результат','created_label':'✅ Створено:','updated_label':'📝 Оновлено:','errors_label':'❌ Помилок:','skipped_exist_label':'⏭ Пропущено (існують):','skipped_exist_label2':'⏭ Пропущено','export_text_only':'📝 Експортувати тільки текст','file_selection':'📁 Вибір файлів','btn_get':'📥 ПОЧАТИ ІМПОРТ','btn_update':'📤 ПОЧАТИ ЕКСПОРТ','plaque_export':'▸ <strong>Налаштування експорту</strong> — відправлення статей на Boostore.pro','dryrun_warn':'⚡ DRY RUN — запити не надсилаються','back_home':'На головну','back_to_settings':'← Назад до налаштувань','step2_header':'▸ <strong>Крок 2</strong> — виберіть файли для експорту','step2_title':'Експорт — вибір файлів','back_to_filters':'← Назад до фільтрів','no_files_found':'Немає файлів, що відповідають критеріям пошуку','files_found':'Знайдено файлів:','select_all':'☑ ВИДІЛИТИ ВСІ','deselect_all':'☐ ЗНЯТИ ВСІ','export_selected':'📤 ЕКСПОРТУВАТИ ВИДІЛЕНІ','no_html_files':'✕ Немає HTML-файлів','no_html_files_desc':'У папці blog/ не знайдено *.html','skipped_category':'⏭ Пропущено — категорія не входить до ALLOWED_CATEGORIES','metadata_title':'📋 Метадані','fields_count':'полів','payload_title':'📦 Дані (payload)','description_title':'📄 Опис (повний текст)','dryrun_skip':'⚡ DRY RUN — запит не надіслано','curl_error':'✗ cURL Помилка','article_created':'✓ Статтю створено','article_updated':'✓ Статтю оновлено','skipped_fields':'⚠ Пропущені поля:','api_errors':'✩ Помилки API:','field_errors':'⚠ Помилки полів:','api_response':'📬 Відповідь API','verification_title':'🔍 Верифікація','sent_chars':'надіслано символів:','saved_chars':'збережено символів:','status_label':'статус:','article_saved':'✓ Статтю збережено','length_discrepancy':'ℹ Розбіжність у довжині','http_error':'✗ Помилка (HTTP','total_label':'Підсумок:','processed':'опрацьовано','skipped':'пропущено','created':'створено','updated':'оновлено','errors':'помилок','completed_at':'Завершено:','desc_size_label':'розмір description:','chars_count':'символів','all_languages':'всі','lang_ru':'Російська','lang_en':'Англійська','lang_ua':'Українська','lang_pl':'Польська','lang_de':'Німецька','lang_fr':'Французька','lang_es':'Іспанська','lang_it':'Італійська','lang_kk':'Казахська','lang_be':'Білоруська','api_docs':'API Docs','version':'v2.0','date_format':'РРРР-ММ-ДД','search_placeholder':'частина імені, наприклад: shoes','cat_id_placeholder':'ID','cat_name_placeholder':'ім\'я категорії','prompt_values':'Введіть значення (кожен рядок — окреме поле):','step_forward':'➡ ДАЛІ','dry_run_label':'Dry run','filter_name':'Фільтр за іменем (slug)','batch_label':'Відправити за 1 раз','ref_lang_be':'Білоруська (be)','ref_lang_en':'Англійська (en)','ref_lang_ru':'Російська (ru)','ref_lang_ua':'Українська (ua)','ref_lang_pl':'Польська (pl)','date_mode_meta':'З мета-даних (дата з кожної статті)','date_mode_fixed':'Одна дата для всіх статей','date_mode_offset':'Зміщення дат (+N днів на статтю)','planned_notset':'— не вказано (з мета-даних)','planned_0':'0 — не відкладена','planned_1':'1 — відкладена публікація','status_mode_meta':'З мета-даних (статус з кожної статті)','status_mode_override':'Перевизначити для всіх статей','date_mode_label':'📅 Режим дати публікації','title':'Керування сторінками — Boostore.pro','fix_import_title':'🔧 Виправлення за еталоном','fix_import_desc':'Синхронізувати поля з еталонною сторінкою (по slug) після збереження','ref_lang_label':'🌐 Мова еталонної сторінки','export_fields_label':'📋 Поля для експорту','import_only_named':'Тільки з назвою'}};
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});document.querySelectorAll('[data-i18n-placeholder]').forEach(function(el){var key=el.getAttribute('data-i18n-placeholder');if(_t[l]&&_t[l][key]!==undefined)el.placeholder=_t[l][key];});}
function applyLang(l){try{localStorage.setItem('boostore_lang',l);}catch(e){}_lang=l;document.querySelectorAll('[data-i18n]').forEach(function(el){var key=el.getAttribute('data-i18n');if(_t[l]&&_t[l][key]!==undefined)el.innerHTML=_t[l][key];});}
if(_lang!='ru'){document.addEventListener('DOMContentLoaded',function(){applyLang(_lang);});}
document.addEventListener('DOMContentLoaded',function(){var ls=document.getElementById('lang_switcher');if(ls){ls.value=_lang;ls.addEventListener('change',function(){applyLang(this.value);});}});
</script>
</div></body></html>
<?php exit;
// ===================================================================
// _update-pages.php — END
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
    $SITES[$ts]['status_mode'] = $post['STATUS_MODE'] ?? '';
    $SITES[$ts]['status_override'] = (int)($post['STATUS_OVERRIDE'] ?? 1);
    $SITES[$ts]['date_mode'] = $post['DATE_MODE'] ?? '';
    $SITES[$ts]['date_fixed'] = $post['DATE_FIXED'] ?? '';
    $SITES[$ts]['date_offset_base'] = $post['DATE_OFFSET_BASE'] ?? '';
    $SITES[$ts]['date_offset_days'] = (int)($post['DATE_OFFSET_DAYS'] ?? 1);
    $SITES[$ts]['per_page'] = (int)($post['PER_PAGE'] ?? 200);
    $SITES[$ts]['send_batch_limit'] = (int)($post['SEND_BATCH_LIMIT'] ?? 200);
    $SITES[$ts]['reference_lang'] = $post['REFERENCE_LANG'] ?? 'ru';
    $SITES[$ts]['fix_multilangid'] = isset($post['FIX_MULTILANGID']);
    $SITES[$ts]['fix_status'] = isset($post['FIX_STATUS']);
    $SITES[$ts]['fix_datestamp'] = isset($post['FIX_DATESTAMP']);
    // Build config content
    $c = "<?php\n// === per-site config ===\n\$SITES = [\n";
    foreach ($SITES as $sd => $sc) {
        $sk = var_export($sc['key'] ?? '', true);
        $c .= "  ".var_export($sd, true)." => ['key' => $sk";
        $c .= ", 'status_mode' => ".var_export($sc['status_mode']??'',true);
        $c .= ", 'status_override' => ".(int)($sc['status_override']??1);
        $c .= ", 'date_mode' => ".var_export($sc['date_mode']??'',true);
        $c .= ", 'date_fixed' => ".var_export($sc['date_fixed']??'',true);
        $c .= ", 'date_offset_base' => ".var_export($sc['date_offset_base']??'',true);
        $c .= ", 'date_offset_days' => ".(int)($sc['date_offset_days']??1);
        $c .= ", 'per_page' => ".(int)($sc['per_page']??200);
        $c .= ", 'send_batch_limit' => ".(int)($sc['send_batch_limit']??200);
        $c .= ", 'reference_lang' => ".var_export($sc['reference_lang']??'ru',true);
        $c .= ", 'fix_multilangid' => ".($sc['fix_multilangid']??false?'true':'false');
        $c .= ", 'fix_status' => ".($sc['fix_status']??false?'true':'false');
        $c .= ", 'fix_datestamp' => ".($sc['fix_datestamp']??false?'true':'false');
        $c .= "],\n";
    }
    $c .= "];\n";
    file_put_contents(__DIR__.'/_setting_pages.inc', $c);
}
$saveSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    saveConfigFromPost($_POST);
    $saveSuccess = true;
    require __DIR__.'/_setting_pages.inc';
    $currentSite = $_POST['site'] ?? (!empty($_GET['site']) ? $_GET['site'] : (array_keys($SITES)[0] ?? ''));
    $siteCfg = $SITES[$currentSite] ?? [];
    $STATUS_MODE         = $siteCfg['status_mode'] ?? '';
    $STATUS_OVERRIDE     = $siteCfg['status_override'] ?? 1;
    $DATE_MODE           = $siteCfg['date_mode'] ?? '';
    $DATE_FIXED          = $siteCfg['date_fixed'] ?? '';
    $DATE_OFFSET_BASE    = $siteCfg['date_offset_base'] ?? '';
    $DATE_OFFSET_DAYS    = $siteCfg['date_offset_days'] ?? 1;
    $PER_PAGE            = $siteCfg['per_page'] ?? 200;
    $SEND_BATCH_LIMIT    = $siteCfg['send_batch_limit'] ?? 200;
    $REFERENCE_LANG      = $siteCfg['reference_lang'] ?? 'ru';
    $FIX_MULTILANGID     = $siteCfg['fix_multilangid'] ?? false;
    $FIX_STATUS          = $siteCfg['fix_status'] ?? false;
    $FIX_DATESTAMP       = $siteCfg['fix_datestamp'] ?? false;
    $AUTH_KEY = $SITES[$currentSite]['key'] ?? '';
    $apiKeyMissing = empty($AUTH_KEY);
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><title>Управление страницами — Boostore.pro</title>
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
<strong>Boostore.pro</strong> — Скрипты для <strong>экспорта</strong> (скачивания) и <strong>импорта</strong> (отправки) страниц через Commerce API. Сайт: <strong><?=htmlspecialchars($currentSite)?></strong>
</div>
<details class="card"><summary><span data-i18n="instr_title">📖 Инструкция</span> <span class="arrow">▶</span></summary><div class="card-body">
<p data-i18n="instr_intro">Все настройки — в разделе «Конфигурация» ниже. Если список категорий пуст — обрабатываются все категории.</p>
<h3 data-i18n="quickstart">Быстрый старт</h3>
<ol style="margin-left:18px;line-height:1.7;">
<li data-i18n="step1">Настройте <strong>ключ доступа</strong> в разделе «Настройка → Магазин → Доступ к статистике продаж»</li>
<li data-i18n="step2">Укажите ключ и URL вашего сайта в <strong>конфигурации</strong> ниже</li>
<li data-i18n="step4">Нажмите <strong>"СКАЧАТЬ"</strong> — страницы скачаются в папку <code><?=htmlspecialchars($currentSite)?>/pages/</code></li>
<li data-i18n="step5">При получении страницы одной группы (одинаковый slug) сверяются с эталонной версией (выбранный язык в конфигурации). <code>multilangid</code>, <code>status</code>, <code>datestamp</code> приводятся к эталону</li>
<li data-i18n="step6">Отредактируйте HTML-файлы в <code><?=htmlspecialchars($currentSite)?>/pages/</code> при необходимости</li>
<li data-i18n="step7">Нажмите <strong>"ОТПРАВИТЬ"</strong> — изменения отправятся на сайт</li>
</ol>
<h3 data-i18n="file_naming">Именование файлов</h3><p data-i18n="file_naming_desc">Шаблон: <code>{id}-{name}-{language}.html</code>. Пример: <code>123-moya-statya-ru.html</code></p>
<h3 data-i18n="file_format">Формат файла</h3><p data-i18n="file_format_desc">Мета-данные в <code>&lt;meta name="..." content="..."&gt;</code> передают настройки страницы: slug, заголовок, язык, теги, дату публикации, статус доступа, описание и системные параметры. Содержимое — после <code>&lt;!-- CONTENT SEPARATOR BELOW --&gt;</code></p>
<p style="color:#ff9800;font-size:12px;margin-top:6px;">💡 <strong>Удаление:</strong> добавьте <code>&lt;meta name="delete" content="true"&gt;</code> в HTML-файл и укажите <code>id</code> страницы — при экспорте запись будет удалена с сервера.</p>
</div></details>
<div class="card"><div class="card-header" data-i18n="actions_title">⚡ Действия</div><div class="card-body">
<div class="btn-group"><a href="?action=get&site=<?=urlencode($currentSite)?>" class="btn btn-primary" data-i18n="btn_get">📥 СКАЧАТЬ</a>
<a href="?action=update&site=<?=urlencode($currentSite)?>" class="btn btn-success" data-i18n="btn_update">📤 ОТПРАВИТЬ</a>
<a href="?action=update&dry-run&site=<?=urlencode($currentSite)?>" class="btn btn-warning" data-i18n="btn_dryrun">🔍 Тест (сухая отправка)</a></div>
<div style="font-size:12px;color:#888;margin-top:8px;" data-i18n="dryrun_desc">Режим «тест» — проверяет какие страницы будут отправлены, но сами запросы к API не выполняются.</div>
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
<hr><h3 data-i18n="import_settings_title">📥 Настройки импорта</h3>
<div style="font-size:12px;color:#888;margin-bottom:8px;" data-i18n="import_settings_desc">Параметры, влияющие на получение и сохранение страниц из API</div>
<div class="form-row"><div class="field" style="max-width:200px;">
<label data-i18n="per_page_label">Страниц за запрос (per_page)</label>
<div style="font-size:11px;color:#888;margin-bottom:6px;" data-i18n="per_page_desc">Сколько страниц загружать за 1 запрос к API. Максимум 2000</div>
<input type="number" name="PER_PAGE" value="<?=(int)($PER_PAGE??200)?>" min="1" max="2000">
</div></div>
<h3 style="margin:12px 0 8px;font-size:14px;color:#888;" data-i18n="fix_export_title">🔧 Исправление при экспорте</h3>
<div class="form-row"><div class="field" style="max-width:250px;">
<label data-i18n="ref_lang_label">🌐 Язык эталонной страницы</label>
<div style="font-size:11px;color:#888;margin-bottom:6px;" data-i18n="ref_lang_desc">Страницы этого языка считаются эталоном. При получении страниц других языков сверяются с ним</div>
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
<div style="font-size:12px;color:#888;margin-bottom:8px;" data-i18n="export_settings_desc">Параметры, влияющие на отправку страниц на сайт</div>
<div class="form-row"><div class="field" style="max-width:400px;">
<label data-i18n="date_mode_label">📅 Режим даты публикации</label>
<div style="font-size:11px;color:#888;margin-bottom:6px;" data-i18n="date_mode_desc">Как определять дату публикации страниц при импорте</div>
<select name="DATE_MODE" id="date_mode" onchange="toggleDateFields()">
<option value=""<?=$DATE_MODE===''?' selected':''?> data-i18n="date_mode_meta">Из мета-данных (дата из каждой страницы)</option>
<option value="fixed"<?=$DATE_MODE==='fixed'?' selected':''?> data-i18n="date_mode_fixed">Одна дата для всех страниц</option>
<option value="offset"<?=$DATE_MODE==='offset'?' selected':''?> data-i18n="date_mode_offset">Смещение дат (+N дней на страницу)</option>
</select>
</div></div>
<div id="date_fixed_block" style="display:<?=$DATE_MODE==='fixed'?'block':'none'?>;margin-top:-10px;">
<div class="form-row"><div class="field" style="max-width:300px;">
<label data-i18n="date_fixed_label">📅 Фиксированная дата</label>
<div style="font-size:11px;color:#888;margin-bottom:6px;" data-i18n="date_fixed_desc">Эта дата будет установлена всем статьям</div>
<input type="date" name="DATE_FIXED" value="<?=htmlspecialchars($DATE_FIXED?:date('Y-m-d'))?>">
</div></div>
</div>
<div id="date_offset_block" style="display:<?=$DATE_MODE==='offset'?'block':'none'?>;margin-top:-10px;">
<div class="form-row"><div class="field" style="max-width:250px;">
<label data-i18n="date_offset_label">📅 Базовая дата (от которой начинать отсчёт)</label>
<input type="date" name="DATE_OFFSET_BASE" value="<?=htmlspecialchars($DATE_OFFSET_BASE?:date('Y-m-d'))?>">
</div>
<div class="field" style="max-width:120px;">
<label data-i18n="date_offset_days">+ дней на страницу</label>
<input type="number" name="DATE_OFFSET_DAYS" value="<?=(int)($DATE_OFFSET_DAYS??1)?>" min="0" max="365">
</div></div>
<div style="font-size:11px;color:#888;" data-i18n="date_offset_desc">Первая уникальная статья (по slug) получит базовую дату, вторая — базовую дату + N дней, и т.д. Статьи на разных языках с одинаковым slug считаются одной статьёй и получают одинаковую дату.</div>
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
<div style="font-size:11px;color:#888;margin-bottom:6px;" data-i18n="status_mode_desc">Режим определения статуса публикации страниц при импорте</div>
<select name="STATUS_MODE" id="status_mode" onchange="toggleStatusFields()">
<option value=""<?=$STATUS_MODE===''?' selected':''?> data-i18n="status_mode_meta">Из мета-данных (статус из каждой страницы)</option>
<option value="override"<?=$STATUS_MODE==='override'?' selected':''?> data-i18n="status_mode_override">Переопределить для всех страниц</option>
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
<div style="font-size:11px;color:#888;margin-bottom:6px;" data-i18n="send_limit_desc">Сколько страниц можно отправить за один запуск. Максимум 5000</div>
<input type="number" name="SEND_BATCH_LIMIT" value="<?=(int)($SEND_BATCH_LIMIT??200)?>" min="1" max="5000">
</div></div>
<hr><h3 data-i18n="fix_title">🔄 Исправление по эталону</h3>
<div style="font-size:11px;color:#888;margin-bottom:8px;" data-i18n="fix_desc">Какие поля автоматически исправлять по эталону при получении страниц</div>
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
<div class="footer"><strong>Boostore.pro</strong> — <span data-i18n="footer_text">Управление страницами</span> &nbsp;|&nbsp; <a href="https://boostore.pro/ru/docs/api-integration/#hotengine-CommerceAPI" target="_blank" data-i18n="footer_docs">Документация API</a> &nbsp;|&nbsp; <a href="index.php" style="color:#00d4ff;" data-i18n="back_home">← На главную</a></div>
</div>
<script>
// ===== i18n Translations =====
var i18n = {
  ru: {
    'title':'Управление страницами — Boostore.pro',
    'warn_nokey':'⚠ Необходимо указать ключ доступа API (Consumer Secret) в разделе «Конфигурация» ниже, иначе скрипты не будут работать.',
    'warn_domain':'⚠ Для работы API обязательно нужно открывать его с использованием адреса вашего сайта, созданного на платформе Boostore.pro. Измените домен в конфигурации ниже на ваш (например: moy-sayt.boostore.pro).',
    'saved':'Конфигурация сохранена',
    'plaque':'Boostore.pro — Скрипты для экспорта (скачивания) и импорта (отправки) страниц через Commerce API. Домен: ',
    'plaque_import':'▸ <strong>Настройки импорта</strong> — получение страниц с API',
    'plaque_export':'▸ <strong>Настройки экспорта</strong> — отправка страниц на Boostore.pro',
    'instr_title':'📖 Инструкция',
    'instr_intro':'Все настройки — в разделе «Конфигурация» ниже.',
    'quickstart':'Быстрый старт',
    'step1':'Настройте ключ доступа в разделе «Настройка → Магазин → Доступ к статистике продаж»',
    'step2':'Укажите ключ и URL вашего сайта в конфигурации ниже',
    'step4':'Нажмите «СКАЧАТЬ» — страницы скачаются в папку pages/',
    'step5':'При получении страницы одной группы (одинаковый slug) сверяются с эталонной версией (выбранный язык в конфигурации). multilangid, status, datestamp приводятся к эталону',
    'step6':'Отредактируйте HTML-файлы в pages/ при необходимости',
    'step7':'Нажмите «ОТПРАВИТЬ» — изменения отправятся на сайт',
    'file_naming':'Именование файлов',
    'file_naming_desc':'Шаблон: {id}-{name}-{language}.html. Пример: 123-moya-stranica-ru.html',
    'file_format':'Формат файла',
    'file_format_desc':'Мета-данные в &lt;meta name=&quot;...&quot; content=&quot;...&quot;&gt; передают настройки страницы. Содержимое — после &lt;!-- CONTENT SEPARATOR BELOW --&gt;',
    'actions_title':'⚡ Действия',
    'btn_get':'📥 СКАЧАТЬ',
    'btn_update':'📤 ОТПРАВИТЬ',
    'btn_dryrun':'🔍 Тест (сухая отправка)',
    'dryrun_desc':'Режим «тест» — проверяет какие страницы будут отправлены, но сами запросы к API не выполняются.',
    'btn_save':'💾 Сохранить конфигурацию',
    'config_title':'⚙ Конфигурация',
    'lbl_key':'🔑 Ключ доступа (Consumer Secret)',
    'lbl_url':'🌐 Домен сайта (например: site.boostore.pro)',
    'api_note':'⚠ Для работы API обязательно нужно открывать его с использованием адреса вашего сайта, созданного на платформе Boostore.pro. Измените домен ниже на ваш.',
    'footer_text':'Управление страницами',
    'footer_docs':'Документация API',
    'confirm_get':'Запустить получение страниц?',
    'confirm_update':'Запустить отправку страниц?',
    'confirm_dryrun':'Запустить пробную отправку?',
    'ref_lang_be':'Белорусский (be)',
    'ref_lang_en':'English (en)',
    'ref_lang_ru':'Русский (ru)',
    'ref_lang_ua':'Українська (ua)',
    'ref_lang_pl':'Polski (pl)',
    'date_mode_meta':'Из мета-данных (дата из каждой страницы)',
    'date_mode_fixed':'Одна дата для всех страниц',
    'date_mode_offset':'Смещение дат (+N дней на страницу)',
    'status_mode_meta':'Из мета-данных (статус из каждой страницы)',
    'status_mode_override':'Переопределить для всех страниц',
    'export_settings_title':'📤 Настройки экспорта',
    'export_settings_desc':'Параметры, влияющие на получение и сохранение страниц из API',
    'per_page_label':'Страниц за запрос (per_page)',
    'per_page_desc':'Сколько страниц загружать за 1 запрос к API. Максимум 2000',
    'fix_export_title':'🔧 Исправление при экспорте',
    'ref_lang_label':'🌐 Язык эталонной страницы',
    'ref_lang_desc':'Страницы этого языка считаются эталоном. При получении страниц других языков сверяются с ним',
    'import_settings_title':'📥 Настройки импорта',
    'import_settings_desc':'Параметры, влияющие на получение и сохранение страниц из API',
    'date_mode_label':'📅 Режим даты публикации',
    'date_mode_desc':'Как определять дату публикации страниц при импорте',
    'date_fixed_label':'📅 Фиксированная дата',
    'date_fixed_desc':'Эта дата будет установлена всем страницам',
    'date_offset_label':'📅 Базовая дата (от которой начинать отсчёт)',
    'date_offset_days':'+ дней на страницу',
    'date_offset_desc':'Первая уникальная страница (по slug) получит базовую дату, вторая — базовую дату + N дней, и т.д. Страницы на разных языках с одинаковым slug считаются одной страницей и получают одинаковую дату.',
    'status_mode_label':'🔒 Статус доступа (status)',
    'status_mode_desc':'Режим определения статуса публикации страниц при импорте',
    'status_value_label':'Значение статуса',
    'status_published':'1 — опубликовано (доступно)',
    'status_hidden':'0 — скрыто (недоступно)',
    'send_limit_title':'📦 Лимит отправки',
    'send_limit_label':'Лимит отправки за 1 раз',
    'send_limit_desc':'Сколько страниц можно отправить за один запуск. Максимум 5000',
    'fix_title':'🔄 Исправление по эталону',
    'fix_desc':'Какие поля автоматически исправлять по эталону при получении страниц',
    'back_home':'Назад',
    'btn_more':'+ ЕЩЕ',
    'btn_more_multi':'📋 ЕЩЕ НЕСКОЛЬКО',
    'per_page_import':'Страниц за запрос',
    'date_from':'Дата с',
    'date_to':'Дата по',
    'lang_label':'Язык',
    'fix_multilangid':'multilangid',
    'fix_status':'status',
    'fix_datestamp':'datestamp',
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
    'prompt_values':'Введите значения (каждая строка — отдельное поле):',
    'step_forward':'➡ ДАЛЕЕ',
    'filter_name':'Фильтр по имени (slug)',
    'batch_label':'Отправить за 1 раз',
    'home':'🏠 Главная',
    'entity_name':'Страницы',
    'site_label':'Сайт:',
    'delete_site':'🗑 Удалить настройки сайта',
  },
  en: {
    'title':'Pages Management — Boostore.pro',
    'warn_nokey':'⚠ You need to specify the API access key (Consumer Secret) in the «Configuration» section below, otherwise scripts will not work.',
    'warn_domain':'⚠ API must be accessed using your site\'s domain created on the Boostore.pro platform. Change the domain in the configuration below to yours (e.g. my-site.boostore.pro).',
    'saved':'Configuration saved',
    'plaque':'Boostore.pro — Scripts for exporting (downloading) and importing (uploading) pages via Commerce API. Domain: ',
    'plaque_import':'▸ <strong>Import Settings</strong> — fetching pages from API',
    'plaque_export':'▸ <strong>Export Settings</strong> — sending pages to Boostore.pro',
    'instr_title':'📖 Instructions',
    'instr_intro':'All settings are in the «Configuration» section below.',
    'quickstart':'Quick Start',
    'step1':'Set up the access key in «Settings → Store → Sales statistics access»',
    'step2':'Specify the key and your site URL in the configuration below',
    'step4':'Click «START IMPORT» — pages will be downloaded to the pages/ folder',
    'step5':'Pages in the same group (same slug) are checked against the reference language version (set in config). multilangid, status, datestamp are synced to reference',
    'step6':'Edit HTML files in pages/ if needed',
    'step7':'Click «START EXPORT» — changes will be uploaded to the site',
    'file_naming':'File Naming',
    'file_naming_desc':'Template: {id}-{name}-{language}.html. Example: 123-moya-statya-ru.html',
    'file_format':'File Format',
    'file_format_desc':'Meta data in &lt;meta name=&quot;...&quot; content=&quot;...&quot;&gt; carries page settings. Content after &lt;!-- CONTENT SEPARATOR BELOW --&gt;',
    'actions_title':'⚡ Actions',
    'btn_get':'📥 DOWNLOAD',
    'btn_update':'📤 UPLOAD',
    'btn_dryrun':'🔍 Test (dry run — no API calls)',
    'dryrun_desc':'Test mode — checks which pages will be sent, but no actual API requests are made.',
    'btn_save':'💾 Save Configuration',
    'config_title':'⚙ Configuration',
    'lbl_key':'🔑 Access Key (Consumer Secret)',
    'lbl_url':'🌐 Site domain (e.g. site.boostore.pro)',
    'api_note':'⚠ API must be accessed using your site\'s domain created on the Boostore.pro platform. Change the domain below to yours.',
    'footer_text':'Pages Management',
    'footer_docs':'API Documentation',
    'confirm_get':'Start fetching pages?',
    'confirm_update':'Start sending pages?',
    'confirm_dryrun':'Start dry-run?',
    'ref_lang_be':'Belarusian (be)',
    'ref_lang_en':'English (en)',
    'ref_lang_ru':'Russian (ru)',
    'ref_lang_ua':'Ukrainian (ua)',
    'ref_lang_pl':'Polish (pl)',
    'date_mode_meta':'From meta-data (date from each page)',
    'date_mode_fixed':'Single date for all pages',
    'date_mode_offset':'Date offset (+N days per page)',
    'status_mode_meta':'From meta-data (status from each page)',
    'status_mode_override':'Override for all pages',
    'export_settings_title':'📤 Export Settings',
    'export_settings_desc':'Parameters affecting fetching and saving pages from the API',
    'per_page_label':'Pages per request (per_page)',
    'per_page_desc':'How many pages to load per API request. Max 2000',
    'fix_export_title':'🔧 Fix on Export',
    'ref_lang_label':'🌐 Reference Language',
    'ref_lang_desc':'Pages in this language are considered reference. When fetching pages in other languages, they are checked against it',
    'import_settings_title':'📥 Import Settings',
    'import_settings_desc':'Parameters affecting fetching and saving pages from the API',
    'date_mode_label':'📅 Publication Date Mode',
    'date_mode_desc':'How to determine page publication date during import',
    'date_fixed_label':'📅 Fixed Date',
    'date_fixed_desc':'This date will be set for all pages',
    'date_offset_label':'📅 Base Date (start offset from)',
    'date_offset_days':'+ days per page',
    'date_offset_desc':'The first unique page (by slug) gets the base date, the second gets base date + N days, etc. Pages in different languages with the same slug count as one page and get the same date.',
    'status_mode_label':'🔒 Access Status (status)',
    'status_mode_desc':'How to determine page publication status during import',
    'status_value_label':'Status Value',
    'status_published':'1 — published (public)',
    'status_hidden':'0 — hidden (private)',
    'send_limit_title':'📦 Send Limit',
    'send_limit_label':'Send limit per run',
    'send_limit_desc':'How many pages can be sent in one run. Max 5000',
    'fix_title':'🔄 Fix by Reference',
    'fix_desc':'Which fields to auto-fix by reference when fetching pages',
    'back_home':'Home',
    'btn_more':'+ MORE',
    'btn_more_multi':'📋 ADD MULTIPLE',
    'per_page_import':'Pages per request',
    'date_from':'Date from',
    'date_to':'Date to',
    'lang_label':'Language',
    'fix_multilangid':'multilangid',
    'fix_status':'status',
    'fix_datestamp':'datestamp',
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
    'prompt_values':'Enter values (each line is a separate field):',
    'step_forward':'➡ NEXT',
    'filter_name':'Filter by name (slug)',
    'batch_label':'Send per run',
'home':'🏠 Home',
    'entity_name':'Pages',
    'site_label':'Site:',
    'delete_site':'🗑 Delete site settings',
  },
  ua: {
    'title':'Керування сторінками — Boostore.pro',
    'warn_nokey':'⚠ Необхідно вказати ключ доступу API (Consumer Secret) у розділі «Конфігурація» нижче, інакше скрипти не будуть працювати.',
    'warn_domain':'⚠ Для роботи API обов\'язково потрібно відкривати його з використанням адреси вашого сайту, створеного на платформі Boostore.pro. Змініть домен у конфігурації нижче на ваш (наприклад: miy-sayt.boostore.pro).',
    'saved':'Конфігурацію збережено',
    'plaque':'Boostore.pro — Скрипти для експорту (завантаження) та імпорту (відправлення) сторінок через Commerce API. Домен: ',
    'plaque_import':'▸ <strong>Налаштування імпорту</strong> — отримання сторінок з API',
    'plaque_export':'▸ <strong>Налаштування експорту</strong> — відправлення сторінок на Boostore.pro',
    'instr_title':'📖 Інструкція',
    'instr_intro':'Всі налаштування — у розділі «Конфігурація» нижче.',
    'quickstart':'Швидкий старт',
    'step1':'Налаштуйте ключ доступу в розділі «Налаштування → Магазин → Доступ до статистики продажів»',
    'step2':'Вкажіть ключ та URL вашого сайту в конфігурації нижче',
    'step4':'Натисніть «ОТРИМАТИ СТОРІНКИ» — сторінки завантажаться в папку pages/',
    'step5':'Сторінки однієї групи (однаковий slug) звіряються з еталонною версією (вибрана мова в конфігурації). multilangid, status, datestamp приводяться до еталона',
    'step6':'Відредагуйте HTML-файли в pages/ за потреби',
    'step7':'Натисніть «ВІДПРАВИТИ СТОРІНКИ» — зміни відправляться на сайт',
    'file_naming':'Іменування файлів',
    'file_naming_desc':'Шаблон: {id}-{name}-{language}.html. Приклад: 123-moya-statya-ru.html',
    'file_format':'Формат файлу',
    'file_format_desc':'Мета-дані в &lt;meta name=&quot;...&quot; content=&quot;...&quot;&gt; передають налаштування сторінки. Вміст після &lt;!-- CONTENT SEPARATOR BELOW --&gt;',
    'actions_title':'⚡ Дії',
    'btn_get':'📥 ОТРИМАТИ СТОРІНКИ',
    'btn_update':'📤 ВІДПРАВИТИ СТОРІНКИ',
    'btn_dryrun':'🔍 Тест (сухе відправлення)',
    'dryrun_desc':'Режим «тест» — перевіряє які сторінки будуть відправлені, але самі запити до API не виконуються.',
    'btn_save':'💾 Зберегти конфігурацію',
    'config_title':'⚙ Конфігурація',
    'lbl_key':'🔑 Ключ доступу (Consumer Secret)',
    'lbl_url':'🌐 Домен сайту (наприклад: site.boostore.pro)',
    'api_note':'⚠ Для роботи API обов\'язково потрібно відкривати його з використанням адреси вашого сайту, створеного на платформі Boostore.pro. Змініть домен нижче на ваш.',
    'footer_text':'Керування сторінками',
    'footer_docs':'Документація API',
    'confirm_get':'Запустити отримання сторінок?',
    'confirm_update':'Запустити відправлення сторінок?',
    'confirm_dryrun':'Запустити пробне відправлення?',
    'ref_lang_be':'Білоруська (be)',
    'ref_lang_en':'Англійська (en)',
    'ref_lang_ru':'Російська (ru)',
    'ref_lang_ua':'Українська (ua)',
    'ref_lang_pl':'Польська (pl)',
    'date_mode_meta':'З мета-даних (дата з кожної сторінки)',
    'date_mode_fixed':'Одна дата для всіх сторінок',
    'date_mode_offset':'Зміщення дат (+N днів на сторінку)',
    'status_mode_meta':'З мета-даних (статус з кожної сторінки)',
    'status_mode_override':'Перевизначити для всіх сторінок',
    'export_settings_title':'📤 Налаштування експорту',
    'export_settings_desc':'Параметри, що впливають на отримання та збереження сторінок з API',
    'per_page_label':'Сторінок за запит (per_page)',
    'per_page_desc':'Скільки сторінок завантажувати за 1 запит до API. Максимум 2000',
    'fix_export_title':'🔧 Виправлення при експорті',
    'ref_lang_label':'🌐 Мова еталонної сторінки',
    'ref_lang_desc':'Сторінки цієї мови вважаються еталоном. При отриманні сторінок іншими мовами звіряються з ним',
    'import_settings_title':'📥 Налаштування імпорту',
    'import_settings_desc':'Параметри, що впливають на отримання та збереження сторінок з API',
    'date_mode_label':'📅 Режим дати публікації',
    'date_mode_desc':'Як визначати дату публікації сторінок при імпорті',
    'date_fixed_label':'📅 Фіксована дата',
    'date_fixed_desc':'Ця дата буде встановлена для всіх сторінок',
    'date_offset_label':'📅 Базова дата (від якої починати відлік)',
    'date_offset_days':'+ днів на сторінку',
    'date_offset_desc':'Перша унікальна сторінка (по slug) отримує базову дату, друга — базову дату + N днів, і т.д. Сторінки різними мовами з однаковим slug вважаються однією сторінкою та отримують однакову дату.',
    'status_mode_label':'🔒 Статус доступу (status)',
    'status_mode_desc':'Режим визначення статусу публікації сторінок при імпорті',
    'status_value_label':'Значення статусу',
    'status_published':'1 — опубліковано (доступно)',
    'status_hidden':'0 — приховано (недоступно)',
    'send_limit_title':'📦 Ліміт відправлення',
    'send_limit_label':'Ліміт відправлення за 1 раз',
    'send_limit_desc':'Скільки сторінок можна відправити за один запуск. Максимум 5000',
    'fix_title':'🔄 Виправлення за еталоном',
    'fix_desc':'Які поля автоматично виправляти за еталоном при отриманні сторінок',
    'back_home':'На головну',
    'btn_more':'+ ЩЕ',
    'btn_more_multi':'📋 ДОДАТИ КІЛЬКА',
    'per_page_import':'Сторінок за запит',
    'date_from':'Дата з',
    'date_to':'Дата по',
    'lang_label':'Мова',
    'fix_multilangid':'multilangid',
    'fix_status':'status',
    'fix_datestamp':'datestamp',
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
    'prompt_values':'Введіть значення (кожен рядок — окреме поле):',
    'step_forward':'➡ ДАЛІ',
    'filter_name':'Фільтр за іменем (slug)',
    'batch_label':'Відправити за 1 раз',
    'home':'🏠 Головна',
    'entity_name':'Сторінки',
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


</script>
</body>
</html>