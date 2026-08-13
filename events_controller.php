<?php

class SendEventsController{
    private $eventID;
    private $totalValue;
    private $fbpixelId = '1678719472762199'; // Facebook Pixel ID
    private $tkpixelId = 'D0FPJABC77U0DNP5PHR0'; // Tiktok Pixel ID
    private $gakpixelId = 'G-WZSCV1ZE2Y'; // GA4 ID
    private $apiVersion = 'v22.0'; // Версия API
    private $accessTokenfb = 'EAAmh7hEmLboBO9Rns15MKACUggWffTpcsWwfpP8GKh2P8BXLAtglXldZA3Dc6HdSu8bvZAGR4Cx33k4vaAW3nOpttncIF7fqXMnyZB5aF6ZAmd7MoDRQxt9ATYrkQtPIsGgFuNbZCwZCy2ZAqm1zlBHVErQMFIQr5Tv3roOwCsnbOYqIJ3TJBMNffhRn2ZCocbfHOwZDZD'; // FB Access Token
    private $accessTokentk = '443dd7a157dff9a917af22e9606c9c97768e29a6'; // TikTok Access Token
    private $accessTokenga = 'sziqbz5CR1K-85BmBEIDLQ'; // GA4 Access Token
    public $fbp;
    public $fbc;
    private $source_url;
    public $ttp;
    public $ttq;
    public $ttclid;
    public $gaCookie;
    public $gid;
    public $gaClientId;

public function getDataFromDB() {
    global $wpdb;

    $logDir = __DIR__ . '/../logs';
    $dbLogFile = $logDir . '/db_operations.log';
    $orderDataFile = $logDir . '/last_order_data.log';

    $query = "SELECT * FROM {$wpdb->posts} WHERE post_type = 'wpstripeco_order' ORDER BY post_date DESC LIMIT 1";
    $result = $wpdb->get_row($query, ARRAY_A);

    file_put_contents($dbLogFile, "Выполнен SQL-запрос: " . $query . PHP_EOL, FILE_APPEND);

    if ($result && isset($result['post_content'])) {
        $post_content = $result['post_content'];

        // Инициализация переменных
        $transaction_id = $product_name = $product_id = $price_id = '';
        $amount = $price = 0.0;
        $currency = $billing_name = $name = $email = '';
        $stripe_customer_id = $billing_address = '';

        $pattern = '/<strong>([^:]+):\s*<\/strong>(.*?)(?:<br \/>|$)/';
        preg_match_all($pattern, $post_content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = trim($match[1]);
            $value = $match[2]; // Убираем trim() чтобы сохранить точные пробелы

            switch ($key) {
                case 'Transaction ID': $transaction_id = trim($value); break;
                case 'Product name': $product_name = $value; break;
                case 'Product ID': $product_id = $value; break;
                case 'Price ID': $price_id = $value; break;
                case 'Amount': 
                    $amount = floatval($value);
                    $price = floatval($value); 
                    break;
                case 'Currency': $currency = $value; break;
                case 'Billing Name': 
                    $billing_name = $value;
                    $name = $value;
                    break;
                case 'Email': $email = $value; break;
                case 'Stripe Customer ID': $stripe_customer_id = $value; break;
                case 'Billing Address': $billing_address = $value; break;
            }
        }

        // Хеширование без изменений оригинальных данных
        $hashed_email = !empty($email) ? hash('sha256', strtolower(trim($email))) : '';
        $hashed_name = !empty($name) ? hash('sha256', strtolower(trim($name))) : '';
        $hashed_stripe_customer_id = !empty($stripe_customer_id) ? hash('sha256', $stripe_customer_id) : '';
        $hashed_billing_address = !empty($billing_address) ? hash('sha256', $billing_address) : '';

        // Логирование
        $orderData = "Данные последнего заказа:\n";
        $orderData .= "Transaction ID: " . $transaction_id . "\n";
        $orderData .= "Product Name: " . $product_name . "\n";
        $orderData .= "Product ID: " . $product_id . "\n";
        $orderData .= "Price ID: " . $price_id . "\n";
        $orderData .= "Price: " . $price . "\n";
        $orderData .= "Amount: " . $amount . "\n";
        $orderData .= "Currency: " . $currency . "\n";
        $orderData .= "Billing Name: " . $billing_name . "\n";
        $orderData .= "Name (hashed): [HASHED]\n";
        $orderData .= "Email (hashed): [HASHED]\n";
        $orderData .= "Stripe Customer ID (hashed): [HASHED]\n";
        $orderData .= "Billing Address (hashed): [HASHED]\n";

        file_put_contents($orderDataFile, $orderData, FILE_APPEND);

        return array(
            'transaction_id' => $transaction_id,
            'product_name' => $product_name,
            'product_id' => $product_id,
            'price_id' => $price_id,
            'price' => $price,
            'amount' => $amount,
            'currency' => $currency,
            'billing_name' => $billing_name,
            'name' => $hashed_name,
            'email' => $hashed_email,
            'stripe_customer_id' => $hashed_stripe_customer_id,
            'billing_address' => $hashed_billing_address
        );

    } else {
        $errorMessage = "Ошибка выполнения запроса к БД или отсутствует post_content: " . $wpdb->last_error;
        error_log($errorMessage . ' - ' . date('Y-m-d H:i:s') . PHP_EOL, 3, $dbLogFile);
        return null;
    }
}

