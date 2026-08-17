# AGENTS.md

# Инструкции за AI агент (Codex)

## Език

- Отговаряй на **български**, освен ако потребителят изрично поиска друг език.
- Имена на класове, namespaces, methods, properties, variables, database columns и други code identifiers да бъдат на **английски**.
- Техническите коментари в кода да следват езика и стила на съседния код.

---

## Проект

Това repository съдържа native **PrestaShop 8.x module** за:

**UniCredit Credit Calculator / Покупки на кредит**

Repository:

```text
wiley68/uni-ps8
```

Module technical name:

```text
unipayment
```

Работната директория е директно в тестов PrestaShop installation:

```text
/var/www/presta8.avalonbg.com/modules/unipayment
```

Repository root е едновременно и **PrestaShop module root**.

Няма отделен local build/deploy workflow. Промените в repository-то променят директно модула в тестовия магазин.

---

# Задължителен проектен контекст

Преди значима задача прочети:

```text
docs/IMPLEMENTATION_PLAN.md
```

Ако съществува:

```text
docs/ARCHITECTURE.md
```

прочети и него.

`IMPLEMENTATION_PLAN.md` е основният development plan и определя фазите, scope-а и STOP GATE точките.

Не преминавай към следваща фаза без изрично указание от потребителя.

---

# Reference repositories

Проектът има два важни външни reference repository-та.

## WooCommerce reference implementation

```text
wiley68/uni-woo
```

Това е работещата WooCommerce реализация на същия UniCredit продукт.

Използвай я за установяване на:

- business behavior;
- Control Panel communication;
- authentication flow;
- configuration cache;
- KOP logic;
- leasing calculations;
- promotional logic;
- product behavior;
- cart behavior;
- checkout behavior;
- order payload;
- SmartUCF payload;
- SmartUCF communication;
- diagnostic logging;
- bank-status synchronization.

### Важно

WooCommerce repository-то е **reference implementation**, не архитектурен шаблон.

Не копирай механично:

- WordPress hooks;
- WooCommerce classes;
- WP options;
- WP REST API architecture;
- `$wpdb`;
- WP AJAX;
- WordPress-specific helpers;
- WooCommerce-specific order abstractions.

При анализ на reference code класифицирай логиката като:

```text
A. Business rule
B. External API contract
C. WooCommerce-specific implementation
D. Presentation/UI
```

След това:

```text
A → preserve behavior
B → preserve contract
C → replace with native PrestaShop implementation
D → reproduce required UX with PrestaShop mechanisms
```

---

## Control Panel

```text
wiley68/uni.avalonbg.com
```

Stack:

```text
Laravel / Inertia / React / Shadcn
```

Control Panel е **source of truth** за module configuration и за съществуващия communication contract.

Използвай repository-то за проверка на:

- API routes;
- request validation;
- response structures;
- shop configuration payload;
- order payload;
- KOP/filter structures;
- coefficient structures;
- status synchronization;
- scheduled promotion behavior;
- module callbacks.

### Основно правило

**Не променяй Control Panel contract или business logic като част от този проект**, освен ако потребителят изрично не одобри такава промяна.

Целта е PrestaShop module да се адаптира към съществуващата система.

---

# Архитектурни принципи

Изграждай **native PrestaShop 8 module**.

Предпочитай:

- PrestaShop hooks;
- `PaymentModule`;
- `PaymentOption`;
- module front controllers;
- Symfony services, когато са подходящи;
- namespaced PHP classes в `src/`;
- отделни services;
- repositories за persistence;
- Smarty templates само за presentation;
- отделни JS/CSS assets.

Избягвай:

- Core overrides;
- глобални utility функции без необходимост;
- business logic в templates;
- API calls от templates;
- SQL в templates/controllers;
- огромни monolithic classes;
- копиране на WooCommerce architecture.

Core override може да бъде предложен само ако няма разумен native extension point.

---

# Разделение на слоевете

Стреми се към следното логическо разделение:

```text
Hooks / Controllers / Templates
            │
            ▼
       Application
            │
   ┌────────┼─────────┐
   │        │         │
Calculator Orders Configuration
   │        │         │
   └────────┼─────────┘
            ▼
      Infrastructure
       │     │      │
       CP SmartUCF Database
```

Business calculation logic трябва да бъде максимално независима от PrestaShop UI и controllers.

---

# Control Panel communication

Съществуващият outward API contract трябва да бъде запазен.

## Module → Control Panel

Поддържаните операции включват:

```text
POST  /api/v1/auth/login
POST  /api/v1/auth/refresh
POST  /api/v1/auth/logout

GET   /api/v1/shop

POST  /api/v1/orders
PATCH /api/v1/orders/status
```

Authentication използва:

```text
UNICID
shop name / URL
secret
```

след което се използва Bearer token.

