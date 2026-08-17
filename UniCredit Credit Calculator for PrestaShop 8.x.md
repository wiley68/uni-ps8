# UniCredit Credit Calculator for PrestaShop 8.x
## Implementation Plan

**Repository:** `wiley68/uni-ps8`  
**Module technical name:** `unipayment`  
**Target platform:** PrestaShop 8.x  
**Reference implementation:** `wiley68/uni-woo`  
**Control Panel:** `wiley68/uni.avalonbg.com`

---

# 1. Project objective

Да се разработи нов native PrestaShop 8.x модул за **UniCredit Credit Calculator / покупки на кредит**, който реализира функционалността на съществуващия WooCommerce модул, без механично пренасяне на WordPress архитектура.

Модулът трябва да работи директно в:

```text
/var/www/presta8.avalonbg.com/modules/unipayment
```

Git repository root е едновременно и module root.

Основният принцип е:

```text
Control Panel = source of truth за configuration и business contract
WooCommerce module = reference implementation
PrestaShop module = нов native platform implementation
```

Целта не е WooCommerce кодът да бъде „преведен“ на PrestaShop, а съществуващото функционално поведение и protocol да бъдат реализирани с подходящите PrestaShop 8 механизми.

---

# 2. Основни архитектурни правила

## 2.1. Control Panel contract остава непроменен

Освен ако по време на реализацията не бъде доказана техническа необходимост, **не се променя логиката на Control Panel-а**.

PrestaShop модулът трябва да се адаптира към съществуващите API contracts.

Това включва:

```text
Module → CP

POST  /api/v1/auth/login
POST  /api/v1/auth/refresh
POST  /api/v1/auth/logout

GET   /api/v1/shop

POST  /api/v1/orders
PATCH /api/v1/orders/status
```

и съществуващите CP → Module операции:

```text
POST shop-cache
POST smartucf-debug-log
POST order-bank-status
```

Имената на module-side URL адресите могат да бъдат PrestaShop-specific, но payload contract-ът и семантиката трябва да останат съвместими с Control Panel-а.

---

# 3. Отговорности на трите системи

## Control Panel

Control Panel остава master за:

- shop configuration;
- UniCredit environment settings;
- KOP definitions;
- KOP filters;
- promotional schemes;
- времево активирани промоции;
- разрешени месеци;
- calculator configuration;
- coefficients;
- advertising configuration;
- consent configuration;
- bank-status monitoring;
- централизирано разглеждане на SmartUCF debug информация.

## PrestaShop module

Модулът отговаря за:

- authentication към CP;
- локално кеширане на shop configuration;
- приемане на push configuration updates;
- визуализация на рекламния блок;
- product calculator;
- cart calculator;
- checkout payment method;
- избор и повторна валидация на кредитна схема;
- създаване на PrestaShop order;
- създаване на CP order;
- директна комуникация със SmartUCF;
- локално записване на SmartUCF diagnostic data;
- redirect към банковото приложение;
- приемане и показване на банков статус;
- module-side API за Control Panel.

## SmartUCF

Модулът комуникира директно със SmartUCF за създаване на банковата credit/application session.

Control Panel **не трябва да става proxy на тази комуникация**.

---

# 4. Целева архитектура

Препоръчителното логическо разделение е:

```text
PrestaShop hooks / controllers
            │
            ▼
       Application
            │
     ┌──────┼─────────┐
     │      │         │
Calculator Order    Configuration
     │      │         │
     └──────┼─────────┘
            ▼
       Infrastructure
       │      │      │
       CP   SmartUCF DB/cache
```

Примерна module структура:

```text
unipayment/
├── unipayment.php
├── composer.json
├── .editorconfig
├── .gitignore
├── README.md
├── AGENTS.md
│
├── config/
│   └── services.yml
│
├── controllers/
│   └── front/
│
├── src/
│   ├── Api/
│   ├── Calculator/
│   ├── Configuration/
│   ├── Controller/
│   ├── Order/
│   ├── Repository/
│   ├── Security/
│   └── Service/
│
├── views/
│   ├── css/
│   ├── js/
│   └── templates/
│
├── translations/
│
└── docs/
    ├── IMPLEMENTATION_PLAN.md
    └── ARCHITECTURE.md
```

