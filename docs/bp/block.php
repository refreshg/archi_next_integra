// ═══════ Archi -> Next : სტატუსი + ლოგი ორივე პორტალზე ═══════
// ჩასვი Archi-ს ბიზნეს პროცესის "PHP Code" ბლოკში. <?php ტეგი არ დაამატო.
//
// ლოგს წერს ორივეგან:
//   Next-ში  -> PROPERTY_547  (Interga_History)
//   Archi-ში -> PROPERTY_1702 (archi_next_history)

// ─── კონფიგი ───
$webhookNext  = 'https://bitrix.nextgroup.ge/rest/1/NEXT_TOKEN/';
$webhookArchi = 'https://crm.archi.ge/rest/1/ARCHI_TOKEN/';

$VAR_IN   = 'status';
$VAR_OUT  = 'Send_log';
$VAR_STAT = 'Logstat';

$MAP = array(
    'free'     => 'თავისუფალი',
    'hold'     => 'უფასო ჯავშანი',
    'reserved' => 'ფასიანი ჯავშანი',
    'sold'     => 'გაყიდული',
);
$STAGE = array(
    'free'     => 'გაუქმება',
    'hold'     => 'უფასო ჯავშანი',
    'reserved' => 'რეზერვი',
    'sold'     => 'გაყიდვა',
);
$PROTECTED = array('უფასო ჯავშანი', 'ფასიანი ჯავშანი', 'გაყიდული');

$F_ID    = 'PROPERTY_546';    // Next: UF_ARCHI_ID
$F_STAT  = 'PROPERTY_64';     // Next: სტატუსი
$F_LOG   = 'PROPERTY_547';    // Next: Interga_History
$F_ALOG  = 'PROPERTY_1702';   // Archi: archi_next_history

$NL     = chr(10);
$detail = array();
$t0     = microtime(true);

$safeUrl = function ($u) { $p = explode('/rest/', $u); return $p[0] . '/rest/***'; };

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

// curl-ს თუ ვერ ვიყენებთ, ბიტრიქსის HttpClient-ზე გადავდივართ
$rest = function ($base, $method, $params) {
    $url  = $base . $method . '.json';
    $body = http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return array('raw' => $raw, 'err' => $err, 'http' => $code, 'via' => 'curl', 'data' => json_decode($raw, true));
    }

    $cls = 'Bitrix' . chr(92) . 'Main' . chr(92) . 'Web' . chr(92) . 'HttpClient';
    if (class_exists($cls)) {
        $http = new $cls(array('socketTimeout' => 10, 'streamTimeout' => 20, 'waitResponse' => true));
        $http->setHeader('Content-Type', 'application/x-www-form-urlencoded; charset=utf-8');
        $raw  = $http->post($url, $body);
        $errs = $http->getError();
        return array('raw' => $raw, 'err' => is_array($errs) ? implode('; ', $errs) : (string) $errs,
                     'http' => $http->getStatus(), 'via' => 'HttpClient', 'data' => json_decode($raw, true));
    }

    return array('raw' => false, 'err' => 'curl და HttpClient ორივე მიუწვდომელია', 'http' => 0, 'via' => 'none', 'data' => null);
};

$root = $this->GetRootActivity();

// ─── 0. სტატუსი ცვლადიდან ───
$rawStatus = '';
if ($root && method_exists($root, 'GetVariable')) {
    $rawStatus = trim((string) $root->GetVariable($VAR_IN));
}

$key       = strtolower($rawStatus);
$isAlias   = isset($MAP[$key]);
$newStatus = $isAlias ? $MAP[$key] : $rawStatus;
$stage     = $isAlias ? $STAGE[$key] : '';

$detail[] = 'დრო       : ' . date('d.m.Y H:i:s');
$detail[] = 'Next      : ' . $safeUrl($webhookNext);
$detail[] = 'Archi     : ' . $safeUrl($webhookArchi);
$detail[] = 'ცვლადი    : ' . $VAR_IN . ' = "' . $rawStatus . '"';

$this->WriteToTrackingService('▶ დაიწყო. ' . $VAR_IN . ' = "' . $rawStatus . '"');

if ($newStatus === '') {
    $this->WriteToTrackingService('❌ ცვლადი "' . $VAR_IN . '" ცარიელია');
    $finish('BAD_STATUS', 'ცვლადი "' . $VAR_IN . '" ცარიელია', $detail);
    return;
}

$detail[] = 'ჩაიწერება : ' . $newStatus . ($isAlias ? ' (კოდიდან "' . $rawStatus . '")' : ' (პირდაპირ)');

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

if (count($rows) > 1) {
    $detail[] = 'გადაწყვეტა: არაფერი გაიგზავნა — ერთზე მეტი პროდუქტია';
    $this->WriteToTrackingService('⚠ გარიგებაზე ' . count($rows) . ' პროდუქტია — არაფერი გაიგზავნა');
    $finish('REJECTED_MULTI', 'გარიგებაზე ' . count($rows) . ' პროდუქტია', $detail);
    return;
}

