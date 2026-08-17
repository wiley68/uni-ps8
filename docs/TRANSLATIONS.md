# Translation guidelines

The module uses the PrestaShop 8 translation system. English is the source
language. Bulgarian and other translations are managed from the Back Office:

1. Install the destination language in PrestaShop.
2. Open **International > Translations**.
3. Select **Installed modules translations**.
4. Select `unipayment` and the destination language.
5. Translate and save the discovered wording.

PrestaShop stores translations created in the Back Office in its translation
storage. Translation catalogues intended for distribution with the module must
be exported as XLIFF files and kept in `translations/`.

## Domains

Use these module domains consistently:

- `Modules.Unipayment.Admin` for Back Office wording, configuration, admin
  order information, status messages, and diagnostics.
- `Modules.Unipayment.Shop` for Front Office wording, product and cart
  calculators, checkout, validation messages shown to customers, and payment
  confirmation pages.

Domain values and source wording must always be string literals. This is
required for the PrestaShop source scanner to discover them. Do not hide calls
behind a custom translation helper and do not build source wording dynamically.
Use named placeholders for dynamic values.

## PHP

Inside the main module class:

```php
$this->trans(
    'Monthly installment: %amount%',
    ['%amount%' => $formattedAmount],
    'Modules.Unipayment.Shop'
);
```

In module front controllers, use the module translator. In Symfony services,
inject the `translator` service rather than obtaining the module globally:

```php
$this->translator->trans(
    'The selected financing option is unavailable.',
    [],
    'Modules.Unipayment.Shop'
);
```

Business/domain services should remain independent from presentation wording.
Return typed results or exceptions and translate them at the application or UI
boundary.

## Smarty

Use the `{l}` function with a literal module domain:

```smarty
{l s='Buy on credit' d='Modules.Unipayment.Shop'}
```

For placeholders:

```smarty
{l
    s='Pay in %months% monthly installments'
    sprintf=['%months%' => $months]
    d='Modules.Unipayment.Shop'
}
```

Templates must not perform business calculations.

## Twig

Use the `trans` filter with a literal module domain:

```twig
{{ 'Connection status'|trans({}, 'Modules.Unipayment.Admin') }}
```

## JavaScript

Do not hard-code visible wording in JavaScript. Translate it in PHP, Smarty, or
Twig and pass the translated value to JavaScript as escaped JSON or through a
data attribute. JavaScript must not become the source of translation wording.

## Development checklist

- Write every visible source wording in English.
- Translate all administrator and customer-facing wording.
- Keep source wording and domains as literals.
- Use named placeholders instead of string concatenation.
- Escape translated output for its HTML, attribute, JavaScript, or JSON context.
- Use `Admin` only in the Back Office and `Shop` only in the Front Office.
- After adding wording, verify that it appears under the installed module
  translations for `unipayment`.
- Export Bulgarian translations before packaging them with the module.
