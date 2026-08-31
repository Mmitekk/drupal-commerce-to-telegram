<?php

/**
 * @file
 * Страница настроек Telegram-уведомлений о заявках.
 */

declare(strict_types=1);

namespace Drupal\webform_telegram_notifier\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Utility\Token;
use Drupal\webform_telegram_notifier\Service\TelegramSender;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Настройки модуля Webform Telegram Notifier.
 */
class SettingsForm extends ConfigFormBase {

  const SETTINGS = 'webform_telegram_notifier.settings';

  /**
   * Хранилище сущностей (для списка форм Webform).
   */
  protected EntityTypeManagerInterface $entityTypeManager;

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
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->sender = $container->get('webform_telegram_notifier.sender');
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
    return 'webform_telegram_notifier_settings';
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
    $form['telegram'] = [
      '#type' => 'details',
      '#title' => $this->t('Бот и чат'),
      '#open' => TRUE,
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
      '#description' => $this->t('Для HTML поддерживаются теги: <b>, <i>, <u>, <s>, <a href="…">, <code>, <pre>. Если разметка окажется некорректной, модуль автоматически повторит отправку простым текстом, чтобы заявка не потерялась.'),
    ];

    // --- Формы для отправки ------------------------------------------------
    $form['forms_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Формы для отправки'),
      '#open' => TRUE,
      '#description' => $this->t('Отметьте формы Webform, новые заявки которых будут доставляться в Telegram.'),
    ];

    $webforms = $this->entityTypeManager->getStorage('webform')->loadMultiple();
    uasort($webforms, static function ($a, $b) {
      return strcmp((string) $a->label(), (string) $b->label());
    });

    $options = [];
    foreach ($webforms as $webform) {
      $options[$webform->id()] = $webform->label() . ' [' . $webform->id() . ']';
    }

    if ($options !== []) {
      $form['forms_section']['enabled_webforms'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Включённые формы'),
        '#options' => $options,
        '#default_value' => (array) $config->get('enabled_webforms'),
      ];
    }
    else {
      $form['forms_section']['no_webforms'] = [
        '#markup' => '<p>' . $this->t('Формы Webform не найдены. Создайте форму в разделе «Структура → Формы Webform».') . '</p>',
      ];
    }

    // --- Шаблон сообщения --------------------------------------------------
    $form['message_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Шаблон сообщения'),
      '#open' => TRUE,
    ];

    $form['message_section']['message_template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Текст сообщения'),
      '#default_value' => $config->get('message'),
      '#rows' => 10,
      '#required' => TRUE,
      '#description' => $this->t('Используйте токены. Значения полей подставляются по ключу элемента: [webform_submission:values:КЛЮЧ] — машинное имя элемента смотрите на вкладке «Элементы» нужной формы. Токен [webform_submission:values] без ключа подставит все заполненные поля заявки.'),
    ];

    $form['message_section']['token_help'] = [
      '#theme' => 'token_tree_link',
      '#token_types' => ['webform_submission', 'webform', 'site', 'user', 'current-user', 'current-date'],
    ];

    // --- Кнопка тестовой отправки -------------------------------------------
    $form['actions']['#type'] = 'actions';
    $form['actions']['send_test'] = [
      '#type' => 'submit',
      '#button_type' => 'secondary',
      '#value' => $this->t('Отправить тестовое сообщение'),
      '#submit' => ['::sendTest'],
      '#limit_validation_errors' => [
        ['telegram', 'bot_token'],
        ['telegram', 'chat_id'],
        ['telegram', 'parse_mode'],
      ],
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

    $text = "<b>Тестовое сообщение</b>\nСайт: [site:name]\nМодуль: Webform Telegram Notifier.\n\nБот и чат настроены правильно — заявки будут доставляться сюда.";
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $telegram = (array) $form_state->getValue('telegram');
    $stored_token = trim((string) $this->config(self::SETTINGS)->get('bot_token'));
    $bot_token = trim((string) ($telegram['bot_token'] ?? ''));

    $this->config(self::SETTINGS)
      ->set('bot_token', $bot_token !== '' ? $bot_token : $stored_token)
      ->set('chat_id', trim((string) ($telegram['chat_id'] ?? '')))
      ->set('parse_mode', (string) ($telegram['parse_mode'] ?? 'html'))
      ->set('enabled_webforms', array_values(array_filter((array) $form_state->getValue(['forms_section', 'enabled_webforms']))))
      ->set('message', (string) $form_state->getValue(['message_section', 'message_template']))
      ->save();

    $this->messenger()->addStatus($this->t('Настройки Telegram-уведомлений сохранены.'));
  }

}
