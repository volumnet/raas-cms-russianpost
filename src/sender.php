<?php
/**
 * Файл класса отправки заказов через Почту России
 */
namespace RAAS\CMS\RussianPost;

use Exception;
use SOME\Text;
use RAAS\Application;
use RAAS\CMS\Shop\Order;
use RAAS\CMS\Shop\Order_History;

/**
 * Класс отправки заказов через Почту России
 *
 * Предустановленные форматы данных:
 * <pre>[Нормализованный адрес] => array_merge(
 *     [SendAPI::Нормализованный адрес],
 *     [
 *         'stringifiedAddress' => string Входной адрес в текстовом виде,
 *         'isCorrect' => bool Адрес нормализован корректно
 *     ]
 * )
 *
 * [Данные заказа для отправки] => [
 *     'address-type-to' => ('DEFAULT')|
 *                          ('PO_BOX' Абонентский ящик)|
 *                          ('DEMAND' До востребования) Тип адреса,
 *     'area-to' ?=> string Район,
 *     'building-to' ?=> string Часть здания: Строение,
 *     'comment' => string Комментарий:Номер заказа. Внешний идентификатор заказа, который формируется отправителем,
 *     'corpus-to' ?=> string Часть здания: Корпус,
 *     'courier' => false Отметка 'Курьер',
 *     'fragile' => false Установлена ли отметка 'Осторожно/Хрупкое'?,
 *     'given-name' => string Имя получателя,
 *     'hotel-to' ?=> string Название гостиницы,
 *     'house-to' => string Часть адреса: Номер здания,
 *     'index-to' => int Почтовый индекс,
 *     'insr-value' ?=> int Сумма объявленной ценности (копейки),
 *     'letter-to' ?=> string Часть здания: Литера,
 *     'location-to' ?=> string Микрорайон,
 *     'mail-category' => ('ORDERED' Заказное - для оплаченного заказа)|
 *                        (
 *                            'WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY'
 *                            С объявленной ценностью и наложенным платежом -
 *                            для неоплаченного заказа
 *                        )
 *                        Категория РПО,
 *     'mail-direct' => 643 (Россия) Код страны,
 *     'mail-type' => ('POSTAL_PARCEL' Посылка "нестандартная") Вид РПО,
 *     'manual-address-input' => false Отметка 'Ручной ввод адреса',
 *     'mass' => int Вес РПО (в граммах),
 *     'middle-name' ?=> string Отчество получателя,
 *     'num-address-type-to' ?=> string Номер для а/я, войсковая часть, войсковая часть ЮЯ, полевая почта,
 *     'order-num' => string Номер заказа. Внешний идентификатор заказа, который формируется отправителем,
 *     'payment' ?=> int Сумма наложенного платежа (копейки),
 *     'payment-method' => ('CASHLESS' Безналичный расчет) Способ оплаты,
 *     'place-to' => string Населенный пункт,
 *     'recipient-name' => string Наименование получателя одной строкой (ФИО, наименование организации),
 *     'region-to' => string Область, регион,
 *     'room-to' ?=> string Часть здания: Номер помещения,
 *     'slash-to' ?=> string Часть здания: Дробь,
 *     'sms-notice-recipient' ?=> int Признак услуги SMS уведомления,
 *     'str-index-to' => string Почтовый индекс (буквенно-цифровой),
 *     'street-to' => string Часть адреса: Улица,
 *     'surname' => string Фамилия получателя,
 *     'tel-address' ?=> int Телефон получателя (может быть обязательным для некоторых типов отправлений),
 *     'with-order-of-notice' => false Отметка 'С заказным уведомлением',
 *     'with-simple-notice' => false Отметка 'С простым уведомлением',
 *     'transport-type' => ('COMBINED' Комбинированный) Возможный вид транспортировки,
 *     'completeness-checking' => false Отметка 'с проверкой комлектности'
 * ]</pre>
 */
class Sender
{
    /**
     * Предустановленные данные заказа
     * @var array
     */
    public $predefinedData = [
        'courier' => false,
        'fragile' => false,
        'mail-direct' => 643, // Россия
        'mail-type' => 'POSTAL_PARCEL',
        'manual-address-input' => false,
        'payment-method' => 'CASHLESS',
        'with-order-of-notice' => false,
        'with-simple-notice' => false,
        'transport-type' => 'COMBINED',
        'completeness-checking' => false,
    ];


    /**
     * Переопределяемые данные заказа
     * @var array
     */
    public $overrideData = [];

    /**
     * Компоненты адреса заказа
     * @var string[] Список URN полей
     */
    public $addressComponents = [
        'post_code',
        'region',
        'city',
        'street',
        'house',
        'apartment'
    ];

    /**
     * Компоненты имени получателя
     * @var string[] Список URN полей
     */
    public $fullNameComponents = [
        'last_name',
        'first_name',
        'second_name',
    ];

