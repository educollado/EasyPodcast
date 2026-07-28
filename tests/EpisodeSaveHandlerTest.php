<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/episode_save_handler.php';

// =============================================================================
// validateEpisodeForm — función pura, sin BD ni ficheros
// =============================================================================

function makeValidForm(): array
{
    return [
        'guid'             => '',
        'title'            => 'Mi episodio de prueba',
        'content'          => 'Una descripción completa del episodio.',
        'link'             => '',
        'pub_date'         => '2024-06-15T10:00',
        'audio_url'        => 'https://example.com/audio.mp3',
        'audio_mime_type'  => 'audio/mpeg',
        'audio_size_bytes' => '12345678',
        'duration'         => '',
        'explicit'         => '',
        'season_number'    => '',
        'episode_number'   => '',
        'episode_type'     => '',
        'image_url'        => '',
        'author'           => '',
        'status'           => 'draft',
    ];
}

test('audioBytesToMegabytesForInput: convierte bytes a MB', function () {
    assert_eq('10.00', audioBytesToMegabytesForInput('10485760'));
    assert_eq('11.77', audioBytesToMegabytesForInput('12345678'));
});

test('audioMegabytesToBytes: convierte MB con punto o coma a bytes', function () {
    assert_eq('10485760', audioMegabytesToBytes('10'));
    assert_eq('1572864', audioMegabytesToBytes('1.5'));
    assert_eq('1572864', audioMegabytesToBytes('1,5'));
});

test('validateEpisodeForm: formulario válido → null', function () {
    assert_null(validateEpisodeForm(makeValidForm()));
});

test('validateEpisodeForm: título vacío → error de obligatorio', function () {
    $form = makeValidForm();
    $form['title'] = '';
    assert_eq('El título es obligatorio.', validateEpisodeForm($form));
});

test('validateEpisodeForm: descripción vacía → error de obligatorio', function () {
    $form = makeValidForm();
    $form['status'] = 'published';
    $form['content'] = '';
    assert_eq('Título y descripción son obligatorios.', validateEpisodeForm($form));
});

test('validateEpisodeForm: draft con solo título → válido', function () {
    $form = makeValidForm();
    $form['content'] = '';
    $form['audio_url'] = '';
    $form['audio_size_bytes'] = '';
    assert_null(validateEpisodeForm($form));
});

test('validateEpisodeForm: published sin contenido → error', function () {
    $form = makeValidForm();
    $form['status'] = 'published';
    $form['content'] = '';
    assert_eq('Título y descripción son obligatorios.', validateEpisodeForm($form));
});

test('validateEpisodeForm: pub_date vacío → null (se asigna automáticamente)', function () {
    // pub_date ya no es obligatorio en el formulario; saveEpisode lo auto-asigna.
    $form = makeValidForm();
    $form['pub_date'] = '';
    assert_null(validateEpisodeForm($form));
});

test('validateEpisodeForm: explicit inválido → error', function () {
    $form = makeValidForm();
    $form['explicit'] = '2';
    assert_eq('El valor de explícito no es válido.', validateEpisodeForm($form));
});

test('validateEpisodeForm: explicit vacío → válido', function () {
    $form = makeValidForm();
    $form['explicit'] = '';
    assert_null(validateEpisodeForm($form));
});

test('validateEpisodeForm: explicit "0" → válido', function () {
    $form = makeValidForm();
    $form['explicit'] = '0';
    assert_null(validateEpisodeForm($form));
});

test('validateEpisodeForm: explicit "1" → válido', function () {
    $form = makeValidForm();
    $form['explicit'] = '1';
    assert_null(validateEpisodeForm($form));
});

test('validateEpisodeForm: status inválido → error', function () {
    $form = makeValidForm();
    $form['status'] = 'pending';
    assert_eq('El estado debe ser draft, published o scheduled.', validateEpisodeForm($form));
});

