<?php

/**
 * @file
 * Сервис отправки сообщений в Telegram (заказы Commerce и заявки Webform).
 */

declare(strict_types=1);

namespace Drupal\commerce_to_telegram\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Utility\Token;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

/**
 * Отправка уведомлений в Telegram через Bot API.
 */
class TelegramSender {

  const CONFIG_NAME = 'commerce_to_telegram.settings';
  const LOGGER_CHANNEL = 'commerce_to_telegram';
  const TELEGRAM_API = 'https://api.telegram.org';
  const MAX_LENGTH = 4096;

  /**
   * Официальные IP-адреса api.telegram.org (Telegram DC4, AS62041).
   *
   * Используются как запасные маршруты, когда DNS хостинга не резолвит
   * имя api.telegram.org или подменяет его адрес.
   */
  const TELEGRAM_FALLBACK_IPS = ['149.154.167.198', '149.154.167.220'];

  /**
   * HTTP-клиент Guzzle.
   */
  protected ClientInterface $httpClient;

  /**
   * Фабрика конфигурации.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Сервис токенов Drupal.
   */
  protected Token $token;

  /**
   * Фабрика каналов журналирования.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Обработчик модулей.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Конструктор.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, Token $token, LoggerChannelFactoryInterface $logger_factory, ModuleHandlerInterface $module_handler) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->token = $token;
    $this->loggerFactory = $logger_factory;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Отправляет уведомление о новом заказе Drupal Commerce.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Заказ.
   *
   * @return bool
   *   TRUE, если сообщение доставлено в Telegram.
   */
  public function notifyOrder(OrderInterface $order): bool {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    if (!(bool) $config->get('commerce.enabled')) {
      return FALSE;
    }

    [$bot_token, $chat_id] = $this->getCredentials();
    if ($bot_token === '' || $chat_id === '') {
      $this->loggerFactory->get(self::LOGGER_CHANNEL)->warning('Telegram-уведомление о заказе @oid пропущено: не задан токен бота или ID чата. Укажите их на странице /admin/config/system/commerce-to-telegram.', [
        '@oid' => $order->id(),
      ]);
      return FALSE;
    }

    $template = trim((string) $config->get('commerce.message'));
    if ($template === '') {
      $this->loggerFactory->get(self::LOGGER_CHANNEL)->warning('Telegram-уведомление о заказе @oid пропущено: шаблон сообщения пуст.', [
        '@oid' => $order->id(),
      ]);
      return FALSE;
    }

    $message = $this->replaceTokens($template, [
      'commerce_order' => $order,
      'user' => $order->getCustomer(),
    ]);
    if (trim(strip_tags($message)) === '') {
      $this->loggerFactory->get(self::LOGGER_CHANNEL)->warning('Telegram-уведомление о заказе @oid пропущено: после подстановки токенов сообщение пусто.', [
        '@oid' => $order->id(),
      ]);
      return FALSE;
    }

    $parse_mode = $config->get('parse_mode') === 'plain' ? NULL : 'HTML';
    $result = $this->send($bot_token, $chat_id, $message, $parse_mode);

    if (!$result['ok']) {
      $this->loggerFactory->get(self::LOGGER_CHANNEL)->error('Ошибка отправки заказа @oid в Telegram: @description', [
        '@oid' => $order->id(),
        '@description' => $result['description'],
      ]);
    }

    return $result['ok'];
  }