Точните namespaces и директории могат да бъдат прецизирани във Phase 0.

PrestaShop препоръчва собствените PHP classes на module да бъдат в `src/`, а front controllers да бъдат в `controllers/front/`.

---

# 5. Общи development правила

При всяка фаза:

1. Прочети настоящия документ.
2. Прочети `AGENTS.md`.
3. Анализирай relevant implementation в `uni-woo`.
4. При необходимост анализирай съответния contract в Control Panel.
5. Опиши предварително файловете, които възнамеряваш да създадеш или промениш.
6. Реализирай **само текущата фаза**.
7. Изпълни наличните проверки и тестове.
8. Покажи summary на всички промени.
9. Посочи открити рискове или отклонения от reference implementation.
10. Спри и изчакай review преди следващата фаза.

Не се преминава автоматично към следваща фаза.

---

# Phase 0 — Repository foundation и PrestaShop module skeleton

## Цел

Да се създаде минимален, валиден и installable PrestaShop 8 module без UniCredit business functionality.

## Задачи

Създаване на:

- `unipayment.php`;
- module metadata;
- install/uninstall lifecycle;
- namespaces и Composer autoload;
- `config/services.yml`, ако архитектурата го изисква;
- `.editorconfig`;
- `.gitignore`;
- coding-standard configuration;
- basic static-analysis configuration, ако е подходящо;
- `views/`;
- `controllers/front/`;
- `src/`;
- `translations/`;
- `docs/`.

Да се използват PrestaShop 8 conventions.

Да не се използват Core overrides, освен ако по-късна фаза не докаже, че са неизбежни. Официалната документация също препоръчва overrides да се избягват, когато има друга възможност.

## Проверка

Модулът трябва:

- да бъде видим в Module Manager;
- да се инсталира без error;
- да се enable/disable;
- да се uninstall-ва;
- да не променя поведението на магазина.

## STOP GATE 0

Review на skeleton, namespace design и tooling.

---

# Phase 1 — Module configuration

## Цел

Да се реализира минималната локална конфигурация, необходима за връзка с Control Panel.

## Настройки

Минимум:

```text
enabled
unicid
secret
```

Допълнителни локални настройки да се добавят само когато имат platform-specific причина.

Business settings като:

```text
KOP
months
promo schemes
coefficients
bank URLs
advertising
consents
```

**не трябва да бъдат дублирани в module configuration**, защото master е Control Panel.

## Admin функции

Да има:

- enable/disable;
- UNICID;
- secret;
- connection status;
- cache status;
- последно успешно обновяване;
- бутон за ръчен refresh;
- подходящи диагностични съобщения.

Secret стойността да не се показва обратно в plain text след запис.

## STOP GATE 1

Проверка на configuration UX и storage.

---

# Phase 2 — Control Panel API client и authentication

## Цел

Native PrestaShop service за комуникация:

```text
Module → Control Panel
```

## Необходима функционалност

Service от рода на:

```text
ControlPanelClient
```

който реализира:

```text
login()
refreshToken()
logout()
getShop()
createOrder()
updateOrderStatus()
```

Authentication flow:

```text
UNICID + shop URL/name + secret
              │
              ▼
          POST login
              │
              ▼
        Bearer token
              │
              ▼
     authenticated requests
```

Token-ът трябва:

- да се пази локално;
- да има expiration timestamp;
- да се refresh-ва;
- при 401 да има controlled retry;
- при окончателен authentication failure да бъде invalidated.

Да няма API calls директно от hooks/templates.

## Error classes

Да се различават поне:

- connection error;
- timeout;
- authentication error;
- HTTP error;
- malformed JSON;
- invalid CP payload.

## STOP GATE 2

Ръчен тест:

```text
login
GET /shop
refresh
logout
login again
```

---

# Phase 3 — Shop configuration cache

## Цел

Да се реализира локален persistent cache на configuration snapshot-а от CP.

## Основен модел

