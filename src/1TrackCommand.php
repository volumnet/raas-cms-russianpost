<?php
/**
 * Команда отслеживания заказов по трек-номеру
 */
namespace RAAS\CMS\RussianPost;

use SoapFault;
use Exception;
use RAAS\Command;
use RAAS\CMS\Field;
use RAAS\CMS\Form;
use RAAS\CMS\Shop\Cart_Type;
use RAAS\CMS\Shop\Order;
use RAAS\CMS\Shop\Order_History;

/**
 * Команда отслеживания заказов по трек-номеру
 *
 * Статусы отслеживания со стороны Почты России представлены в виде:
 * [Код операции].[Код атрибута], например, 2.1 - вручение адресату
 * (см. https://tracking.pochta.ru/support/dictionaries/operation_codes)
 *
 * Предустановленные типы:
 * <pre>[Настройки трекинга] => [
 *     'trackOperations' => array<[
 *         'opCodes' => string|int|(string|int)[] Статусы отслеживания
 *                          (возможно в виде числа для всех кодов атрибута);
 *                          если запись одна, может быть записано не в массиве
 *         'ignoreOpCodes' ?=> string|string[] Исключенные статусы отслеживания
 *                                             (в таком же виде),
 *         'statusId' => int ID# статуса заказа, который установим,
 *                           если встретится заданный статус отслеживания,
 *         'includeAddress' => bool Включать в статус адрес,
 *     ]>,
 *     'finalStatuses' => int[] ID# финальных статусов заказов
 *                              (заказы с такими статусами не отслеживаются),
 *     'barcodeVar' => string Переменная заказа, содержащая трек-номер
 * ]
 *
 * [Операция] => [
 *     'datetimeOriginal' => string Исходный текст даты операции,
 *     'time' => int UNIX-timestamp даты операции,
 *     'post_date' => string Системная дата операции в виде "ГГГГ-ДД-ММ чч:мм:сс"
 *     'code' => string Код/атрибут операции в виде [Код].[Атрибут],
 *     'status_id' => int Статус заказа операции
 *     'description' => string Текстовое описание операции
 * ]</pre>
 * Настройки трекинга могут содержать и другие параметры, для удобства хранения
 * конфигурации в более поверхностных уровнях массива
 */
class TrackCommand extends Command
{
    /**
     * Настройки трекинга
     * @var array <pre>[Настройки трекинга]</pre>
     */
    public $config = [
        'login' => '',
        'password' => '',
        'trackOperations' => [
            [
                'opCodes' => 2,
                'ignoreOpCodes' => ['2.2'],
                'statusId' => 2,
                'includeAddress' => false,
            ],
            [
                'opCodes' => '2.2',
                'statusId' => 3,
                'includeAddress' => false
            ],
        ],
        'finalStatuses' => [2, 3],
        'barcodeVar' => 'barcode',
    ];

    /**
     * API трекинга
     * @var TrackingAPI
     */
    private $trackingAPI = null;

    /**
     * Отработка команды
     *
     * Глобальные переменные заданы в виде (пример) config.post.tracking
     * ($GLOBALS['config']['post']['tracking'])
     * @param string $configVar Глобальная переменная, содержащая настройки
     *                          трекинга в виде <pre>[Настройки трекинга]</pre>
     */
    public function process($configVar = null)
    {
        if ($configVar) {
            $config = $this->getGlobalVar($configVar);
            if ($config && is_array($config)) {
                $this->config = array_merge($this->config, $config);
            } else {
                $this->controller->doLog(
                    'Переменная ' . $configVar . ' не задана'
                );
                return;
            }
        } else {
            $logMessage = 'Необходимо задать переменную конфигурации';
            $this->controller->doLog($logMessage);
        }
        if (!$this->config['login']) {
            $logMessage = 'Необходимо задать переменную конфигурации для логина'
                        . ' (login)';
            $this->controller->doLog($logMessage);
            return;
        }
        if (!$this->config['password']) {
            $logMessage = 'Необходимо задать переменную конфигурации для пароля'
                        . ' (password)';
            $this->controller->doLog($logMessage);
            return;
        }
        $this->trackingAPI = new TrackingAPI($this->config['login'], $this->config['password']);
        $orders = $this->getOrdersToTrack();
        if ($orders) {
            $logMessage = 'Найдено ' . count($orders)
                        . ' заказов для отслеживания';
            $this->controller->doLog($logMessage);
            $this->trackOrders($orders);
        }
    }


