<?php
class Telegram {
    public function __construct($chatId, $messageId) {
        $this->chatId = $chatId;
        $this->messageId = $messageId;
    }

    function sendChatAction($action)
    {
        $payload = json_encode(
            array_merge(
                array(
                    'chat_id' => $this->chatId,
                    'action' => $action
                )
            )
        );
        $this->sendCurlRequest('sendChatAction', $payload);
    }

    function sendCurlRequest($method, $payload)
    {
        $ch = curl_init('https://api.telegram.org/bot1273940781:AAFArzw3KQ3OYXt3vJFRiGuOJjbb5J9WEZo/' . $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        # Return response instead of printing.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        # Send request.
        $result = curl_exec($ch);
        curl_close($ch);
        # Print response.
        echo $result;
        // echo $result;
    }

    /**
     * this needs $this->chatId and $this->messageId
     */
    function sendMessage($method = 'sendMessage', $answer = '', $additionalOptions = array())
    {
        $payload = json_encode(
            array_merge(
                array(
                    'chat_id' => $this->chatId,
                    'message_id' => $this->messageId,
                    'text' => $answer
                ),
                $additionalOptions
            )
        );
        $this->sendCurlRequest($method, $payload);
    }
}