```text
CP
 │
 │ GET /shop
 ▼
ShopConfigurationService
 │
 ▼
ShopConfigurationCache
```

Препоръчителен custom DB table:

```text
unipayment_shop_cache
```

с поне:

```text
id
unicid
shop_data
coeff_list
kop_data
consents
fetched_at
expires_at
```

Точната схема се уточнява при реализацията.

## Поведение

Default TTL:

```text
24 hours
```

Поддържат се:

### Pull refresh

При:

- липсващ cache;
- expired cache;
- manual admin refresh.

### Push refresh

Control Panel може незабавно да изпрати нов configuration snapshot.

Това е критично за бъдещо активиране/деактивиране на промоционални схеми по дата.

Push refresh **не чака TTL**.

## Failure policy

При authentication/shop-not-found тип failure:

- token се инвалидира;
- configuration cache не трябва тихо да продължи с невалидна stale конфигурация.

При временен transport problem failure policy трябва да бъде изрично определен и тестван.

## STOP GATE 3

Проверка:

- initial fetch;
- cache hit;
- expiration;
- manual refresh;
- invalid credentials;
- CP push refresh.

---

# Phase 4 — CP → Module API

## Цел

Да се реализират трите Control Panel initiated operations.

### A. `shop-cache`

Purpose:

```text
CP scheduled/event logic
        ↓
promotion becomes active/inactive
        ↓
push complete fresh configuration
        ↓
PrestaShop cache replacement
```

Изисквания:

- POST only;
- `unicid + secret` authentication;
- payload validation;
- atomic cache replacement;
- clear success/error response;
- no partial configuration update.

### B. `smartucf-debug-log`

Purpose:

```text
Control Panel
     ↓
request diagnostic for order X
     ↓
PrestaShop module
     ↓
return locally stored SmartUCF request/response
```

Debug UI **не се изгражда в PrestaShop admin**.

Diagnostic information трябва да бъде достъпна за CP чрез authenticated endpoint.

### C. `order-bank-status`

Purpose:

```text
CP bank-status monitor
       ↓
status changed
       ↓
push status to module
       ↓
store on PrestaShop order
       ↓
show to shop administrator
```

Payload compatibility със съществуващия Woo contract трябва да се запази.

## Security

Всички endpoint-и:

- валидират method;
- валидират payload;
- валидират UNICID;
- сравняват secret безопасно;
- не expose-ват stack traces;
- не връщат secrets;
- логват диагностично необходимото без credential leakage.

PrestaShop front controllers са native mechanism за module-side HTTP endpoints и поддържат POST requests за операции, които променят state.

## STOP GATE 4

Ръчни CP → PS интеграционни тестове за трите операции.

---

# Phase 5 — Calculator domain

## Цел

Да се пренесе **business behavior**, а не WooCommerce implementation.

Да се създаде самостоятелен calculator/domain layer без dependency към Smarty templates или controllers.

## Основни concepts

Трябва да бъдат покрити:

```text
uni_status
uni_minstojnost
uni_maxstojnost
uni_first_vnoska
uni_shema_current
uni_typekop
uni_kop_default
uni_kop_promo
uni_promo_price
uni_promo_meseci
uni_promo_meseci_znak
enabled months
KOP filters
coeff_list
promo filters
product/category filters
price filters
date filters
first installment
```

### KOP mode 0

Default/promo KOP logic.

### KOP mode 1

Schema/filter-based selection.

Filter matching включва:

- product;
- category;
- price from/to;
- allowed months;
- promotion indicator;
- date from/to.

### Preferred offer

Да се запази reference behavior за:

- `uni_shema_current`;
- fallback selection;
- installment calculation;
- first installment;
- zero-interest/promo handling.

## IMPORTANT

Date-sensitive promotion logic се оценява и в модула спрямо получения configuration snapshot.

Control Panel push mechanism гарантира, че snapshot-ът ще бъде актуализиран при настъпване на planned CP event.

## Unit-test candidates

Calculator domain трябва да бъде максимално чист PHP, за да могат да се тестват:

- default KOP;
- schema KOP;
- category match;
- product match;
- price boundaries;
- date boundaries;
- first installment;
- unavailable schemes;
- promo;
- invalid coeff;
- preferred month.

## STOP GATE 5

Сравняване на резултатите със същите test inputs в WooCommerce reference module.

---

# Phase 6 — Product page

## Цел

Да се реализира UniCredit calculator/button върху PrestaShop product page.

## Поведение

За текущ продукт:

1. Получаване на текущия shop configuration от cache service.
2. Проверка дали module е enabled.
3. Проверка на currency.
4. Проверка на min/max условия.
5. KOP/filter resolution.
6. Изчисляване на наличните предложения.
7. Избиране на default button offer.
8. Визуализация.
9. Popup/interaction за избор на схема.
10. Възможност за започване на leasing purchase flow.

## Requirements

- native PrestaShop hook;
- template отделен от PHP logic;
- JS отделен от template;
- no API calls directly from template;
- calculator logic само през domain service;
- proper escaping;
- translation-ready strings.

## STOP GATE 6

Ръчни тестове с:

- simple product;
- combinations;
- различни категории;
- standard KOP;
- promo KOP;
- schema KOP;
- boundary prices;
- unavailable credit.

---

# Phase 7 — Cart calculator

## Цел

Да се реализира calculator върху целия PrestaShop cart.

Cart calculator **не е просто product calculator върху cart total**.

За всяка cart line трябва да се намерят допустимите схеми и след това да се изчисли общото множество от валидни предложения.

Conceptual flow:

```text
Product A → valid KOP/months ┐
Product B → valid KOP/months ├→ intersection → cart offers
Product C → valid KOP/months ┘
```

Да се запази reference поведението за:

- common KOP;
- common installment count;
- scheme type;
- standard/promo separation;
- LCM behavior, когато е приложимо;
- first installment;
- cart total;
- unavailable combinations.

Да се създаде reusable:

```text
CartSchemeResolver
```

или еквивалентен domain service.

## STOP GATE 7

Тестове с:

- един продукт;
- няколко еднакви продукта;
- различни продукти;
- различни категории;
- несъвместими KOP;
- общ KOP;
- common months;
- no common scheme;
- promo + standard combinations.

---

# Phase 8 — Checkout payment method

## Цел

Да се реализира native PrestaShop payment module integration.

`unipayment` трябва да бъде `PaymentModule`.

PrestaShop 8 payment integration използва `hookPaymentOptions()` и `PaymentOption`; payment action трябва да води към module controller, който обработва flow-а.

## Checkout UX

Payment method показва:

- име;
- описание;
- допустимите кредитни схеми;
- installment data;
- first installment, когато е приложимо;
- required customer fields за съответния process;
- mandatory consents.

## Критично правило

**Никога не се доверявай само на избраните от browser стойности.**

При checkout submit:

```text
posted scheme
      ↓
server-side cart reconstruction
      ↓
fresh scheme resolution
      ↓
validation
      ↓
calculation
```

Трябва повторно да се проверят:

- scheme;
- KOP;
- months;
- filter;
- cart total;
- first installment;
- required fields;
- mandatory consents.

## STOP GATE 8

Checkout може да достигне до validated internal payment request, но SmartUCF submission още може да бъде disabled/stubbed за фазовия тест.

---

# Phase 9 — Order orchestration и Control Panel order

## Цел

Да се реализира надеждният transactional flow.

Conceptual flow:

```text
Checkout validated
       ↓
Create PrestaShop Order
       ↓
persist selected financing metadata
       ↓
Create CP Order
       ↓
prepare SmartUCF request
       ↓
Phase 10
```

## Order metadata

Да се пазят необходимите immutable snapshot стойности за момента на покупката, например:

- selected KOP;
- selected months;
- scheme/filter;
- first installment;
- installment;
- interest;
- financing amount;
- CP order identifier;
- bank/session identifiers;
- current bank status;
- customer fields required by UniCredit.

Не трябва след бъдещ cache refresh стар order да изглежда сякаш е бил направен по нова configuration схема.

## CP order

Payload трябва да бъде функционално съвместим със съществуващия Woo implementation.

