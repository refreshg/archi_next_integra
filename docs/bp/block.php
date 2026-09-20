// ═══════ Archi -> Next : სტატუსი BP ცვლადიდან + პროდუქტი გარიგებიდან ═══════
// ჩასვი "PHP Code" ბლოკში. <?php ტეგი არ დაამატო.
// Archi-ს მხარეს მხოლოდ კითხულობს — არც გარიგებას და არც პროდუქტს არ ცვლის.

// ─── კონფიგი ───
$webhook  = 'https://bitrix.nextgroup.ge/rest/1/xxxxxxxxxxxxxxxx/';
$VAR_IN   = 'status';     // საიდან იკითხება სტატუსი
$VAR_OUT  = 'Send_log';   // დეტალური ანგარიში
$VAR_STAT = 'Logstat';    // მოკლე კოდი (UPDATED / REJECTED_SAME / ...)

// მისაღები მნიშვნელობები -> რა ჩაიწერება Next-ში
$MAP = array(
    'free'     => 'თავისუფალი',
    'reserved' => 'ფასიანი ჯავშანი',
    'sold'     => 'გაყიდული',
);
// ეტაპის სახელი ლოგისთვის
$STAGE = array(
    'free'     => 'გაუქმება',
    'reserved' => 'რეზერვი',
    'sold'     => 'გაყიდვა',
);
// დაცული სტატუსები: იგივე რომ დახვდეს, თავზე არ გადაეწერება
$PROTECTED = array('ფასიანი ჯავშანი', 'გაყიდული');

$F_ID = 'PROPERTY_546'; $F_STAT = 'PROPERTY_64'; $F_LOG = 'PROPERTY_547';

$NL     = chr(10);
$detail = array();
$t0     = microtime(true);

$urlParts    = explode('/rest/', $webhook);
$safeWebhook = $urlParts[0] . '/rest/***';

$finish = function ($code, $short, $lines) use ($VAR_OUT, $VAR_STAT, $NL, $t0) {
    $head    = array('CODE: ' . $code, 'შედეგი: ' . $short, '');
    $lines[] = '';
    $lines[] = 'ხანგრძლივობა: ' . round((microtime(true) - $t0) * 1000) . ' ms';
    $root    = $this->GetRootActivity();
    if ($root) {
        $root->SetVariable($VAR_STAT, $code);
        $root->SetVariable($VAR_OUT, implode($NL, array_merge($head, $lines)));
    }
};

$root = $this->GetRootActivity();

// ─── 0. სტატუსი ცვლადიდან ───
$rawStatus = '';
if ($root && method_exists($root, 'GetVariable')) {
    $rawStatus = trim((string) $root->GetVariable($VAR_IN));
}

$newStatus = '';
$stage     = '';
$key       = strtolower($rawStatus);

if (isset($MAP[$key])) {
    $newStatus = $MAP[$key];
    $stage     = $STAGE[$key];
} else {
    foreach ($MAP as $k => $v) {
        if ($v === $rawStatus) { $newStatus = $v; $stage = $STAGE[$k]; break; }
    }
}

$detail[] = 'დრო       : ' . date('d.m.Y H:i:s');
$detail[] = 'პორტალი   : ' . $safeWebhook;
$detail[] = 'ცვლადი    : ' . $VAR_IN . ' = "' . $rawStatus . '"';

$this->WriteToTrackingService('▶ დაიწყო. ' . $VAR_IN . ' = "' . $rawStatus . '"');

if ($newStatus === '') {
    $detail[] = 'მისაღებია : ' . implode(', ', array_keys($MAP)) . ' | ' . implode(', ', array_values($MAP));
    $this->WriteToTrackingService('❌ სტატუსი არასწორია: "' . $rawStatus . '"');
    $finish('BAD_STATUS', 'ცვლადში არასწორი სტატუსია: "' . $rawStatus . '"', $detail);
    return;
}

$detail[] = 'სტატუსი   : ' . $newStatus;
$detail[] = 'ეტაპი     : ' . $stage;

// ─── 1. გარიგების ID ───
$dealId = 0;
$docId  = null;
if ($root && method_exists($root, 'GetDocumentId')) { $docId = $root->GetDocumentId(); }
if (empty($docId) && method_exists($this, 'GetDocumentId')) { $docId = $this->GetDocumentId(); }
if (is_array($docId) && isset($docId[2])) { $dealId = intval(str_replace('DEAL_', '', $docId[2])); }

$detail[] = 'გარიგება  : ' . ($dealId > 0 ? '#' . $dealId : 'ვერ დადგინდა');

if ($dealId <= 0) {
    $detail[] = 'docId     : ' . print_r($docId, true);
    $this->WriteToTrackingService('❌ გარიგების ID ვერ დადგინდა');
    $finish('NO_DEAL', 'გარიგების ID ვერ დადგინდა', $detail);
    return;
}

// ─── 2. გარიგების პროდუქტები (მხოლოდ კითხვა) ───
CModule::IncludeModule('crm');
$rows = CCrmProductRow::LoadRows('D', $dealId);

