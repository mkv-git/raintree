<?php 
declare(strict_types=1);

namespace PaymentMethod;


require_once('classifiers.php');

use \PDO;
use \Classifiers\PAYMENT_TYPES;


interface PaymentMethodInterface
{
  public function isActive(): bool;
  public function __toString(): string;
  public function setActive(\PDO $db_connection, bool $status): void;
  public function haveExistingPaymentMethod(\PDO $db_connection, bool $active_only=true): bool;
  public function savePaymentMethod(\PDO $db_connection): bool;
  public function getMaskedCardNumber(): string;
}


abstract class AbstractPaymentMethod implements PaymentMethodInterface
{
  protected const ?string PAYMENT_TYPE = null;

  protected int $patient_id;
  protected ?string $status = null;
  protected array $payment_data = [];
  protected ?int $payment_method_id;

  public function __construct(int $patient_id, array $payment_data, ?int $payment_method_id=null) {
    $this->patient_id = $patient_id;
    $this->payment_data = $payment_data;
    $this->payment_method_id = $payment_method_id;
  }

  public function __get(string $property): mixed {
    switch($property) {
      case "payment_method_id":
        return $this->payment_method_id;
      default:
        return null;
    }
  }

  public function savePaymentMethod(\PDO $db_connection): bool {
    if ($this->haveExistingPaymentMethod($db_connection, true)) {
      printf('Payment method exists!' . PHP_EOL);
      return false;
    }

    $query = "
      INSERT INTO payment_methods
      (patient_id, payment_type, payment_data)
      VALUES (
        :patient_id, :payment_type, :payment_data
      )
    ";

    $stmt = $db_connection->prepare($query);
    $stmt->execute(
      array(
        ':patient_id' => $this->patient_id,
        ':payment_type' => $this::PAYMENT_TYPE,
        ':payment_data' => json_encode($this->payment_data),
      )
    );

    $this->payment_method_id = (int)$db_connection->lastInsertId();
    $this->status = ($this->payment_data['status']) ? 'active' : 'inactive';

    return true;
  }

  public function isActive(): bool {
    $ret_val =  $this->payment_data['status'];
    $this->status = ($ret_val) ? 'active' : 'inactive';
    return $ret_val;
  }

  public function setActive(\PDO $db_connection, bool $status): void {
    /* replacing whole payment_data, because haven't figured out yet, why bool value 
     * is sent as int, even if PDO::PARAM_BOOL is used @bindValue
     */

    $this->payment_data['status'] = $status;

    $query = '
      UPDATE payment_methods
      SET payment_data = :payment_data
      WHERE id = :payment_method_id
    ';

    $stmt = $db_connection->prepare($query);
    $stmt->execute([
      ':payment_data' => json_encode($this->payment_data),
      ':payment_method_id' => $this->payment_method_id,
    ]);

    $this->status = $status ? 'active' : 'inactive';
  }

  public function getMaskedCardNumber(): string {

    if ($this::PAYMENT_TYPE === PAYMENT_TYPES::CREDIT_CARD->value) {
      $number = (string)$this->payment_data['card_number'];
    } else if ($this::PAYMENT_TYPE === PAYMENT_TYPES::BANK_ACCOUNT->value) {
      $number = (string)$this->payment_data['account_number'];
    } else {
      return "N/A";
    }

    // split number by chunks of 4 digits, mask everything but 4 last digits
    $masked = str_split(substr_replace($number, str_repeat('*', strlen($number) - 4), 0), 4);
    $visible = substr($number, -4);

    return sprintf('%s %s', implode(' ', $masked), $visible);
  }

}


class CreditCard extends AbstractPaymentMethod {
  protected const string PAYMENT_TYPE = PAYMENT_TYPES::CREDIT_CARD->value;

  private function isValid(): bool {
    /* check if credit card is expired
    */

    $today = new \DateTime();
    $exp_date_obj = date_create_from_format('Y-m', $this->payment_data['expiration_date']);

    if (!$exp_date_obj) {
      return false;
    }

    return ($exp_date_obj > $today);
  }

  public function isActive(): bool {
    $ret_val =  $this->payment_data['status'];
    $this->status = ($ret_val) ? 'active' : 'inactive';

    if (!$this->isValid()) {
      $this->status = 'expired';
    }

    return $ret_val;
  }

  public function __toString(): string {
    $this->isActive();

    $exp_date_obj = null;

    if (!is_null($this->payment_data['expiration_date'])) {
      $exp_date_obj = date_create_from_format('Y-m', $this->payment_data['expiration_date']);
      if (!$exp_date_obj) {
        $expiration_date = 'Invalid';
      } else {
        $expiration_date = $exp_date_obj->format('m/y');
      }
    } else {
      $expiration_date = 'N/A';
    }

    return sprintf("Payment Method: %s | Masked Number: %s (%s) | Status: %s" . PHP_EOL,
      self::PAYMENT_TYPE,
      $this->getMaskedCardNumber(),
      $expiration_date,
      $this->status,
    );
  }

  public function haveExistingPaymentMethod(\PDO $db_connection, bool $active_only=true): bool {
    $query = '
      SELECT 1
      FROM payment_methods
      WHERE patient_id = :patient_id
        AND payment_type = :payment_type
        AND payment_data->"$.card_number" = :card_number
    ';

    if ($active_only) {
      $query .= ' AND payment_data->"$.status" = true ';
    }

    $stmt = $db_connection->prepare($query);
    $stmt->bindValue(':patient_id', $this->patient_id, \PDO::PARAM_INT);
    $stmt->bindValue(':payment_type', $this::PAYMENT_TYPE);
    $stmt->bindValue(':card_number', $this->payment_data['card_number'], \PDO::PARAM_INT);
    $stmt->execute();

    $res = $stmt->fetch();
    return (bool)$res;
  }

}

class ACH extends AbstractPaymentMethod {
  protected const string PAYMENT_TYPE = PAYMENT_TYPES::BANK_ACCOUNT->value;

  public function __toString(): string {
    $this->isActive();

    return sprintf("Payment Method: %s | Masked Number: %s | Status: %s" . PHP_EOL,
      self::PAYMENT_TYPE,
      $this->getMaskedCardNumber(),
      $this->status,
    );
  }

  public function haveExistingPaymentMethod(\PDO $db_connection, bool $active_only=true): bool {
    $query = '
      SELECT 1
      FROM payment_methods
      WHERE patient_id = :patient_id
        AND payment_type = :payment_type
        AND (
          payment_data->"$.account_number" = :account_number
          OR payment_data->"$.routing_number" = :routing_number
        )
    ';

    if ($active_only) {
      $query .= ' AND payment_data->"$.status" = true ';
    }

    $stmt = $db_connection->prepare($query);
    $stmt->bindValue(':patient_id', $this->patient_id, \PDO::PARAM_INT);
    $stmt->bindValue(':payment_type', $this::PAYMENT_TYPE);
    $stmt->bindValue(':account_number', $this->payment_data['account_number'], \PDO::PARAM_INT);
    $stmt->bindValue(':routing_number', $this->payment_data['routing_number'], \PDO::PARAM_INT);
    $stmt->execute();

    $res = $stmt->fetch();
    return (bool)$res;
  }
}

?>