    /**
     * Переменная индекса у заказа
     * @var string
     */
    public $postCodeVar = 'post_code';

    /**
     * Переменная фамилии получателя
     * @var string
     */
    public $lastNameVar = 'last_name';

    /**
     * Переменная имени получателя
     * @var string
     */
    public $firstNameVar = 'first_name';

    /**
     * Переменная отчества получателя
     * @var string
     */
    public $secondNameVar = 'second_name';

    /**
     * Переменная телефона получателя
     * @var string
     */
    public $phoneVar = 'phone';

    /**
     * Переменная веса заказа
     * @var string
     */
    public $weightVar = 'weight';

    /**
     * Переменная веса товара
     * @var string
     */
    public $itemWeightVar = 'weight';

    /**
     * Переменная трек-номера заказа
     * @var string
     */
    public $barcodeVar = 'barcode';

    /**
     * Множитель веса
     * @var float
     */
    public $weightRatio = 1;

    /**
     * Вес товара по умолчанию, кг
     * @var float
     */
    public $defaultItemWeight = 0.1;

    /**
     * Вес заказа по умолчанию
     * @var float
     */
    public $defaultWeight = 1;

    /**
     * Статус "Отправлен Почтой России"
     * @var int
     */
    public $sentStatusId = 0;

    /**
     * Экземпляр API отправки
     * @var SendAPI;
     */
    protected $api;

    /**
     * Конструктор класса
     * @param string $login Логин
     * @param string $password Пароль
     * @param string $token Токен авторизации приложения
     */
    public function __construct($login, $password, $token)
    {
        $this->api = new SendAPI($login, $password, $token);
    }


    /**
     * Получить строку адреса у заказа
     * @param Order $order Заказ
     * @return string
     */
    public function stringifyOrderAddress(Order $order)
    {
        $arr = [];
        foreach ($this->addressComponents as $key) {
            if ($val = trim($order->$key)) {
                $arr[] = $val;
            }
        }
        return implode(', ', $arr);
    }


    /**
     * Нормализация адресов заказов
     * @param Order[] $orders Заказы
     * @return array <pre>array<
     *     string[] ID# заказа => [Нормализованный адрес]
     * ></pre> (только для тех заказов, у которых получилось определить адрес)
     */
    public function normalizeOrdersAddresses(array $orders = [])
    {
        $addresses = [];
        foreach ($orders as $i => $order) {
            $stringifiedAddress = $this->stringifyOrderAddress($order);
            $addresses[] = trim($stringifiedAddress);
        }
        $addresses = array_filter($addresses);
        $addressesMapping = array_keys($addresses);
        $addresses = array_values($addresses);

        $normalizedAddresses = [];
        try {
            $normalizedAddresses = $this->api->normalizeAddresses($addresses);
        } catch (Exception $e) {
        }
        $result = [];
        foreach ($normalizedAddresses as $i => $res) {
            $j = $addressesMapping[$i];
            $order = $orders[$j];
            $res['stringifiedAddress'] = $addresses[$j];
            $res['isCorrect'] = (
                in_array(
                    $res['quality-code'],
                    ['GOOD', 'POSTAL_BOX', 'ON_DEMAND', 'UNDEF_05']
                ) && in_array(
                    $res['validation-code'],
                    ['VALIDATED', 'OVERRIDDEN', 'CONFIRMED_MANUALLY']
                ) && (
                    trim($res['index']) == trim($order->{$this->postCodeVar})
                )
            );
            $result[trim($order->id)] = $res;
        }
        return $result;
    }


    /**
     * Функция расчета веса
     * @param Order $order Заказ
     * @return float Вес в кг
     */
    public function getWeight(Order $order)
    {
        $result = 0;

        if ($orderWeight = $order->{$this->weightVar}) {
            $result = $orderWeight;
        } else {
            foreach ($order->items as $item) {
                if (!($weight = (float)$item->{$this->itemWeightVar})) {
                    $weight = (float)$this->defaultItemWeight;
                }
                $result += ($weight * $item->amount);
            }
            if (!$result) {
                $result = $this->defaultWeight;
            }
        }
        $result *= $this->weightRatio;
        return $result;
    }


