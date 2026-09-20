// ═════════ Next-ის მხარე : სტატუსი + ლოგი ორივე პორტალზე ═════════
// ცალკე მდგომი ბიზნეს პროცესი Next-ზე, გარიგებაზე (BP 39 Archi_integra).
// ჩასვი "PHP Code" ბლოკში. <?php ტეგი არ დაამატო.
//
// ლოგს წერს ორივეგან, იმავე ფორმატით, როგორც Archi-ს ბლოკი:
//   Next-ში  -> PROPERTY_547  (Interga_History)
//   Archi-ში -> PROPERTY_1702 (archi_next_history)
//
// უსაფრთხოება: პროდუქტი არასდროს იძებნება ფილტრით — მხოლოდ ზუსტი ID-ით.

// ─── კონფიგი ───
$webhookNext  = 'https://bitrix.nextgroup.ge/rest/1/NEXT_TOKEN/';
$webhookArchi = 'https://crm.archi.ge/rest/1/ARCHI_TOKEN/';

$VAR_IN   = 'status';
$VAR_OUT  = 'Send_log';
$VAR_STAT = 'Logstat';

// true  = Archi-ს ჯავშანს/გაყიდვას Next ვერ გადააწერს (სრული სიმეტრია)
// false = Next ყოველთვის უფროსია საკუთარ კატალოგში
$RESPECT_ARCHI = true;

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

$F_ID   = 'PROPERTY_546';    // Next: UF_ARCHI_ID — აქედან ვიგებთ Archi-ს ID-ს
$F_STAT = 'PROPERTY_64';     // Next: სტატუსი
$F_LOG  = 'PROPERTY_547';    // Next: Interga_History
$F_ALOG  = 'PROPERTY_1702';   // Archi: archi_next_history
$F_ASTAT = 'PROPERTY_429';    // Archi: სტატუსი (სია, enum ID-ებით)
$F_AAPT  = 'PROPERTY_377';    // Archi: ბინის ნომერი — ჯვარედინი შემოწმებისთვის
$F_NAPT  = 'PROPERTY_63';     // Next:  უძრავი ქონების # — იგივესთვის

// Next-ის სტატუსი -> Archi-ს enum ID.
// ⚠ Archi-ს სიაში ცალკე „უფასო ჯავშანი" არ არის — ორივე ჯავშანი 819-ში ჯდება.
$ARCHI_STATUS = array(
    'თავისუფალი'      => 811,
    'უფასო ჯავშანი'   => 819,   // დაჯავშნილი
    'ფასიანი ჯავშანი' => 819,   // დაჯავშნილი
    'გაყიდული'        => 810,
);

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

// curl-ს თუ ვერ ვიყენებთ (Next-ის box-ზე BP-ის სავარძელი კეტავს),
// ბიტრიქსის HttpClient-ზე გადავდივართ.
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

// თვისების მნიშვნელობა — crm.product.get {valueId, value} სახით აბრუნებს,
// HTML-ტიპისას კი value თვითონ არის {TEXT, TYPE}
$propText = function ($prop) {
    if (!isset($prop)) { return ''; }
    $v = is_array($prop) && array_key_exists('value', $prop) ? $prop['value'] : $prop;
    if (is_array($v)) { return isset($v['TEXT']) ? $v['TEXT'] : ''; }
    return (string) $v;
};

$root = $this->GetRootActivity();

// ─── 0. სტატუსი ცვლადიდან ───
// რაც ცვლადში წერია, ის მიდის. ლათინური კოდები მოხერხებულობისთვისაა.
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

// ─── 2. ვინ ასრულებს ───
$actorId  = 0;
$actorHow = '';
if (isset($GLOBALS['USER']) && is_object($GLOBALS['USER']) && $GLOBALS['USER']->IsAuthorized()) {
    $actorId  = intval($GLOBALS['USER']->GetID());
    $actorHow = 'გამშვები';
}
if ($actorId <= 0) {
    $dl = $rest($webhookNext, 'crm.deal.get', array('id' => $dealId));
    if (isset($dl['data']['result']['MODIFIED_BY_ID'])) {
        $actorId  = intval($dl['data']['result']['MODIFIED_BY_ID']);
        $actorHow = 'გარიგების რედაქტორი';
    }
}

