<?php
declare(strict_types=1);

namespace RestClient;

use \DateTime;


class PatientService {
  private const string APP_Id = 'AWAiZy-v37PRRPk-li56T-OLVx2m';
  private const string CLIENT_ID = 'd358308571c2815462e6a5ec';
  private const string CLIENT_SECRET = 'bf3f8770097416ce6a615e82c4bd3dc7';
  private const string REST_API_URL = 'https://rtdemo.raintreeinc.com/webapidev/api';

  private ?string $access_token = null;
  private ?string $refresh_token = null;
  private DateTime $expiration_ts;

  private function setAccessToken(bool $refresh=false): void {
    $payload = [
      'client_id' => self::CLIENT_ID,
    ];

    if ($refresh) {
      $payload['grant_type'] = 'refresh_token';
      $payload['refresh_token'] = $this->refresh_token;
    } else {
      $payload['grant_type'] = 'client_credentials';
      $payload['client_secret'] = self::CLIENT_SECRET;
    };

    $options = [
      'http' => [
        'method' => 'POST',
        'header' => [
          'Content-type: application/x-www-form-urlencoded',
          'AppId: ' . self::APP_Id,
        ],
        'content' => http_build_query($payload),
      ]
    ];

    $response = file_get_contents(self::REST_API_URL . '/token', false, stream_context_create($options));
    $json_response = json_decode($response);

    $this->access_token = $json_response->access_token;
    $this->refresh_token = $json_response->refresh_token;
    $this->expiration_ts = new DateTime($json_response->{".expires"});
  }

  private function validateAccess(): void {
    $now = new \DateTime();
    if (is_null($this->access_token) || ($this->expiration_ts < $now)) {
      $this->setAccessToken(true ? !is_null($this->refresh_token) : false);
    }
  }

  public function findPatientRes(string $first_name, string $last_name, string $dob, string $email): array {
    $this->validateAccess();

    $options = [
      'http' => [
        'method' => 'GET',
        'header' => [
          'Content-type: application/json',
          'Authorization: Bearer ' . $this->access_token,
        ],
      ]
    ];

    $date_of_birth = new \DateTime($dob);
    $params = [
      'first' => $first_name,
      'last' => $last_name,
      'dob' => $date_of_birth->format('Y-m-d'),
      'email' => $email,
    ];

    $uri = self::REST_API_URL . '/patients?' . http_build_query($params);
    $response = file_get_contents($uri, false, stream_context_create($options));
    try {
      return json_decode($response, true);
    } catch (Exception $e) {
      printf('Unable to decode response @ findPatientRes' . PHP_EOL);
      return [];
    }
  }

  public function getPatientData(string $patient_number): array {
    $this->validateAccess();

    $options = [
      'http' => [
        'method' => 'GET',
        'header' => [
          'Content-type: application/json',
          'Authorization: Bearer ' . $this->access_token,
        ],
      ]
    ];

    $uri = self::REST_API_URL . '/patients/' . $patient_number;
    $response = file_get_contents($uri, false, stream_context_create($options));
    try {
      return json_decode($response, true);
    } catch (Exception $e) {
      printf('Unable to decode response @ getPatientData' . PHP_EOL);
      return [];
    }
  }

}

$ps = new PatientService();
$patient_res = $ps->findPatientRes("Patricia", "Doe", "March 2nd, 1955", "patricia@doemail.com");
if ($patient_res) {
  $patient_number = $patient_res['records'][0]['pn'] ?? null;

  if (is_numeric($patient_number)) {
    $res = $ps->getPatientData($patient_number);
    var_dump($res);
  } else {
    printf("Incorrect patient number: \"$patient_number\"" . PHP_EOL);
  }
} else {
  printf('Unable to find patient' . PHP_EOL);
}

?>
