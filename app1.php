<?php
declare(strict_types=1);

namespace App;

require_once('sql.php');
require_once('patient.php');
require_once('classifiers.php');
require_once('payment_method.php');

use \DBH\db_connection;
use \Patient\Patient;
use \Classifiers\PAYMENT_TYPES;


function patients_cnt(\PDO $db_connection): int {
  $query = '
    SELECT COUNT(*) AS cnt
    FROM patients
  ';
  $stmt = $db_connection->prepare($query);
  $stmt->execute();
  $res = $stmt->fetch();

  return $res['cnt'];
}

function payment_methods_cnt(\PDO $db_connection, int $patient_id, ?bool $status=null): int {
  $query = '
    SELECT COUNT(*) AS cnt
    FROM payment_methods
    WHERE patient_id = :patient_id
  ';

  if ($status === true) {
    $query .= ' AND payment_data->"$.status" = true ';
  } else if ($status === false) {
    $query .= ' AND payment_data->"$.status" = false ';
  }

  $stmt = $db_connection->prepare($query);
  $stmt->bindValue(':patient_id', $patient_id, \PDO::PARAM_INT);
  $stmt->execute();
  $res = $stmt->fetch();

  return $res['cnt'];
}

function add_expired_card(\PDO $db_connection, int $patient_id): void {
  $query = '
    INSERT INTO payment_methods
    (patient_id, payment_type, payment_data)
    VALUES (
      :patient_id, :payment_type, :payment_data
    )
  ';

  $payment_data = [
    'status' => true,
    'cardholder_name' => 'Tracey Fritsch',
    'card_number' => 2522513177073266,
    'expiration_date' => '2024-03',
  ];

  $stmt = $db_connection->prepare($query);
  $stmt->execute(
    array(
      ':patient_id' => $patient_id,
      ':payment_type' => PAYMENT_TYPES::CREDIT_CARD->value,
      ':payment_data' => json_encode($payment_data),
    )
  );
}

