<?php

/**
 * @file
 * Подписчик на события заказа Drupal Commerce.
 */

declare(strict_types=1);

namespace Drupal\commerce_to_telegram\EventSubscriber;

use Drupal\commerce_to_telegram\Service\TelegramSender;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Отправка уведомления в Telegram при размещении заказа.
 */
class OrderEventSubscriber implements EventSubscriberInterface {

  /**
   * Сервис отправки в Telegram.
   */
  protected TelegramSender $sender;

  /**
   * Фабрика каналов журналирования.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Конструктор.
   */
  public function __construct(TelegramSender $sender, LoggerChannelFactoryInterface $logger_factory) {
    $this->sender = $sender;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Если модуль Drupal Commerce не установлен — не подписываемся ни на
    // какие события, чтобы не вызвать фатальную ошибку при загрузке класса
    // события.
    if (!class_exists('\Drupal\commerce_order\Event\CommerceOrderEvents')) {
      return [];
    }

    return [
      \Drupal\commerce_order\Event\CommerceOrderEvents::ORDER_PLACE => ['onOrderPlace', 100],
    ];
  }

  /**
   * Отправляет уведомление о размещённом заказе.
   *
   * Любая ошибка не должна прерывать оформление заказа, поэтому
   * исключения перехватываются и пишутся в журнал.
   */
  public function onOrderPlace($event): void {
    try {
      $this->sender->notifyOrder($event->getOrder());
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('commerce_to_telegram')->error('Не удалось обработать заказ @oid для отправки в Telegram: @message', [
        '@oid' => $event->getOrder()->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