$first     = reset($rows);
$archiId   = intval($first['PRODUCT_ID']);
$archiName = isset($first['PRODUCT_NAME']) ? $first['PRODUCT_NAME'] : '';
$detail[] = 'აღებული   : #' . $archiId . ' ' . $archiName;

// ═══ დაცვა 1: ID აუცილებლად დადებითი რიცხვი ═══
// ცარიელი ან 0 რომ გაგვეშვა, ბიტრიქსი ფილტრს უგულებელყოფდა
// და მთელ კატალოგს დააბრუნებდა.
if ($archiId <= 0) {
    $this->WriteToTrackingService('⛔ PRODUCT_ID არასწორია — შეჩერდა');
    $finish('REJECTED_UNSAFE', 'PRODUCT_ID არასწორია (' . var_export($first['PRODUCT_ID'], true) . ')', $detail);
    return;
}

$this->WriteToTrackingService('● პროდუქტი Archi #' . $archiId . ' (' . $archiName . ')');

// ─── 3. ძებნა Next-ში ───
$find = $rest($webhookNext, 'crm.product.list', array(
    'filter' => array($F_ID => $archiId),
    'select' => array('ID', 'NAME', $F_ID, $F_STAT, $F_LOG),
));

$detail[] = '';
$detail[] = '--- ძებნა Next-ში ---';
$detail[] = 'HTTP      : ' . $find['http'] . '  (' . $find['via'] . ')';

