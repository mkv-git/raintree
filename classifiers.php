<?php
declare(strict_types=1);

namespace Classifiers;

enum PAYMENT_TYPES: string
{
  case CREDIT_CARD = 'CreditCard';
  case BANK_ACCOUNT = 'ACH';
}


?>
