<?php
/**
 * Интерфейс взаимодействия с транспортной компанией СДЭК
 */
namespace RAAS\CMS\RussianPost;

use RAAS\Controller_Frontend;
use RAAS\CMS\AbstractInterface;
use RAAS\CMS\Shop\Cart;
use RAAS\CMS\Shop\Cart_Type;

/**
 * Класс интерфейса взаимодействия с Почтой России
 * @property-write string $login Логин
 * @property-write string $password Пароль
 * @property-write string $token Токен авторизации приложения
 */
class RussianPostInterface extends AbstractInterface
{
    /**
     * Логин
     * @var string
     */
    protected $login = '';

    /**
     * Пароль
     * @var string
     */
    protected $password = '';

    /**
     * Токен авторизации приложения
     * @var string
     */
    protected $token = '';

    /**
     * Коэффициент расчета цены
     * @var float
     */
    public $priceRatio = 1;

    /**
     * Параметры модуля отправки
     * @var array $senderParams
     */
    public $senderParams = [];


    public function __set($var, $val)
    {
        switch ($var) {
            case 'login':
            case 'password':
            case 'token':
                $this->$var = $val;
                break;
        }
    }


    public function process()
    {
        switch ($this->get['action']) {
            case 'calculator':
                $cartTypeId = (int)$this->get['cart_type'];
                $result = $this->calculator($cartTypeId, $this->post);
                break;

        }
        return $result;
    }


    /**
     * Вычисляет стоимость товаров
     * @param array $post POST-параметры
     * @return array
     */
    public function calculator($cartTypeId, array $post = [])
    {
        if ($cartTypeId = (int)$cartTypeId) {
            $cartType = new Cart_Type($cartTypeId);
        } else {
            $cartType = Cart_Type::importByURN('cart');
        }
        $user = Controller_Frontend::i()->user;
        $cart = new Cart($cartType, $user);

        $sender = new Sender($this->login, $this->password, $this->token);
        foreach ($this->senderParams as $key => $val) {
            if (is_array($sender->$key) && is_array($val)) {
                $sender->$key = array_merge($sender->$key, $val);
            } else {
                $sender->$key = $val;
            }
        }
        $cartSum = (float)$cart->sum;
        $deliveryPrice = (float)$sender->calculateCart($cart, $post);

        $sum = $deliveryPrice + ($cartSum + $deliveryPrice) * ($this->priceRatio - 1);
        $result = ['result' => $sum];
        return $result;
    }
}
