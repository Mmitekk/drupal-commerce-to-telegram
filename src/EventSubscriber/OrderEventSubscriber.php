<?php

/**
 * @file
 * Подписчик на размещение заказа Drupal Commerce.
 */

declare(strict_types=1);

namespace Drupal\commerce_to_telegram\EventSubscriber;

use Drupal\commerce_to_telegram\Service\TelegramSender;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Отправка уведомления в Telegram при размещении заказа.
 *
 * Подписка повторяет паттерн самого Commerce: модуль commerce_order
 * отправляет письмо-квитанцию на то же событие
 * «commerce_order.place.post_transition» (см. OrderReceiptSubscriber).
 */
class OrderEventSubscriber implements EventSubscriberInterface {

  const EVENT_ORDER_PLACED = 'commerce_order.place.post_transition';

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
    // Если модуль state_machine (поставляется вместе с Commerce) отсутствует —
    // не подписываемся ни на какие события, чтобы не вызвать фатальную ошибку
    // при загрузке класса события.
    if (!class_exists('\Drupal\state_machine\Event\WorkflowTransitionEvent')) {
      return [];
    }

    return [
      // Событие «place» возникает один раз — в момент оформления заказа
      // (переход черновика в статус «Завершён» по завершении checkout).
      self::EVENT_ORDER_PLACED => ['onOrderPlace', -200],
    ];
  }

  /**
   * Отправляет уведомление о размещённом заказе.
   *
   * Любая ошибка не должна прерывать оформление заказа, поэтому
   * исключения перехватываются и пишутся в журнал.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   Событие перехода статуса заказа.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    // Защита от повторной отправки в рамках одного запроса.
    static $sent = [];
    $order_id = $order->id();
    if ($order_id !== NULL && isset($sent[$order_id])) {
      return;
    }

    try {
      $ok = $this->sender->notifyOrder($order);
      if ($ok && $order_id !== NULL) {
        $sent[$order_id] = TRUE;
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('commerce_to_telegram')->error('Не удалось обработать заказ @oid для отправки в Telegram: @message', [
        '@oid' => $order_id ?? 0,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