  /**
   * Отправляет уведомление о новой заявке Webform (если форма включена).
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   Заявка Webform.
   *
   * @return bool
   *   TRUE, если сообщение доставлено в Telegram.
   */
  public function notifySubmission(WebformSubmissionInterface $webform_submission): bool {
    if (!$this->moduleHandler->moduleExists('webform')) {
      return FALSE;
    }
    if ($webform_submission->isDraft()) {
      return FALSE;
    }
    if (method_exists($webform_submission, 'isTesting') && $webform_submission->isTesting()) {
      return FALSE;
    }

    $config = $this->configFactory->get(self::CONFIG_NAME);

    $enabled = array_values(array_filter((array) $config->get('webform.enabled_forms')));
    $webform = $webform_submission->getWebform();
    if (!$webform || !in_array($webform->id(), $enabled, TRUE)) {
      return FALSE;
    }

    [$bot_token, $chat_id] = $this->getCredentials();
    if ($bot_token === '' || $chat_id === '') {
      $this->loggerFactory->get(self::LOGGER_CHANNEL)->warning('Telegram-уведомление пропущено: не задан токен бота или ID чата. Укажите их на странице /admin/config/system/commerce-to-telegram.');
      return FALSE;
    }

    $template = trim((string) $config->get('webform.message'));
    if ($template === '') {
      $this->loggerFactory->get(self::LOGGER_CHANNEL)->warning('Telegram-уведомление пропущено: шаблон сообщения пуст.');
      return FALSE;
    }

    $message = $this->replaceTokens($template, [
      'webform_submission' => $webform_submission,
      'node' => $webform_submission->getSourceEntity(),
      'user' => $webform_submission->getOwner(),
    ]);
    if (trim(strip_tags($message)) === '') {
      $this->loggerFactory->get(self::LOGGER_CHANNEL)->warning('Telegram-уведомление пропущено: после подстановки токенов сообщение пусто (заявка @sid).', [
        '@sid' => $webform_submission->id(),
      ]);
      return FALSE;
    }

    $parse_mode = $config->get('parse_mode') === 'plain' ? NULL : 'HTML';
    $result = $this->send($bot_token, $chat_id, $message, $parse_mode);

    if (!$result['ok']) {
      $this->loggerFactory->get(self::LOGGER_CHANNEL)->error('Ошибка отправки заявки @sid в Telegram: @description', [
        '@sid' => $webform_submission->id(),
        '@description' => $result['description'],
      ]);
    }

    return $result['ok'];
  }

  /**
   * Возвращает сохранённые учётные данные бота [токен, chat_id].
   */
  protected function getCredentials(): array {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    return [
      trim((string) $config->get('bot_token')),
      trim((string) $config->get('chat_id')),
    ];
  }

  /**
   * Возвращает базовый адрес Telegram API.
   *
   * По умолчанию — официальный https://api.telegram.org, но если хостинг
   * не имеет прямого доступа к Telegram (cURL error 28), администратор
   * может указать адрес собственного релея (например, Cloudflare Worker),
   * пересылающего запросы на api.telegram.org.
   */
  protected function getApiUrl(): string {
    $custom = rtrim(trim((string) $this->configFactory->get(self::CONFIG_NAME)->get('api_url')), '/');
    return $custom !== '' ? $custom : self::TELEGRAM_API;
  }

  /**
   * Возвращает адрес прокси для запросов к Telegram (или пустую строку).
   *
   * Поддерживаются форматы Guzzle: http://, https://, socks5:// и т.д.
   */
  protected function getProxy(): string {
    return trim((string) $this->configFactory->get(self::CONFIG_NAME)->get('proxy'));
  }

  /**
   * Возвращает IP для прямого соединения с Telegram (или пустую строку).
   *
   * Аналог «curl --resolve api.telegram.org:443:IP» (в Node — https.request()
   * с прямым IP): полезен, когда DNS хостинга не резолвит api.telegram.org
   * или подменяет его адрес, а сам IP Telegram API с сервера доступен.
   */
  protected function getResolveIp(): string {
    return trim((string) $this->configFactory->get(self::CONFIG_NAME)->get('resolve_ip'));
  }

  /**
   * Скрывает токен бота в тексте ошибки (чтобы он не попадал в журнал и UI).
   */
  protected function maskToken(string $text, string $bot_token): string {
    return $bot_token === '' ? $text : str_replace($bot_token, '•••', $text);
  }

  /**
   * Подставляет токены в шаблон.
   *
   * Поддерживаются все токены соответствующих сущностей
   * ([commerce_order:…], [webform_submission:…]) и глобальные токены
   * Token-модуля ([site:name], [current-date:*] и т.д.), включая
   * дополнительные токены самого модуля ([commerce_order:items_table] и др.).
   *
   * @param string $text
   *   Текст шаблона.
   * @param array $data
   *   Данные для подстановки.
   *
   * @return string
   *   Готовый текст сообщения.
   */
  public function replaceTokens(string $text, array $data): string {
    $message = $this->token->replace($text, $data, ['clear' => TRUE]);

    // Telegram не поддерживает тег <br>: заменяем его на перевод строки.
    $message = (string) preg_replace('/<br\s*\/?>/i', "\n", $message);

    return trim($message);
  }

