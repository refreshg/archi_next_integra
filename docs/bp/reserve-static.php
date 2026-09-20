// ══════════════════════════════════════════════════════════════
//  Archi -> Next : ბინის დაჯავშნა
//  სატესტო ვერსია — ID სტატიკურად
//  ჩასვი ბიზნეს პროცესის "PHP Code" ბლოკში (გახსნის ტეგის გარეშე)
// ══════════════════════════════════════════════════════════════

$webhook   = 'https://bitrix.nextgroup.ge/rest/1/<შენი_ტოკენი>/';                 // ← ჩასვი Next-ის webhook, ბოლო სლეშით
$archiId   = '5335768';               // ბინა N1001, Kobuleti Beach Resort
$newStatus = 'ფასიანი ჯავშანი';

// ── მოთხოვნის აწყობა ──
$post = http_build_query(array(
    'halt' => 1,
    'cmd'  => array(
        'find' => 'crm.product.list?' . http_build_query(array(
            'filter' => array('PROPERTY_546' => $archiId),
            'select' => array('ID', 'PROPERTY_546', 'PROPERTY_64'),
        )),
        'upd'  => 'crm.product.update?' . http_build_query(array(
            'id'     => '$result[find][0][ID]',
            'fields' => array('PROPERTY_64' => $newStatus),
        )),
    ),
));

// ── გაგზავნა ──
$ch = curl_init($webhook . 'batch.json');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$raw = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

// ── შედეგი BP-ს ლოგში ──
if ($raw === false) {
    $this->WriteToTrackingService('❌ ქსელის შეცდომა: ' . $err);
} else {
    $a     = json_decode($raw, true);
    $found = isset($a['result']['result']['find']) ? $a['result']['result']['find'] : array();
    $errs  = isset($a['result']['result_error']) ? $a['result']['result_error'] : array();

    if (!is_array($a)) {
        $this->WriteToTrackingService('❌ არასწორი პასუხი: ' . substr($raw, 0, 400));
    } elseif (empty($found)) {
        $this->WriteToTrackingService('❌ ბინა ვერ მოიძებნა (PROPERTY_546 = ' . $archiId . '). პასუხი: ' . substr($raw, 0, 400));
    } elseif (!empty($errs)) {
        $this->WriteToTrackingService('❌ განახლება ჩავარდა: ' . json_encode($errs));
    } else {
        $this->WriteToTrackingService('✅ დაიჯავშნა — Next ID ' . $found[0]['ID'] . ', სტატუსი: ' . $newStatus);
    }
}
