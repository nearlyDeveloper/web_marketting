<?php
  include '../../admin/config.php'; 
   
class SuccessCartController {
    private $eventID;
    private $totalValue;
    private $pixelId = '1205316136168724'; // Pixel ID
    private $apiVersion = 'v20.0'; // Версия API
    private $accessToken = 'EAAJYgYAQTecBO8E8N6ucH5DK3X0AsFWn7ZCBGowwZC5rBZAYdscSJfKQdG3PCtvQ0ott1X9yntZCdugwHZCb9JW7SYG6I3pSfkM9lLmMwmzuhFRNhfVuQoS80diooSWwnpC0VF33NPp3SOgNf9tqAhX7ZCZBsZCx7dffZC9qMejVXL3DbKVLnq2DNfIpby13DZBkGjsgZDZD'; // Access Token
    private $fbp;
    private $fbc;
    private $source_url;
    
    public function getDataFromDB(){
        function logToFile($message) {
        $logFile = __DIR__ . '/../logs/bdLog.txt'; // путь к файлу логов
        // Проверяем, существует ли файл и сколько записей в нем
        $lines = file_exists($logFile) ? file($logFile) : [];
        $lineCount = count($lines);
        // Если количество записей больше или равно 1000, очищаем файл
        if ($lineCount >= 1000) {
            file_put_contents($logFile, ""); // Очищаем файл
            $lineCount = 0; // Сбрасываем счетчик
        }
            // Нумеруем запись
            $recordNumber = $lineCount + 1;
            /* $logEntry = date('Y-m-d H:i:s') . " - [$recordNumber] " . $message . PHP_EOL; */
            $logEntry = " [$recordNumber] - " . date('Y-m-d H:i:s') . $message . PHP_EOL;
            // Записываем в файл
            file_put_contents($logFile, $logEntry, FILE_APPEND);
        }

        // Обращение к базе данных, чтобы достать id заказа и итоговую сумму заказа
        $db = mysqli_connect(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE);
        $db->set_charset('utf8'); 
        if (!$db) {
        logToFile("Ошибка подключения к базе данных: " . mysqli_connect_error());
        } 
        
        $result_order = mysqli_query($db, "SELECT * FROM oc_order WHERE order_status_id != '0' ORDER BY date_added DESC LIMIT 1");
    
        if ($result_order) {
        $order = mysqli_fetch_assoc($result_order);
        // Теперь $last_order содержит данные последнего заказа
        } else {
            echo "Ошибка выполнения запроса: " . mysqli_error($db);
        }
          
        if ($order) {
            // Если заказ найден
            $eventID = $order['order_id'];
            $totalValue = $order['total'];
            $email = $order['email'];
            $telephone = $order['telephone'];
            
            logToFile("Заказ найден: eventID = $eventID, totalValue = $totalValue, email = $email, telephone = $telephone");
         
            $hashedEmail = hash('sha256', $email);
            $hashedTelephone = hash('sha256', $telephone);
            
             return [$eventID, $totalValue, $hashedEmail, $hashedTelephone]; // Возвращаем значения
        } else {
            // Если заказ не найден
            logToFile("Заказ не найден.");
        }
    } 
  
 
    public function getOrderData($eventID, $totalValue, $hashedEmail, $hashedTelephone){
        $this->eventID = $eventID;
        $this->totalValue = $totalValue;
        $this->email = $hashedEmail;
        $this->telephone = $hashedTelephone;
     
        
    /*
       file_put_contents(__DIR__ . '/../logs/getDataFromOrder.txt', "Stored Event ID: $eventID, Total Value: $totalValue, Hashed Email: $hashedEmail, Hashed Telephone: $hashedTelephone" . PHP_EOL, FILE_APPEND); */
    }

      public function cookieFB() {
        if (isset($_COOKIE['_fbp'])) {
            $this->fbp = $_COOKIE['_fbp'];
        } else {
            $this->fbp = null; // null если значение пусто
        }
        if (isset($_COOKIE['_fbc'])) {
        $this->fbc = $_COOKIE['_fbc'];
            } else {
             $this->fbc = null; // null если значение пусто
        }
    } 
    public function getSourceUrl() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
       $host = $_SERVER['HTTP_HOST'];
       $uri = $_SERVER['REQUEST_URI'];

