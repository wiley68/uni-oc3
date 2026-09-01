<?php

$_['heading_title'] = 'УниКредит покупки на Кредит';

$_['text_extension'] = 'Разширения';
$_['text_success'] = 'Успех: Промените в настройките на UniCredit бяха запазени!';
$_['text_edit'] = 'Редакция на UniCredit плащане';
$_['text_enabled'] = 'Включено';
$_['text_disabled'] = 'Изключено';
$_['text_yes'] = 'Да';
$_['text_no'] = 'Не';
$_['text_module_identity'] = 'Идентичност на модула';
$_['text_health'] = 'Здраве и готовност';
$_['text_health_ready'] = 'Готово';
$_['text_health_warning'] = 'Предупреждение';
$_['text_health_not_configured'] = 'Не е конфигурирано';
$_['text_health_unavailable'] = 'Недостъпно';
$_['text_health_future_phase'] = 'Бъдеща фаза';
$_['text_environment_test'] = 'Тест (SmartUCF / CP тест)';
$_['text_environment_production'] = 'Продукция';
$_['text_secret_keep_current'] = 'Оставете празно, за да запазите текущия записан секрет.';
$_['text_secret_phase2'] = 'Сигурното съхранение на секрети не е налично във Фаза 1. Записът на CP секрет изисква криптиране от Фаза 2.';
$_['text_deployment_paths'] = 'Очаквани защитени пътища (спрямо открития root)';

$_['entry_status'] = 'Статус';
$_['entry_sort_order'] = 'Подредба';
$_['entry_environment'] = 'Среда';
$_['entry_debug'] = 'Debug логване';
$_['entry_unicid'] = 'UNICID';
$_['entry_secret'] = 'CP секрет';

$_['help_status'] = 'Методът остава откриваем в админ. Storefront финансирането е изключено до по-късни фази.';
$_['help_sort_order'] = 'Подредба сред методите за плащане (използва се след активиране на checkout потока).';
$_['help_environment'] = 'Placeholder за одобрен избор на CP/SmartUCF среда. Outbound CP повиквания липсват във Фаза 1.';
$_['help_debug'] = 'Запазено за редуцирани диагностики в по-късни фази. Без чувствителни логове във Фаза 1.';
$_['help_unicid'] = 'Идентификатор на магазина в Control Panel. Необходим преди CP интеграция (Фаза 4).';
$_['help_secret'] = 'Никога не се показва след запис. Фаза 1 не записва plaintext секрети.';

$_['column_check'] = 'Проверка';
$_['column_status'] = 'Статус';
$_['column_detail'] = 'Детайл';

$_['button_save'] = 'Запази';
$_['button_cancel'] = 'Отказ';

$_['error_permission'] = 'Внимание: Нямате права да променяте настройките на UniCredit плащането!';
$_['error_invalid_sort_order'] = 'Подредбата трябва да е цяло число.';
$_['error_invalid_environment'] = 'Средата трябва да е тест или продукция.';
$_['error_secret_phase2_required'] = 'CP секретът не може да се запише във Фаза 1. Сигурното съхранение идва във Фаза 2.';