test('validateEpisodeForm: status "published" → válido', function () {
    $form = makeValidForm();
    $form['status'] = 'published';
    assert_null(validateEpisodeForm($form));
});

test('validateEpisodeForm: episode_type inválido → error', function () {
    $form = makeValidForm();
    $form['episode_type'] = 'live';
    assert_eq('El tipo de episodio debe ser full, trailer o bonus.', validateEpisodeForm($form));
});

test('validateEpisodeForm: episode_type "full" → válido', function () {
    $form = makeValidForm();
    $form['episode_type'] = 'full';
    assert_null(validateEpisodeForm($form));
});

test('validateEpisodeForm: episode_type vacío → válido', function () {
    $form = makeValidForm();
    $form['episode_type'] = '';
    assert_null(validateEpisodeForm($form));
});

test('validateEpisodeForm: pub_date con formato inválido → error', function () {
    $form = makeValidForm();
    $form['pub_date'] = 'no-es-una-fecha';
    assert_eq('La fecha de publicación no es válida.', validateEpisodeForm($form));
});

test('validateEpisodeForm: pub_date con formato SQL → válido', function () {
    $form = makeValidForm();
    $form['pub_date'] = '2024-06-15 10:00:00';
    assert_null(validateEpisodeForm($form));
});

test('validateEpisodeForm: audio_size_bytes negativo → error numérico', function () {
    $form = makeValidForm();
    $form['audio_size_bytes'] = '-5';
    assert_eq('Revisa los campos numéricos: deben ser enteros positivos.', validateEpisodeForm($form));
});

test('validateEpisodeForm: season_number negativo → error numérico', function () {
    $form = makeValidForm();
    $form['season_number'] = '-1';
    assert_eq('Revisa los campos numéricos: deben ser enteros positivos.', validateEpisodeForm($form));
});

test('validateEpisodeForm: episode_number no entero → error numérico', function () {
    $form = makeValidForm();
    $form['episode_number'] = 'abc';
    assert_eq('Revisa los campos numéricos: deben ser enteros positivos.', validateEpisodeForm($form));
});

test('validateEpisodeForm: campos numéricos vacíos → válido', function () {
    $form = makeValidForm();
    $form['audio_size_bytes'] = '';
    $form['season_number']    = '';
    $form['episode_number']   = '';
    assert_null(validateEpisodeForm($form));
});

test('validateEpisodeForm: season y episode con valores válidos → válido', function () {
    $form = makeValidForm();
    $form['season_number']  = '3';
    $form['episode_number'] = '12';
    assert_null(validateEpisodeForm($form));
});

// =============================================================================
// episodeFormDefaults
// =============================================================================

test('episodeFormDefaults: contiene todas las claves del formulario', function () {
    $defaults = episodeFormDefaults(['image_url' => '', 'author' => '']);
    $expectedKeys = ['guid', 'title', 'content', 'link', 'pub_date', 'audio_url',
                     'audio_mime_type', 'audio_size_bytes', 'duration', 'explicit',
                     'season_number', 'episode_number', 'episode_type', 'image_url',
                     'author', 'status'];
    foreach ($expectedKeys as $key) {
        assert_true(array_key_exists($key, $defaults), "Falta la clave '$key'");
    }
});

test('episodeFormDefaults: image_url y author heredan del podcast', function () {
    $defaults = episodeFormDefaults(['image_url' => 'https://example.com/cover.jpg', 'author' => 'Autor Test']);
    assert_eq('https://example.com/cover.jpg', $defaults['image_url']);
    assert_eq('Autor Test', $defaults['author']);
});

test('episodeFormDefaults: status inicial es draft', function () {
    $defaults = episodeFormDefaults(['image_url' => '', 'author' => '']);
    assert_eq('draft', $defaults['status']);
});

test('episodeFormDefaults: audio_mime_type inicial es audio/mpeg', function () {
    $defaults = episodeFormDefaults(['image_url' => '', 'author' => '']);
    assert_eq('audio/mpeg', $defaults['audio_mime_type']);
});