    /**
     * Получает значение глобальной переменной
     * @param string $var Глобальная переменная в виде config.post.tracking
     *                    ($GLOBALS['config']['post']['tracking'])
     * @return mixed null, если не определено
     */
    public function getGlobalVar($var)
    {
        if (!$var) {
            return null;
        }
        $varArr = explode('.', trim($var));
        $traceVar = $GLOBALS;
        foreach ($varArr as $varChunk) {
            if (!isset($traceVar[$varChunk])) {
                return null;
            }
            $traceVar = $traceVar[$varChunk];
        }
        return $traceVar;
    }


    /**
     * Получает данные по заказам для трекинга
     * @return Order[]
     */
    public function getOrdersToTrack()
    {
        // Получим ID# форм заказов
        $sqlQuery = "SELECT form_id
                       FROM " . Cart_Type::_tablename()
                  . " WHERE form_id";
        $formsIds = Cart_Type::_SQL()->getcol($sqlQuery);
        if (!$formsIds) {
            return [];
        }

        // Получим поля трек-номеров
        $sqlQuery = "SELECT id
                       FROM " . Field::_tablename()
                  . " WHERE urn = ?
                        AND classname = ?
                        AND pid IN (" . implode(", ", $formsIds) . ")";
        $fieldsIds = Field::_SQL()->getcol([
            $sqlQuery,
            $this->config['barcodeVar'],
            Form::class
        ]);
        if (!$fieldsIds) {
            return [];
        }

        // Получим ID# заказов для отслеживания
        $sqlQuery = "SELECT tOr.id, tD.value AS " . $this->config['barcodeVar']
                  . "  FROM " . Order::_tablename() . " AS tOr
                       JOIN cms_data AS tD ON tD.pid = tOr.id
                      WHERE tD.fid IN (" . implode(", ", $fieldsIds) . ")
                        AND tD.value != ''";
        if ($this->config['finalStatuses']) {
            $sqlQuery .= " AND tOr.status_id NOT IN (" . implode(", ", $this->config['finalStatuses']) . ")
                           AND (
                                   SELECT COUNT(*)
                                     FROM " . Order_History::_tablename() . " AS tOH
                                    WHERE tOH.order_id = tOr.id
                                      AND tOH.status_id IN (" . implode(", ", $this->config['finalStatuses']) . ")
                               ) = 0";
            // Проверяем также по истории, потому что после получения финального
            // статуса, администратор может сменить статус вручную
        }
        $sqlQuery .= " ORDER BY tOr.id";
        $result = Order::getSQLSet($sqlQuery);

        return $result;
    }


    /**
     * Отслеживает заказы
     * @param Order[] $orders Заказы для отслеживания
     */
    public function trackOrders(array $orders = [])
    {
        $barcodeVar = $this->config['barcodeVar'];
        $c = count($orders);
        foreach ($orders as $i => $order) {
            try {
                $this->trackOrder($order);
                $logMessage = 'Обработан заказ #' . $order->id
                            . ' (' . ($i + 1) . '/' . $c . ')';
                $this->controller->doLog($logMessage);
            } catch (SoapFault $e) {
                $logMessage = get_class($e)
                            . '#' . $e->getCode()
                            . ': ' . $e->getMessage()
                            . ' в заказе #' . $order->id;
                if ($e->detail) {
                    $logMessage .= ' (';
                    $logMessage .= json_encode(
                        $e->detail,
                        JSON_UNESCAPED_UNICODE
                    );
                    $logMessage .= ')';
                }
                $this->controller->doLog($logMessage);
            } catch (Exception $e) {
                $logMessage = get_class($e)
                            . '#' . $e->getCode()
                            . ': ' . $e->getMessage()
                            . ' в заказе #' . $order->id;
                $this->controller->doLog($logMessage);
            }
        }
    }


