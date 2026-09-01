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
   * Выполняет HTTP-запрос к Telegram Bot API.
   */
  protected function doRequest(string $bot_token, array $params): array {
    try {
      $response = $this->httpClient->post(self::TELEGRAM_API . '/bot' . $bot_token . '/sendMessage', [
        'form_params' => $params,
        'timeout' => 10,
        'connect_timeout' => 5,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      if (is_array($data) && !empty($data['ok'])) {
        return ['ok' => TRUE, 'description' => ''];
      }
      return [
        'ok' => FALSE,
        'description' => (string) ($data['description'] ?? 'Неизвестный ответ Telegram API.'),
      ];
    }
    catch (RequestException $e) {
      $description = $e->getMessage();
      if ($response = $e->getResponse()) {
        $body = json_decode((string) $response->getBody(), TRUE);
        if (is_array($body) && !empty($body['description'])) {
          $description = (string) $body['description'];
        }
      }
      return ['ok' => FALSE, 'description' => $description];
    }
    catch (\Exception $e) {
      return ['ok' => FALSE, 'description' => $e->getMessage()];
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