$detail[] = '';
$detail[] = '--- გარიგების პროდუქტები ---';
$detail[] = 'რაოდენობა : ' . (is_array($rows) ? count($rows) : 0);

if (empty($rows)) {
    $this->WriteToTrackingService('❌ გარიგებაზე პროდუქტი არ არის მიმაგრებული');
    $finish('NO_PRODUCT', 'გარიგებაზე პროდუქტი არ არის', $detail);
    return;
}

foreach ($rows as $r) {
    $detail[] = '  - #' . $r['PRODUCT_ID'] . '  ' . (isset($r['PRODUCT_NAME']) ? $r['PRODUCT_NAME'] : '');
}

// ერთზე მეტი პროდუქტი — Next-ში არაფერი იგზავნება
if (count($rows) > 1) {
    $names = array();
    foreach ($rows as $r) { $names[] = '#' . $r['PRODUCT_ID'] . ' ' . (isset($r['PRODUCT_NAME']) ? $r['PRODUCT_NAME'] : ''); }
    $detail[] = 'გადაწყვეტა: არაფერი გაიგზავნა — ერთზე მეტი პროდუქტია';
    $this->WriteToTrackingService('⚠ გარიგებაზე ' . count($rows) . ' პროდუქტია (' . implode(', ', $names) . ') — Next-ში არაფერი გაიგზავნა. დატოვე მხოლოდ ერთი ბინა.');
    $finish('REJECTED_MULTI', 'გარიგებაზე ' . count($rows) . ' პროდუქტია — სტატუსი არ გაგზავნილა', $detail);
    return;
}

$first     = reset($rows);
$archiId   = intval($first['PRODUCT_ID']);
$archiName = isset($first['PRODUCT_NAME']) ? $first['PRODUCT_NAME'] : '';
$detail[] = 'აღებული   : #' . $archiId . ' ' . $archiName;

if ($archiId <= 0) {
    $this->WriteToTrackingService('❌ PRODUCT_ID ცარიელია');
    $finish('NO_PRODUCT', 'PRODUCT_ID ცარიელია', $detail);
    return;
}

$this->WriteToTrackingService('● პროდუქტი Archi #' . $archiId . ' (' . $archiName . ')');

// ─── REST დამხმარე ───
$rest = function ($method, $params) use ($webhook) {
    $ch = curl_init($webhook . $method . '.json');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('raw' => $raw, 'err' => $err, 'http' => $code, 'data' => json_decode($raw, true));
};

// ─── 3. ძებნა Next-ში ───
$find = $rest('crm.product.list', array(
    'filter' => array($F_ID => $archiId),
    'select' => array('ID', 'NAME', $F_STAT, $F_LOG),
));

$detail[] = '';
$detail[] = '--- ძებნა Next-ში ---';
$detail[] = 'HTTP      : ' . $find['http'];

if ($find['raw'] === false) {
    $detail[] = 'curl      : ' . $find['err'];
    $this->WriteToTrackingService('❌ curl: ' . $find['err']);
    $finish('ERROR_NETWORK', 'ქსელის შეცდომა', $detail);
    return;
}
if (isset($find['data']['error'])) {
    $e = $find['data']['error'];
    $detail[] = 'REST      : ' . $e . ' — ' . $find['data']['error_description'];
    $this->WriteToTrackingService('❌ ' . $e);
    $isAuth = ($e === 'INVALID_CREDENTIALS' || $e === 'NO_AUTH_FOUND' || $e === 'expired_token');
    $finish($isAuth ? 'ERROR_AUTH' : 'ERROR_REST', $isAuth ? 'webhook-ის ტოკენი არასწორია' : $e, $detail);
    return;
}
if (!isset($find['data']['result'][0])) {
    $detail[] = 'პასუხი    : ' . substr($find['raw'], 0, 300);
    $this->WriteToTrackingService('❌ ბინა ვერ მოიძებნა Next-ში: ' . $archiId);
    $finish('NOT_FOUND', 'ბინა ვერ მოიძებნა Next-ში', $detail);
    return;
}

$p         = $find['data']['result'][0];
$nextId    = $p['ID'];
$oldStatus = isset($p[$F_STAT]['value']) ? $p[$F_STAT]['value'] : '—';
$oldRaw    = isset($p[$F_LOG]['value']) ? $p[$F_LOG]['value'] : '';
$oldLog    = is_array($oldRaw) ? (isset($oldRaw['TEXT']) ? $oldRaw['TEXT'] : '') : $oldRaw;

// ─── ვისი სტატუსია ახლა: Archi-ს თუ Next-ის ───
// ყოველ რეალურ ცვლილებას ხელს ვაწერთ " | Archi BP"-ით. ვეძებთ ბოლო ასეთ ხაზს
// და ვნახულობთ, რა სტატუსი დატოვა. თუ ის ემთხვევა ახლანდელს — სტატუსი ჩვენია.
$lastArchiSet = null;
if ($oldLog !== '') {
    foreach (explode('<br>', $oldLog) as $line) {   // ახლიდან ძველისკენ
        if (strpos($line, 'Archi BP') === false) { continue; }
        $pos = strpos($line, ' → ');
        if ($pos === false) { continue; }            // უცვლელი ან უარყოფილი ხაზი
        $tail = substr($line, $pos + strlen(' → '));
        $bar  = strpos($tail, ' | ');
        $lastArchiSet = trim($bar === false ? $tail : substr($tail, 0, $bar));
        break;
    }
}
$ownedByArchi = ($lastArchiSet !== null && $lastArchiSet === $oldStatus);
$owner        = $ownedByArchi ? 'Archi' : 'Next';

