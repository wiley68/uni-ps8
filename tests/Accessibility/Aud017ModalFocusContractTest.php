<?php

declare(strict_types=1);

/**
 * AUD-017 — modal focus trap and background inert contracts.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertAud017(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$js = (string) file_get_contents($root . '/views/js/product-calculator.js');
$productTpl = (string) file_get_contents($root . '/views/templates/hook/product_calculator.tpl');
$cartTpl = (string) file_get_contents($root . '/views/templates/hook/cart_calculator.tpl');

// Test A — modal accessibility semantics
foreach ([$productTpl, $cartTpl] as $index => $tpl) {
    $label = $index === 0 ? 'product' : 'cart';
    assertAud017(strpos($tpl, 'role="dialog"') !== false, "{$label} template must keep role=dialog");
    assertAud017(strpos($tpl, 'aria-modal="true"') !== false, "{$label} template must keep aria-modal=true");
    assertAud017(strpos($tpl, 'tabindex="-1"') !== false, "{$label} dialog must allow programmatic focus fallback");
}

// Test B — initial focus inside modal
assertAud017(strpos($js, 'function focusInitialInModal') !== false, 'initial modal focus helper missing');
assertAud017(strpos($js, 'select.focus()') !== false, 'scheme select must remain preferred initial focus target');
assertAud017(strpos($js, 'focusInitialInModal()') !== false, 'open() must call initial focus helper');

// Test C / D — Tab and Shift+Tab wrap
assertAud017(strpos($js, 'function handleModalTabKey') !== false, 'Tab trap handler missing');
assertAud017(
    strpos($js, 'event.shiftKey && active === first') !== false,
    'Shift+Tab must wrap from first focusable to last'
);
assertAud017(
    strpos($js, '!event.shiftKey && active === last') !== false,
    'Tab must wrap from last focusable to first'
);

// Test E — focus trap + background inert
assertAud017(strpos($js, 'function applyBackgroundInert') !== false, 'background inert apply helper missing');
assertAud017(strpos($js, 'function restoreBackgroundInert') !== false, 'background inert restore helper missing');
assertAud017(strpos($js, 'function enableModalFocusGuard') !== false, 'focus containment guard missing');
assertAud017(strpos($js, 'document.body.inert') === false, 'must not set document.body.inert');

// Test F — dynamic focusable discovery (no permanent cache at open)
assertAud017(strpos($js, 'function getFocusableElements') !== false, 'focusable discovery helper missing');
assertAud017(
    strpos($js, 'querySelectorAll(FOCUSABLE_SELECTOR)') !== false,
    'focusable elements must be queried from current DOM'
);
assertAud017(
    !preg_match('/function open\([^)]*\)\s*\{[\s\S]*?getFocusableElements/', $js),
    'open() must not cache focusable elements at open time'
);
assertAud017(
    strpos($js, 'getFocusableElements(container)') !== false,
    'Tab trap must query focusable elements on each keydown'
);

// Test G — processing-state dialog fallback
assertAud017(strpos($js, 'function focusDialogContainer') !== false, 'dialog container fallback focus missing');
assertAud017(
    strpos($js, 'if (!focusables.length)') !== false && strpos($js, 'focusDialogContainer()') !== false,
    'empty focusable set must fall back to dialog container'
);

// Test H — dynamically inserted close buttons use current discovery
assertAud017(strpos($js, 'data-unipayment-close') !== false, 'dynamic close button contract must remain');
assertAud017(
    strpos($js, "button:not([disabled])") !== false,
    'close buttons must be included in focusable selector'
);

// Test I — Escape closes
assertAud017(strpos($js, 'event.key === "Escape"') !== false, 'Escape handler missing');

// Test J — focus restore to trigger
assertAud017(strpos($js, 'lastOpenTrigger.focus()') !== false, 'focus must restore to opening trigger');

// Test K — background inert restored on close
assertAud017(
    strpos($js, 'restoreBackgroundInert();') !== false && strpos($js, 'applyBackgroundInert();') !== false,
    'open/close must apply and restore background inert'
);
assertAud017(
    strpos($js, 'disableModalFocusGuard();') !== false,
    'close must detach focus guard'
);

// Test L — pre-existing inert preserved
assertAud017(
    strpos($js, 'inert: element.inert') !== false,
    'background inert must record previous state per element'
);
assertAud017(
    strpos($js, 'record.el.inert = record.inert') !== false,
    'background inert restore must reinstate previous value'
);

// Test M — redirectPending preserves modal state
assertAud017(
    strpos($js, 'if (redirectPending) return;') !== false,
    'close must remain blocked while redirectPending'
);

// Test N — repeated open/close must not leak handlers
assertAud017(
    strpos($js, 'if (modalFocusInGuard) return;') !== false,
    'focus guard must not register duplicate handlers'
);
assertAud017(
    strpos($js, 'backgroundInertRecords = [];') !== false,
    'inert records must reset between sessions'
);

fwrite(STDOUT, "OK (AUD-017 modal focus trap contracts)\n");