$actorName = '';
if ($actorId > 0) {
    $u   = $rest($webhookNext, 'user.get', array('ID' => $actorId));
    $row = isset($u['data']['result'][0]) ? $u['data']['result'][0] : null;
    if ($row) {
        $actorName = trim((isset($row['NAME']) ? $row['NAME'] : '') . ' ' . (isset($row['LAST_NAME']) ? $row['LAST_NAME'] : ''));
    }
}
$actor  = $actorId > 0 ? (($actorName !== '' ? $actorName . ' ' : '') . '#' . $actorId) : 'უცნობი';
$SOURCE = 'Next BP: ' . $actor;

$detail[] = 'ვინ       : ' . $actor . ($actorHow !== '' ? ' (' . $actorHow . ')' : '');

// ─── 3. გარიგების პროდუქტები (მხოლოდ კითხვა) ───
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
    $detail[] = 'გადაწყვეტა: არაფერი შეიცვალა — ერთზე მეტი პროდუქტია';
    $this->WriteToTrackingService('⚠ გარიგებაზე ' . count($rows) . ' პროდუქტია — სტატუსი არ შეცვლილა');
    $finish('REJECTED_MULTI', 'გარიგებაზე ' . count($rows) . ' პროდუქტია', $detail);
    return;
}

$first  = reset($rows);
$nextId = intval($first['PRODUCT_ID']);
$detail[] = 'აღებული   : #' . $nextId . ' ' . (isset($first['PRODUCT_NAME']) ? $first['PRODUCT_NAME'] : '');

// ═══ დაცვა 1: ID აუცილებლად დადებითი რიცხვი ═══
if ($nextId <= 0) {
    $this->WriteToTrackingService('⛔ PRODUCT_ID არასწორია — შეჩერდა');
    $finish('REJECTED_UNSAFE', 'PRODUCT_ID არასწორია (' . var_export($first['PRODUCT_ID'], true) . ')', $detail);
    return;
}

// ─── 4. პროდუქტის წაკითხვა — ზუსტი ID-ით, ფილტრის გარეშე ───
// crm.product.get არასწორ ID-ზე შეცდომას აბრუნებს და არასდროს — სიას.
$get = $rest($webhookNext, 'crm.product.get', array('id' => $nextId));

$detail[] = '';
$detail[] = '--- პროდუქტის წაკითხვა ---';
$detail[] = 'HTTP      : ' . $get['http'] . '  (' . $get['via'] . ')';

if ($get['raw'] === false) {
    $detail[] = 'შეცდომა   : ' . $get['err'];
    $this->WriteToTrackingService('❌ ' . $get['err']);
    $finish('ERROR_NETWORK', 'ქსელის შეცდომა', $detail);
    return;
}
if (isset($get['data']['error'])) {
    $e = $get['data']['error'];
    $detail[] = 'REST      : ' . $e . ' — ' . $get['data']['error_description'];
    $this->WriteToTrackingService('❌ ' . $e);
    $isAuth = ($e === 'INVALID_CREDENTIALS' || $e === 'NO_AUTH_FOUND' || $e === 'expired_token');
    $finish($isAuth ? 'ERROR_AUTH' : 'ERROR_REST', $isAuth ? 'webhook-ის ტოკენი არასწორია' : $e, $detail);
    return;
}
if (!isset($get['data']['result']['ID'])) {
    $this->WriteToTrackingService('❌ პროდუქტი #' . $nextId . ' ვერ წაიკითხა');
    $finish('NOT_FOUND', 'პროდუქტი #' . $nextId . ' ვერ წაიკითხა', $detail);
    return;
}

$p = $get['data']['result'];