    /**
     * Отслеживает один заказ
     */
    public function trackOrder(Order $order)
    {
        $barcode = $order->{$this->config['barcodeVar']};
        $barcode = preg_replace('/\\s+/umis', '', $barcode);
        $barcode = trim($barcode);
        if (!$barcode) {
            $this->controller->doLog(
                'У заказа #' . (int)$order->id . ' отсутствует трек-номер'
            );
            return;
        }
        $operationHistory = $this->getOperationHistory($barcode);
        $changeData = $this->getChangeData($order, $operationHistory);
        foreach ($changeData['history'] as $history) {
            $history->commit();
        }
        if ($changeData['statusId']) {
            $order->status_id = (int)$changeData['statusId'];
            $order->commit();
        }
    }


    /**
     * Получает информацию по истории отправления
     * @param string $barcode Трек-номер
     * @return array <pre>[Операция][]</pre>
     */
    public function getOperationHistory($barcode)
    {
        $operationHistory = $this->trackingAPI->getOperationHistory($barcode);
        $operationHistory = $operationHistory->OperationHistoryData->historyRecord;
        $result = [];
        foreach ($operationHistory as $operationHistoryEntry) {
            $operationParameters = $operationHistoryEntry->OperationParameters;
            $operType = $operationParameters->OperType;
            $operAttr = $operationParameters->OperAttr;
            $code = trim($operType->Id);
            if ($operAttr->Id) {
                $code .= '.' . $operAttr->Id;
            }
            $trackOpConfig = $this->getHistoryOperationConfig($code);
            if (!$trackOpConfig) {
                continue;
            }
            $statusId = $trackOpConfig['statusId'];
            $description = trim($operType->Name);
            if ($operAttr->Name) {
                $description .= ' / ' . $operAttr->Name;
            }
            if ($trackOpConfig['includeAddress']) {
                $address = $operationHistoryEntry->AddressParameters->OperationAddress;
                $description .= ' / ' . $address->Index . ' ' . $address->Description;
            }
            $dateText = $operationParameters->OperDate;
            $time = strtotime($dateText);
            $result[] = [
                'datetimeOriginal' => $dateText,
                'time' => $time,
                'post_date' => date('Y-m-d H:i:s', $time),
                'code' => $code,
                'status_id' => $statusId,
                'description' => $description,
            ];
        }
        return $result;
    }


    /**
     * Получает соответствующую настройку смены статуса по коду операции
     * @param int|string $opCode Код операции
     * @return array|null <pre>[
     *     'statusId' => int ID# статуса,
     *     'includeAddress' => bool Включать в событие истории адрес
     * ]</pre> или null, если не найдена
     */
    public function getHistoryOperationConfig($opCode)
    {
        foreach ($this->config['trackOperations'] as $operationParam) {
            $opCodes = (array)$operationParam['opCodes'];
            $ignoreOpCodes = (array)$operationParam['ignoreOpCodes'];
            foreach ($ignoreOpCodes as $ignoreOpCodeTemplate) {
                if ($this->codeMatches($ignoreOpCodeTemplate, $opCode)) {
                    return null;
                }
            }
            foreach ($opCodes as $opCodeTemplate) {
                if ($this->codeMatches($opCodeTemplate, $opCode)) {
                    return $operationParam;
                }
            }
        }
        return null;
    }


    /**
     * Проверяет, соответствует ли код операции шаблону
     * @param int|string $expected Шаблон кода для проверки
     * @param int|string $actual Код для проверки
     * @return bool
     */
    public function codeMatches($expected, $actual)
    {
        if (trim($expected) === trim($actual)) {
            // Строгое соответствие, потому что с точки зрения PHP "8.10" == "8.1"
            return true;
        }
        $expectedArr = explode('.', trim($expected));
        $actualArr = explode('.', trim($actual));
        for ($i = 0; $i < count($expectedArr); $i++) {
            if (($expectedArr[$i] != '*') &&
                ($actualArr[$i] != $expectedArr[$i])
            ) {
                return false;
            }
        }
        return true;
    }


