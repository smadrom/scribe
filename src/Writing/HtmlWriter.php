<?php

namespace Knuckles\Scribe\Writing;

use Illuminate\Support\Facades\View;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Knuckles\Scribe\Tools\Globals;
use Knuckles\Scribe\Tools\Utils;
use Knuckles\Scribe\Tools\WritingUtils;
use Parsedown;

/**
 * Transforms the extracted data (endpoints YAML, API details Markdown) into a HTML site
 */
class HtmlWriter
{
    protected DocumentationConfig $config;
    protected string $baseUrl;
    protected Parsedown $markdownParser;

    public function __construct(DocumentationConfig $config = null)
    {
        $this->config = $config ?: new DocumentationConfig(config('scribe', []));
        $this->markdownParser = new Parsedown();
        $this->baseUrl = $this->config->get('base_url') ?? config('app.url');
    }

    public function generate(array $groupedEndpoints, string $sourceFolder, string $destinationFolder)
    {
        $intro = $this->transformMarkdownFileToHTML($sourceFolder . '/intro.md');
        $auth = $this->transformMarkdownFileToHTML($sourceFolder . '/auth.md');

        $appendFile = rtrim($sourceFolder, '/') . '/' . 'append.md';
        $append = file_exists($appendFile) ? $this->transformMarkdownFileToHTML($appendFile) : '';

        // If they're using the default static path,
        // then use '../docs/{asset}', so assets can work via Laravel app or via index.html
        $assetPathPrefix = '../docs/';
        if ($this->config->get('type') == 'static'
            && rtrim($this->config->get('static.output_path', ''), '/') != 'public/docs'
        ) {
            $assetPathPrefix = './';
        }

        $theme = $this->config->get('theme') ?? 'default';
        $output = View::make("scribe::themes.$theme.index", [
            'metadata' => $this->getMetadata(),
            'baseUrl' => $this->baseUrl,
            'tryItOut' => $this->config->get('try_it_out'),
            'intro' => $intro,
            'auth' => $auth,
            'groupedEndpoints' => $groupedEndpoints,
            'append' => $append,
            'assetPathPrefix' => $assetPathPrefix,
        ])->render();

        if (!is_dir($destinationFolder)) {
            mkdir($destinationFolder, 0777, true);
        }

        file_put_contents($destinationFolder . '/index.html', $output);

        // Copy assets
        $assetsFolder = __DIR__ . '/../../resources';
        if (!is_dir($destinationFolder . "/js")) {
            mkdir($destinationFolder."/js", 0777, true);
        }
        Utils::copyDirectory("{$assetsFolder}/images/", "{$destinationFolder}/images");

        $assets = [
            "{$assetsFolder}/css/theme-$theme.style.css" => ["$destinationFolder/css/", "theme-$theme.style.css"],
            "{$assetsFolder}/css/theme-$theme.print.css" => ["$destinationFolder/css/", "theme-$theme.print.css"],
            "{$assetsFolder}/js/theme-$theme.js" => ["$destinationFolder/js/", WritingUtils::getVersionedAsset("theme-$theme.js")],
        ];

        foreach ($assets as $path => [$destination, $fileName]) {
            if (file_exists($path)) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0777, true);
                }
                copy($path, $destination.$fileName);
            }
        }

        if ($this->config->get('try_it_out.enabled', true)) {
            copy("{$assetsFolder}/js/tryitout.js", $destinationFolder . WritingUtils::getVersionedAsset('/js/tryitout.js'));
        }
    }

    protected function transformMarkdownFileToHTML(string $markdownFilePath): string
    {
        return $this->markdownParser->text(file_get_contents($markdownFilePath));
    }

    protected function getMetadata(): array
    {
        // NB:These paths are wrong for laravel type but will be set correctly by the Writer class
        $links = [];
        if ($this->config->get('postman.enabled', true)) {
            $links[] = '<a href="../docs/collection.json">View Postman collection</a>';
        }
        if ($this->config->get('openapi.enabled', false)) {
            $links[] = '<a href="../docs/openapi.yaml">View OpenAPI spec</a>';
        }

        $auth = $this->config->get('auth');
        if ($auth['in'] === 'bearer' || $auth['in'] === 'basic') {
            $auth['name'] = 'Authorization';
            $auth['location'] = 'header';
            $auth['prefix'] = ucfirst($auth['in']).' ';
        } else {
            $auth['location'] = $auth['in'];
            $auth['prefix'] = '';
        }

        return [
            'title' => $this->config->get('title') ?: config('app.name', '') . ' Documentation',
            'example_languages' => $this->config->get('example_languages'),
            'logo' => $this->config->get('logo') ?? false,
            'last_updated' => date("F j Y"),
            'auth' => $auth,
            'try_it_out' => $this->config->get('try_it_out'),
            'links' => $links + ['<a href="http://github.com/knuckleswtf/scribe">Documentation powered by Scribe ✍</a>'],
        ];
    }
}