  /**
   * Отправляет сообщение в Telegram (низкоуровневый вызов Bot API).
   *
   * @param string $bot_token
   *   Токен бота.
   * @param string $chat_id
   *   ID чата или группы.
   * @param string $text
   *   Текст сообщения.
   * @param string|null $parse_mode
   *   'HTML', 'MarkdownV2' или NULL для простого текста.
   *
   * @return array
   *   Массив с ключами: ok (bool), description (string).
   */
  public function send(string $bot_token, string $chat_id, string $text, ?string $parse_mode = 'HTML'): array {
    $params = [
      'chat_id' => $chat_id,
      'text' => $this->truncate($text),
      'disable_web_page_preview' => TRUE,
    ];
    if ($parse_mode !== NULL && $parse_mode !== '') {
      $params['parse_mode'] = $parse_mode;
    }

    $result = $this->doRequest($bot_token, $params);

    // Если разметка некорректна — повторяем отправку простым текстом,
    // чтобы уведомление всё равно дошло до группы.
    if (!$result['ok'] && isset($params['parse_mode']) && $this->isParseError($result['description'])) {
      unset($params['parse_mode']);
      $result = $this->doRequest($bot_token, $params);
    }

    return $result;
  }

  /**
   * Выполняет HTTP-запрос к Telegram Bot API с перебором маршрутов.
   *
   * Порядок попыток (для официального хоста api.telegram.org):
   * 1. IP из настройки «Прямое соединение по IP» (если задан);
   * 2. официальные IP Telegram (TELEGRAM_FALLBACK_IPS);
   * 3. обычный DNS-запрос.
   *
   * Если Telegram ответил по сети (даже с ошибкой API — 401, 400 и т.п.),
   * перебор прекращается: дальше помогают только настройки токена/чата,
   * смена маршрута не нужна. Полная диагностика неудачных попыток
   * попадает в текст ошибки (и в журнал), чтобы по тестовой кнопке было
   * видно, какие именно маршруты пробовал модуль.
   */
  protected function doRequest(string $bot_token, array $params): array {
    $attempts = $this->buildConnectionAttempts();

    $connection_errors = [];
    foreach ($attempts as $index => $attempt) {
      $result = $this->attemptRequest($bot_token, $params, $attempt, $index > 0);
      if ($result['ok']) {
        return $result;
      }
      if (empty($result['connect'])) {
        // Telegram ответил по сети — это ошибка API, а не соединения.
        return $result;
      }
      $connection_errors[] = $attempt['label'] . ' — «' . $result['description'] . '»';
    }

    $description = 'Не удалось соединиться с Telegram API. Пробовали: '
      . implode('; ', $connection_errors)
      . '. Убедитесь, что проверка curl выполняется именно на сервере сайта (команда curl -sS --max-time 5 2ip.ru должна показывать его публичный IP), и что сам сервер открывает api.telegram.org: curl -sS --max-time 10 https://api.telegram.org/ и curl -sS --max-time 10 --resolve api.telegram.org:443:149.154.167.220 https://api.telegram.org/. Если недоступен даже прямой IP — хостинг блокирует Telegram целиком: настройте прокси или «Адрес Telegram API» (релей), либо обратитесь в поддержку хостинга.';
    return ['ok' => FALSE, 'description' => $this->maskToken($description, $bot_token)];
  }

  /**
   * Возвращает список маршрутов отправки (в порядке приоритета).
   *
   * Пиннинг IP имеет смысл только для официального хоста Telegram API:
   * свой релей в «Адрес Telegram API» должен резолвиться обычным образом.
   * Прокси сам резолвит имя — с ним пиннинг не применяется вовсе.
   */
  protected function buildConnectionAttempts(): array {
    if ($this->getProxy() !== '') {
      return [['label' => 'через прокси', 'resolve_ip' => '']];
    }

    $api_host = parse_url($this->getApiUrl(), PHP_URL_HOST) ?: 'api.telegram.org';
    $is_telegram_host = $api_host === 'api.telegram.org'
      || (function_exists('str_ends_with') && str_ends_with($api_host, '.telegram.org'));

    if (!$is_telegram_host) {
      return [['label' => 'через DNS (' . $api_host . ')', 'resolve_ip' => '']];
    }

    $attempts = [];
    $resolve_ip = $this->getResolveIp();
    $has_pinned = $resolve_ip !== '' && filter_var($resolve_ip, FILTER_VALIDATE_IP) !== FALSE;

    if ($has_pinned) {
      // Сначала IP, указанный администратором...
      $attempts[] = ['label' => 'напрямую через указанный IP ' . $resolve_ip, 'resolve_ip' => $resolve_ip];
      // ...затем остальные официальные IP Telegram...
      foreach (self::TELEGRAM_FALLBACK_IPS as $ip) {
        if ($ip !== $resolve_ip) {
          $attempts[] = ['label' => 'напрямую через IP ' . $ip, 'resolve_ip' => $ip];
        }
      }
      // ...и только потом обычный DNS (обычно он и сломан).
      $attempts[] = ['label' => 'через DNS (api.telegram.org)', 'resolve_ip' => ''];
    }
    else {
      $attempts[] = ['label' => 'через DNS (api.telegram.org)', 'resolve_ip' => ''];
      foreach (self::TELEGRAM_FALLBACK_IPS as $ip) {
        $attempts[] = ['label' => 'напрямую через IP ' . $ip, 'resolve_ip' => $ip];
      }
    }

    return $attempts;
  }

