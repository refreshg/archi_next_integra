// ══════════════════════════════════════════════════════════════
//  Archi -> Next : სტატუსის შეცვლა + ისტორიის ლოგი
//  ჩასვი ბიზნეს პროცესის "PHP Code" ბლოკში (გახსნის ტეგის გარეშე)
// ══════════════════════════════════════════════════════════════

// ─────────── კონფიგი ───────────
$webhook = 'https://bitrix.nextgroup.ge/rest/1/<შენი_ტოკენი>/';

$F_ID    = 'PROPERTY_546';   // UF_ARCHI_ID
$F_STAT  = 'PROPERTY_64';    // სტატუსი
$F_LOG   = 'PROPERTY_547';   // Interga_History
$LOG_MAX = 7000;             // ლოგის ზედა ზღვარი სიმბოლოებში

// ─────────── შესატანი მონაცემი ───────────
$archiId   = '5335768';              // შემდეგ: {=Document:UF_CRM_XXXX}
$newStatus = 'ფასიანი ჯავშანი';      // ან: თავისუფალი | გაყიდული
$dealId    = '';                     // შემდეგ: {=Document:ID}
$stage     = '';                     // ადამიანური სახელი: რეზერვი | გაყიდვა | გაუქმება
$source    = 'Archi BP';

// ─────────── REST დამხმარე ───────────
$rest = function ($method, $params) use ($webhook) {
    $ch = curl_init($webhook . $method . '.json');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return array('raw' => $raw, 'err' => $err, 'data' => json_decode($raw, true));
};

// ─────────── 1. ბინის მოძებნა ───────────
$find = $rest('crm.product.list', array(
    'filter' => array($F_ID => $archiId),
    'select' => array('ID', 'NAME', $F_STAT, $F_LOG),
));

if ($find['raw'] === false) {
    $this->WriteToTrackingService('❌ ქსელის შეცდომა ძებნისას: ' . $find['err']);
    return;
}
if (!isset($find['data']['result'][0])) {
    $this->WriteToTrackingService('❌ ბინა ვერ მოიძებნა. ' . $F_ID . ' = ' . $archiId);
    return;
}

$product   = $find['data']['result'][0];
$nextId    = $product['ID'];
$oldStatus = isset($product[$F_STAT]['value']) ? $product[$F_STAT]['value'] : '—';

// ისტორია TEXT/HTML ტიპისაა — მნიშვნელობა მასივად მოდის
$oldRaw = isset($product[$F_LOG]['value']) ? $product[$F_LOG]['value'] : '';
$oldLog = is_array($oldRaw) ? (isset($oldRaw['TEXT']) ? $oldRaw['TEXT'] : '') : $oldRaw;

// ─────────── 2. ჩანაწერის აწყობა ───────────
$parts = array(date('d.m.Y H:i'));
$parts[] = ($oldStatus === $newStatus)
    ? $newStatus . ' (უცვლელი)'
    : $oldStatus . ' → ' . $newStatus;

if ($dealId !== '') { $parts[] = 'გარიგება #' . $dealId; }
if ($stage  !== '') { $parts[] = $stage; }
$parts[] = $source;

$entry = implode(' | ', $parts);

// ⚠️ ხაზების გამყოფი <br> უნდა იყოს და არა \n —
//    ბიტრიქსი ამ ველს ბარათზე HTML-ად აჩვენებს.
$newLog = $entry . ($oldLog !== '' ? '<br>' . $oldLog : '');

// ზღვრის დაცვა — ჭრის მთელ ჩანაწერზე, შუაში არა
if (strlen($newLog) > $LOG_MAX) {
    $newLog = substr($newLog, 0, $LOG_MAX);
    $cut    = strrpos($newLog, '<br>');
    if ($cut !== false) { $newLog = substr($newLog, 0, $cut); }
    $newLog .= '<br>... [ძველი ჩანაწერები მოიჭრა]';
}

// ─────────── 3. განახლება ───────────
$upd = $rest('crm.product.update', array(
    'id'     => $nextId,
    'fields' => array(
        $F_STAT => $newStatus,
        $F_LOG  => array('TEXT' => $newLog, 'TYPE' => 'HTML'),
    ),
));

if ($upd['raw'] === false) {
    $this->WriteToTrackingService('❌ ქსელის შეცდომა განახლებისას: ' . $upd['err']);
} elseif (isset($upd['data']['error'])) {
    $this->WriteToTrackingService('❌ განახლება ჩავარდა: ' . $upd['data']['error'] . ' — ' . $upd['data']['error_description']);
} else {
    $this->WriteToTrackingService('✅ ბინა ' . $product['NAME'] . ' (Next #' . $nextId . '): ' . $entry);
}
