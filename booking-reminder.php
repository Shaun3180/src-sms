foreach ($bookings as $booking) {
            // Skip if the booking's post status is 'trash'
            if ('trash' === get_post_status($booking->ID)) {
                continue; // Skip this iteration and move to the next booking
            }
            
            $event_id = get_post_meta($booking->ID, 'mec_event_id', true);
            $end = $db->select("SELECT `tend` FROM `#__mec_dates` WHERE `post_id`='" . $event_id . "' AND `tstart`='" . $booking->mec_timestamp . "' LIMIT 1", 'loadResult');

            $timestamps = $booking->mec_timestamp . ':' . $end;

            $result = $notif->booking_reminder($booking->ID, $timestamps);
            if ($result) {
                $sent_reminders++;
            }

            try {
                
                // Define constant to skip direct entry-point code
                define('DSA_SMS_SCRIPT', true);

                // to include the SMS functionality
                require_once get_stylesheet_directory() . '/dsa-utilities/mec-sms.php';

                // Fetch phone number from serialized attendees data
                $attendees_data = get_post_meta($booking->ID, 'mec_attendees', true);
                $attendees_data = maybe_unserialize($attendees_data); // Unserialize if needed

                if (is_array($attendees_data) && isset($attendees_data[0])) {
                    // Extract phone number from the 'reg' array (index 3)
                    $phone = $attendees_data[0]['reg'][3] ?? null;

                    if ($phone) {
                        $message = sprintf(
                            "Reminder: Your event '%s' is starting soon. Please be on time.",
                            get_the_title($event_id) // Fetch the event title
                        );

                        // Send SMS only for site ID 58, the SRC
                        if (get_current_blog_id() == 58) {
                            // Prepare the booking data in the format expected by the DsaMecSms class
                            $postData = [
                                'id' => $booking->ID,
                                'event' => [
                                    'title' => get_the_title($event_id),
                                ],
                                'start' => date('Y-m-d H:i:s', $booking->mec_timestamp),
                                'attendees' => [
                                    [
                                        'name' => $booking->post_title,
                                        'email' => '', // Extract email if available, or set as blank
                                        'fields' => [
                                            ['value' => $phone], // Phone number field
                                        ],
                                    ],
                                ],
                            ];

                            // Instantiate the DsaMecSms class
                            $smsHandler = new DsaMecSms($postData);

                            // Process the booking to send SMS
                            $smsHandler->processBooking();
                        }
                    } else {
                        //echo 'Phone number not found.';
                    }
                } else {
                    //echo 'No attendee data found.';
                }
            } catch (Exception $e) {
                echo 'Message: ' . $e->getMessage();
            }
        }
