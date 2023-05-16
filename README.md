# module-wordpress-woocommerce  
  
Модуль интеграции платформы [WordPress](https://wordpress.org/) с платежной системой [PayBox](http://paybox.money) для магазина [WooCommerce](http://www.woothemes.com/woocommerce/).  

[Wordpress 4.3.x](https://github.com/PayBox/module-wordpress-woocommerce/archive/4.3.zip)  
[Wordpress 4.9.x](https://github.com/PayBox/module-wordpress-woocommerce/archive/4.9.zip)  
[Wordpress 5.3.x](https://github.com/PayBox/module-wordpress-woocommerce/archive/5.3.zip)  
[Wordpress 5.4.x](https://ru.wordpress.org/plugins/paybox-payment-gateway/)  
  
### Инструкция  
  
Для работы модуля необходимо выполнить следующие шаги:  
  
##### 1. Заключить договор с PayBox  
  
Заполнить форму заявки на сайте [PayBox](http://paybox.money) для получения доступа к личному кабинету PayBox.  
  
##### 2. Установить и настроить модуль модуль  
  
**Важно!** *Следуюшие шаги предполагают, что у вас уже установлен модуль магазина WooCommerce*.  
  
1. Установка модуля. Перейдите в маркетплейс Wordpress, введите в поисковике paybox.money, выберете наш модуль и нажмите на кнопку *Установить*.
После сообщения об успешной установке перейти на страницу *Плагины &rarr; Установленные*, найти в списке **Paybox Payment Gateway** и нажать на кнопку *Активировать*.  
2. Настройка. В консоли администратора WordPress выбрать *WooCommerce &rarr; Настройки* и перейти на вкладку *Оплата*. В списке *Платежные шлюзы* найти **Paybox** и перейти к настройкам (кликнуть по ссылке на названии).  
На странице настройки модуля ввести **Номер магазина** и **Секретный ключ** &mdash; эти данные берутся из [личного кабинета PayBox](https://my.paybox.money). Остальные настройки можно оставить по умолчанию.
3. После того, как все настройки будут сохранены, вам будет доступен метод оплаты через систему PayBox.  
