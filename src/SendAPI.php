<?php
/**
 * Файл класса API отправки
 */
namespace RAAS\CMS\RussianPost;

use Exception;

/**
 * Класс API отправки
 *
 * Предустановленные форматы данных:
 * <pre>[Нормализованный адрес] => [
 *     'address-type' => ('DEFAULT')|
 *                       ('PO_BOX' Абонентский ящик)|
 *                       ('DEMAND' До востребования) Тип адреса,
 *     'area' ?=> string Район,
 *     'building' ?=> string Часть здания: Строение,
 *     'corpus' ?=> string Часть здания: Корпус,
 *     'hotel' ?=> string Название гостиницы,
 *     'house' ?=> string Часть адреса: Номер здания,
 *     'id' => string Идентификатор записи,
 *     'index' => string Почтовый индекс,
 *     'letter' ?=> string Часть здания: Литера,
 *     'location' ?=> string Микрорайон,
 *     'num-address-type' ?=> string Номер для а/я, войсковая часть, войсковая часть ЮЯ, полевая почта,
 *     'original-address' => string Оригинальные адрес одной строкой,
 *     'place' => string Населенный пункт,
 *     'quality-code' => ('GOOD' Пригоден для почтовой рассылки)|
 *                       ('ON_DEMAND' До востребования)|
 *                       ('POSTAL_BOX' Абонентский ящик)|
 *                       ('UNDEF_01' Не определен регион)|
 *                       ('UNDEF_02' Не определен город или населенный пункт)|
 *                       ('UNDEF_03' Не определена улица)|
 *                       ('UNDEF_04' Не определен номер дома)|
 *                       ('UNDEF_05' Не определена квартира/офис)|
 *                       ('UNDEF_06' Не определен)|
 *                       ('UNDEF_07' Иностранный адрес) Код качества нормализации адреса,
 *     'region' => string Область, регион,
 *     'room' ?=> string Часть здания: Номер помещения,
 *     'slash' ?=> string Часть здания: Дробь,
 *     'street' ?=> string Часть адреса: Улица,
 *     'validation-code' => ('CONFIRMED_MANUALLY' => Подтверждено контролером)|
 *                          ('VALIDATED' => Уверенное распознавание)|
 *                          ('OVERRIDDEN' => Распознан: адрес был перезаписан в справочнике)|
 *                          ('NOT_VALIDATED_HAS_UNPARSED_PARTS' => На проверку, неразобранные части)|
 *                          ('NOT_VALIDATED_HAS_ASSUMPTION' => На проверку, предположение)|
 *                          ('NOT_VALIDATED_HAS_NO_MAIN_POINTS' => На проверку, нет основных частей)|
 *                          ('NOT_VALIDATED_HAS_NUMBER_STREET_ASSUMPTION' => На проверку, предположение по улице)|
 *                          ('NOT_VALIDATED_HAS_NO_KLADR_RECORD' => На проверку, нет в КЛАДР)|
 *                          ('NOT_VALIDATED_HOUSE_WITHOUT_STREET_OR_NP' => На проверку, нет улицы или населенного пункта)|
 *                          ('NOT_VALIDATED_HOUSE_EXTENSION_WITHOUT_HOUSE' => На проверку, нет дома)|
 *                          ('NOT_VALIDATED_HAS_AMBI' => На проверку, неоднозначность)|
 *                          ('NOT_VALIDATED_EXCEDED_HOUSE_NUMBER' => На проверку, большой номер дома)|
 *                          ('NOT_VALIDATED_INCORRECT_HOUSE' => На проверку, некорректный дом)|
 *                          ('NOT_VALIDATED_INCORRECT_HOUSE_EXTENSION' => На проверку, некорректное расширение дома)|
 *                          ('NOT_VALIDATED_FOREIGN' => Иностранный адрес)|
 *                          ('NOT_VALIDATED_DICTIONARY' => На проверку, не по справочнику) Код проверки нормализации адреса,
 * ]</pre>
 */
class SendAPI
{
    /**
     * Базовый URL для запроса
     */
    const URL_PREFIX = 'https://otpravka-api.pochta.ru/1.0/';

    /**
     * Токен авторизации приложения
     * @var string
     */
    private $token;

    /**
     * Ключ авторизации пользователя
     * @var string
     */
    private $userKey;

    /**
     * Конструктор класса
     * @param string $login Логин
     * @param string $password Пароль
     * @param string $token Токен авторизации приложения
     */
    public function __construct($login, $password, $token)
    {
        $this->token = $token;
        $this->userKey = base64_encode($login . ':' . $password);
    }


    /**
     * Базовый запрос
     * @param string $method URL метода (не включая версию)
     * @param array<string[] => mixed> $data Данные для отправки
     * @param bool|string $post Отправлять методом POST или вручную указывать метод
     * @return array Данные, полученные от API Почты России
     */
    public function method($method, array $data = [], $post = false)
    {
        // $data['method'] = $method;
        // $data['token'] = $this->token;
        $url = static::URL_PREFIX . $method;
        if (!$post && $data) {
            $url .= '?' . http_build_query($data);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        // if ($method == 'tariff') {
        //     print_r([
        //         'Content-Type: application/json',
        //         'Accept: application/json;charset=UTF-8',
        //         'Authorization: AccessToken ' . $this->token,
        //         'X-User-Authorization: Basic ' . $this->userKey
        //     ]);
        //     print_r (json_encode($data, JSON_PRETTY_PRINT));
        //     exit;
        // }
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json;charset=UTF-8',
            'Authorization: AccessToken ' . $this->token,
            'X-User-Authorization: Basic ' . $this->userKey
        ]);
        if ($post) {
            if ($post === true) {
                curl_setopt($ch, CURLOPT_POST, true);
            } else {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $post);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $text = curl_exec($ch);
        $data = (array)json_decode($text, true);
        return $data;
    }


    /**
     * Нормализация адресов
     * @param string[] $address Входные адреса
     * @return array <pre>[Нормализованный адрес][]</pre>
     * @throws Exception В случае ошибки
     */
    public function normalizeAddresses(array $addresses = [])
    {
        $data = [];
        foreach ($addresses as $key => $address) {
            $data[] = array('original-address' => trim($address));
        }
        $result = $this->method('clean/address', $data, true);
        if ($result['error']) {
            throw new Exception($result['error'], $result['status']);
        }
        return $result;
    }


    /**
     * Магический запрос к методу
     * @param string $method Наименование метода
     * @param array<string[] => mixed> $data Данные для отправки
     * @param bool $post Отправлять методом POST
     * @return array Данные, полученные от Boxberry
     */
    public function __call($name, array $arguments = [])
    {
        $data = isset($arguments[0]) ? $arguments[0] : [];
        $post = isset($arguments[1]) ? $arguments[1] : false;
        return $this->method($name, $data, $post);
    }
}