function app1(\PDO $db_connection): void {
  $truncate_query = '
    SET FOREIGN_KEY_CHECKS = 0; 
    TRUNCATE TABLE patients;
    TRUNCATE TABLE payment_methods;
    SET FOREIGN_KEY_CHECKS = 1; 
    ALTER TABLE patients AUTO_INCREMENT = 1;
    ALTER TABLE payment_methods AUTO_INCREMENT = 1;
  ';

  $db_connection->exec($truncate_query);
  assert(patients_cnt($db_connection) == 0, '"patients" not empty!');

  $new_patient = new Patient($db_connection);
  $new_patient->first_name = "John";
  $new_patient->last_name = "Doe";
  $new_patient->address = "111 Some str";
  $new_patient->gender = "male";
  $new_patient->date_of_birth = "1983-01-01";
  $new_patient->savePatientInfo();

  assert(patients_cnt($db_connection) == 1);

  $loaded_patient = new Patient($db_connection, 1);

  assert($loaded_patient->first_name === $new_patient->first_name);
  assert($loaded_patient->last_name === $new_patient->last_name);
  assert($loaded_patient->address === $new_patient->address);
  assert($loaded_patient->gender === $new_patient->gender);
  assert($loaded_patient->date_of_birth === $new_patient->date_of_birth);

  $loaded_patient->gender = "female";
  $loaded_patient->last_name = "miskine";
  $loaded_patient->savePatientInfo();

  $reloaded_patient = new Patient($db_connection, 1);
  assert($reloaded_patient->gender === $loaded_patient->gender);
  assert($reloaded_patient->last_name === $loaded_patient->last_name);

  assert(payment_methods_cnt($db_connection, $reloaded_patient->patient_id) === 0, '"payment_methods" not empty!');

  $cc_payment_data = array(
    'status' => true,
    'cardholder_name' => 'Tracey Fritsch',
    'card_number' => 2522513177073266,
    'expiration_date' => '2026-03',
  );
  $reloaded_patient->addPaymentMethod(PAYMENT_TYPES::CREDIT_CARD->value, $cc_payment_data);
  assert(payment_methods_cnt($db_connection, $reloaded_patient->patient_id) === 1);
  assert(count($reloaded_patient->payment_methods) === 1);

  // invalid expiration date
  $invalid_cc_payment_data = array(
    'status' => true,
    'cardholder_name' => 'Tracey Fritsch',
    'card_number' => 2522513177073266,
    'expiration_date' => '226-03',
  );
  $reloaded_patient->addPaymentMethod(PAYMENT_TYPES::CREDIT_CARD->value, $invalid_cc_payment_data);
  assert(payment_methods_cnt($db_connection, $reloaded_patient->patient_id) === 1);
  assert(count($reloaded_patient->payment_methods) === 1);


  // no active payment method with same card_number for the same patient
  $reloaded_patient->addPaymentMethod(PAYMENT_TYPES::CREDIT_CARD->value, $cc_payment_data);
  assert(payment_methods_cnt($db_connection, $reloaded_patient->patient_id) === 1);
  assert(count($reloaded_patient->payment_methods) === 1);

  $cc_payment_data2 = array(
    'status' => true,
    'cardholder_name' => 'Tracey Fritsch',
    'card_number' => 2522513177073267,
    'expiration_date' => '2026-03',
  );
  $reloaded_patient->addPaymentMethod(PAYMENT_TYPES::CREDIT_CARD->value, $cc_payment_data2);
  assert(payment_methods_cnt($db_connection, $reloaded_patient->patient_id) === 2);
  assert(count($reloaded_patient->payment_methods) === 2);

  $expired_cc_payment_data = array(
    'status' => true,
    'cardholder_name' => 'Tracey Fritsch',
    'card_number' => 2522513177073299,
    'expiration_date' => '2025-06',
  );
  $reloaded_patient->addPaymentMethod(PAYMENT_TYPES::CREDIT_CARD->value, $expired_cc_payment_data);
  assert(payment_methods_cnt($db_connection, $reloaded_patient->patient_id) === 2);
  assert(count($reloaded_patient->payment_methods) === 2);

  $ba_payment_data = array(
    'status' => true,
    'account_number' => 27290363,
    'routing_number' => 262182245,
    'holder_name' => 'John Doe',
  );
  $reloaded_patient->addPaymentMethod(PAYMENT_TYPES::BANK_ACCOUNT->value, $ba_payment_data);
  assert(payment_methods_cnt($db_connection, $reloaded_patient->patient_id) === 3);
  assert(count($reloaded_patient->payment_methods) === 3);

  // no new payment method if either bank account or router number exists for the same patient
  $ba_payment_data_with_same_route_nr = array(
    'status' => true,
    'account_number' => 27290362,
    'routing_number' => 262182245,
    'holder_name' => 'John Doe',
  );
  $reloaded_patient->addPaymentMethod(PAYMENT_TYPES::BANK_ACCOUNT->value, $ba_payment_data_with_same_route_nr);
  assert(payment_methods_cnt($db_connection, $reloaded_patient->patient_id) === 3);
  assert(count($reloaded_patient->payment_methods) === 3);

  // no new payment method if either bank account or router number exists for the same patient
  $ba_payment_data_with_same_acc_nr = array(
    'status' => true,
    'account_number' => 27290363,
    'routing_number' => 262182244,
    'holder_name' => 'John Doe',
  );
  $reloaded_patient->addPaymentMethod(PAYMENT_TYPES::BANK_ACCOUNT->value, $ba_payment_data_with_same_acc_nr);
  assert(payment_methods_cnt($db_connection, $reloaded_patient->patient_id) === 3);
  assert(count($reloaded_patient->payment_methods) === 3);

  print(PHP_EOL);
  print(PHP_EOL . 'Just reloaded patient' . PHP_EOL);
  $reloaded_patient->showPatientInfo(true);

  // disable credit card payment method
  $reloaded_patient->payment_methods[2]->setActive($db_connection, false);
  assert(payment_methods_cnt($db_connection, $reloaded_patient->patient_id, true) === 2);
  assert(count($reloaded_patient->payment_methods) === 3);

  print(PHP_EOL . 'Patient with disabled 1 credit card' . PHP_EOL);
  $reloaded_patient->showPatientInfo(true);

  add_expired_card($db_connection, 1);
  $patient_with_expired_cc = new Patient($db_connection, 1);
  print(PHP_EOL . 'Patient with expired credit card' . PHP_EOL);
  $patient_with_expired_cc->showPatientInfo(true);
}


app1($db_connection);


?>