    /**
     * Получает данные для обновления заказа
     * @param Order $order Заказ
     * @param array $operationHistory <pre>[Операция][]</pre>
     * @return array <pre>[
     *     'history' => Order_History[] События для добавления,
     *     'statusId' ?=> Статус для смены (если нужно поменять)
     * ]</pre>
     * @todo
     */
    public function getChangeData(Order $order, array $operationHistory = [])
    {
        $result = ['history' => []];
        $orderHistory = $order->history;
        if ($orderHistory) {
            usort($orderHistory, function ($a, $b) {
                return strtotime($b->post_date) - strtotime($a->post_date);
            }); // Отсортируем историю заказа по убыванию времени
        }
        if ($operationHistory) {
            usort($operationHistory, function ($a, $b) {
                return $b['time'] - $a['time'];
            }); // Отсортируем историю отправления по убыванию времени
        }
        $lastHistoryTime = 0;
        if ($orderHistory) {
            $lastHistoryTime = strtotime($orderHistory[0]->post_date);
        }
        $lastStatusId = $order->status_id;
        foreach ($operationHistory as $operationHistoryEntry) {
            $orderHistoryEntryData = $this->findHistoryEntry(
                $operationHistoryEntry['time'],
                $operationHistoryEntry['status_id'],
                $orderHistory
            );
            if (!$orderHistoryEntry['current']) {
                $newOrderHistoryEntry = new Order_History([
                    'uid' => 0,
                    'order_id' => (int)$order->id,
                    'status_id' => $operationHistoryEntry['status_id'],
                    'paid' => (
                        $orderHistoryEntry ?
                        (int)$orderHistoryEntry['entry']->paid :
                        0
                    ),
                    'post_date' => $operationHistoryEntry['post_date'],
                    'description' => $operationHistoryEntry['description'],
                ]);
                $result['history'][] = $newOrderHistoryEntry;
                if ($operationHistoryEntry['time'] > $lastHistoryTime) {
                    $lastHistoryTime = $operationHistoryEntry['time'];
                    if ($operationHistoryEntry['status_id'] != $lastStatusId) {
                        $lastStatusId = $operationHistoryEntry['status_id'];
                    }
                }
            }
        }
        if ($lastStatusId != $order->status_id) {
            $result['statusId'] = $lastStatusId;
        }
        if ($result['history']) {
            usort($result['history'], function ($a, $b) {
                return strtotime($a->post_date) - strtotime($b->post_date);
            });
        }
        // Отсортируем новые сообщения в истории по возрастанию времени,
        // чтобы последовательность ID# соответствовала времени
        return $result;
    }


    /**
     * Ищет соответствующую запись в истории заказа
     * или предыдущую перед этим запись
     * @param int $time UNIX-timestamp для поиска
     * @param int $statusId ID# статуса для поиска
     * @param Order_History[] $orderHistory История заказов,
     *                                      отсортированная по убыванию даты
     * @return array|null <pre>[
     *     'entry' => Order_History Запись истории заказа
     *     'current' => bool true, если найдена конкретная запись,
     *                       false, если найдена предыдущая
     * ]</pre>, либо null, если не найдено
     */
    public function findHistoryEntry($time, $statusId, array $orderHistory)
    {
        foreach ($orderHistory as $orderHistoryEntry) {
            $orderHistoryEntryTime = strtotime($orderHistoryEntry->post_date);
            if (($orderHistoryEntry->status_id == $statusId) &&
                ($orderHistoryEntryTime == $time)
            ) {
                return ['entry' => $orderHistoryEntry, 'current' => true];
            } elseif ($orderHistoryEntryTime < $time) {
                return ['entry' => $orderHistoryEntry, 'current' => false];
            }
        }
        return null;
    }
}