 public function getFormattedCartData(): array {
        if (!function_exists('WC') || !WC()->cart) {
            error_log('WooCommerce cart is not available.');
            // Возвращаем пустой массив с дефолтными значениями, включая пустой event_id
            return ['total_value' => 0.0, 'currency' => 'USD', 'items' => [], 'event_id' => ''];
        }

        $cart = WC()->cart->get_cart();
        $total_value = (float) WC()->cart->get_cart_contents_total();
        $currency = "USD"; // Или WC()->cart->get_currency() для большей точности

        $formattedItems = [];
        foreach ($cart as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $product_id = $product->get_id();
            $product_name = $product->get_name();
            $price_per_item = (float) $cart_item['data']->get_price();
            $quantity = (int) $cart_item['quantity'];

            if ($cart_item['variation_id'] && $cart_item['variation_id'] > 0) {
                $product_id = $cart_item['variation_id'];
                $product_name .= ' (' . wc_get_formatted_variation($cart_item['variation'], true) . ')';
            }

            $formattedItems[] = [
                'id' => $product_id,
                'name' => $product_name,
                'price' => $price_per_item,
                'quantity' => $quantity,
            ];
        }

        
        $event_id_initiate_checkout = '';
        foreach ($_COOKIE as $name => $value) {
            if (strpos($name, 'wp_woocommerce_session_') === 0) {
                $decoded_value = urldecode($value);
                $parts = explode('||', $decoded_value);

                if (count($parts) > 3) {
                    $event_id_initiate_checkout = $parts[3]; // Последняя часть - уникальный ID
                    break; // Как только нашли, выходим из цикла
                }
            }
        }
        // Если кука не найдена или не в ожидаемом формате после цикла, генерируем новый UUID
        if (empty($event_id_initiate_checkout)) {
            $event_id_initiate_checkout = uniqid('checkout_');
        }
      


        return [
            'total_value' => $total_value,
            'currency' => $currency,
            'items' => $formattedItems,
            'event_id' => $event_id_initiate_checkout // ДОБАВЛЕНО: event_id
        ];
    }

public function getOrderData(
    $transaction_id,
    $product_name,
    $product_id,
    $price_id,
    $price,
    $amount,
    $currency,
    $billing_name,
    $name, // Хэшированное (точный оригинал)
    $email, // Хэшированное (точный оригинал)
    $stripe_customer_id, // Хэшированное (точный оригинал)
    $billing_address // Хэшированное (точный оригинал)
) {
    $this->transaction_id = $transaction_id;
    $this->product_name = $product_name;
    $this->product_id = $product_id;
    $this->price_id = $price_id;
    $this->price = $price;
    $this->amount = $amount;
    $this->currency = $currency;
    $this->billing_name = $billing_name;
    $this->name = $name;
    $this->email = $email;
    $this->stripe_customer_id = $stripe_customer_id;
    $this->billing_address = $billing_address;
}

public function collectTrackingCookies() {
    // Facebook параметры
    $this->fbp = $_COOKIE['_fbp'] ?? null;
    $this->fbc = $_COOKIE['_fbc'] ?? null;
    
    // TikTok параметры
    $this->ttp = $_COOKIE['_ttp'] ?? null;
    $this->ttq = $_COOKIE['_ttq'] ?? null;  // Основной TikTok Tracking Cookie
    $this->ttclid = $_COOKIE['ttclid'] ?? '';
   $this->gaCookie = $_COOKIE['_ga'] ?? null;
        $this->gaClientId = $this->parseGAClientId($this->gaCookie);

        // Извлекаем Stream ID из полного Measurement ID
        // G-WZSCV1ZE2Y -> WZSCV1ZE2Y
        $gaStreamId = str_replace('G-', '', $this->gakpixelId);
        // Формируем правильное имя куки: _ga_WZSCV1ZE2Y
        $ga4SessionCookieName = '_ga_' . $gaStreamId;
        $ga4SessionCookie = $_COOKIE[$ga4SessionCookieName] ?? null;

        // Парсим session_id из куки нового формата
        $this->gaSessionId = $this->parseGA4SessionId($ga4SessionCookie);

        // ... ваш существующий код для других куки ...
        $this->gid = $_COOKIE['_gid'] ?? null;
}

/**
 * Парсит client_id из cookie _ga
 * Формат cookie: _ga=GA1.2.1234567890.1234567890
 * Где client_id это часть после GA1.2.
 */
  private function parseGA4SessionId($ga4SessionCookie) {
         if (!$ga4SessionCookie) {
            error_log("GA4 Session Cookie is null or empty. Cannot parse session_id.", 3, __DIR__.'/../logs/errors.log');
            return null;
        }

        // Пример куки: GS2.1.s1747938804$o12$g1$t1747940980$j58$l0$h2002577356$dbC4LDs09r0HyCwSsl2sb9x36FTlSLn9vzA
        // session_id (timestamp начала сессии) находится после 's' и до первого '$'
        if (preg_match('/s(\d+)\$/', $ga4SessionCookie, $matches)) {
            return $matches[1];
        }

        error_log("GA4 Session Cookie format invalid for session_id (regex failed): " . $ga4SessionCookie, 3, __DIR__.'/../logs/errors.log');
        return null;
    
    }
private function parseGAClientId($gaCookie) {
    if (!$gaCookie) {
        return null;
    }
    
    $parts = explode('.', $gaCookie);
    if (count($parts) >= 4) {
        return $parts[2] . '.' . $parts[3];
    }
    
    return null;
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


private function prepareFacebookData($eventName, $customProperties) {
    return [
        'data' => [[
            'event_name' => $eventName,
            'event_time' => time(),
            'event_id' => $this->transaction_id,
            'event_source_url' => $this->source_url,
            'action_source' => 'website',
            'user_data' => [
                'client_ip_address' => $_SERVER['REMOTE_ADDR'],
                'client_user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'fbp' => $this->fbp,
                'fbc' => $this->fbc,
                'em' => $this->email,
                'fn' => $this->name,
                'external_id' => $this->stripe_customer_id
            ],
            'custom_data' => array_merge([
                'currency' => 'USD',
                'value' => floatval($this->amount),
                'order_id' => $this->transaction_id,
                'content_type' => 'product',
                'contents' => [[
                    'id' => $this->product_id,
                    'quantity' => 1
                ]]
            ], $customProperties)
        ]]
    ];
}


private function prepareTikTokData($eventName, $customProperties) {
    return [
        'event_source' => 'web',
        'event_source_id' => $this->tkpixelId,
       // 'test_event_code' => 'TEST29627',
        'data' => [[
            'event' => $eventName,
            'event_time' => time(),
            'event_id' => $this->transaction_id,
            'user' => [
                'external_id' => $this->stripe_customer_id,
                'email' => $this->email,
                'ip' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'ttp' => $this->ttp,
                'ttq' => $this->ttq,
                'ttclid' => $this->ttclid
                // 'ttp' => '01JVHG14PFJBWVM93RZMBABHVS_.tt.1'
            ],
            'properties' => array_merge([
                'currency' => 'USD',
                'value' => floatval($this->amount),
                'content_type' => 'product',
                'contents' => [[
                    'content_id' => $this->product_id,
                    'content_name' => $this->product_name,
                    'price' => floatval($this->price),
                    'quantity' => 1
                ]]
            ], $customProperties)
        ]]
    ];
}

private function prepareGA4Data($eventName, $customProperties) {
    // timestamp_micros: На корневом уровне, как число.
    $timestampMicrosValue = round(microtime(true) * 1000000);

    return [
        'client_id' => $this->gaClientId,
        'user_id' => $this->stripe_customer_id, 
        'timestamp_micros' => $timestampMicrosValue, 
        'non_personalized_ads' => false, 
        'events' => [[ // Массив, содержащий одно событие
            'name' => $eventName,
            'params' => array_merge([ 
                'session_id' => $this->gaSessionId,
                'event_id' => $this->transaction_id, // Очень важно для дедупликации
                'engagement_time_msec' => 100, // Рекомендован для всех событий
                //'debug_mode' => true,
                'currency' => 'USD',
                'value' => $this->amount,
                'transaction_id' => $this->transaction_id, // Обязателен для 'purchase'
                // --- Параметры товара/товаров (для событий с items) ---
                'items' => [[ // Массив товаров
                    'item_id' => $this->product_id,
                    'item_name' => $this->product_name,
                    'price' => $this->price,
                    'quantity' => 1
                ]],
                'price_id' => $this->price_id,
                'source_url' => $this->source_url,

            ], $customProperties) // $customProperties могут переопределить или добавить новые
        ]]
    ];
}
private function prepareFacebookInitiateCheckoutData(): array
    {
        $cartData = $this->getFormattedCartData();
        $totalValue = $cartData['total_value'];
        $currency = $cartData['currency'] ?? 'USD'; // Используем валюту из корзины, или по умолчанию USD
        $contents = [];

        foreach ($cartData['items'] as $item) {
            $contents[] = [
                'id' => (string) $item['id'], // ID продукта/вариации
                'quantity' => (int) $item['quantity'],
            ];
        }

        return [
            'data' => [[
                'event_name' => 'InitiateCheckout',
                'event_time' => time(),
                'event_id' => $cartData['event_id'],
                'event_source_url' => $this->source_url,
                'action_source' => 'website',
                'user_data' => [
                    'client_ip_address' => $_SERVER['REMOTE_ADDR'],
                    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'],
                    'fbp' => $this->fbp,
                    'fbc' => $this->fbc,
                ],
                'custom_data' => [
                    'currency' => $currency,
                    'value' => floatval($totalValue),
                    'num_items' => count($cartData['items']),
                    'content_type' => 'product', // Или 'product_group' если товаров много
                    'contents' => $contents
                ]
            ]]
        ];
    }

    /**
     * Подготавливает данные для события InitiateCheckout в TikTok.
     *
     * @return array Готовый массив данных для отправки в TikTok Events API.
     */
    private function prepareTikTokInitiateCheckoutData(): array
    {
        $cartData = $this->getFormattedCartData();
        $totalValue = $cartData['total_value'];
        $currency = $cartData['currency'] ?? 'USD';
        $contents = [];

        foreach ($cartData['items'] as $item) {
            $contents[] = [
                'content_id' => (string) $item['id'],
                'content_name' => $item['name'],
                'price' => floatval($item['price']), // Цена за единицу
                'quantity' => (int) $item['quantity']
            ];
        }

        return [
            'event_source' => 'web',
            'event_source_id' => $this->tkpixelId,
           // 'test_event_code' => 'TEST17502',
            'data' => [[
                'event' => 'InitiateCheckout',
                'event_time' => time(),
                'event_id' => $cartData['event_id'],
                'user' => [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'ttp' => $this->ttp,
                    'ttq' => $this->ttq,
                    'ttclid' => $this->ttclid 
                ],
                'properties' => [
                    'currency' => $currency,
                    'value' => floatval($totalValue),
                    'contents' => $contents,
                    'content_type' => 'product' // Или 'product_group'
                ]
            ]]
        ];
    }

    /**
     * Подготавливает данные для события begin_checkout в Google Analytics 4.
     *
     * @return array Готовый массив данных для отправки в GA4 Measurement Protocol.
     */

private function prepareGA4InitiateCheckoutData(): array
{
    $cartData = $this->getFormattedCartData();
    $totalValue = $cartData['total_value'];
    $currency = $cartData['currency'] ?? 'USD';
    $items = [];

    foreach ($cartData['items'] as $item) {
        $items[] = [
            'item_id' => (string) $item['id'],
            'item_name' => $item['name'],
            'price' => floatval($item['price']),
            'quantity' => (int) $item['quantity']
        ];
    }

    // timestamp_micros: Должен быть строкой, представляющей время в микросекундах.
    $timestampMicros = (string)(round(microtime(true) * 1000000));

    $engagementTimeMsec = 100; // Пример: 100 миллисекунд.

    // Формируем основной Payload для GA4 Measurement Protocol
    return [
        'client_id' => $this->gaClientId ?? null, // Твой Client ID (он должен быть всегда)
        'timestamp_micros' => $timestampMicros, // timestamp_micros на корневом уровне и строка
        'non_personalized_ads' => false,        // Рекомендуемый параметр

        'events' => [[ // Это массив, содержащий одно событие
            'name' => 'begin_checkout',
            'params' => [ // Это объект с параметрами события
                'session_id' => $this->gaSessionId, // Обязательный параметр для корректной сессии
                'event_id' => $cartData['event_id'], // Уникальный ID события для дедупликации
                'currency' => $currency,
                'value' => floatval($totalValue),
                'items' => $items,
                'engagement_time_msec' => $engagementTimeMsec, // Время вовлечения в миллисекундах
                'debug_mode' => true // Включаем режим отладки для DebugView
            ]
        ]]
    ];

}

  private function isPurchaseAlreadySent($eventId): bool {
        $logDir = __DIR__ . '/../logs';
        
        // Проверяем все JSON-логи платформ
        $platforms = ['facebook', 'tiktok', 'ga4'];
        
        foreach ($platforms as $platform) {
            $jsonFile = $logDir . '/' . $platform . '_events.json';
            
            if (!file_exists($jsonFile)) {
                continue;
            }
            
            $content = file_get_contents($jsonFile);
            $logs = json_decode($content, true);
            
            if (!is_array($logs) || empty($logs)) {
                continue;
            }
            
            foreach ($logs as $logEntry) {
                if (!isset($logEntry['data'])) {
                    continue;
                }
                
                $data = $logEntry['data'];
                
                // Проверяем для Facebook
                if ($platform === 'facebook') {
                    if (isset($data[0]['event_name']) && 
                        $data[0]['event_name'] === 'Purchase' &&
                        isset($data[0]['event_id']) && 
                        $data[0]['event_id'] === $eventId) {
                        return true;
                    }
                }
                
                // Проверяем для TikTok
                if ($platform === 'tiktok') {
                    if (isset($data['data']) && is_array($data['data']) && !empty($data['data'])) {
                        $tiktokEvent = $data['data'][0];
                        if (isset($tiktokEvent['event']) && 
                            $tiktokEvent['event'] === 'Purchase' &&
                            isset($tiktokEvent['event_id']) && 
                            $tiktokEvent['event_id'] === $eventId) {
                            return true;
                        }
                    }
                }
                
                // Проверяем для GA4
                if ($platform === 'ga4') {
                    if (isset($data['events'][0]['name']) && 
                        $data['events'][0]['name'] === 'purchase' &&
                        isset($data['events'][0]['params']['event_id']) && 
                        $data['events'][0]['params']['event_id'] === $eventId) {
                            return true;
                    }
                }
            }
        }
        
        return false;
    }
    
public function completePurchase() {
    $logDir = __DIR__ . '/../logs';
        
        // Проверяем, есть ли transaction_id
        if (empty($this->transaction_id)) {
            error_log("[" . date('Y-m-d H:i:s') . "] Transaction ID отсутствует\n", 3, $logDir . '/errors.log');
            return;
        }
        
        // Проверяем в JSON-логах, не отправлялось ли уже это событие
        if ($this->isPurchaseAlreadySent($this->transaction_id)) {
            // Логируем пропущенный дубль
            $duplicateLog = sprintf(
                "[%s] Пропущен дубль события Purchase. Transaction ID: %s\n",
                date('Y-m-d H:i:s'),
                $this->transaction_id
            );
            file_put_contents($logDir . '/skipped_purchases.log', $duplicateLog, FILE_APPEND);
            
            return; // Выходим, не отправляем событие
        }
    
    $this->trackEvent('Purchase', ['value' => $this->amount]);
    $this->trackTikTokEvent('Purchase', ['value' => $this->amount]);
    $this->trackGA4Event('purchase', ['value' => $this->amount]);
}
public function InitiateCheckout() {
        // Подготавливаем данные для каждой платформы, используя новые методы
        $facebookData = $this->prepareFacebookInitiateCheckoutData();
        $tiktokData = $this->prepareTikTokInitiateCheckoutData();
        $ga4Data = $this->prepareGA4InitiateCheckoutData();
        
        $this->logEvent($facebookData, 'facebook'); // <-- ДОБАВЛЕНО
        $this->logEvent($tiktokData, 'tiktok');    // Уже было или будет здесь
        $this->logEvent($ga4Data, 'ga4');  
        
        // Отправляем данные
        $this->sendToFacebook($facebookData);
        $this->sendToTikTok($tiktokData);
        $this->sendToGA4($ga4Data);

        // Теперь тебе не нужно передавать 'value' => '1' или '100' вручную,
        // так как общая стоимость корзины берется динамически.
}

public function ViewContent() {
    $this->trackEvent('ViewContent', ['value' => '1', 'product_id'=>'1']);
    $this->trackTikTokEvent('ViewContent', ['value' => '1', 'product_id'=>'1']);
    $this->trackGA4Event('view_item', ['value' => '1', 'items' => [['item_id' => '1']]]);
}

public function trackEvent($eventName, $customData) {
    // Подготовка данных для Facebook
    $fbData = $this->prepareFacebookData($eventName, $customData);
    $this->logEvent($fbData, 'facebook');
    $this->sendToFacebook($fbData);
}

public function trackTikTokEvent($eventName, $customProperties) {
    // Подготовка данных для TikTok
    $tiktokData = $this->prepareTikTokData($eventName, $customProperties);
    $this->logEvent($tiktokData, 'tiktok');
    $this->sendToTikTok($tiktokData);
}

public function trackGA4Event($eventName, $customParams) {
    // Подготовка данных для GA4
    $ga4Data = $this->prepareGA4Data($eventName, $customParams);
    $this->logEvent($ga4Data, 'ga4');
    $this->sendToGA4($ga4Data);
}

private function logEvent($data, $platform) {
    $logsDir = __DIR__ . '/../logs';
    
    // Создаем папку если не существует
    if (!is_dir($logsDir)) {
        mkdir($logsDir, 0755, true);
    }
    
    // Формируем имена файлов с указанием платформы
    $jsonFile = $logsDir . '/' . $platform . '_events.json';
    $txtFile = $logsDir . '/' . $platform . '_events.log';
    
    // Подготовка данных для логирования
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'platform' => $platform,
        'data' => $data
    ];
    
    // Логирование в JSON
    $jsonData = [];
    if (file_exists($jsonFile)) {
        $jsonData = json_decode(file_get_contents($jsonFile), true) ?: [];
    }
    
    // Очистка файла если записей больше 1000
    if (count($jsonData) >= 1000) {
        $jsonData = [];
    }
    
    $jsonData[] = $logEntry;
    file_put_contents($jsonFile, json_encode($jsonData, JSON_PRETTY_PRINT));
    
    // Логирование в текстовый файл
    $textLog = sprintf(
        "[%s] %s\n%s\n\n",
        date('Y-m-d H:i:s'),
        strtoupper($platform),
        json_encode($data, JSON_PRETTY_PRINT)
    );
    file_put_contents($txtFile, $textLog, FILE_APPEND);
    
    // Определяем имя события для лога
    $eventName = 'unknown';
    
    if ($platform === 'facebook' && isset($data['data'][0]['event_name'])) {
        $eventName = $data['data'][0]['event_name'];
    } 
    elseif ($platform === 'tiktok' && isset($data['data'][0]['event'])) {
        $eventName = $data['data'][0]['event'];
    } 
    elseif ($platform === 'ga4' && isset($data['events'][0]['name'])) {
        $eventName = $data['events'][0]['name'];
    }
    
    $commonLog = sprintf(
        "[%s] [%s] %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($platform),
        $eventName
    );
    file_put_contents($logsDir . '/all_platforms.log', $commonLog, FILE_APPEND);
  
}
    /*
     * Очищает старые записи в JSON-логах (оставляет последние 50 записей)
     */
    public function cleanupOldLogs(): void {
        $logDir = __DIR__ . '/../logs';
        $platforms = ['facebook', 'tiktok', 'ga4'];
        
        foreach ($platforms as $platform) {
            $jsonFile = $logDir . '/' . $platform . '_events.json';
            
            if (!file_exists($jsonFile)) {
                continue;
            }
            
            $content = file_get_contents($jsonFile);
            $logs = json_decode($content, true);
            
            if (is_array($logs) && count($logs) > 50) {
                // Оставляем только последние 50 записей
                $logs = array_slice($logs, -50);
                file_put_contents($jsonFile, json_encode($logs, JSON_PRETTY_PRINT));
                
                error_log("[" . date('Y-m-d H:i:s') . "] Очищен лог $platform, оставлено 50 записей\n", 
                    3, $logDir . '/cleanup.log');
            }
        }
    }
 

private function sendToFacebook($data) {
    $logDir = __DIR__ . '/../logs';
    $fbLogFile = $logDir . '/facebook_api.log';

    // Проверка корректности данных перед отправкой
    $jsonData = json_encode($data);
    if ($jsonData === false) {
        $errorMessage = 'Ошибка кодирования JSON: ' . json_last_error_msg();
        error_log($errorMessage . ' - ' . date('Y-m-d H:i:s') . PHP_EOL, 3, $fbLogFile);
        return false;
    }

    $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->fbpixelId}/events";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->accessTokenfb
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_SSL_VERIFYPEER => true, // Не отключать проверку в продакшене!
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 30, // Таймаут на выполнение запроса
        CURLOPT_CONNECTTIMEOUT => 10, // Таймаут на подключение
        CURLOPT_VERBOSE => true // Включение подробного лога для диагностики
    ]);

    // Файл для лога cURL (временный)
    $verboseLog = fopen($logDir . '/curl_verbose.log', 'a+');
    curl_setopt($ch, CURLOPT_STDERR, $verboseLog);

    $result = curl_exec($ch);
    $curlInfo = curl_getinfo($ch); // Получение информации о запросе
    
    // Логирование ошибок cURL
    if (curl_errno($ch)) {
        $errorMessage = 'Ошибка cURL: ' . curl_error($ch) 
                      . ' | Код: ' . curl_errno($ch)
                      . ' | HTTP Code: ' . ($curlInfo['http_code'] ?? 'N/A');
        error_log($errorMessage . ' - ' . date('Y-m-d H:i:s') . PHP_EOL, 3, $fbLogFile);
    }

    // Логирование подробной информации (для диагностики)
    error_log("cURL Info: " . print_r($curlInfo, true) . PHP_EOL, 3, $logDir . '/curl_debug.log');

    curl_close($ch);
    fclose($verboseLog);

    if ($result === false) {
        return false;
    } else {
        error_log('Успешный ответ: ' . $result . PHP_EOL, 3, $fbLogFile);
        
        // Проверка HTTP-статуса ответа
        $responseCode = $curlInfo['http_code'] ?? 0;
        if ($responseCode >= 400) {
            error_log("HTTP Error: $responseCode - " . $result . PHP_EOL, 3, $fbLogFile);
            return false;
        }
        
        return true;
    }
}
 private function sendToTikTok($tiktokEventData) {
    $logDir = __DIR__ . '/../logs';
    $ttLogFile = $logDir . '/tiktok.log';

    // Проверка JSON
    $jsonData = json_encode($tiktokEventData, JSON_UNESCAPED_UNICODE);
    if ($jsonData === false) {
        error_log('TikTok JSON Error: ' . json_last_error_msg(), 3, $ttLogFile);
        return false;
    }

    // URL для TikTok Events API (версия 1.3)
    $url = "https://business-api.tiktok.com/open_api/v1.3/event/track/";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => [
            'Access-Token: ' . $this->accessTokentk,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    // Логирование запроса
    file_put_contents($logDir . '/tiktok_requests.log', 
        "[" . date('Y-m-d H:i:s') . "] Request:\n$jsonData\n\n", FILE_APPEND);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Логирование ответа
    $logMessage = "[" . date('Y-m-d H:i:s') . "] Response ($httpCode):\n$response\n\n";
    file_put_contents($logDir . '/tiktok_responses.log', $logMessage, FILE_APPEND);

    if ($httpCode !== 200) {
        error_log("TikTok API Error: HTTP $httpCode - $response", 3, $ttLogFile);
        return false;
    }

    $responseData = json_decode($response, true);
    if (isset($responseData['code']) && $responseData['code'] !== 0) {
        $errorMsg = $responseData['message'] ?? 'Unknown TikTok API error';
        error_log("TikTok API Error: $errorMsg", 3, $ttLogFile);
        return false;
    }

    return true;
} 
private function sendToGA4($ga4Data) {
    $logDir = __DIR__ . '/../logs';
    $gaLogFile = $logDir . '/ga4_api.log';
    $requestLogFile = $logDir . '/ga4_requests.log';
    $responseLogFile = $logDir . '/ga4_responses.log';

    // Убедимся, что директория логов существует
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Преобразуем данные в JSON
    $jsonData = json_encode($ga4Data, JSON_UNESCAPED_UNICODE);
    if ($jsonData === false) {
        $error = json_last_error_msg();
        error_log("[" . date('Y-m-d H:i:s') . "] GA4 JSON Error: $error\n\n", 3, $gaLogFile);
        return false;
    }

    // URL GA4 Measurement Protocol
    $url = "https://www.google-analytics.com/mp/collect?measurement_id={$this->gakpixelId}&api_secret={$this->accessTokenga}";

    // Логируем отправляемые данные
    file_put_contents($requestLogFile, 
        "[" . date('Y-m-d H:i:s') . "] Request to: $url\n$jsonData\n\n", FILE_APPEND);

    // Отправка cURL запроса
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Логируем ответ
    $logMessage = "[" . date('Y-m-d H:i:s') . "] Response ($httpCode):\n$response\n\n";
    file_put_contents($responseLogFile, $logMessage, FILE_APPEND);

    // Обработка ошибок
    if ($response === false) {
        error_log("[" . date('Y-m-d H:i:s') . "] GA4 cURL Error: $curlError\n", 3, $gaLogFile);
        return false;
    }

    if ($httpCode >= 400) {
        error_log("[" . date('Y-m-d H:i:s') . "] GA4 HTTP Error $httpCode - $response\n", 3, $gaLogFile);
        return false;
    }

    return true;
}

}
?>