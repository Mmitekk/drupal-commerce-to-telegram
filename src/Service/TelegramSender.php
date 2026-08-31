<?php

/**
 * @file
 * Сервис отправки сообщений в Telegram для заявок Webform.
 */

declare(strict_types=1);

namespace Drupal\webform_telegram_notifier\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Utility\Token;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Отправка заявок Webform в Telegram через Bot API.
 */
class TelegramSender {

  const CONFIG_NAME = 'webform_telegram_notifier.settings';
  const LOGGER_CHANNEL = 'webform_telegram_notifier';
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
   * Конструктор.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, Token $token, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->token = $token;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Отправляет уведомление о новой заявке (если форма включена в настройках).
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   Заявка Webform.
   *
   * @return bool
   *   TRUE, если сообщение доставлено в Telegram.
   */
  public function notifySubmission(WebformSubmissionInterface $webform_submission): bool {
    // Черновики и тестовые отправки не уведомляем.
    if ($webform_submission->isDraft()) {
      return FALSE;
    }
    if (method_exists($webform_submission, 'isTesting') && $webform_submission->isTesting()) {
      return FALSE;
    }

    $config = $this->configFactory->get(self::CONFIG_NAME);
    $bot_token = trim((string) $config->get('bot_token'));
    $chat_id = trim((string) $config->get('chat_id'));
    if ($bot_token === '' || $chat_id === '') {
      $this->loggerFactory->get(self::LOGGER_CHANNEL)->warning('Telegram-уведомление пропущено: не задан токен бота или ID чата. Укажите их на странице /admin/config/system/telegram-notify.');
      return FALSE;
    }

    $webform = $webform_submission->getWebform();
    if (!$webform) {
      return FALSE;
    }

    $enabled = array_values(array_filter((array) $config->get('enabled_webforms')));
    if (!in_array($webform->id(), $enabled, TRUE)) {
      return FALSE;
    }

    $template = (string) $config->get('message');
    if (trim($template) === '') {
      $this->loggerFactory->get(self::LOGGER_CHANNEL)->warning('Telegram-уведомление пропущено: шаблон сообщения пуст.');
      return FALSE;
    }

    $message = $this->replaceTokens($template, $webform_submission);
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
   * Подставляет токены в шаблон для конкретной заявки.
   *
   * Поддерживаются все токены Webform ([webform_submission:values:ключ]),
   * а также глобальные токены Token-модуля ([site:name], [current-date:*] и т.д.).
   *
   * @param string $text
   *   Текст шаблона.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   Заявка.
   *
   * @return string
   *   Готовый текст сообщения.
   */
  public function replaceTokens(string $text, WebformSubmissionInterface $webform_submission): string {
    $data = [
      'webform_submission' => $webform_submission,
      'node' => $webform_submission->getSourceEntity(),
      'user' => $webform_submission->getOwner(),
    ];
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
    // чтобы заявка всё равно дошла до группы.
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