## Failure matrix

Да се дефинира и тества поведението при:

```text
PS order OK / CP order FAIL
PS order OK / CP order OK / SmartUCF FAIL
duplicate submission
browser retry
network timeout
CP timeout
```

Idempotency/duplicate protection трябва да бъде разгледана изрично.

## STOP GATE 9

Преди SmartUCF да бъде включен, проверка на:

- PrestaShop order;
- order state;
- stored financing metadata;
- CP order;
- duplicate prevention.

---

# Phase 10 — SmartUCF integration

## Цел

Да се реализира директната:

```text
PrestaShop module → SmartUCF
```

комуникация.

## SmartUcfClient

Изолиран infrastructure service.

Отговаря за:

- test/production endpoint;
- `sucfOnlineSessionStart`;
- JSON serialization;
- timeout;
- TLS;
- optional client certificate;
- certificate/key loading;
- response validation;
- `sucfOnlineSessionID`;
- application redirect URL;
- redirect host validation.

## Certificate handling

Private keys, passwords и certificates не трябва да попадат в Git history.

Да се дефинира secure deployment/configuration mechanism за production credentials.

## Diagnostic storage

Всеки relevant SmartUCF attempt да може да създаде diagnostic record:

```text
order identifier
request
response
HTTP status
transport error
timestamp
```

Sensitive data трябва да бъде redacted там, където е необходимо.

Diagnostic record е предназначен за CP retrieval, не за merchant-facing UI.

## Browser flow

При успешна session:

```text
SmartUCF session id
       ↓
trusted application URL
       ↓
browser redirect
```

Redirect трябва да бъде разрешен само към configured SmartUCF application base URL.

## STOP GATE 10

Пълни test-environment операции със SmartUCF.

---

# Phase 11 — Landing / advertising block

## Цел

Да се реализира marketing/landing functionality.

Control Panel управлява рекламното съдържание.

R2/CDN asset URL-ите се получават като част от shop configuration.

Трябва да се поддържат:

- desktop image;
- mobile image;
- configured URL;
- enabled/disabled state;
- responsive display;
- безопасен external URL handling.

Модулът **не управлява R2**.

Той е consumer на вече подготвените от Control Panel asset URLs.

## STOP GATE 11

Visual и responsive test.

---

# Phase 12 — Bank status synchronization и Back Office

## Цел

Merchant administrator да вижда актуалния UniCredit bank status върху PrestaShop order.

Flow:

```text
Control Panel console/status monitor
             ↓
bank status changed
             ↓
POST order-bank-status
             ↓
PrestaShop module
             ↓
order financing metadata
             ↓
Back Office order display
```

Да се пазят:

```text
status_id
status label
updated_at
```

Да не се смесва автоматично bank status с PrestaShop core order state без изрично business правило.

По подразбиране:

```text
PrestaShop Order State ≠ UniCredit Bank Status
```

Bank status трябва да бъде отделна информация.

## STOP GATE 12

Push status от CP и проверка в Back Office.

---

# Phase 13 — Logging, observability и error handling

## Цел

Диагностиката да бъде полезна, но да не expose-ва чувствителни данни.

Да бъдат разграничени:

```text
application log
CP API diagnostic
SmartUCF diagnostic
order financing metadata
```

Да няма:

- passwords;
- secret;
- Bearer tokens;
- private keys;
- certificate passwords

в обикновените logs.

Да има достатъчно correlation identifiers:

```text
PS order id
CP order id
UNICID
SmartUCF session id
```

където са приложими.

---

# Phase 14 — Security hardening

Проверка на:

- authentication;
- secret comparison;
- token storage;
- CSRF за merchant/admin actions;
- public front controllers;
- input validation;
- output escaping;
- SQL queries;
- redirect validation;
- TLS verification;
- certificate handling;
- replay/duplicate requests;
- API rate abuse;
- error information leakage;
- authorization boundaries;
- module enable/disable behavior.

Особено внимание на CP → Module endpoint-ите, защото са Internet-facing.

---

# Phase 15 — Compatibility и regression testing

## PrestaShop

