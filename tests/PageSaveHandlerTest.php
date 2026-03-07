<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/page_save_handler.php';

// =============================================================================
// buildPagePreviewPath
// =============================================================================

test('buildPagePreviewPath: prioriza full_path para páginas hijas', function () {
    $form = ['full_path' => 'sobre/equipo', 'slug' => 'equipo'];
    assert_eq('/sobre/equipo', buildPagePreviewPath($form));
});

test('buildPagePreviewPath: usa slug cuando full_path está vacío', function () {
    $form = ['full_path' => '', 'slug' => 'acerca-de'];
    assert_eq('/acerca-de', buildPagePreviewPath($form));
});

test('buildPagePreviewPath: si no hay datos devuelve raíz', function () {
    assert_eq('/', buildPagePreviewPath([]));
});

// =============================================================================
// ensurePageParentIsValid
// =============================================================================

test('ensurePageParentIsValid: impide parent_id igual al id en edición', function () {
    $thrown = false;
    try {
        ensurePageParentIsValid(10, 10);
    } catch (RuntimeException $e) {
        $thrown = true;
        assert_contains('propia página padre', $e->getMessage());
    }
    assert_true($thrown, 'Se esperaba RuntimeException cuando parent_id == editId.');
});

test('ensurePageParentIsValid: permite parent_id distinto del id en edición', function () {
    ensurePageParentIsValid(11, 10);
    assert_true(true);
});

test('ensurePageParentIsValid: permite crear con parent_id null', function () {
    ensurePageParentIsValid(null, null);
    assert_true(true);
});

