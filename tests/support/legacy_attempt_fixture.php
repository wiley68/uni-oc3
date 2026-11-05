<?php
/** Historical test data only. Never uses the production new-attempt INSERT API. */
function mtuc_test_legacy_attempt($repository, $storeId, $orderId, $unicid, $operation, $selection, $fingerprint, $entry = 'checkout')
{
    $adapter = $repository->database();
    $property = new ReflectionProperty('MtUniCreditDbAdapter', 'db');
    $property->setAccessible(true);
    $memory = $property->getValue($adapter);
    if (!$memory instanceof Phase2MemoryDb) { throw new RuntimeException('Legacy fixtures require isolated memory DB'); }
    $existing = $repository->findByStoreOrder($storeId, $orderId);
    if ($existing !== null) { return $existing; }
    $row = $memory->seedFinancingAttempt(array('store_id' => $storeId, 'order_id' => (string) $orderId,
        'unicid' => $unicid, 'operation_key_hash' => $operation, 'selection_hash' => $selection,
        'request_fingerprint' => $fingerprint, 'entry_point' => $entry, 'state' => MtUniCreditFinancingAttemptState::ORDER_CREATED));
    return $repository->findById($row['attempt_id']);
}
