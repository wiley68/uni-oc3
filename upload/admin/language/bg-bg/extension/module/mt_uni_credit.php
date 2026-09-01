<?php

$_['heading_title'] = 'УниКредит покупки на Кредит';

$_['text_extension'] = 'Разширения';
$_['text_home'] = 'Начало';
$_['text_success'] = 'Настройките са запазени успешно.';
$_['text_edit'] = 'Настройки на модула';
$_['text_enabled'] = 'Включен';
$_['text_disabled'] = 'Изключен';
$_['text_health'] = 'Здраве и готовност';
$_['text_health_ready'] = 'Готово';
$_['text_health_warning'] = 'Предупреждение';
$_['text_health_not_configured'] = 'Не е конфигурирано';
$_['text_health_unavailable'] = 'Недостъпно';
$_['text_health_future_phase'] = 'Бъдеща фаза';
$_['text_environment_test'] = 'Тест (SmartUCF / CP тест)';
$_['text_environment_production'] = 'Продукция';
$_['text_secret_keep_current'] = 'Оставете празно, за да запазите текущия секрет.';
$_['text_secret_phase2'] = 'Сигурното съхранение на секрети не е налично във Фаза 1. Записът на секрет изисква криптиране от Фаза 2.';
$_['text_deployment_paths'] = 'Очаквани защитени пътища (спрямо открития root)';

$_['entry_status'] = 'Статус';
$_['entry_environment'] = 'Среда';
$_['entry_debug_enabled'] = 'Режим отстраняване на грешки';
$_['entry_unicid'] = 'Уникален идентификационен код на магазина Ви';
$_['entry_secret'] = 'Секретен код на магазина Ви';

$_['help_status'] = 'Главен превключвател на модула. Storefront финансирането е изключено до по-късни фази.';
$_['help_environment'] = 'Placeholder за одобрен избор на CP/SmartUCF среда. Outbound CP повиквания липсват във Фаза 1.';
$_['help_debug_enabled'] = 'При включване се записват сървърни SmartUCF диагностични записи (заявка/отговор) за поддръжка. Данните се редактират и не се показват на клиента.';
$_['help_unicid'] = 'Вашият уникален идентификационен код на магазина в системата на УниКредит.';
$_['help_secret'] = 'Вашият секретен код на магазина в системата на УниКредит.';

$_['column_check'] = 'Проверка';
$_['column_status'] = 'Статус';
$_['column_detail'] = 'Детайл';

$_['button_save'] = 'Запиши';
$_['button_cancel'] = 'Отказ';

$_['error_permission'] = 'Предупреждение: Нямате права да променяте настройките на модула УниКредит!';
$_['error_invalid_environment'] = 'Средата трябва да е тест или продукция.';
$_['error_unicid_required'] = 'UNICID е задължителен.';
$_['error_unicid_max_length'] = 'UNICID не може да надвишава 36 символа.';
$_['error_secret_required'] = 'Секретният код на магазина е задължителен.';
$_['error_secret_max_length'] = 'Секретният код не може да надвишава 64 символа.';
$_['error_secret_phase2_required'] = 'Секретът не може да се запише във Фаза 1. Сигурното съхранение идва във Фаза 2.';