       // Формируем полный URL
       $this->source_url = $protocol . $host . $uri;
       // Возвращаем собранный URL
       return $this->source_url;
   }

    public function trackEvent($eventName, $customData) {
        // Сбор данных о посещении
        $data = array(
            'data' => array(array(
                'event_name' => $eventName,
                'event_time' => time(),
                'event_id' => $this->eventID,
                'event_source_url' => $this->source_url,
                'user_data' => array(
                    'client_ip_address' => $_SERVER['REMOTE_ADDR'],
                    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'],
                    'fbp' => $this->fbp,
                    'fbc' => $this->fbc,
                    'em' => $this->email,
                    'ph' => $this->telephone
                ),
                'action_source' => 'website',
                'custom_data' => array_merge(array(
                    'currency' => 'PLN',
                ), $customData)
            ))
        );

        // Запись данных в json и txt
        $this->logVisit($data);
        // Отправка данных на Facebook
        $this->sendToFacebook($data);
    }
    public function completePurchase() {
        // Отправляем событие "Purchase"
        $this->trackEvent('Purchase', array('value' => $this->totalValue));
    }
    public function InitiateCheckout() {
        // Отправляем событие "InitiateCheckout"
        $this->trackEvent('InitiateCheckout', array('value' => '100'));
    } 
     public function ViewContent() {
        // Отправляем событие "ViewContent"
        $this->trackEvent('ViewContent', array('value' => '1', 'product_id'=>'1'));
    } 


    private function logVisit($data) {
        $jsonFilePath = __DIR__ . '/../logs/visits.json'; // Путь к JSON файлу
        $txtFilePath = __DIR__ . '/../logs/trackVisitLog.txt'; // Путь к текстовому файлу
    
        // Чтение существующих данных из JSON файла
        if (file_exists($jsonFilePath)) {
            $currentData = json_decode(file_get_contents($jsonFilePath), true);
        } else {
            $currentData = [];
        }
    
        // Проверяем количество записей и очищаем файл, если их 1000 или больше
        if (count($currentData) >= 1000) {
            $currentData = []; // Очищаем массив
            file_put_contents($jsonFilePath, json_encode($currentData, JSON_PRETTY_PRINT)); // Очищаем JSON файл
            file_put_contents($txtFilePath, ''); // Очищаем текстовый файл
        }
    
        // Нумеруем запись
        $recordNumber = count($currentData) + 1;
    
        // Добавление нового визита без record_number в $data
        $currentData[] = $data;
    
        // Запись обратно в JSON файл
        if (file_put_contents($jsonFilePath, json_encode($currentData, JSON_PRETTY_PRINT)) === false) {
            error_log('Ошибка записи в JSON файл.');
        }
    
        // Запись данных в текстовый файл с record_number
        $logEntry = " [$recordNumber] - " . date('Y-m-d H:i:s') . " - " . json_encode($data) . PHP_EOL; // Объединяем данные с номером записи
        if (file_put_contents($txtFilePath, $logEntry . PHP_EOL, FILE_APPEND) === false) {
            error_log('Ошибка записи в текстовый файл.');
        }
    }


    private function sendToFacebook($data) {
    $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->pixelId}/events";
    
    // Используем cURL для отправки запроса
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $this->accessToken // Используем Bearer токен для авторизации
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('Ошибка cURL: ' . curl_error($ch));
    }
    curl_close($ch);

    if ($result === false) {
        // Обработка других ошибок
        error_log('Ошибка отправки данных на Facebook: ');
    } else {
        // Можно добавить логирование успешного ответа
        error_log('Ответ от Facebook: ' . $result);
    }
}

} 
<script>
    var eventId = <?php echo addslashes($transaction_id); ?>;
    var amount = <?php echo floatval($amount); ?>;
    var hashedEmail = <?php echo json_encode($email); ?>;
    var name = <?php echo json_encode($name); ?>;
    var external = <?php echo json_encode($stripe_customer_id); ?>
    function getCookie(name) {
            const cookies = document.cookie.split('; ');
            for (let cookie of cookies) {
                const [key, value] = cookie.split('=');
                if (key === name) {
                    return decodeURIComponent(value);
                }
            }
            return null; // Если cookie не найдено
        }

        // Получаем значения fbp и fbc
        const fbp = getCookie('_fbp');
        const fbc = getCookie('_fbc');
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '1678719472762199');
fbq('track', 'Purchase', {value: amount, currency: 'USD'}, {eventID: eventId}, {em:hashedEmail, fn:name, fbp:fbp, fbc:fbc,external_id:external});
</script>
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id=1678719472762199&ev=PageView&noscript=1"
/></noscript>
?>

/* public function trackEvent($eventName, $customData) {
    // Сбор данных о посещении
     $data = array(
        'data' => array(array(
            'event_name' => $eventName,
            'event_time' => time(),
            'event_id' => $this->transaction_id,
            'event_source_url' => $this->source_url,
            'user_data' => array(
                'client_ip_address' => $_SERVER['REMOTE_ADDR'],
                'client_user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'fbp' => $this->fbp,
                'fbc' => $this->fbc,
                'em' => $this->email,
                'fn' => $this->name,
                'external_id' => $this->stripe_customer_id
            ),
            'action_source' => 'website',
            'content_type' => 'product',
            'content_ids' => array($this->product_id),
            'custom_data' => array_merge(array(
                'currency' => 'USD',
            ), $customData)
        ))
    );  
    
    // Запись данных в json и txt
    $this->logVisit($data);
    // Отправка данных на Facebook
    $this->sendToFacebook($data);
}

 public function trackTikTokEvent($eventName, $customProperties) {
    // Подготовка данных в точном соответствии с TikTok API
    $tiktokEventData = [
        'event_source' => 'web',
        'event_source_id' => $this->tiktokPixelId,
        'data' => [
            [
                'event' => $eventName,
                'event_time' => time(),
                'event_id' => $this->transaction_id,
                'user' => [
                    'external_id' => $this->stripe_customer_id,
                    'phone' => '', // Можно добавить хешированный телефон если есть
                    'email' => $this->email, // Уже хешированный из getDataFromDB()
                    'ip' => $_SERVER['REMOTE_ADDR'],
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                    'ttp' => $this->ttp
                ],
                'properties' => array_merge([
                    'currency' => $this->currency,
                    'value' => $this->amount,
                    'content_type' => 'product',
                    'contents' => [
                        [
                            'content_id' => $this->product_id,
                            'content_name' => $this->product_name,
                            'price' => $this->price,
                            'quantity' => 1,
                            'content_category' => '', // Можно добавить категорию
                            'brand' => '' // Можно добавить бренд
                        ]
                    ]
                ], $customProperties)
            ]
        ]
    ];

    $this->logVisit($tiktokEventData);
    $this->sendToTikTok($tiktokEventData);
} */ 