Не измисляй нов authentication mechanism.

---

# Control Panel → Module

Модулът трябва да предоставя функционалния еквивалент на съществуващите три операции:

```text
shop-cache
smartucf-debug-log
order-bank-status
```

## shop-cache

Control Panel може да push-не нов configuration snapshot при:

- activation на бъдеща promotional scheme;
- expiration на promotional scheme;
- друга configuration промяна.

Push update трябва да влиза в сила незабавно и да не чака cache TTL.

## smartucf-debug-log

SmartUCF communication се извършва директно от модула.

Diagnostic request/response информацията се пази локално в магазина, но се разглежда централизирано през Control Panel.

Не създавай merchant-facing SmartUCF debug UI, освен ако изрично не бъде поискано.

## order-bank-status

Control Panel следи банковия status и push-ва промяната към модула.

Модулът трябва:

- да намери съответния PrestaShop order;
- да запише bank status;
- да го покаже на administrator-а.

По подразбиране:

```text
PrestaShop Order State != UniCredit Bank Status
```

Не променяй автоматично PrestaShop core order state според bank status без изрично business правило.

---

# Shop configuration

Control Panel е master за:

- KOP;
- promotional schemes;
- filters;
- coefficients;
- enabled months;
- min/max amounts;
- first installment;
- SmartUCF endpoints;
- environment;
- advertising;
- consents;
- останалите UniCredit business settings.

Не дублирай тези настройки като editable module settings.

Локалният module configuration трябва да съдържа само platform-specific/local настройки, необходими за функционирането му.

---

# Cache

Shop configuration се пази в persistent local cache.

Поддържай:

- initial fetch;
- cache hit;
- TTL;
- refresh;
- forced/manual refresh;
- Control Panel push replacement.

Push payload представлява configuration snapshot.

Не прави частично merge-ване на нов snapshot със стар configuration без изрично основание.

При permanent authentication/shop failure не продължавай мълчаливо с невалидна stale configuration.

---

# Calculator business logic

Calculator logic е критична и трябва да бъде отделена от presentation layer.

Пази reference поведението за:

- default KOP;
- schema KOP;
- promotional KOP;
- product filters;
- category filters;
- price filters;
- date filters;
- allowed months;
- coefficients;
- preferred month;
- first installment;
- zero-interest schemes;
- min/max financing amount.

Не опростявай съществуващата business logic само заради по-лесна имплементация.

---

# Cart logic

Cart calculator не е просто calculator върху cart total.

За всяка cart line трябва да бъдат определени допустимите financing schemes.

След това трябва да се намерят общите схеми за целия cart.

Запази reference behavior за:

- common KOP;
- common months;
- scheme type;
- promo/standard;
- LCM behavior, когато е приложимо;
- unavailable combinations.

Тази логика трябва да бъде реализирана като reusable domain service, а не директно в hook/template.

---

# Checkout security

Никога не се доверявай на financing данни, изпратени от browser.

При checkout submit винаги извършвай server-side повторна проверка на:

- cart;
- total;
- selected scheme;
- KOP;
- months;
- filter;
- first installment;
- required customer fields;
- mandatory consents.

Client-side стойностите са избор на потребителя, не source of truth.

---

# Orders

След създаване на PrestaShop order запази snapshot на financing условията, използвани при покупката.

Не извличай историческите условия на вече направена поръчка от текущия shop cache.

Подходящи order metadata включват:

```text
KOP
months
scheme/filter
first installment
monthly installment
interest
financed amount
Control Panel order id
SmartUCF session id
bank status id
bank status label
```

Точните полета се определят според implementation plan и reference contract.

---

# SmartUCF

SmartUCF комуникацията е:

```text
PrestaShop Module → SmartUCF
```

а не:

```text
PrestaShop Module → Control Panel → SmartUCF
```

Използвай отделен SmartUCF client/service.

Запази reference behavior за:

- test/production environment;
- service URL;
- `sucfOnlineSessionStart`;
- JSON payload;
- TLS;
- optional client certificate;
- session id;
- application redirect.

External redirect трябва да бъде валидиран срещу configured SmartUCF application URLs.

---

# Secrets и чувствителни файлове

Никога не commit-вай:

- real secret keys;
- Bearer tokens;
- private SSL keys;
- certificates, ако не са изрично предназначени за repository;
- certificate passwords;
- production credentials;
- `.env`;
- local secret configuration.

Преди commit или final review проверявай за accidental credential leakage.

Не показвай secrets в:

- logs;
- errors;
- debug output;
- screenshots;
- generated documentation.

---

# Работа директно в тестова среда

Repository-то се намира директно в работещ PrestaShop test installation.

Затова приемай всички filesystem и database действия като действия върху **жива тестова среда**.

Не изпълнявай destructive операции без изрично разрешение.

## Забранено без изрично разрешение

