<?php

// Prevent the entry-point code from running if included directly
if (!defined('DSA_SMS_SCRIPT')) {
  header('Content-Type: application/json');

  try {
      $input = $_POST;
      if (empty($input)) {
          $input = json_decode(file_get_contents('php://input'), true);
      }
      if (!$input) {
          throw new Exception("Invalid or missing input data");
      }

      $dsaMecSms = new DsaMecSms($input);
      $dsaMecSms->processBooking();

      echo json_encode(['status' => 'success', 'message' => 'Booking processed successfully.']);
  } catch (Exception $e) {
      http_response_code(400);
      echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
  }

  exit; // Prevent further execution
}

class DsaMecSms
{
    const PROGRAM_SID = 'wjERfy2xU23kx79T';
    const PROGRAM_KEY = 'hauwF9g2eHR5zjwGpvKvaRgKgZ9CgLyS';
    const API_URL = 'https://wsprod.colostate.edu/cwis463/smshub/api';
    const DSA_SMS_DEBUG = true;

    private $postData;

    public function __construct($postData)
    {
        $this->postData = $postData;
    }

    public function processBooking()
    {
        try {
            $bookingData = $this->postData;

            if (self::DSA_SMS_DEBUG) {
                $this->logToFile("Received Booking Data: " . print_r($bookingData, true));
            }

            $bookingId = $bookingData['id'] ?? null;
            $eventTitle = $bookingData['event']['title'] ?? 'Unknown Event';
            $startDate = $bookingData['start'] ?? 'Unknown Start Date';
            $endDate = $bookingData['end'] ?? 'Unknown End Date';

            if (self::DSA_SMS_DEBUG) {
                $this->logToFile("Processing event: $eventTitle, Booking ID: $bookingId");
            }

            $attendees = $bookingData['attendees'] ?? [];
            foreach ($attendees as $attendee) {
                $attendeeName = $attendee['name'] ?? 'Unknown Name';
                $attendeeEmail = $attendee['email'] ?? 'Unknown Email';
                $phoneNumber = $attendee['fields'][0]['value'] ?? 'Unknown Phone Number';
                $csuId = $attendee['fields'][1]['value'] ?? 'Unknown CSU ID';

                if (self::DSA_SMS_DEBUG) {
                    $this->logToFile("Attendee: $attendeeName, Email: $attendeeEmail, Phone: $phoneNumber, CSU ID: $csuId");
                }

                $this->sendConfirmationMessage($attendeeName, $attendeeEmail, $eventTitle, $startDate, $endDate, $phoneNumber, $csuId);
            }
        } catch (Exception $e) {
            $this->logToFile("Error in processBooking: " . $e->getMessage());
            throw $e;
        }
    }

    private function sendConfirmationMessage($name, $email, $event, $start, $end, $phone, $csuId)
    {
        $message = $this->generateConfirmationMessage($name, $event, $start, $end, $phone, $csuId);

        if (self::DSA_SMS_DEBUG) {
            $this->logToFile("Confirmation Message: $message");
        }

        $this->sendSMS([$phone], $message);
    }

    private function generateConfirmationMessage($name, $event, $start, $end, $phone, $csuId)
    {
        $eventDate = date('l, F j, Y', strtotime($start));
        $eventTime = date('g:i A', strtotime($start));
        $eventLocation = "Student Resolution Center";

        return sprintf(
            "Workshop reminder – %s, %s on %s at %s with the Student Resolution Center. Call 970-491-7165 to cancel. Please arrive on time (latecomers may not be admitted). No food is allowed, but you may bring a beverage.",
            $event,
            $eventTime,
            $eventDate,
            $eventLocation
        );
    }

    private static function sendSMS($phones, $msg)
    {
        $phones = implode(',', $phones);
        try {
            $token = self::getAuthToken(self::PROGRAM_SID, self::PROGRAM_KEY, self::API_URL);
            if (!$token) {
                throw new Exception("Failed to obtain authentication token.");
            }

            $smsUrl = self::API_URL . '/TextMessages';
            $smsHeaders = ['Content-Type: application/json', 'Authorization: ' . $token];

            $smsBody = json_encode([
                'PhoneNumber' => $phones,
                'Message' => $msg,
                'DateSent' => date('Y-m-d H:i:s'),
            ]);

            $ch = curl_init($smsUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $smsHeaders);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $smsBody);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("Unexpected HTTP response code $httpCode: $response");
            }
        } catch (Exception $e) {
            self::logToFile("Error in sendSMS: " . $e->getMessage());
            throw $e;
        }
    }

    private static function getAuthToken($programSID, $programKey, $apiURL)
    {
        try {
            $authUrl = $apiURL . '/Login';
            $authBody = http_build_query([
                'programSID' => $programSID,
                'ProgramKey' => $programKey,
            ]);

            $ch = curl_init($authUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $authBody);

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception("cURL Error: " . curl_error($ch));
            }
            curl_close($ch);

            return trim($response, '"');
        } catch (Exception $e) {
            self::logToFile("Error in getAuthToken: " . $e->getMessage());
            throw $e;
        }
    }
    private function logToFile($message)
    {
        $logFile = __DIR__ . '/mec-booking-data.log';
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
    }
}