  /**
   * Одиночная попытка отправки с заданным маршрутом.
   *
   * @param array $attempt
   *   Маршрут: label (для ошибок/журнала) и resolve_ip (или пустая строка).
   * @param bool $fallback
   *   TRUE, если это не первая попытка — при успехе пишем в журнал,
   *   каким резервным маршрутом доставлено сообщение.
   *
   * @return array
   *   ok (bool), description (string), connect (bool — ошибка соединения).
   */
  protected function attemptRequest(string $bot_token, array $params, array $attempt, bool $fallback): array {
    $options = [
      'form_params' => $params,
      'timeout' => 10,
      'connect_timeout' => 5,
    ];

    // Прокси — если хостинг не имеет прямого выхода к Telegram.
    $proxy = $this->getProxy();
    if ($proxy !== '') {
      $options['proxy'] = $proxy;
    }

    // Ряд хостингов пытается соединяться с Telegram по неработающему
    // IPv6 и получает «Connection timed out» — принудительно используем
    // IPv4 (api.telegram.org всегда доступен по IPv4).
    $curl_options = [];
    if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
      $curl_options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }

    // Прямое соединение по IP — аналог «curl --resolve»: DNS-кэш libcurl
    // получает запись «хост:443 → IP», при этом имя api.telegram.org
    // сохраняется в HTTP-запросе и в проверке TLS-сертификата. Обход
    // сломанного/подменённого DNS: имя не резолвится, но сам IP Telegram
    // с сервера доступен. С прокси не сочетается — прокси сам резолвит имя.
    if ($proxy === '' && !empty($attempt['resolve_ip'])) {
      $api_host = parse_url($this->getApiUrl(), PHP_URL_HOST);
      if ($api_host !== FALSE && $api_host !== NULL && defined('CURLOPT_RESOLVE')) {
        $curl_options[CURLOPT_RESOLVE] = [$api_host . ':443:' . $attempt['resolve_ip']];
      }
    }

    if ($curl_options !== []) {
      $options['curl'] = $curl_options;
    }

    try {
      $response = $this->httpClient->post($this->getApiUrl() . '/bot' . $bot_token . '/sendMessage', $options);
      $data = json_decode((string) $response->getBody(), TRUE);
      if (is_array($data) && !empty($data['ok'])) {
        if ($fallback) {
          $this->loggerFactory->get(self::LOGGER_CHANNEL)->info('Telegram: сообщение доставлено резервным маршрутом (@label).', [
            '@label' => $attempt['label'],
          ]);
        }
        return ['ok' => TRUE, 'description' => '', 'connect' => FALSE];
      }
      return [
        'ok' => FALSE,
        'description' => (string) ($data['description'] ?? 'Неизвестный ответ Telegram API.'),
        'connect' => FALSE,
      ];
    }
    catch (ConnectException $e) {
      return [
        'ok' => FALSE,
        'description' => $this->maskToken($e->getMessage(), $bot_token),
        'connect' => TRUE,
      ];
    }
    catch (RequestException $e) {
      $description = $e->getMessage();
      if ($response = $e->getResponse()) {
        $body = json_decode((string) $response->getBody(), TRUE);
        if (is_array($body) && !empty($body['description'])) {
          // Telegram ответил по HTTP — перебор маршрутов не нужен.
          return [
            'ok' => FALSE,
            'description' => $this->maskToken($description, $bot_token),
            'connect' => FALSE,
          ];
        }
      }
      return [
        'ok' => FALSE,
        'description' => $this->maskToken($description, $bot_token),
        'connect' => TRUE,
      ];
    }
    catch (\Exception $e) {
      return [
        'ok' => FALSE,
        'description' => $this->maskToken($e->getMessage(), $bot_token),
        'connect' => FALSE,
      ];
    }
  }

  /**
   * Проверяет, связана ли ошибка с разметкой сообщения.
   */
  protected function isParseError(string $description): bool {
    return stripos($description, 'parse entities') !== FALSE;
  }

  /**
   * Обрезает сообщение до лимита Telegram (4096 символов).
   */
  protected function truncate(string $text): string {
    if (mb_strlen($text) <= self::MAX_LENGTH) {
      return $text;
    }
    return mb_substr($text, 0, self::MAX_LENGTH - 40) . "\n\n[сообщение обрезано]";
  }

}