Не изпълнявай:

```text
DROP TABLE
TRUNCATE
database reset
mass DELETE
PrestaShop reinstall
shop reset
destructive cleanup
```

Не изтривай:

- orders;
- customers;
- products;
- categories;
- shop data

освен при изрично указание.

Module-specific test tables могат да бъдат променяни само когато това е част от текущата одобрена фаза и промяната е предварително обяснена.

---

# Git правила

1. Работи само по необходимия scope.
2. Не прави несвързан refactoring.
3. Не създавай Git commit, освен ако потребителят изрично го поиска.
4. Не push-вай автоматично.
5. Не създавай branch автоматично.
6. Не reset-вай или rewrite-вай Git history.
7. Не използвай destructive Git commands без изрично разрешение.
8. Не променяй reference repository-тата.

Преди завършване на задача показвай кои файлове са:

```text
created
modified
deleted
```

Deleted files трябва да бъдат изрично обяснени.

---

# Работа по IMPLEMENTATION_PLAN

Изпълнявай само текущо зададената фаза.

Пример:

Ако е възложен:

```text
Phase 2
```

не започвай Phase 3 дори Phase 2 да е завършен.

## Преди реализация

Преди първата промяна:

1. Прочети relevant phase.
2. Провери съществуващия project state.
3. Провери relevant WooCommerce reference implementation.
4. Провери Control Panel contract, ако е необходимо.
5. Представи кратък execution plan.
6. Посочи файловете, които очакваш да създадеш/промениш.

След това започни реализацията.

## След реализация

Предостави:

### Changed files

Какво е създадено/променено.

### Implementation

Какво реално е реализирано.

### Reference parity

Кое reference поведение е запазено.

### Tests/checks

Какво е изпълнено и какъв е резултатът.

### Risks / differences

Всички известни разлики, ограничения или нерешени въпроси.

### STOP

Спри на STOP GATE.

Не продължавай автоматично.

---

# Кога задължително да спреш

Спри и поискай решение, ако откриеш необходимост от:

- промяна в Control Panel API contract;
- промяна в SmartUCF contract/payload;
- промяна на KOP business logic;
- промяна на established order lifecycle;
- Core override;
- нова external runtime dependency;
- destructive database operation;
- премахване на съществуващо reference behavior;
- security compromise;
- съхраняване на secret по небезопасен начин;
- неяснота, която може да промени business behavior.

Не избирай сам архитектурно решение при тези случаи.

---

# Coding style

Следвай:

1. PrestaShop 8 conventions.
2. PSR-compatible namespaced PHP за новите classes, където е подходящо.
3. Existing project style, след като такъв бъде установен.
4. Small focused classes.
5. Explicit dependencies.
6. Clear return types и parameter types, когато target PHP compatibility го позволява.
7. Early validation.
8. Predictable error handling.

Избягвай:

- magic values;
- copy/paste business logic;
- deeply nested control flow;
- hidden side effects;
- silent failures;
- catch-all exceptions без полезен handling;
- direct global access от domain services.

---

# Frontend

Smarty templates трябва да съдържат presentation logic, не business calculations.

JavaScript:

- не трябва да бъде source of truth;
- може да управлява UI interactions;
- може да извършва preview calculations само ако server-side logic остава authoritative;
- не трябва да съдържа secrets.

След промяна на frontend functionality провери реалния резултат в PrestaShop test environment, когато средата позволява това.

---

# Database

Използвай PrestaShop DB mechanisms и module-owned tables, когато са необходими.

Всички SQL операции трябва:

- да използват правилния PrestaShop table prefix;
- да escape/validate input;
- да избягват concatenation на uncontrolled values;
- да бъдат безопасни при повторно изпълнение, когато това е lifecycle операция.

Schema changes трябва да са съвместими с бъдещ module upgrade path.

---

# Testing

Приоритетни за автоматизирани unit tests са pure/domain components:

- KOP resolution;
- filters;
- date rules;
- price boundaries;
- installment months;
- first installment;
- promo behavior;
- cart scheme intersection;
- LCM behavior;
- coefficient selection.

Integration поведението трябва да бъде проверявано и в реалния PrestaShop test shop.

Не променяй production-like data само за да улесниш тест.

---

# Definition of quality

При избор между:

```text
по-бърза имплементация
```

и

```text
ясна, проследима и native PrestaShop имплементация
```

предпочитай второто.

Целта е кодът да остане разбираем за разработчик, който проследява всяка промяна и трябва да може да поддържа проекта без зависимост от AI-generated history.

---

# Основно правило

Не работи като автономен собственик на проекта.

Работи като engineering agent, който:

```text
анализира
→ предлага
→ реализира определения scope
→ проверява
→ обяснява
→ спира за review
```

Потребителят запазва контрола върху архитектурата, business решенията и преминаването между фазите.