    /**
     * Формирует данные для регистрации заказа в сервисе отправки Почты России
     * @param Order $order Заказ
     * @param array $normalizedAddress <pre>[Нормализованный адрес]</pre>
     * @return array|null <pre>[Данные заказа для отправки]<pre> или null,
     *                    если нормализованный адрес не задан
     */
    public function getOrderDataToSend(Order $order, array $normalizedAddress)
    {
        if (!$normalizedAddress) {
            return null;
        }
        $recipientNameArr = [];
        foreach ($this->fullNameComponents as $key) {
            if ($val = trim($order->$key)) {
                $recipientNameArr[] = $val;
            }
        }
        $recipientFullName = trim(implode(' ', $recipientNameArr));
        $weight = $this->getWeight($order);

        $result = array_merge($this->predefinedData, [
            'comment' => trim($order->id),
            'given-name' => trim($order->{$this->firstNameVar}),
            'mass' => ceil($weight * 1000),
            'order-num' => trim($order->id),
            'recipient-name' => $recipientFullName,
            // 'sms-notice-recipient' => 0, // Узнать, что это
            'surname' => trim($order->{$this->lastNameVar}),
        ]);
        // Отчество
        if ($secondName = trim($order->{$this->secondNameVar})) {
            $result['middle-name'] = $secondName;
        }

        // Адрес доставки
        foreach ([
            'address-type',
            'area',
            'building',
            'corpus',
            'hotel',
            'house',
            'index',
            'letter',
            'location',
            'num-address-type',
            'place',
            'region',
            'room',
            'slash',
            'street',
        ] as $key) {
            if (isset($normalizedAddress[$key])) {
                $result[$key . '-to'] = $normalizedAddress[$key];
            }
        }
        if (isset($result['index-to'])) {
            $result['str-index-to'] = $result['index-to'];
            $result['index-to'] = (int)$result['index-to'];
        }

        // Категория РПО, объявленная ценность и сумма наложенного платежа
        if ($order->paid) {
            $result['mail-category'] = 'ORDERED';
        } else {
            $result['mail-category'] = 'WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY';
            $result['insr-value'] = $result['payment'] = ceil($order->sum * 100);
        }


        // Телефон
        $beautifiedPhone = Text::beautifyPhone($order->{$this->phoneVar});
        if (mb_strlen($beautifiedPhone) == '10') {
            $result['tel-address'] = '7' . $beautifiedPhone;
        }

        $result = array_merge($result, $this->overrideData);

        return $result;
    }


    /**
     * Формирует данные для регистрации заказов в сервисе отправки Почты России
     * @param Order[] $orders Заказы
     * @param array $normalizedAddresses <pre>array<
     *     string[] ID# заказа => [Нормализованный адрес]
     * ></pre>
     * @return array <pre>array<
     *     string[] ID# заказа => [Данные заказа для отправки]
     * ></pre>
     */
    public function getOrdersDataToSend(
        array $orders,
        array $normalizedAddresses
    ) {
        $result = [];
        foreach ($orders as $order) {
            $orderData = $this->getOrderDataToSend(
                $order,
                $normalizedAddresses[$order->id]
            );
            $result[trim($order->id)] = $orderData;
        }
        return $result;
    }


    /**
     * Отправляет данные о заказах
     * @param array<Order> $orders Заказы
     */
    public function sendOrders(array $orders = [])
    {
        $result = [];

        $normalizedOrdersAddresses = $this->normalizeOrdersAddresses($orders);
        $ordersDataToSend = $this->getOrdersDataToSend(
            $orders,
            $normalizedOrdersAddresses
        );
        $ordersToSend = $data = [];
        foreach ($orders as $i => $order) {
            if ($normalizeOrderAddress = $normalizedOrdersAddresses[$order->id]) {
                $order->russianPostNormalizedAddress = $normalizeOrderAddress;
            }
            if ($orderDataToSend = $ordersDataToSend[$order->id]) {
                $data[] = $orderDataToSend;
                $ordersToSend[] = $order;
            }
        }

        $result = $this->api->method('user/backlog', $data, 'PUT');

        if ($result['errors']) {
            foreach ($result['errors'] as $error) {
                $errorArr = array_map(function ($x) {
                    return $x['description'];
                }, $error['error-codes']);
                $errorText = implode("\n", $errorArr);
                $ordersToSend[$error['position']]->russianPostErrors = $errorText;
            }
        }

        $ordersSucceeded = [];
        foreach ($ordersToSend as $i => $order) {
            if (!$order->russianPostErrors) {
                $ordersSucceeded[] = $order;
            }
        }

        if ($this->sentStatusId) {
            if ($result['result-ids']) {
                foreach ($result['result-ids'] as $i => $resultId) {
                    $trackIdData = $this->api->method(
                        'backlog/' . $resultId,
                        [],
                        false
                    );
                    $barcode = $trackIdData['barcode'];
                    $order = $ordersSucceeded[$i];
                    $history = new Order_History([
                        'uid' => (int)Application::i()->user->id,
                        'order_id' => (int)$order->id,
                        'status_id' => (int)$this->sentStatusId,
                        'paid' => $order->paid,
                        'post_date' => date('Y-m-d H:i:s'),
                        'description' => 'Передан в Почту России с ID# ' . $resultId
                                      . ($barcode . ', трек-номер ' . $barcode),
                    ]);
                    $history->commit();
                    $order->status_id = (int)$this->sentStatusId;
                    $order->commit();
                    $order->russianPostId = $resultId;
                    if ($barcode &&
                        ($barcodeField = $order->fields[$this->barcodeVar])
                    ) {
                        $barcodeField->deleteValues();
                        $barcodeField->addValue($barcode);
                    }
                }
            }
        }
    }
}
