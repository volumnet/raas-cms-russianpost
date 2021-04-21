<?php
/**
 * Файл класса отправки заказов через Почту России
 */
namespace RAAS\CMS\RussianPost;

use Exception;
use SOME\Text;
use RAAS\Application;
use RAAS\CMS\Material;
use RAAS\CMS\Shop\Cart;
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
        'completeness-checking' => false, // Признак услуги проверки комплектности
        'contents-checking' => true, // Признак услуги проверки вложения
        'courier' => false, // Отметка "Курьер"
        'entries-type' => 'GIFT', // Категория вложения
        'fragile' => false, // Отметка "Осторожно/Хрупко"
        'index-from' => null, // Почтовый индекс объекта почтовой связи места приема
        'inventory' => true, // Опись вложения
        'mail-category' => 'WITH_DECLARED_VALUE',
        'mail-direct' => 643, // Россия
        'mail-type' => 'POSTAL_PARCEL', // Категория РПО
        'manual-address-input' => false,
        'notice-payment-method' => 'CASHLESS', // Способ оплаты уведомеления
        'payment-method' => 'CASHLESS', // Способ оплаты
        'sms-notice-recipient' => 0, // Признак услуги SMS уведомления
        'transport-type' => 'COMBINED', // Вид транспортировки
        'vsd' => true, // Возврат сопроводительныйх документов
        'with-electronic-notice' => true, // Отметка 'С электронным уведомлением'
        'with-order-of-notice' => false, // Отметка 'С заказным уведомлением'
        'with-simple-notice' => false, // Отметка 'С простым уведомлением'
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
     * Переменная длины заказа
     * @var string
     */
    public $lengthVar = 'length';

    /**
     * Переменная длины товара
     * @var string
     */
    public $itemLengthVar = 'length';

    /**
     * Множитель длины
     * @var float
     */
    public $lengthRatio = 1;

    /**
     * Длина товара по умолчанию, см
     * @var float
     */
    public $defaultItemLength = 10;

    /**
     * Длина заказа по умолчанию, см
     * @var float
     */
    public $defaultLength = 20;

    /**
     * Переменная ширины заказа
     * @var string
     */
    public $widthVar = 'width';

    /**
     * Переменная ширины товара
     * @var string
     */
    public $itemWidthVar = 'width';

    /**
     * Множитель ширины
     * @var float
     */
    public $widthRatio = 1;

    /**
     * Ширина товара по умолчанию, см
     * @var float
     */
    public $defaultItemWidth = 10;

    /**
     * Ширина заказа по умолчанию, см
     * @var float
     */
    public $defaultWidth = 20;

    /**
     * Переменная высоты заказа
     * @var string
     */
    public $heightVar = 'height';

    /**
     * Переменная высоты товара
     * @var string
     */
    public $itemHeightVar = 'height';

    /**
     * Множитель высоты
     * @var float
     */
    public $heightRatio = 1;

    /**
     * Высота товара по умолчанию, см
     * @var float
     */
    public $defaultItemHeight = 10;

    /**
     * Высота заказа по умолчанию, см
     * @var float
     */
    public $defaultHeight = 20;

    /**
     * Переменная трек-номера заказа
     * @var string
     */
    public $barcodeVar = 'barcode';

    /**
     * Статус "Отправлен Почтой России"
     * @var int
     */
    public $sentStatusId = 0;

    /**
     * Стоимость доставки по умолчанию, руб.
     * @var int
     */
    public $defaultDeliveryPrice = 200;

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
     * Получить строку адреса у корзины
     * @param array $postData POST-данные
     * @return string
     */
    public function stringifyCartAddress(array $postData = [])
    {
        $arr = [];
        foreach ($this->addressComponents as $key) {
            if ($val = trim($postData[$key])) {
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
     * Нормализация адрес корзины
     * @param array $postData POST-данные
     * @return array [Нормализованный адрес]
     */
    public function normalizeCartAddress(array $postData = [])
    {
        $stringifiedAddress = $this->stringifyCartAddress($postData);
        $addresses = [trim($stringifiedAddress)];

        $normalizedAddresses = [];
        try {
            $normalizedAddresses = $this->api->normalizeAddresses($addresses);
        } catch (Exception $e) {
        }
        $result = [];
        $result = array_shift(array_values($normalizedAddresses));
        $result['stringifiedAddress'] = $stringifiedAddress;
        $result['isCorrect'] = (
            in_array(
                $result['quality-code'],
                ['GOOD', 'POSTAL_BOX', 'ON_DEMAND', 'UNDEF_05']
            ) && in_array(
                $result['validation-code'],
                ['VALIDATED', 'OVERRIDDEN', 'CONFIRMED_MANUALLY']
            ) && (
                trim($result['index']) == trim($postData[$this->postCodeVar])
            )
        );
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
     * Функция расчета размера
     * @param Order $order Заказ
     * @param 'string' $var <pre>'width'|'lehgth'|'height'</pre> Измерение
     * @return array int Измерение в см
     */
    public function getDimension(Order $order, $var)
    {
        $result = 0;

        $sizeVar = $this->{$var . 'Var'};
        $itemSizeVar = $this->{'item' . ucfirst($var) . 'Var'};
        $defaultItemSize = $this->{'defaultItem' . ucfirst($var)};
        $defaultSize = $this->{'default' . ucfirst($var)};
        $sizeRatio = $this->{$var . 'Ratio'};

        if ($orderSize = $order->{$sizeVar}) {
            $result = $orderSize;
        } else {
            if (count($order->items) == 1) {
                $item = $order->items[0];
                if (!($size = (int)$item->{$itemSizeVar})) {
                    $size = (int)$defaultItemSize;
                }
                $result = $size;
            }
            if (!$result) {
                $result = $defaultSize;
            }
        }
        $result *= $sizeRatio;
        return $result;
    }


    /**
     * Функция расчета веса корзины
     * @return float Вес в кг
     */
    public function getCartWeight(Cart $cart)
    {
        $result = 0;

        foreach ($cart->items as $cartItem) {
            $item = new Material($cartItem->id);
            if (!($weight = (float)$item->{$this->itemWeightVar})) {
                $weight = (float)$this->defaultItemWeight;
            }
            $result += ($weight * $item->amount);
        }
        if (!$result) {
            $result = $this->defaultWeight;
        }
        $result *= $this->weightRatio;
        return $result;
    }


    /**
     * Функция расчета размера из корзины
     * @param Cart $cart Заказ
     * @param 'string' $var <pre>'width'|'lehgth'|'height'</pre> Измерение
     * @return array int Измерение в см
     */
    public function getCartDimension(Cart $cart, $var)
    {
        $result = 0;

        $sizeVar = $this->{$var . 'Var'};
        $itemSizeVar = $this->{'item' . ucfirst($var) . 'Var'};
        $defaultItemSize = $this->{'defaultItem' . ucfirst($var)};
        $defaultSize = $this->{'default' . ucfirst($var)};
        $sizeRatio = $this->{$var . 'Ratio'};

        if (count($cart->items) == 1) {
            $cartItem = $cart->items[0];
            $item = new Material($cartItem->id);
            if (!($size = (int)$item->{$itemSizeVar})) {
                $size = (int)$defaultItemSize;
            }
            $result = $size;
        }
        if (!$result) {
            $result = $defaultSize;
        }
        $result *= $sizeRatio;
        return $result;
    }


    /**
     * Расчет типоразмера
     * @param int $x Длина
     * @param int $y Ширина
     * @param int $z Высота
     * @return string <pre>'S'|'M'|'L'|'XL'|'OVERSIZED'</pre>
     */
    public function getDimensionType($x, $y, $z)
    {
        $sizes = [(int)$x, (int)$y, (int)$z];
        sort($sizes);
        list($x, $y, $z) = $sizes;
        if (($x <= 260) && ($y <= 170) && ($z <= 80)) {
            return 'S';
        } elseif (($x <= 300) && ($y <= 200) && ($z <= 150)) {
            return 'M';
        } elseif (($x <= 400) && ($y <= 270) && ($z <= 180)) {
            return 'L';
        } elseif (($x <= 530) && ($y <= 260) && ($z <= 220)) {
            return 'XL';
        }
        return 'OVERSIZED';
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

        $predefinedData = array_intersect_key(
            $this->predefinedData,
            array_flip([
                'completeness-checking',
                'courier',
                'fragile',
                'mail-direct',
                'mail-type',
                'manual-address-input',
                'payment-method',
                'transport-type',
                'with-order-of-notice',
                'with-simple-notice',
            ])
        );
        $result = array_merge($predefinedData, [
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
                    'status_id' => (int)($this->sentStatusId ?: $order->status_id),
                    'paid' => $order->paid,
                    'post_date' => date('Y-m-d H:i:s'),
                    'description' => 'Передан в Почту России с ID# ' . $resultId
                                  . ($barcode . ', трек-номер ' . $barcode),
                ]);
                $history->commit();
                if ($this->sentStatusId) {
                    $order->status_id = (int)$this->sentStatusId;
                    $order->commit();
                }
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


    /**
     * Рассчитывает стоимость доставки корзины
     * @param Cart $cart Корзина для расчета
     * @param array $postData POST-данные для расчета
     * @return float Стоимость, руб.
     */
    public function calculateCart(Cart $cart, $postData = [])
    {
        $predefinedData = array_intersect_key(
            $this->predefinedData,
            array_flip([
                'completeness-checking',
                'contents-checking',
                'courier',
                'entries-type',
                'fragile',
                'index-from',
                'inventory',
                'mail-category',
                'mail-direct',
                'mail-type',
                'notice-payment-method',
                'payment-method',
                'sms-notice-recipient',
                'transport-type',
                'vsd',
                'with-electronic-notice',
                'with-order-of-notice',
                'with-simple-notice',
            ])
        );
        // var_dump($predefinedData); exit;

        $weight = $this->getCartWeight($cart);
        $data = $predefinedData;
        if (!$data['index-from']) {
            unset($data['index-from']);
        }
        $data['mass'] = ceil($weight * 1000);
        foreach (['length', 'width', 'height'] as $key) {
            $data['dimension'][$key] = $this->getCartDimension($cart, $key);
        }
        $data['dimension-type'] = $this->getDimensionType(
            $data['dimension']['length'],
            $data['dimension']['width'],
            $data['dimension']['height']
        );
        $data['declared-value'] = $cart->sum;
        $normalizedAddress = $this->normalizeCartAddress($postData);
        $data['index-to'] = $normalizedAddress['index'];

        $sum = (int)$this->defaultDeliveryPrice;
        $minDays = $maxDays = null;
        try {
            $rawResult = $this->api->method('tariff', $data, 'POST');
            if ($rawResult['total-rate']) {
                $sum = ceil($rawResult['total-rate'] / 100);
            }
            if ($rawResult['delivery-time']['min-days']) {
                $minDays = $rawResult['delivery-time']['min-days'];
            }
            if ($rawResult['delivery-time']['max-days']) {
                $maxDays = $rawResult['delivery-time']['max-days'];
            }
        } catch (Exception $e) {
        }
        $result = [
            'sum' => $sum,
            'minDays' => $minDays,
            'maxDays' => $maxDays,
        ];
        return $result;
    }
}
