<?php

/**
 * @file
 * Страница настроек Telegram-уведомлений (заказы Commerce и заявки Webform).
 */

declare(strict_types=1);

namespace Drupal\commerce_to_telegram\Form;

use Drupal\commerce_to_telegram\Service\TelegramSender;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Utility\Token;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Настройки модуля Commerce to Telegram.
 */
class SettingsForm extends ConfigFormBase {

  const SETTINGS = 'commerce_to_telegram.settings';

  /**
   * Сервис отправки в Telegram.
   */
  protected TelegramSender $sender;

  /**
   * Сервис токенов.
   */
  protected Token $token;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new static(
      $container->get('config.factory'),
      $container->has('typed_config_manager') ? $container->get('typed_config_manager') : NULL
    );
    $instance->sender = $container->get('commerce_to_telegram.sender');
    $instance->token = $container->get('token');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, $typed_config_manager = NULL) {
    parent::__construct($config_factory);
    // Drupal 10.2+: передаём менеджер типизированных конфигов, если ядро
    // его поддерживает (свойство объявлено в ConfigFormBase с 10.2).
    if ($typed_config_manager !== NULL && property_exists($this, 'typedConfigManager')) {
      $this->typedConfigManager = $typed_config_manager;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_to_telegram_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::SETTINGS);
    $stored_token = trim((string) $config->get('bot_token'));

    // --- Бот и чат ---------------------------------------------------------
    // #tree = TRUE обязателен: без него значения детей не вкладываются
    // в массив 'telegram', и getValue('telegram') возвращает NULL
    // (настройки «не сохранялись» именно поэтому).
    $form['telegram'] = [
      '#type' => 'details',
      '#title' => $this->t('Бот и чат'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['telegram']['bot_token'] = [
      '#type' => 'password',
      '#title' => $this->t('Токен бота'),
      '#description' => $stored_token !== ''
        ? $this->t('Токен сохранён (@hint…). Оставьте поле пустым, чтобы не менять его.', [
          '@hint' => mb_substr($stored_token, 0, 10),
        ])
        : $this->t('Получите токен у @BotFather в Telegram: отправьте ему команду /newbot и следуйте инструкциям.'),
      '#attributes' => ['autocomplete' => 'off'],
      '#required' => $stored_token === '',
    ];

    $form['telegram']['chat_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ID чата или группы'),
      '#default_value' => $config->get('chat_id'),
      '#required' => TRUE,
      '#description' => $this->t('Например: -1001234567890 (супергруппа) или -123456789 (обычная группа). Бот должен быть участником группы (для канала — администратором с правом публикации). Узнать ID можно так: добавьте бота @getidsbot в группу, либо перешлите любое сообщение из группы боту @getmyid_bot.'),
    ];

    $form['telegram']['parse_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Формат сообщения'),
      '#options' => [
        'html' => $this->t('HTML'),
        'plain' => $this->t('Простой текст'),
      ],
      '#default_value' => $config->get('parse_mode') ?: 'html',
      // ВАЖНО: названия тегов передаются через @-плейсхолдер, который
      // экранирует HTML. Раньше теги вставлялись в описание «как есть»,
      // браузер считал их настоящими элементами: незакрытый <s> зачёркивал
      // всю страницу ниже, <a> делал тексты ссылками и т.д.
      '#description' => $this->t('Для HTML поддерживаются теги: @tags. Если разметка окажется некорректной, модуль автоматически повторит отправку простым текстом, чтобы уведомление не потерялось.', [
        '@tags' => '<b>, <i>, <u>, <s>, <a>, <code>, <pre>',
      ]),
    ];

    // Если хостинг не имеет прямого выхода к Telegram (cURL error 28),
    // администратор может указать свой релей или прокси.
    $form['telegram']['api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Адрес Telegram API'),
      '#default_value' => $config->get('api_url') ?: 'https://api.telegram.org',
      '#description' => $this->t('Обычно менять не нужно. Если соединение с api.telegram.org блокируется на стороне хостинга (ошибка «cURL error 28: Connection timed out»), укажите здесь адрес собственного релея — например, бесплатного Cloudflare Worker, пересылающего запросы на api.telegram.org. Инструкция — в README модуля, раздел «Если хостинг не открывает api.telegram.org».'),
    ];

    $form['telegram']['proxy'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Прокси для доступа к Telegram (опционально)'),
      '#default_value' => $config->get('proxy'),
      '#description' => $this->t('Применяется, только если заполнено. Форматы: http://хост:порт, https://хост:порт или socks5://логин:пароль@хост:порт. Альтернатива прокси — релей в поле «Адрес Telegram API».'),
    ];

    // --- Заказы Drupal Commerce ----------------------------------------------
    $form['commerce'] = [
      '#type' => 'details',
      '#title' => $this->t('Заказы Drupal Commerce'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    if (\Drupal::moduleHandler()->moduleExists('commerce_order')) {
      $form['commerce']['commerce_enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Отправлять новые заказы в Telegram'),
        '#default_value' => (bool) $config->get('commerce.enabled'),
        '#description' => $this->t('Уведомление отправляется в момент размещения заказа (переход в статус «Завершён» при оформлении).'),
      ];

      $form['commerce']['commerce_message'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Шаблон сообщения о заказе'),
        '#default_value' => $config->get('commerce.message'),
        '#rows' => 10,
        '#description' => $this->t('Токены заказа: [commerce_order:order_number], [commerce_order:total_price], [commerce_order:mail], [commerce_order:state]. Дополнительные токены модуля: [commerce_order:items_table] (все позиции заказа), [commerce_order:billing_address], [commerce_order:shipping_address].'),
      ];

      if (\Drupal::moduleHandler()->moduleExists('token')) {
        $form['commerce']['commerce_token_help'] = [
          '#theme' => 'token_tree_link',
          '#token_types' => ['commerce_order', 'site', 'user', 'current-user', 'current-date'],
        ];
      }
    }
    else {
      $form['commerce']['commerce_missing'] = [
        '#markup' => '<p>' . $this->t('Модуль Drupal Commerce не установлен — интеграция с заказами недоступна. Раздел появится автоматически после установки Drupal Commerce.') . '</p>',
      ];
    }

    // --- Формы Webform --------------------------------------------------------
    $form['webform_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Формы Webform (опционально)'),
      '#open' => FALSE,
      '#tree' => TRUE,
    ];

    if (\Drupal::moduleHandler()->moduleExists('webform')) {
      $form['webform_section']['webform_hint'] = [
        '#markup' => '<p>' . $this->t('Отметьте формы Webform, новые заявки которых будут доставляться в Telegram.') . '</p>',
      ];

      $webforms = \Drupal::entityTypeManager()->getStorage('webform')->loadMultiple();
      uasort($webforms, static function ($a, $b) {
        return strcmp((string) $a->label(), (string) $b->label());
      });

      $options = [];
      foreach ($webforms as $webform) {
        $options[$webform->id()] = $webform->label() . ' [' . $webform->id() . ']';
      }

      if ($options !== []) {
        $form['webform_section']['enabled_webforms'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Включённые формы'),
          '#options' => $options,
          '#default_value' => (array) $config->get('webform.enabled_forms'),
        ];
      }
      else {
        $form['webform_section']['no_webforms'] = [
          '#markup' => '<p>' . $this->t('Формы Webform не найдены. Создайте форму в разделе «Структура → Формы Webform».') . '</p>',
        ];
      }

      $form['webform_section']['webform_message'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Шаблон сообщения о заявке'),
        '#default_value' => $config->get('webform.message'),
        '#rows' => 10,
        '#description' => $this->t('Значения полей подставляются по ключу элемента: [webform_submission:values:КЛЮЧ]. Токен [webform_submission:values] без ключа подставит все заполненные поля заявки.'),
      ];

      if (\Drupal::moduleHandler()->moduleExists('token')) {
        $form['webform_section']['webform_token_help'] = [
          '#theme' => 'token_tree_link',
          '#token_types' => ['webform_submission', 'webform', 'site', 'user', 'current-user', 'current-date'],
        ];
      }
    }
    else {
      $form['webform_section']['webform_missing'] = [
        '#markup' => '<p>' . $this->t('Модуль Webform не установлен — интеграция с формами недоступна.') . '</p>',
      ];
    }

    // --- Кнопка тестовой отправки -------------------------------------------
    // Кнопка сначала СОХРАНЯЕТ настройки, затем отправляет тест —
    // конфигурация и фактическая отправка всегда согласованы.
    $form['actions']['#type'] = 'actions';
    $form['actions']['send_test'] = [
      '#type' => 'submit',
      '#button_type' => 'secondary',
      '#value' => $this->t('Сохранить и отправить тестовое сообщение'),
      '#submit' => ['::submitForm', '::sendTest'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Тестовая отправка сообщения в Telegram.
   */
  public function sendTest(array $form, FormStateInterface $form_state): void {
    $telegram = (array) $form_state->getValue('telegram');
    $stored_token = trim((string) $this->config(self::SETTINGS)->get('bot_token'));
    $bot_token = trim((string) ($telegram['bot_token'] ?? '')) ?: $stored_token;
    $chat_id = trim((string) ($telegram['chat_id'] ?? ''));
    $parse_mode = (($telegram['parse_mode'] ?? 'html') === 'plain') ? NULL : 'HTML';

    if ($bot_token === '' || $chat_id === '') {
      $this->messenger()->addError($this->t('Укажите токен бота и ID чата перед тестовой отправкой.'));
      return;
    }

    $text = "<b>Тестовое сообщение</b>\nСайт: [site:name]\nМодуль: Commerce to Telegram.\n\nБот и чат настроены правильно — уведомления будут доставляться сюда.";
    $text = $this->token->replace($text, [], ['clear' => TRUE]);

    $result = $this->sender->send($bot_token, $chat_id, $text, $parse_mode);
    if ($result['ok']) {
      $this->messenger()->addStatus($this->t('Тестовое сообщение отправлено. Проверьте чат в Telegram.'));
    }
    else {
      $this->messenger()->addError($this->t('Telegram вернул ошибку: @error', [
        '@error' => $result['description'],
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();

    $api_url = trim((string) ($values['telegram']['api_url'] ?? ''));
    if ($api_url !== '' && !preg_match('#^https?://#i', $api_url)) {
      $form_state->setErrorByName('telegram][api_url', $this->t('Адрес Telegram API должен начинаться с http:// или https://.'));
    }

    $proxy = trim((string) ($values['telegram']['proxy'] ?? ''));
    if ($proxy !== '' && !preg_match('#^(https?|socks[45]h?)://#i', $proxy)) {
      $form_state->setErrorByName('telegram][proxy', $this->t('Адрес прокси должен начинаться с http://, https:// или socks5://.'));
    }

    $commerce_enabled = (bool) ($values['commerce']['commerce_enabled'] ?? FALSE);
    $commerce_message = trim((string) ($values['commerce']['commerce_message'] ?? ''));
    if ($commerce_enabled && $commerce_message === '') {
      $form_state->setErrorByName('commerce][commerce_message', $this->t('Шаблон сообщения о заказе не может быть пустым при включённой отправке.'));
    }

    $enabled_webforms = array_filter((array) ($values['webform_section']['enabled_webforms'] ?? []));
    $webform_message = trim((string) ($values['webform_section']['webform_message'] ?? ''));
    if ($enabled_webforms !== [] && $webform_message === '') {
      $form_state->setErrorByName('webform_section][webform_message', $this->t('Шаблон сообщения о заявке не может быть пустым при включённых формах.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $telegram = (array) $form_state->getValue('telegram');
    $commerce = (array) $form_state->getValue('commerce');
    $webform_section = (array) $form_state->getValue('webform_section');

    $stored_token = trim((string) $this->config(self::SETTINGS)->get('bot_token'));
    $bot_token = trim((string) ($telegram['bot_token'] ?? ''));

    $this->config(self::SETTINGS)
      ->set('bot_token', $bot_token !== '' ? $bot_token : $stored_token)
      ->set('chat_id', trim((string) ($telegram['chat_id'] ?? '')))
      ->set('parse_mode', (string) ($telegram['parse_mode'] ?? 'html'))
      ->set('api_url', rtrim(trim((string) ($telegram['api_url'] ?? '')), '/'))
      ->set('proxy', trim((string) ($telegram['proxy'] ?? '')))
      ->set('commerce.enabled', (bool) ($commerce['commerce_enabled'] ?? FALSE))
      ->set('commerce.message', (string) ($commerce['commerce_message'] ?? ''))
      ->set('webform.enabled_forms', array_values(array_filter((array) ($webform_section['enabled_webforms'] ?? []))))
      ->set('webform.message', (string) ($webform_section['webform_message'] ?? ''))
      ->save();

    $this->messenger()->addStatus($this->t('Настройки Telegram-уведомлений сохранены.'));
  }

}
