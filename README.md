# Perspective_Observability

Браузерні Web Vitals і кількість JavaScript-помилок для Magento 2. Збір вимкнений за замовчуванням. Модуль працює без OpenTelemetry SDK та його PHP-розширення, власних таблиць і cron-завдань. Спільний `Profiler.php` використовують серверні адаптери; самостійно він не запускається.

## Чому три репозиторії

Цей модуль відповідає за frontend. [Compat](https://github.com/perspectiveteamcom/magento2-observability-compat) спостерігає серверні операції через Magento plugins. [Otel](https://github.com/perspectiveteamcom/magento2-observability-otel) використовує PHP-розширення OpenTelemetry й охоплює також прямі виклики PDO/cURL. Це різні способи збору; вони мають окремі залежності й межі втручання.

| Пакет | Призначення | PHP |
| --- | --- | --- |
| `perspective/module-observability` 1.x | RUM і спільний CPU-профайлер | 7.4-8.5 |
| `perspective/module-observability-compat` 1.x | Траси через Magento plugins, без ext-opentelemetry | 7.4-8.5 |
| `perspective/module-observability-otel` 1.x | Метапакет, який підключає Compat для старого PHP | 7.4-8.2 |
| `perspective/module-observability-otel` 2.x | Нативні OTel hooks, автоматична інструментація Magento/PDO/cURL | 8.3-8.5 |

Усі модулі вимагають Magento 2.4 (`magento/framework` 103.x). Допустиму пару Magento/PHP додатково обмежують залежності самого магазину. Підтримка PHP 8.5 у модулі не робить старий Magento сумісним із PHP 8.5. Compat і нативний Otel одночасно не встановлюються: це перевіряє Composer через `conflict`.

## Встановлення

Команди виконуйте в корені Magento під тим самим PHP, що й застосунок. У Warden запускайте їх через `warden env exec -T php-fpm`; у Docker/Kubernetes використовуйте PHP-контейнер збірки. Не змінюйте файли робочого `vendor` вручну.

Composer читає VCS-репозиторії лише з кореневого `composer.json`. Додайте їх командами нижче; вони зберігають решту репозиторіїв магазину.

```bash
composer config repositories.perspective-observability vcs https://github.com/perspectiveteamcom/magento2-observability.git
composer config repositories.perspective-observability-otel vcs https://github.com/perspectiveteamcom/magento2-observability-otel.git
composer config repositories.perspective-observability-compat vcs https://github.com/perspectiveteamcom/magento2-observability-compat.git
```
Для RUM без серверного трасування:

```bash
OTEL_PHP_AUTOLOAD_ENABLED=false composer require 'perspective/module-observability:^1.0' --with-dependencies
composer check-platform-reqs
php bin/magento module:enable Perspective_Observability
php bin/magento setup:upgrade
```

Для серверного трасування перейдіть до [інструкції Otel](https://github.com/perspectiveteamcom/magento2-observability-otel#встановлення). Одна вимога `perspective/module-observability-otel:^1.0 || ^2.0` обирає адаптер за PHP й установлює базовий модуль автоматично.

Збережіть `composer.json` і `composer.lock` у репозиторії магазину. Наступні збірки виконують `composer install` із lock-файла. `composer check-platform-reqs` перевіряє фактичний PHP та розширення; `config.platform.php` впливає на вибір залежностей, але не встановлює PHP. Не використовуйте `--ignore-platform-reqs` для розгортання.

Пакети встановлюються в `vendor/perspective/`. Перед переходом із `app/code` приберіть старі каталоги `Perspective/Observability*` у новій збірці, зберігши проєктні правки окремо. Дві копії з однаковою Magento-реєстрацією призведуть до помилки. Файли робочого релізу замінюйте штатним deployment.

Виконайте DI, розгортання статичних файлів та оновлення кешів за процедурою проєкту. Для RUM підключіть локальний шлюз і маршрут Nginx `/__observability/rum`, потім налаштуйте згоду й увімкніть `observability/browser/enabled`. Готовий `view/frontend/web/js/rum.js` входить у пакет; Node.js на сервері магазину не потрібен.

## Налаштування

У Magento: Stores → Configuration → Advanced → Observability.

| Шлях | Типове значення | Призначення |
| --- | --- | --- |
| `observability/browser/enabled` | `0` | Додає браузерний скрипт до сторінки |
| `observability/browser/sample_rate` | `0.1` | Частка відвідувань для вимірювань, від 0 до 1 |
| `observability/browser/require_consent` | `1` | Очікує явного дозволу від засобу керування згодою |

Скрипт поважає Do Not Track і Global Privacy Control. Передає категорію сторінки, числові Web Vitals і клас помилки; не передає URL, DOM, поля форм, cookies чи стек JavaScript. Відкликання згоди припиняє надсилання й очищає накопичені дані. Після відкликання новий дозвіл почне збір з наступного завантаження сторінки.

## Точки розширення

Проєктні зміни робіть у власному модулі, який залежить від `Perspective_Observability` через `etc/module.xml`. Так спільний код можна оновлювати без перенесення правок магазину.

### Згода користувача

Підключіть свій засіб керування згодою до одного сигналу. Після читання збереженого вибору та після кожної його зміни виконайте:

```javascript
window.perspectiveObservabilityConsent = analyticsAllowed === true;
window.dispatchEvent(new Event('perspective:observability-consent'));
```

`analyticsAllowed` має бути фактичним вибором користувача. При відкликанні задайте `false` й надішліть ту саму подію. Значення до ініціалізації також враховується. У спільному модулі немає читання cookies або localStorage конкретного постачальника згоди.

### Категорії сторінок

Додайте Magento-плагін до `Perspective\Observability\Block\Browser::getPageTypes()`. Наприклад, у власному `etc/frontend/di.xml`:

```xml
<type name="Perspective\Observability\Block\Browser">
    <plugin name="project_observability_pages" type="Vendor\Project\Plugin\BrowserPages"/>
</type>
```

```php
public function afterGetPageTypes(
    \Perspective\Observability\Block\Browser $subject,
    array $pages
): array {
    $pages['custom_catalog_view'] = 'category';
    return $pages;
}
```

Шлюз приймає категорії `home`, `content`, `product`, `category`, `search`, `cart`, `checkout`, `login`, `other`. Новий маршрут віднесіть до однієї з них. Для нової категорії потрібно також змінити дозволений список шлюзу й запити Grafana; не передавайте довільний URL або ідентифікатор товару як категорію.

### Тема й статичні файли

Точка підключення: `view/frontend/layout/default.xml` і `templates/browser.phtml`. Тема може перевизначити шаблон стандартним способом Magento. Збережіть `defer` та `data-observability-config`; JSON має залишатися екранованим. Перевірте CSP, кеш сторінок та фактичне завантаження скрипта у своїй темі.

## Сумісність і перевірка

Мінімальна ціль базового модуля: Magento 2.4 із PHP 7.4. Його PHP-код використовує синтаксис 7.4, а шаблон має варіант для Magento без `SecureHtmlRenderer`. Ця вимога до коду не є гарантією для всіх версій Magento, тем і сторонніх модулів.

Синтаксис і контракти базового модуля перевірено на PHP 7.4 та 8.1-8.5 без запуску Magento. Помилка читання налаштувань, кодування JSON, пошуку статичного файла або рендерингу скрипта пропускає вставку RUM. Модуль не змінює бізнес-дані чи відповіді API; його зміна HTML обмежена підключенням скрипта, коли збір увімкнений.

Після встановлення перевірте компіляцію DI, сторінки товару/кошика/оформлення, REST і GraphQL. З RUM перевірте відсутність надсилання до згоди, надсилання після дозволу, відкликання, DNT/GPC і CSP. Базовий модуль самостійно не створює серверних трас. На PHP 7.4 їх додає Compat через стандартні Magento plugins.

## Вимкнення й міграція

Вимкніть `observability/browser/enabled` і оновіть кеш конфігурації та сторінок. Для видалення спочатку вимкніть серверний адаптер, якщо він є, потім `Perspective_Observability`. Видаліть потрібний пакет через `composer remove` у збірці й виконайте звичайний deployment.

Якщо встановлено модулі зі старим вендором `Company`, перед видаленням старих каталогів вимкніть `Company_ObservabilityOtel` та `Company_Observability`. Оновіть проєктні посилання на класи, шаблони й сигнал згоди: `window.perspectiveObservabilityConsent` та `perspective:observability-consent`.

Попередній об'єднаний модуль видаліть повністю з нової збірки: у його каталозі могли залишитися `Bootstrap.php`, `Cron/`, `tools/` і XML. Прив'язку до засобу керування згодою перенесіть у модуль проєкту через сигнал вище; старе `consent_source` її не підключає.
