<?php
/**
 * სატესტო ბლოკი — ერთ ბინაზე თანმიმდევრობით ცვლის სამივე სტატუსს
 * და ბოლოს აბრუნებს საწყისში. ბიზნეს პროცესში ერთხელ გასაშვებად.
 *
 * ⚠️ რეალურ მონაცემზე წერს. გაუშვი მხოლოდ სატესტო ბინაზე.
 */

$webhook   = 'https://bitrix.nextgroup.ge/rest/1/xxxxxxxxxxxxxxxx/';
$idField   = 'PROPERTY_546';
$statField = 'PROPERTY_64';
$archiId   = '5335768';   // სატესტო ბინა N1001

$sequence = array('reserved', 'sold', 'free');   // ბოლოს თავისუფალზე ბრუნდება

$statuses = array(
    'free'     => 'თავისუფალი',
    'reserved' => 'ფასიანი ჯავშანი',
    'sold'     => 'გაყიდული',
);

foreach ($sequence as $statusKey) {

    $post = http_build_query(array(
        'halt' => 1,
        'cmd'  => array(
            'find' => 'crm.product.list?' . http_build_query(array(
                'filter' => array($idField => $archiId),
                'select' => array('ID', $idField, $statField),
            )),
            'upd'  => 'crm.product.update?' . http_build_query(array(
                'id'     => '$result[find][0][ID]',
                'fields' => array($statField => $statuses[$statusKey]),
            )),
        ),
    ));

    $ch = curl_init($webhook . 'batch.json');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $raw = curl_exec($ch);
    curl_close($ch);

    $answer = json_decode($raw, true);
    $found  = isset($answer['result']['result']['find']) ? $answer['result']['result']['find'] : array();
    $errors = isset($answer['result']['result_error']) ? $answer['result']['result_error'] : array();

    if (empty($found)) {
        $this->WriteToTrackingService('❌ ' . $statuses[$statusKey] . ' — პროდუქტი ვერ მოიძებნა');
    } elseif (!empty($errors)) {
        $this->WriteToTrackingService('❌ ' . $statuses[$statusKey] . ' — ' . json_encode($errors));
    } else {
        $this->WriteToTrackingService('✅ ' . $statuses[$statusKey] . ' — Next ID ' . $found[0]['ID']);
    }
}
