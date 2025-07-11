<?php
declare(strict_types=1);

namespace Patient;

require_once('classifiers.php');
require_once('payment_method.php');

use \PDO;
use \DateTime;

use \Classifiers\PAYMENT_TYPES;
use \PaymentMethod\{CreditCard, ACH};

const DEFAULT_DATE_FORMAT = 'Y-m-d';


class Patient
{

  private PDO $db_connection;
  private ?int $patient_id = null;
  private ?string $first_name = null;
  private ?string $last_name = null;
  private ?DateTime $date_of_birth = null;
  private ?string $gender = null;
  private ?string $address = null;

  private array $payment_methods = [];

  public function __construct(PDO $db_connection, ?int $patient_id=null) {

    $this->db_connection = $db_connection;

    $this->patient_id = $patient_id;
    if ($patient_id) {
      $this->loadPatientInfo();
      $this->loadPaymentMethods();
    }
  }

  private function validateDate(string $date_str, string $format=DEFAULT_DATE_FORMAT): bool {
    $date = date_create_from_format($format, $date_str);
    return $date && $date->format($format) === $date_str;
  }

  public function savePatientInfo(): bool {
    /* create new patient record or if patient_id is not null, then update it
     
    */
    $add_query = "
      INSERT INTO patients
      (first_name, last_name, date_of_birth, gender, address)
      VALUES (
        :first_name, :last_name, :date_of_birth, :gender, :address
      )
    ";

    $update_query = "
      UPDATE patients
        SET first_name = :first_name,
          last_name = :last_name,
          date_of_birth = :date_of_birth,
          gender = :gender,
          address = :address
      WHERE id = :patient_id
    ";

    $payload = array(
      ':first_name' => $this->first_name,
      ':last_name' => $this->last_name,
      ':date_of_birth' => $this->date_of_birth->format(DEFAULT_DATE_FORMAT),
      ':gender' => $this->gender,
      ':address' => $this->address,
    );

    if (!is_null($this->patient_id)) {
      $query = $update_query;
      $payload[':patient_id'] = $this->patient_id;
    } else {
      $query = $add_query;
    }

    $stmt = $this->db_connection->prepare($query);
    $stmt->execute($payload);
    $this->patient_id = (int)$this->db_connection->lastInsertId();

    return true;
  }

  public function loadPatientInfo(): void {
    $query = "
      SELECT *
      FROM patients
      WHERE id = :patient_id
    ";

    $stmt = $this->db_connection->prepare($query);
    $stmt->execute(array(':patient_id' => $this->patient_id));
    $res = $stmt->fetch();

    foreach($res as $key => $val) {
      if ($key == 'date_of_birth') {
        $this->date_of_birth = date_create_from_format(DEFAULT_DATE_FORMAT, $val);
      } else {
        $this->{$key} = $val;
      }
    }
  }

  public function addPaymentMethod(string $payment_type, array $payment_data): bool {
    if ($payment_type === PAYMENT_TYPES::CREDIT_CARD->value) {
      if (!$this->validateDate($payment_data['expiration_date'], 'Y-m')) {
        $exp_date = $payment_data['expiration_date'];
        printf("Invalid date: \"$exp_date\"" . PHP_EOL);
        return false;
      } 

      $exp_date_obj = date_create_from_format('Y-m', $payment_data['expiration_date']);
      $today = new DateTime();
      if ($exp_date_obj < $today) {
        printf('Card expired' . PHP_EOL);
        return false;
      }


      $pm_obj = new CreditCard($this->patient_id, $payment_data);
    } else if ($payment_type === PAYMENT_TYPES::BANK_ACCOUNT->value) {
      $pm_obj = new ACH($this->patient_id, $payment_data);
    }

    $result = $pm_obj->savePaymentMethod($this->db_connection);
    if ($result) {
        $this->payment_methods[$pm_obj->payment_method_id] = $pm_obj;
    }

    return $result;
  }

  public function loadPaymentMethods(): void {
    $query = "
      SELECT *
      FROM payment_methods
      WHERE patient_id = :patient_id
    ";

    $stmt = $this->db_connection->prepare($query);
    $stmt->execute(array(':patient_id' => $this->patient_id));
    $res = $stmt->fetchall();

    foreach($res as $r_obj) {
      $payment_data = json_decode($r_obj['payment_data'], true);
      if ($r_obj['payment_type'] === PAYMENT_TYPES::CREDIT_CARD->value) {
        $pm_obj = new CreditCard($this->patient_id, $payment_data, $r_obj['id']);
      } else if ($r_obj['payment_type'] === PAYMENT_TYPES::BANK_ACCOUNT->value) {
        $pm_obj = new ACH($this->patient_id, $payment_data, $r_obj['id']);
      }

      $this->payment_methods[$r_obj['id']] = $pm_obj;
    }
  }

  public function __get(string $property): mixed {
    switch ($property) {
      case "patient_id":
        return $this->patient_id;

      case "first_name":
        return $this->first_name;
        
      case "last_name":
        return $this->last_name;

      case "address":
        return $this->address;

      case "date_of_birth":
        return $this->date_of_birth->format(DEFAULT_DATE_FORMAT);

      case "gender":
        return $this->gender;

      case "payment_methods":
        return $this->payment_methods;

      default:
        return null;
    }
  }

  public function __set(string $property, mixed $value): void {
    switch($property) {
      case "first_name":
        $this->first_name = $value;
        break;

      case "last_name": 
        $this->last_name = $value;
        break;

      case "date_of_birth":
        if ($this->validateDate($value)) {
          $this->date_of_birth = date_create_from_format(DEFAULT_DATE_FORMAT, $value);
        } else {
          print("Invalid date: \"$value\"");
        }
       break;

      case "gender":
        if (in_array($value, array("male", "female"))) {
          $this->gender = $value;
        } else {
          print("Invalid gender: \"$value\"");
        }
        break;

      case "address":
        $this->address = $value;
        break;
    }
  }

  public function showPatientInfo(bool $show_payment_method=false): void {
    $gender = !is_null($this->gender) ? strtoupper($this->gender[0]) : 'N/A';
    $dob = !is_null($this->date_of_birth) ? $this->date_of_birth->format(DEFAULT_DATE_FORMAT) : 'N/A';

    printf('Patient: %s %s, Gender: %s, Address: %s, DOB: %s' . PHP_EOL, 
      $this->first_name, 
      $this->last_name, 
      $gender, 
      $this->address, 
      $dob
    );

    if ($show_payment_method) {
      foreach ($this->payment_methods as $obj) {
        echo $obj;
      }
    }

  }

}

?>
