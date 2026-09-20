<?php
/**
 * Archi -> Next : პროდუქტის სტატუსის განახლება
 * ბიზნეს პროცესის აქტივობა "PHP კოდის შესრულება" (Выполнение PHP-кода)
 *
 * ეს ვერსია ID-ს სტატიკურად იღებს — სატესტოდ.
 * როცა იმუშავებს, $archiId შეიცვლება გარიგების ველით (იხ. ბოლოში).
 */

// ─────────── კონფიგი ───────────
$webhook   = 'https://bitrix.nextgroup.ge/rest/1/xxxxxxxxxxxxxxxx/'; // ბოლო სლეში სავალდებულოა
$idField   = 'PROPERTY_546';   // UF_ARCHI_ID
$statField = 'PROPERTY_64';    // სტატუსი

$statuses = array(
    'free'     => 'თავისუფალი',
    'reserved' => 'ფასიანი ჯავშანი',
    'sold'     => 'გაყიდული',
);

// ─────────── სატესტო მონაცემი ───────────
$archiId   = '5335768';   // რეალური ბინა: N1001, Kobuleti Beach Resort
$statusKey = 'reserved';  // free | reserved | sold

// ─────────── გამოძახება ───────────
$log = array();

if (!isset($statuses[$statusKey])) {
    $this->WriteToTrackingService('უცნობი სტატუსი: ' . $statusKey);
    return;
}

$findQuery = http_build_query(array(
    'filter' => array($idField => $archiId),
    'select' => array('ID', $idField, $statField),
));

$updQuery = http_build_query(array(
    'id'     => '$result[find][0][ID]',
    'fields' => array($statField => $statuses[$statusKey]),
));

$post = http_build_query(array(
    'halt' => 1,
    'cmd'  => array(
        'find' => 'crm.product.list?' . $findQuery,
        'upd'  => 'crm.product.update?' . $updQuery,
    ),
));

$ch = curl_init($webhook . 'batch.json');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$raw  = curl_exec($ch);
$cerr = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ─────────── შედეგის დამუშავება ───────────
if ($raw === false) {
    $this->WriteToTrackingService('❌ ქსელის შეცდომა: ' . $cerr);
    return;
}

$answer = json_decode($raw, true);

if (!is_array($answer) || !isset($answer['result'])) {
    $this->WriteToTrackingService('❌ არასწორი პასუხი (HTTP ' . $code . '): ' . substr($raw, 0, 500));
    return;
}

$result = $answer['result'];
$found  = isset($result['result']['find']) ? $result['result']['find'] : array();
$errors = isset($result['result_error']) ? $result['result_error'] : array();

if (isset($errors['find'])) {
    $this->WriteToTrackingService('❌ ძებნა ჩავარდა: ' . json_encode($errors['find']));
    return;
}

if (empty($found)) {
    $this->WriteToTrackingService('❌ პროდუქტი ვერ მოიძებნა Next-ში. ' . $idField . ' = ' . $archiId);
    return;
}

if (isset($errors['upd'])) {
    $this->WriteToTrackingService('❌ განახლება ჩავარდა: ' . json_encode($errors['upd']));
    return;
}

$this->WriteToTrackingService(
    '✅ Next ID ' . $found[0]['ID'] . ' (' . $idField . '=' . $archiId . ') -> ' . $statuses[$statusKey]
);

/* ────────────────────────────────────────────────────────────
   როცა გარიგების ველი გექნება, მხოლოდ ეს ორი ხაზი შეიცვლება:

       $archiId   = $this->GetVariable('archi_id');
   ან  $archiId   = $rootActivity->GetVariable('archi_id');

   ხოლო სტატუსი — თითო ტოტში ფიქსირებული:
       $statusKey = 'free';      // თავისუფალის ტოტში
       $statusKey = 'reserved';  // ჯავშნის ტოტში
       $statusKey = 'sold';      // გაყიდვის ტოტში
   ──────────────────────────────────────────────────────────── */