// ═══ დაცვა 2: დაბრუნდა ზუსტად ის პროდუქტი, რომელიც მოვითხოვეთ ═══
if (intval($p['ID']) !== $nextId) {
    $detail[] = 'გადაწყვეტა: შეჩერდა — დაბრუნდა სხვა პროდუქტი (#' . $p['ID'] . ')';
    $this->WriteToTrackingService('⛔ მოთხოვნილი #' . $nextId . ', დაბრუნდა #' . $p['ID'] . ' — არაფერი შეცვლილა');
    $finish('REJECTED_UNSAFE', 'დაბრუნდა სხვა პროდუქტი: #' . $p['ID'], $detail);
    return;
}

$oldStatus = $propText(isset($p[$F_STAT]) ? $p[$F_STAT] : null);
if ($oldStatus === '') { $oldStatus = '—'; }
$oldLog  = $propText(isset($p[$F_LOG]) ? $p[$F_LOG] : null);
$archiId = intval($propText(isset($p[$F_ID]) ? $p[$F_ID] : null));

$detail[] = 'ბინა      : ' . $p['NAME'];
$detail[] = 'ახლანდელი : ' . $oldStatus;
$nextApt = $propText(isset($p[$F_NAPT]) ? $p[$F_NAPT] : null);
if ($nextApt === '') { $nextApt = (string) $p['NAME']; }

$detail[] = 'archi_id  : ' . ($archiId > 0 ? $archiId : 'არ არის შევსებული');

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
$setByArchi = ($lastSet !== null && $lastSet === $oldStatus && strpos($lastSource, 'Archi') !== false);

$detail[] = 'ვისია     : ' . ($lastSource === null ? 'ჩანაწერი არ არის' : $lastSource);

// ─── 5. დაცვა და ჩანაწერი ───
$isSame    = ($oldStatus === $newStatus);
$isLocked  = in_array($oldStatus, $PROTECTED);
$foreign   = ($RESPECT_ARCHI && $isLocked && $setByArchi && !$isSame);
$skipWrite = ($isSame || $foreign);

$parts = array(date('d.m.Y H:i'));
if ($foreign)                 { $parts[] = $newStatus . ' უარყოფილია — Archi-ს ' . $oldStatus; }
elseif ($isSame && $isLocked) { $parts[] = 'უკვე ' . $newStatus . ' — არ გადაიწერა'; }
elseif ($isSame)              { $parts[] = $newStatus . ' (უცვლელი)'; }
else                          { $parts[] = $oldStatus . ' → ' . $newStatus; }
$parts[] = 'ბინა ' . $p['NAME'];
$parts[] = 'გარიგება #' . $dealId;
if ($stage !== '') { $parts[] = $stage; }
$parts[] = $SOURCE;
$entry = implode(' | ', $parts);

$trim = function ($text) {
    if (strlen($text) <= 7000) { return $text; }
    $text = substr($text, 0, 7000);
    $cut  = strrpos($text, '<br>');
    if ($cut !== false) { $text = substr($text, 0, $cut); }
    return $text . '<br>... [ძველი ჩანაწერები მოიჭრა]';
};

$newLog = $trim($entry . ($oldLog !== '' ? '<br>' . $oldLog : ''));

// ─── 6. ჩაწერა Next-ში — მხოლოდ ერთი ID-ით ───
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

// ─── 7. Archi-ს პროდუქტი: სტატუსი + ისტორია ───
// ძებნა არ ხდება — ID პროდუქტის PROPERTY_546-იდან მოვიდა.
$detail[] = '';
$detail[] = '--- Archi: პროდუქტი ---';
$archiNote = '';

