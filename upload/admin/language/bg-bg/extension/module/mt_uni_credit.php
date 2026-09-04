<?php

$_['heading_title'] = 'УниКредит покупки на Кредит';

$_['text_extension'] = 'Разширения';
$_['text_home'] = 'Начало';
$_['text_success'] = 'Настройките са запазени успешно.';
$_['text_edit'] = 'Настройки на модула';
$_['text_event_health_repair_failed'] = 'Presentation events не могат да бъдат регистрирани или поправени. Проверете правата за запис в event таблицата и OpenCart error log (mt_uni_credit: ensureCatalogEvents).';
$_['text_product_button_add_to_cart'] = 'Добави в количката';
$_['text_product_button_buy'] = 'Купи';
$_['text_secret_keep_current'] = 'Оставете празно, за да запазите текущия секрет.';
$_['text_secret_configured'] = 'Секретът е конфигуриран и съхранен криптирано.';
$_['text_bank_data_refreshed'] = 'Данните от банката са обновени успешно.';
$_['text_bank_data_refreshed_at'] = 'Време на обновяване: %s.';
$_['text_bank_data_scheme_count'] = 'Схеми в кеша: %d.';

$_['entry_status'] = 'Статус';
$_['entry_unicid'] = 'Уникален идентификационен код на магазина Ви';
$_['entry_secret'] = 'Секретен код на магазина Ви';
$_['entry_advertising_enabled'] = 'Визуализиране на реклама';
$_['entry_debug_enabled'] = 'Режим отстраняване на грешки';
$_['entry_product_button_action'] = 'Бутон купи';
$_['entry_button_top_spacing'] = 'Свободно място над бутона';

$_['help_unicid'] = 'Вашият уникален идентификационен код на магазина в системата на УниКредит.';
$_['help_secret'] = 'Вашият секретен код на магазина в системата на УниКредит.';
$_['help_advertising_enabled'] = 'Можете да включите или изключите рекламата на началната страница на магазина.';
$_['help_debug_enabled'] = 'При включване се записват сървърни SmartUCF диагностични записи (заявка/отговор) за поддръжка. Данните се редактират и не се показват на клиента.';
$_['help_product_button_action'] = 'Поведение на вторичния бутон в модулния popup на продуктовата страница.';
$_['help_button_top_spacing'] = 'Свободно място над бутона в px (0–200).';
$_['help_download_journal'] = 'Изтегля JSON журнал с SmartUCF операции за текущия магазин (празен, ако няма записи или режимът е изключен).';

$_['button_save'] = 'Запиши';
$_['button_cancel'] = 'Отказ';
$_['button_refresh_bank_data'] = 'Обнови данните от банката';
$_['button_download_journal'] = 'Изтегли журнал операции';

$_['error_permission'] = 'Предупреждение: Нямате права да променяте настройките на модула УниКредит!';
$_['error_unicid_required'] = 'UNICID е задължителен.';
$_['error_unicid_max_length'] = 'UNICID не може да надвишава 36 символа.';
$_['error_secret_required'] = 'Секретният код на магазина е задължителен.';
$_['error_secret_max_length'] = 'Секретният код не може да надвишава 64 символа.';
$_['error_secret_encrypt_failed'] = 'Секретът не може да бъде запазен сигурно. Предишният секрет е запазен.';
$_['error_invalid_product_button_action'] = 'Невалидно действие за бутона купи.';
$_['error_invalid_button_top_spacing'] = 'Свободното място над бутона трябва да е цяло число между 0 и 200.';
$_['error_journal_download_failed'] = 'Журналът с операции не може да бъде изтеглен.';

$_['error_bank_unicid_missing'] = 'Липсва UNICID.';
$_['error_bank_secret_missing'] = 'Липсва Secret.';
$_['error_bank_secret_unreadable'] = 'Съхраненият Secret не може да бъде прочетен. Въведете го отново и запишете.';
$_['error_bank_shop_url_missing'] = 'Липсва URL на магазина за връзка с Control Panel.';
$_['error_bank_authentication_failed'] = 'Неуспешно удостоверяване към Control Panel.';
$_['error_bank_shop_snapshot_invalid'] = 'Получени са невалидни банкови данни.';
$_['error_bank_transient_failure'] = 'Control Panel временно не отговаря.';
$_['error_bank_request_failed'] = 'Банковите данни не могат да бъдат обновени поради техническа грешка.';