if ($find['raw'] === false) {
    $detail[] = 'შეცდომა   : ' . $find['err'];
    $this->WriteToTrackingService('❌ ' . $find['err']);
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

$found = isset($find['data']['result']) && is_array($find['data']['result']) ? $find['data']['result'] : array();
$total = isset($find['data']['total']) ? intval($find['data']['total']) : count($found);

$detail[] = 'ნაპოვნი   : ' . count($found) . ' (total ' . $total . ')';

if (count($found) === 0) {
    $this->WriteToTrackingService('❌ ბინა ვერ მოიძებნა Next-ში: ' . $archiId);
    $finish('NOT_FOUND', 'ბინა ვერ მოიძებნა Next-ში', $detail);
    return;
}

// ═══ დაცვა 2: ზუსტად ერთი ჩანაწერი ═══
// თუ ფილტრი უგულებელყოფილია, აქ ათობით ჩანაწერი მოვა — ვჩერდებით.
if (count($found) !== 1 || $total !== 1) {
    $detail[] = 'გადაწყვეტა: შეჩერდა — ფილტრმა ერთზე მეტი დააბრუნა';
    $this->WriteToTrackingService('⛔ ფილტრმა ' . $total . ' პროდუქტი დააბრუნა — არაფერი შეცვლილა');
    $finish('REJECTED_UNSAFE', 'ფილტრმა ' . $total . ' პროდუქტი დააბრუნა, 1-ის ნაცვლად', $detail);
    return;
}

$p = $found[0];

// ═══ დაცვა 3: დაბრუნებული ბინის ID ემთხვევა მოთხოვნილს ═══
$gotId = isset($p[$F_ID]['value']) ? (string) $p[$F_ID]['value'] : '';
if ($gotId !== (string) $archiId) {
    $detail[] = 'გადაწყვეტა: შეჩერდა — დაბრუნდა სხვა ბინა (' . $gotId . ')';
    $this->WriteToTrackingService('⛔ მოთხოვნილი ' . $archiId . ', დაბრუნდა ' . $gotId . ' — არაფერი შეცვლილა');
    $finish('REJECTED_UNSAFE', 'დაბრუნდა სხვა ბინა: ' . $gotId, $detail);
    return;
}

$nextId = intval($p['ID']);
if ($nextId <= 0) {
    $this->WriteToTrackingService('⛔ Next-ის ID არასწორია');
    $finish('REJECTED_UNSAFE', 'Next-ის ID არასწორია', $detail);
    return;
}

$oldStatus = isset($p[$F_STAT]['value']) ? $p[$F_STAT]['value'] : '—';
$oldRaw    = isset($p[$F_LOG]['value']) ? $p[$F_LOG]['value'] : '';
$oldLog    = is_array($oldRaw) ? (isset($oldRaw['TEXT']) ? $oldRaw['TEXT'] : '') : $oldRaw;

// ─── ვინ დააყენა ახლანდელი სტატუსი ───
$lastSet    = null;
$lastSource = null;
if ($oldLog !== '') {
    foreach (explode('<br>', $oldLog) as $line) {
        $pos = strpos($line, ' → ');
        if ($pos === false) { continue; }
        $tail = substr($line, $pos + strlen(' → '));
        $bar  = strpos($tail, ' | ');
        $lastSet = trim($bar === false ? $tail : substr($tail, 0, $bar));
        $bits = explode(' | ', $line);
        $lastSource = trim(end($bits));
        break;
    }
}
$ownedByArchi = ($lastSet !== null && $lastSet === $oldStatus && strpos($lastSource, 'Archi') !== false);

$detail[] = 'next_id   : ' . $nextId;
$detail[] = 'ბინა      : ' . $p['NAME'];
$detail[] = 'ახლანდელი : ' . $oldStatus;
$detail[] = 'ვისია     : ' . ($ownedByArchi ? 'Archi' : ($lastSource === null ? 'უცნობი' : $lastSource));

// ─── 4. დაცვა და ჩანაწერი ───
$isSame    = ($oldStatus === $newStatus);
$isLocked  = in_array($oldStatus, $PROTECTED);
$foreign   = ($isLocked && !$ownedByArchi && !$isSame);
$skipWrite = ($isSame || $foreign);

$parts = array(date('d.m.Y H:i'));
if ($foreign)                 { $parts[] = $newStatus . ' უარყოფილია — Next-ის ' . $oldStatus; }
elseif ($isSame && $isLocked) { $parts[] = 'უკვე ' . $newStatus . ' — არ გადაიწერა'; }
elseif ($isSame)              { $parts[] = $newStatus . ' (უცვლელი)'; }
else                          { $parts[] = $oldStatus . ' → ' . $newStatus; }
$parts[] = 'ბინა ' . $p['NAME'];
$parts[] = 'გარიგება #' . $dealId;
if ($stage !== '') { $parts[] = $stage; }
$parts[] = 'Archi BP';
$entry = implode(' | ', $parts);

$trim = function ($text) {
    if (strlen($text) <= 7000) { return $text; }
    $text = substr($text, 0, 7000);
    $cut  = strrpos($text, '<br>');
    if ($cut !== false) { $text = substr($text, 0, $cut); }
    return $text . '<br>... [ძველი ჩანაწერები მოიჭრა]';
};

$newLog = $trim($entry . ($oldLog !== '' ? '<br>' . $oldLog : ''));

// ─── 5. ჩაწერა Next-ში — მხოლოდ ერთი ID-ით ───
$fields = array($F_LOG => array('TEXT' => $newLog, 'TYPE' => 'HTML'));
if (!$skipWrite) { $fields[$F_STAT] = $newStatus; }

$upd = $rest($webhookNext, 'crm.product.update', array('id' => $nextId, 'fields' => $fields));

$detail[] = '';
$detail[] = '--- Next: განახლება ---';
$detail[] = 'HTTP      : ' . $upd['http'];
$detail[] = 'ჩანაწერი  : ' . $entry;

if ($upd['raw'] === false) {
    $this->WriteToTrackingService('❌ ' . $upd['err']);
    $finish('ERROR_NETWORK', 'ქსელის შეცდომა განახლებისას', $detail);
    return;
}
if (isset($upd['data']['error'])) {
    $detail[] = 'REST      : ' . $upd['data']['error'] . ' — ' . $upd['data']['error_description'];
    $this->WriteToTrackingService('❌ REST: ' . $upd['data']['error']);
    $finish('ERROR_REST', $upd['data']['error'], $detail);
    return;
}

// ─── 6. იგივე ჩანაწერი Archi-ს პროდუქტზე ───
// ძებნა არ ხდება — ID უკვე ვიცით, crm.product.update მხოლოდ მას შეეხება.
$aGet = $rest($webhookArchi, 'crm.product.get', array('id' => $archiId));
$aOld = '';
if (isset($aGet['data']['result'][$F_ALOG])) {
    $v = $aGet['data']['result'][$F_ALOG];
    if (is_array($v)) {
        $inner = isset($v['value']) ? $v['value'] : $v;
        $aOld  = is_array($inner) ? (isset($inner['TEXT']) ? $inner['TEXT'] : '') : (string) $inner;
    } else {
        $aOld = (string) $v;
    }
}

$aNew = $trim($entry . ($aOld !== '' ? '<br>' . $aOld : ''));
$aUpd = $rest($webhookArchi, 'crm.product.update', array(
    'id'     => $archiId,
    'fields' => array($F_ALOG => array('TEXT' => $aNew, 'TYPE' => 'HTML')),
));

$detail[] = '';
$detail[] = '--- Archi: ისტორია ---';
$detail[] = 'პროდუქტი  : #' . $archiId;
$detail[] = 'HTTP      : ' . $aUpd['http'];
if (isset($aUpd['data']['error'])) {
    $detail[] = 'REST      : ' . $aUpd['data']['error'] . ' — ' . $aUpd['data']['error_description'];
    $this->WriteToTrackingService('⚠ Archi-ს ისტორია ვერ ჩაიწერა: ' . $aUpd['data']['error']);
} elseif ($aUpd['raw'] === false) {
    $detail[] = 'შეცდომა   : ' . $aUpd['err'];
    $this->WriteToTrackingService('⚠ Archi-ს ისტორია ვერ ჩაიწერა: ' . $aUpd['err']);
} else {
    $detail[] = 'შედეგი    : ✓ ჩაიწერა';
}

// ─── 7. საბოლოო კოდი ───
if ($foreign) {
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