if ($archiId <= 0) {
    $archiNote = $F_ID . ' ცარიელია — Archi-ს ბინა უცნობია';
    $detail[] = 'გამოტოვდა : ' . $archiNote;
} else {
    $aGet = $rest($webhookArchi, 'crm.product.get', array('id' => $archiId));

    if ($aGet['raw'] === false) {
        $archiNote = 'ქსელი: ' . $aGet['err'];
        $detail[] = 'შეცდომა   : ' . $archiNote;
    } elseif (isset($aGet['data']['error'])) {
        $archiNote = 'REST: ' . $aGet['data']['error'];
        $detail[] = 'შეცდომა   : ' . $archiNote;
    } elseif (!isset($aGet['data']['result']['ID'])) {
        $archiNote = 'პროდუქტი #' . $archiId . ' ვერ წაიკითხა';
        $detail[] = 'შეცდომა   : ' . $archiNote;
    } else {
        $a = $aGet['data']['result'];

        // ═══ დაცვა 3: დაბრუნდა ზუსტად ის პროდუქტი ═══
        $aOkId = (intval($a['ID']) === $archiId);

        // ═══ დაცვა 4: ბინის ნომერი ორივე მხარეს ემთხვევა ═══
        // ID-ები რომ არეულიყო, ნომრები არ დაემთხვეოდა — ეს ბოლო ბარიერია.
        $archiApt = $propText(isset($a[$F_AAPT]) ? $a[$F_AAPT] : null);
        $aOkApt   = ($archiApt === '' || $nextApt === '' || $archiApt === $nextApt);

        $detail[] = 'პროდუქტი  : #' . $a['ID'] . '  ბინა ' . $archiApt . '  (Next-ში: ' . $nextApt . ')';

        if (!$aOkId) {
            $archiNote = 'დაბრუნდა სხვა პროდუქტი #' . $a['ID'] . ' — არაფერი შეიცვალა';
            $detail[] = '⛔ ' . $archiNote;
            $this->WriteToTrackingService('⛔ Archi: ' . $archiNote);
        } elseif (!$aOkApt) {
            $archiNote = 'ბინის ნომერი არ ემთხვევა (' . $archiApt . ' vs ' . $nextApt . ') — არაფერი შეიცვალა';
            $detail[] = '⛔ ' . $archiNote;
            $this->WriteToTrackingService('⛔ Archi: ' . $archiNote);
        } else {
            // ისტორია — ყოველთვის
            $aOld = $propText(isset($a[$F_ALOG]) ? $a[$F_ALOG] : null);
            $aFields = array($F_ALOG => array('TEXT' => $trim($entry . ($aOld !== '' ? '<br>' . $aOld : '')), 'TYPE' => 'HTML'));

            // სტატუსი — მხოლოდ მაშინ, როცა Next-შიც შეიცვალა
            $aStatNote = 'არ შეცვლილა';
            if (!$skipWrite) {
                if (isset($ARCHI_STATUS[$newStatus])) {
                    $aFields[$F_ASTAT] = $ARCHI_STATUS[$newStatus];
                    $aStatNote = $newStatus . ' -> enum ' . $ARCHI_STATUS[$newStatus];
                } else {
                    $aStatNote = 'რუკაში არ არის: ' . $newStatus;
                }
            }
            $detail[] = 'სტატუსი   : ' . $aStatNote;

            $aUpd = $rest($webhookArchi, 'crm.product.update', array('id' => $archiId, 'fields' => $aFields));
            $detail[] = 'HTTP      : ' . $aUpd['http'];

            if (isset($aUpd['data']['error'])) {
                $archiNote = 'REST: ' . $aUpd['data']['error'] . ' — ' . $aUpd['data']['error_description'];
                $detail[] = 'შეცდომა   : ' . $archiNote;
                $this->WriteToTrackingService('⚠ Archi ვერ განახლდა: ' . $aUpd['data']['error']);
            } elseif ($aUpd['raw'] === false) {
                $archiNote = 'ქსელი: ' . $aUpd['err'];
                $detail[] = 'შეცდომა   : ' . $archiNote;
                $this->WriteToTrackingService('⚠ Archi ვერ განახლდა: ' . $aUpd['err']);
            } else {
                $detail[] = 'შედეგი    : ✓ ჩაიწერა';
                $this->WriteToTrackingService('● Archi #' . $archiId . ' განახლდა (' . $aStatNote . ')');
            }
        }
    }
}

if ($archiNote !== '') { $detail[] = 'ℹ Archi-ს მხარე: ' . $archiNote; }
// ─── 8. საბოლოო კოდი ───
if ($foreign) {
    $this->WriteToTrackingService('⛔ Archi-ს ' . $oldStatus . ' — ' . $newStatus . ' არ ჩაიწერა');
    $finish('REJECTED_FOREIGN', 'Archi-ს ' . $oldStatus . ' — Next ვერ შეცვლის', $detail);
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
