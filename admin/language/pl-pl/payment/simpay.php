<?php

$_['heading_title'] = 'SimPay.pl Przelewy / BLIK';
$_['simpay_extension'] = 'Rozszerzenia';
$_['simpay_config_updated'] = 'Ustawienia zostały zapisane pomyślnie.';
$_['simpay_text_edit'] = 'Edycja bramki SimPay Przelewy / BLIK';
$_['simpay_entry_bearer'] = 'Hasło API';
$_['simpay_entry_bearer_description'] = 'Konto klienta → API → Szczegóły → Hasło / Bearer Token';
$_['simpay_entry_service_id'] = 'ID usługi';
$_['simpay_entry_service_id_description'] = 'Płatności online → Usługi → Szczegóły → ID usługi';
$_['simpay_entry_service_hash'] = 'Klucz sygnatury IPN';
$_['simpay_entry_service_hash_description'] = 'Płatności online → Usługi → Szczegóły → Ustawienia → Klucz sygnatury IPN';
$_['simpay_entry_ip_verify'] = 'Weryfikuj adres IP nadawcy w powiadomieniach IPN';
$_['simpay_entry_ip_verify_description'] = 'Jeśli sklep korzysta z Cloudflare, nie zalecamy włączania tej opcji.';
$_['simpay_entry_status'] = 'Status płatności';
$_['simpay_error_permission'] = 'Brak uprawnień do edycji tego modułu!';
$_['simpay_error_validation'] = 'Jedno lub więcej z wymaganych parametrów nie zostało uzupełnione!';
$_['simpay_ipn_text'] = 'W Panelu SimPay ustaw adres IPN na:';
$_['simpay_version_current'] = 'Twoja wtyczka jest aktualna. Wersja: ';
$_['simpay_version_old'] = 'Dostępna jest nowa wersja wtyczki OpenCart SimPay. Twoja wersja: %s, nowa wersja: %s. Możesz pobrać wtyczkę bezpośrednio <a href="%s" target="_blank">tutaj</a>.';
$_['simpay_save'] = 'Zapisz';
$_['simpay_enabled'] = 'Włączone';
$_['simpay_disabled'] = 'Wyłączone';
$_['simpay_entry_approved_status'] = 'Status zamówienia po opłaceniu';
$_['simpay_entry_approved_status_description'] = 'Status, który zostanie ustawiony po pomyślnym opłaceniu zamówienia.';
$_['simpay_entry_sort_order'] = 'Kolejność sortowania';


// Połącz z SimPay (OAuth)
$_['simpay_oauth_tab'] = 'Połącz z SimPay (zalecane)';
$_['simpay_manual_tab'] = 'Wpisz dane ręcznie';
$_['simpay_oauth_lead'] = 'Zaloguj się do konta SimPay i udziel dostępu - moduł automatycznie utworzy klucz API, ustawi adres IPN i skonfiguruje klucz sygnatury IPN. Bez kopiowania kluczy.';
$_['simpay_oauth_connect'] = 'Połącz z SimPay';
$_['simpay_oauth_reconnect'] = 'Połącz ponownie / zmień usługę';
$_['simpay_oauth_disconnect'] = 'Rozłącz';
$_['simpay_oauth_disconnect_confirm'] = 'Czy na pewno chcesz odłączyć sklep od SimPay? Płatności SimPay przestaną działać do czasu ponownego połączenia.';
$_['simpay_oauth_connected_title'] = 'Twój sklep jest połączony z SimPay.';
$_['simpay_oauth_account'] = 'Konto SimPay:';
$_['simpay_oauth_service'] = 'Usługa płatności:';
$_['simpay_oauth_detected_callback'] = 'Wykryty adres powrotu:';
$_['simpay_oauth_select_title'] = 'Wybierz usługę płatności SimPay';
$_['simpay_oauth_select_lead'] = 'Wybierz usługę, z której ma korzystać ten sklep:';
$_['simpay_oauth_select_warning'] = 'Moduł utworzy nowy klucz API, ustawi adres IPN i wygeneruje nowy klucz sygnatury IPN dla wybranej usługi. Poprzedni klucz IPN przestanie działać natychmiast - jeśli ta usługa jest używana także przez inny sklep lub integrację, zaktualizuj go również tam.';
$_['simpay_oauth_select_submit'] = 'Połącz wybraną usługę';
$_['simpay_oauth_cancel'] = 'Anuluj';
$_['simpay_oauth_connected'] = 'Sklep został połączony z usługą SimPay "%s". Klucz API, adres IPN i klucz sygnatury IPN zostały skonfigurowane automatycznie.';
$_['simpay_oauth_disconnected'] = 'Sklep został odłączony od SimPay. Płatności SimPay nie będą działać do czasu ponownego połączenia lub ręcznego wpisania danych.';
$_['simpay_oauth_error_https'] = 'Połączenie przez SimPay wymaga, aby sklep działał po HTTPS. Wpisz dane ręcznie.';
$_['simpay_oauth_error_access_denied'] = 'Połączenie z SimPay zostało anulowane.';
$_['simpay_oauth_error_authorization'] = 'Autoryzacja SimPay nie powiodła się: %s';
$_['simpay_oauth_error_invalid_state'] = 'Nieprawidłowa lub wygasła autoryzacja SimPay. Spróbuj połączyć ponownie.';
$_['simpay_oauth_error_no_services'] = 'Na Twoim koncie SimPay nie znaleziono aktywnych usług płatności. Najpierw utwórz usługę w Panelu SimPay.';
$_['simpay_oauth_error_expired'] = 'Autoryzacja SimPay wygasła. Połącz ponownie.';
$_['simpay_oauth_error_unknown_service'] = 'Wybierz usługę płatności.';
$_['simpay_oauth_error_ipn_version'] = 'Wybrana usługa używa IPN w wersji %s, a ten moduł obsługuje wyłącznie IPN 2.0. Zmień wersję IPN na 2.0 w ustawieniach usługi w Panelu SimPay i wybierz usługę ponownie. Nic nie zostało zmienione.';
$_['simpay_oauth_error_generic'] = 'Nie udało się połączyć z SimPay: %s';
