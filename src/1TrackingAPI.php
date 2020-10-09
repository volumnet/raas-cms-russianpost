<?php
/**
 * Файл класса отслеживания отправлений по трек-номерам
 */
namespace RAAS\CMS\RussianPost;

use SoapClient;
use SoapParam;

class TrackingAPI
{
    /**
     * Базовый URL для запроса
     */
    const URL_PREFIX = 'https://tracking.russianpost.ru/';

    /**
     * Логин
     * @var string
     */
    private $login;

    /**
     * Пароль
     * @var string
     */
    private $password;

    /**
     * Конструктор класса
     * @param string $login Логин
     * @param string $password Пароль
     */
    public function __construct($login, $password)
    {
        $this->login = $login;
        $this->password = $password;
    }


    /**
     * Получает историю посылки по трек-номеру
     * @param string $barcode Трек-номер
     * @return mixed Данные, полученные от API Почты России
     */
    public function getOperationHistory($barcode)
    {
        $soap = new SoapClient(
            static::URL_PREFIX . 'rtm34?wsdl',
            ['trace' => 1, 'soap_version' => SOAP_1_2]
        );
        $soapData = [
            'OperationHistoryRequest' => [
                'Barcode' => $barcode,
                'MessageType' => '0',
                'Language' => 'RUS'
            ],
            'AuthorizationHeader' => [
                'login'=> $this->login,
                'password' => $this->password
            ]
        ];
        $result = $soap->getOperationHistory(
            new SoapParam($soapData, 'request')
        );
        return $result;
    }
}
