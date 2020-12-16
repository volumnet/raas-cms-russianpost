<?php
/**
 * Сниппет интерфейса Почты России
 *
 * @param Page $Page Текущая страница
 * @param Block $Block Текущий блок
 */
namespace RAAS\CMS\RussianPost;

$interface = new RussianPostInterface(
    $Block,
    $Page,
    $_GET,
    $_POST,
    $_COOKIE,
    $_SESSION,
    $_SERVER,
    $_FILES
);

$interface->login = $GLOBALS['pochta']['login'];
$interface->password = $GLOBALS['pochta']['password'];
$interface->token = $GLOBALS['pochta']['token'];
$interface->senderParams = $GLOBALS['pochta']['sender'];
$interface->priceRatio = (float)$GLOBALS['pochta']['priceRatio'];

return $interface->process();