$detail[] = 'next_id   : ' . $nextId;
$detail[] = 'ბინა      : ' . $p['NAME'];
$detail[] = 'ახლანდელი : ' . $oldStatus;
$detail[] = 'ვისია     : ' . $owner . ($lastArchiSet !== null ? ' (ბოლო Archi-ს ჩანაწერი: ' . $lastArchiSet . ')' : ' (Archi-ს ჩანაწერი არ არის)');

// ─── 4. დაცვა და ჩანაწერი ───
$isSame   = ($oldStatus === $newStatus);
$isLocked = in_array($oldStatus, $PROTECTED);
// უცხო ჯავშანი/გაყიდვა: ახლანდელი სტატუსი დაცულია და Next-ში დაიდო — ვერ გადავაწერთ
$foreign  = ($isLocked && !$ownedByArchi && !$isSame);
// იგივე სტატუსი ხელახლა არ იწერება — არც დაცულის, არც ჩვეულებრივის შემთხვევაში
$skipWrite = ($isSame || $foreign);

$parts = array(date('d.m.Y H:i'));
if ($foreign)             { $parts[] = $newStatus . ' უარყოფილია — Next-ის ' . $oldStatus; }
elseif ($isSame && $isLocked) { $parts[] = 'უკვე ' . $newStatus . ' — არ გადაიწერა'; }
elseif ($isSame)          { $parts[] = $newStatus . ' (უცვლელი)'; }
else                      { $parts[] = $oldStatus . ' → ' . $newStatus; }
$parts[] = 'გარიგება #' . $dealId;
$parts[] = $stage;
$parts[] = 'Archi BP';
$entry = implode(' | ', $parts);

$newLog = $entry . ($oldLog !== '' ? '<br>' . $oldLog : '');
if (strlen($newLog) > 7000) {
    $newLog = substr($newLog, 0, 7000);
    $cut    = strrpos($newLog, '<br>');
    if ($cut !== false) { $newLog = substr($newLog, 0, $cut); }
    $newLog .= '<br>... [ძველი ჩანაწერები მოიჭრა]';
}

// ─── 5. ჩაწერა Next-ში ───
$fields = array($F_LOG => array('TEXT' => $newLog, 'TYPE' => 'HTML'));
if (!$skipWrite) { $fields[$F_STAT] = $newStatus; }

$upd = $rest('crm.product.update', array('id' => $nextId, 'fields' => $fields));

$detail[] = '';
$detail[] = '--- განახლება ---';
$detail[] = 'HTTP      : ' . $upd['http'];
$detail[] = 'სტატუსი   : ' . ($foreign ? 'არ გადაიწერა (Next-ის ' . $oldStatus . ')' : ($skipWrite ? ($isLocked ? 'არ გადაიწერა (დაცულია)' : 'უცვლელი — ჩაწერა არ დასჭირდა') : 'შეიცვალა'));
$detail[] = 'ჩანაწერი  : ' . $entry;

if ($upd['raw'] === false) {
    $detail[] = 'curl      : ' . $upd['err'];
    $this->WriteToTrackingService('❌ curl განახლებისას: ' . $upd['err']);
    $finish('ERROR_NETWORK', 'ქსელის შეცდომა განახლებისას', $detail);
} elseif (isset($upd['data']['error'])) {
    $detail[] = 'REST      : ' . $upd['data']['error'] . ' — ' . $upd['data']['error_description'];
    $this->WriteToTrackingService('❌ REST: ' . $upd['data']['error']);
    $finish('ERROR_REST', $upd['data']['error'], $detail);
} elseif ($foreign) {
    $this->WriteToTrackingService('⛔ Next-ის ' . $oldStatus . ' — ' . $newStatus . ' არ ჩაიწერა');
    $finish('REJECTED_FOREIGN', 'Next-ის ' . $oldStatus . ' — Archi ვერ შეცვლის', $detail);
} elseif ($isSame && $isLocked) {
    $this->WriteToTrackingService('⚠ უკვე ' . $newStatus . ' — არ გადაწერილა');
    $finish('REJECTED_SAME', 'უკვე ' . $newStatus . ' — არ გადაიწერა', $detail);
} elseif ($isSame) {
    $this->WriteToTrackingService('◦ უცვლელი: ' . $newStatus);
    $finish('UNCHANGED', 'უცვლელი: ' . $newStatus, $detail);
} else {
    $this->WriteToTrackingService('✅ ' . $entry);
    $finish('UPDATED', $oldStatus . ' → ' . $newStatus, $detail);
}