Минимум:

```text
supported PS 8.x target
PHP version(s) used by supported PS8 installations
BGN
EUR
```

## Product tests

- simple product;
- combinations;
- categories;
- quantity > 1;
- promotional scheme;
- normal scheme;
- schema filters;
- boundary values.

## Cart tests

- 1 line;
- multiple lines;
- compatible schemes;
- incompatible schemes;
- quantities;
- mixed categories.

## Checkout

- valid finance;
- changed cart after selection;
- missing required data;
- missing consent;
- duplicate submit;
- browser refresh;
- payment failure.

## Integration

- CP unavailable;
- CP authentication failure;
- CP cache push;
- CP status push;
- SmartUCF timeout;
- SmartUCF invalid response;
- SmartUCF success;
- debug retrieval.

---

# Phase 16 — Final cleanup и release preparation

## Code quality

- remove temporary debug code;
- remove dead code;
- remove development fixtures;
- verify translations;
- verify coding standard;
- static analysis;
- final security review.

## Documentation

Finalize:

```text
README.md
ARCHITECTURE.md
installation/configuration instructions
release notes
```

## Module lifecycle

Проверка:

```text
fresh install
upgrade
disable
enable
uninstall
reinstall
```

Да се реши изрично дали uninstall:

- запазва module data;
- или я премахва.

Никога да не се изтриват merchant/order records без предварително определена политика.

---

# 6. Definition of Done

Модулът се счита за функционално завършен, когато:

- може да бъде инсталиран като native PrestaShop 8 module;
- комуникира със съществуващия Control Panel без промяна на неговия основен contract;
- кешира configuration;
- приема автоматични cache pushes;
- визуализира landing advertising;
- визуализира product financing;
- изчислява cart financing;
- предоставя checkout payment method;
- валидира схемата server-side;
- създава PrestaShop order;
- създава Control Panel order;
- комуникира директно със SmartUCF;
- пази диагностичните SmartUCF request/response данни;
- позволява Control Panel да прочете diagnostic record;
- приема bank status push;
- показва banking status на merchant administrator;
- работи с test SmartUCF environment;
- няма известни credential leaks;
- основните calculation scenarios съвпадат с WooCommerce reference implementation.

---

# 7. Migration principle

При всяка функционалност Codex трябва да класифицира reference кода като:

```text
A. Business rule
B. External API contract
C. Platform-specific WooCommerce implementation
D. Presentation/UI
```

След това:

```text
A → preserve behavior
B → preserve contract
C → replace with native PrestaShop implementation
D → reproduce required UX using PrestaShop mechanisms
```

**WordPress/WooCommerce-specific architecture не трябва да бъде копирана само защото съществува в reference module.**

---

# 8. Change-control principle

Ако по време на реализацията се открие необходимост от:

- промяна на Control Panel contract;
- промяна на SmartUCF payload;
- промяна на KOP business rule;
- промяна на order lifecycle;
- премахване на reference behavior;
- core override;
- нова external dependency;

Codex трябва:

1. да спре текущата реализация;
2. да обясни причината;
3. да покаже засегнатите компоненти;
4. да предложи варианти;
5. да изчака решение.

Такива решения не трябва да се правят автономно.

---

# 9. Recommended implementation order

```text
Phase 0   Foundation
   ↓
Phase 1   Configuration
   ↓
Phase 2   CP API Client
   ↓
Phase 3   Cache
   ↓
Phase 4   CP → Module API
   ↓
Phase 5   Calculator Domain
   ↓
Phase 6   Product
   ↓
Phase 7   Cart
   ↓
Phase 8   Checkout
   ↓
Phase 9   Orders + CP
   ↓
Phase 10  SmartUCF
   ↓
Phase 11  Advertising
   ↓
Phase 12  Bank Status
   ↓
Phase 13  Observability
   ↓
Phase 14  Security
   ↓
Phase 15  Regression
   ↓
Phase 16  Release
```

Всеки `STOP GATE` е задължителен review point.

След успешно преминаване на дадена фаза може да се направи отделен Git commit, така че историята на проекта да следва архитектурните стъпки.