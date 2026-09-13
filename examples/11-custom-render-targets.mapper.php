<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Armes\Armes;
use Armes\Emitter\HtmlEmitter;
use Armes\Emitter\JsxEmitter;
use Armes\Mapper\HtmlElementMapping;
use Armes\Mapper\HtmlMapper;
use Armes\Mapper\ReactComponent;
use Armes\Mapper\ReactMapper;
use Armes\Render\RenderTarget;

$tree = (new Armes())->parseJsonFile(example_path('11-custom-render-targets.source.json'));

$defaultCore = new Armes();
$customCore = Armes::default()
    ->withRenderTarget('html', RenderTarget::make(
        HtmlMapper::make()->element('input', HtmlElementMapping::tag('x-input')),
        new HtmlEmitter(),
    ))
    ->withRenderTarget('jsx', RenderTarget::make(
        ReactMapper::make()->component('input', ReactComponent::imported(
            source: '@headlessui/react',
            export: 'Input',
            as: 'HeadlessInput',
        )),
        new JsxEmitter(),
    ));

$defaultHtml = $defaultCore->renderHtml($tree);
$defaultJsx = $defaultCore->renderJsx($tree);
$customHtml = $customCore->renderHtml($tree);
$customJsx = $customCore->renderJsx($tree);

example_write_output('11-custom-render-targets.default.html', $defaultHtml);
example_write_output('11-custom-render-targets.default.jsx', $defaultJsx);
example_write_output('11-custom-render-targets.custom.html', $customHtml);
example_write_output('11-custom-render-targets.custom.jsx', $customJsx);

example_print('Default HTML', $defaultHtml);
example_print('Default JSX', $defaultJsx);
example_print('Custom HTML', $customHtml);
example_print('Custom JSX', $customJsx);
